#!/usr/bin/perl
#
# agi-agent-gatekeeper.pl
# Agent gatekeeper: choose a single agent and export FORCE_* AGI variables for dialplan.
# IMPORTANT: This AGI does NOT execute Dial(). Dial() must be done in the dialplan.
#
use strict;
use warnings;
use DBI;
use Time::HiRes qw(usleep);
use Asterisk::AGI;

my $script = 'agi-agent-gatekeeper.pl';
my $S = '*';
my $US = '_';

# read astguiclient.conf for DB info
my $PATHconf = '/etc/astguiclient.conf';
my ($VARDB_server,$VARDB_database,$VARDB_user,$VARDB_pass,$VARDB_port,$PATHlogs);
if ( -r $PATHconf ) {
    open my $cf, '<', $PATHconf or die "can't open $PATHconf: $!\n";
    while (<$cf>) {
        my $line = $_;
        $line =~ s/ |>|\n|\r|\t|\#.*|;.*//gi;
        if ($line =~ /^VARDB_server/)   { ($VARDB_server)   = $line =~ /.*=(.*)/; }
        if ($line =~ /^VARDB_database/) { ($VARDB_database) = $line =~ /.*=(.*)/; }
        if ($line =~ /^VARDB_user/)     { ($VARDB_user)     = $line =~ /.*=(.*)/; }
        if ($line =~ /^VARDB_pass/)     { ($VARDB_pass)     = $line =~ /.*=(.*)/; }
        if ($line =~ /^VARDB_port/)     { ($VARDB_port)     = $line =~ /.*=(.*)/; }
        if ($line =~ /^PATHlogs/)       { ($PATHlogs)       = $line =~ /.*=(.*)/; }
    }
    close $cf;
}
$VARDB_port ||= 3306;

# AGI
my $AGI = Asterisk::AGI->new();
my %env = $AGI->ReadParse();

# helpful wrappers
sub agi_log {
    my ($msg) = @_;
    my $ts = scalar localtime;
    print STDERR "$ts|$script|$msg\n";
    if ($PATHlogs) {
        my $f = "$PATHlogs/$script.log";
        if (open my $L, '>>', $f) {
            print $L "$ts|$msg\n";
            close $L;
        }
    }
}

sub gv {
    my ($name) = @_;
    my $v = $AGI->get_variable($name);
    return '' unless defined $v;
    return ref $v eq 'HASH' ? ($v->{value} // $v->{data} // '') : $v;
}

# connect DB
my $dbh = DBI->connect(
    "DBI:mysql:database=$VARDB_database;host=$VARDB_server;port=$VARDB_port",
    $VARDB_user, $VARDB_pass,
    { RaiseError => 0, PrintError => 0, mysql_enable_utf8 => 1 }
);
unless ($dbh) {
    agi_log("DB connect failed: $DBI::errstr");
    $AGI->set_variable("AGENT_RESPONSE","DB_FAIL");
    exit 1;
}

# AGI environment
my $agi_extension = $env{'agi_extension'} // '';
my $agi_context   = $env{'agi_context'} // '';
my $agi_channel   = $env{'agi_channel'} // '';
my $agi_uniqueid  = $env{'agi_uniqueid'} // '';

# parse CLI ARGV (support agi arg like original: "didpattern---AGENTDIRECT---LOGGED_IN..." )
my ($did_settings,$in_group,$agent_active_filter,$user_id_prompt,$minimum_user_digits,
    $invalid_prompt,$invalid_reenter_prompt,$retry_attempts,$invalid_ingroup,
    $transfer_prompt,$transfer_logout_prompt) = ();

if (defined $ARGV[0] && length $ARGV[0] > 1) {
    my @ARGV_vars = split(/---/, $ARGV[0]);
    ($did_settings,$in_group,$agent_active_filter,$user_id_prompt,$minimum_user_digits,
     $invalid_prompt,$invalid_reenter_prompt,$retry_attempts,$invalid_ingroup,
     $transfer_prompt,$transfer_logout_prompt) = @ARGV_vars;
}

$did_settings ||= 'default';
$in_group ||= 'AGENTDIRECT';
$agent_active_filter ||= 'ACTIVE';
$retry_attempts ||= 3;

# support campaign_id and agentid from second CLI arg(s)
my ($campaign_id, $forced_agent);
if (defined $ARGV[0]) {
    ($campaign_id) = $ARGV[0] =~ /campaign_id=(\w+)/ ? $1 : undef;
    ($forced_agent) = $ARGV[0] =~ /agentid=(\w+)/ ? $1 : undef;
}
if (defined $ARGV[1]) {
    ($campaign_id) = $ARGV[1] =~ /campaign_id=(\w+)/ ? $1 : $campaign_id;
    ($forced_agent) = $ARGV[1] =~ /agentid=(\w+)/ ? $1 : $forced_agent;
}

# helper: reset ring_request_status to IDLE unless it's a valid active state
sub check_and_reset_agent {
    my ($user) = @_;
    my $row = $dbh->selectrow_hashref("SELECT status, ring_request_status FROM vicidial_live_agents WHERE user=? LIMIT 1", undef, $user);
    return '' unless $row;
    my $rstat = $row->{ring_request_status} // '';
    if ($rstat !~ /RINGING|ASSIGNED|ACCEPTED|REJECTED|IN_CALL/i) {
        $dbh->do("UPDATE vicidial_live_agents SET ring_request_status='IDLE', last_update_time=NOW() WHERE user=?", undef, $user);
    }
    return $row->{status} // '';
}

# select an available agent (forced or dynamic). This does NOT dial.
sub select_agent {
    my ($forced) = @_;
    # forced agent path
    if ($forced) {
        my $sth = $dbh->prepare("SELECT conf_exten,user,extension,server_ip,status FROM vicidial_live_agents WHERE user=? LIMIT 1");
        $sth->execute($forced);
        my $r = $sth->fetchrow_hashref;
        $sth->finish;
        if ($r) {
            my $status = check_and_reset_agent($r->{user});
            if ($status =~ /READY|CLOSER/i) { return $r; }
            return undef;
        }
        return undef;
    }

    # dynamic selection (READY or CLOSER)
    my $sql = "SELECT conf_exten,user,extension,server_ip,status,campaign_id,closer_campaigns FROM vicidial_live_agents WHERE status IN('READY','CLOSER')";
    my @params;
    if ($campaign_id) {
        $sql .= " AND (campaign_id=? OR closer_campaigns LIKE ?)";
        push @params, $campaign_id, "% $campaign_id %";
    }
    $sql .= " ORDER BY last_call_time ASC, last_state_change ASC LIMIT 20";
    my $sth2 = $dbh->prepare($sql);
    $sth2->execute(@params);
    while (my $row = $sth2->fetchrow_hashref) {
        my $status = check_and_reset_agent($row->{user});
        next unless $status && $status =~ /READY|CLOSER/i;
        $sth2->finish;
        return $row;
    }
    $sth2->finish;
    return undef;
}

# Main: pick agent
my $picked = undef;
if ($forced_agent) {
    agi_log("Trying forced agent $forced_agent");
    $picked = select_agent($forced_agent);
    unless ($picked) {
        agi_log("Forced agent $forced_agent not available");
    }
}

unless ($picked) {
    $picked = select_agent();
}

if ($picked) {
    my $agent_user   = $picked->{user} // '';
    my $conf_exten   = $picked->{conf_exten} // '';
    my $agent_ext    = $picked->{extension} // '';
    my $server_ip    = $picked->{server_ip} // '';

    # mark agent as ASSIGNED (not ringing yet) so other processes know it's selected
    $dbh->do("UPDATE vicidial_live_agents SET ring_request_status='ASSIGNED', last_update_time=NOW() WHERE user=?", undef, $agent_user);

    # Prepare FORCE variables for the dialplan
    # Prefer Local/<conf_exten>@default if conf_exten present; fallbacks provided by dialplan if needed.
    my $force_dial = $conf_exten ? "Local/$conf_exten\@default" : ($agent_ext ? $agent_ext : '');

    # AGI variables to export to dialplan
    $AGI->set_variable("AGENT_RESPONSE","ACCEPTED");
    $AGI->set_variable("FORCE_AGENT",$agent_user);
    $AGI->set_variable("FORCE_CONF_EXTEN",$conf_exten // '');
    $AGI->set_variable("FORCE_SERVER_IP",$server_ip // '');
    $AGI->set_variable("FORCE_DIAL",$force_dial // '');
    $AGI->set_variable("AGENT_USER",$agent_user);
    $AGI->set_variable("AGENT_CONFEXT",$conf_exten // '');

    agi_log("Selected agent $agent_user conf:$conf_exten ext:$agent_ext server:$server_ip - FORCE_DIAL=$force_dial");

    # Return success; dial will be executed in the dialplan to allow proper handling.
    $dbh->disconnect();
    exit 0;
}
else {
    agi_log("No available agent found");
    $AGI->set_variable("AGENT_RESPONSE","ERROR");
    $AGI->set_variable("FORCE_AGENT","");
    $AGI->set_variable("FORCE_CONF_EXTEN","");
    $AGI->set_variable("FORCE_SERVER_IP","");
    $AGI->set_variable("FORCE_DIAL","");
    $dbh->disconnect();
    exit 0;
}
