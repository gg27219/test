<?php
require_once('./php/APIHandler.php');
require_once('./php/CRMDefaults.php');
require_once('./php/UIHandler.php');
require_once('./php/LanguageHandler.php');
require_once('./php/DbHandler.php');
require_once('./php/ModuleHandler.php');

define('GO_BASE_DIRECTORY', str_replace($_SERVER['DOCUMENT_ROOT'], "", dirname(__FILE__)));

// initialize structures
require_once('./php/Session.php');
session_start();
try {
    $api = \creamy\APIHandler::getInstance();
    $ui = \creamy\UIHandler::getInstance();
    $lh = \creamy\LanguageHandler::getInstance();
    $db = new \creamy\DbHandler();
    $user = \creamy\CreamyUser::currentUser();
    $mh = \creamy\ModuleHandler::getInstance();
} catch (\Exception $e) {
    header("location: ./logout.php");
    die();
}

if ($user->getUserRole() != CRM_DEFAULTS_USER_ROLE_AGENT) {
    header("location: index.php");
}

// Verify session variables
if (!isset($_SESSION['username']) || !isset($_SESSION['userid'])) {
    $error_message = 'Session variables not set';
    //echo json_encode(['status' => 'error', 'message' => $error_message]);
    error_log("Error logging OK: Error: $error_message");
    exit();
}

// Extract session variables
$session_id = session_id();
$user_id = $_SESSION['userid'];
$agent_name = $_SESSION['username'] ?? '';

// Prepare log entry
$logFile = '/var/log/checktest.log';
$logEntry = date('Y-m-d H:i:s') . ' - OK - Session ID: ' . $session_id . ' - Agent name: ' . $agent_name . ' - User ID: ' . $user_id . PHP_EOL;

// Write to log file
if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
    $error_message = 'Failed to write log entry';
    //echo json_encode(['status' => 'error', 'message' => $error_message]);
    error_log("Error logging OK: Error: $error_message");
    exit();
}

//echo json_encode(['status' => 'success']);

$lead_id = $_GET['lead_id'];
$output = $api->API_getLeadsInfo($lead_id);
$list_id_ct = count($output->list_id);

if ($list_id_ct > 0) {
    for ($i = 0; $i < $list_id_ct; $i++) {
        $first_name = $output->first_name[$i];
        $middle_initial = $output->middle_initial[$i];
        $last_name = $output->last_name[$i];

        $email = $output->email[$i];
        $phone_number = $output->phone_number[$i];
        $alt_phone = $output->alt_phone[$i];
        $address1 = $output->address1[$i];
        $address2 = $output->address2[$i];
        $address3 = $output->address3[$i];
        $city = $output->city[$i];
        $state = $output->state[$i];
        $country = $output->country[$i];
        $gender = $output->gender[$i];
        $date_of_birth = $output->date_of_birth[$i];
        $comments = $output->comments[$i];
        $title = $output->title[$i];
        $call_count = $output->call_count[$i];
        $last_local_call_time = $output->last_local_call_time[$i];
    }
}
$fullname = $title . ' ' . $first_name . ' ' . $middle_initial . ' ' . $last_name;
$date_of_birth = date('Y-m-d', strtotime($date_of_birth));
//var_dump($output);
$output_script = $ui->getAgentScript($lead_id, $fullname, $first_name, $last_name, $middle_initial, $email,
    $phone_number, $alt_phone, $address1, $address2, $address3, $city, $province, $state, $postal_code, $country);

if (isset($_GET["folder"])) {
    $folder = $_GET["folder"];
} else $folder = MESSAGES_GET_INBOX_MESSAGES;
if ($folder < 0 || $folder > MESSAGES_MAX_FOLDER) {
    $folder = MESSAGES_GET_INBOX_MESSAGES;
}

if (isset($_GET["message"])) {
    $message = $_GET["message"];
} else $message = NULL;

$user_info = $api->API_getUserInfo($_SESSION['user'], "userInfo");

// ECCS Customization
if (ECCS_BLIND_MODE != "y") {
    $html_title = "ATC Agent Panel V8.9";
} else {
    $html_title = "ECCS | Agent";
}
// /.ECCS Customization

// Function to fetch call logs for a specific agent
function fetchCallLogs($agent_name) {
    global $db; // Assuming $db is your database connection object

    // Construct the SQL query
    $sql = "SELECT user AS AgentName, event_time AS CallTime, lead_id AS LeadID, status AS CallDisposition, comments AS Comments 
            FROM vicidial_agent_log 
            WHERE user = ?";

    // Execute the query with the agent name parameter
    $stmt = $db->rawQuery($sql, [$agent_name]);

    // Check if the query executed successfully
    if ($stmt) {
        // Fetch all rows as associative array
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results;
    } else {
        // Return false or handle error as needed
        return false;
    }
}

// ECCS DB DRIVEN SETTING
/*
header("Content-Type:application/json");
$result = mysqli_query($con,"SELECT * FROM `settings` WHERE id=$id");
if(mysqli_num_rows($result)>0){
$row = mysqli_fetch_array($result);
$setting = $row['setting'];
$value = $row['value'];
response($id, $setting, $value);
mysqli_close($con);
}else{
response(NULL, NULL, 200,"No Record Found");
}
else{
response(NULL, NULL, 400,"Invalid Request");
}
*/
function response($order_id, $amount, $response_code, $response_desc) {
    $response['id'] = $_id;
    $response['setting'] = $setting;
    $response['value'] = $value;

    $json_response = json_encode($response);
    echo $json_response;
}

$id = $_POST['id'];
$client = curl_init($id);
curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($client);

$result = json_decode($response);

/*echo "<table>";
echo "<tr><td>ID:</td><td>$result->id</td></tr>";
echo "<tr><td>Setting:</td><td>$result->setting</td></tr>";
echo "<tr><td>Value:</td><td>$result->value</td></tr>";
echo "</table>";
*/

$agent_chat_status = $ui->API_getAgentChatActivation();
$whatsapp_status = $ui->API_getWhatsappActivation();

$osTicket = $mh->moduleIsEnabled('osTicket');

?>

<html>
    <head>
        <meta charset="UTF-8">
       <!-- <title><?=CRM_GOAGENT_TITLE?> - <?=$lh->translateText('GOautodial')." ".CRM_GO_VERSION?></title> -->
	<!-- ECCS Customization -->
	<title><?php echo $html_title; ?></title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

	<!-- /.ECCS Customization -->
        <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
		<!-- SnackbarJS -->
        <link href="css/snackbar/snackbar.min.css" rel="stylesheet" type="text/css" />
        <link href="css/snackbar/material.css" rel="stylesheet" type="text/css" />
		<!-- multiple emails plugin -->
		<link href="css/multiple-emails/multiple-emails.css" rel="stylesheet" type="text/css" />
        <!-- Customized Style -->
        <link href="css/creamycrm_test.css" rel="stylesheet" type="text/css" />
        <?php 
			print $ui->standardizedThemeCSS(); 
			print $ui->creamyThemeCSS();
			print $ui->dataTablesTheme();
		?>      

		<!-- ECCS JS -->
		<!--script src="eccs.js" type="text/javascript"></script-->
		<!-- Multi file upload -->
		<script src="js/plugins/multifile/jQuery.MultiFile.min.js" type="text/javascript"></script>
		<!-- Multiple emails -->
		<script src="js/plugins/multiple-emails/multiple-emails.js" type="text/javascript"></script>
		<!-- Print page -->
		<script src="js/plugins/printThis/printThis.js" type="text/javascript"></script>
		<!-- SIMPLE LINE ICONS-->
		<link rel="stylesheet" src="js/dashboard/simple-line-icons/css/simple-line-icons.css">
		<!-- WHIRL (spinners)-->
		<link rel="stylesheet" src="js/dashboard/whirl/dist/whirl.css">
		<!-- =============== PAGE VENDOR STYLES ===============-->
		<!-- WEATHER ICONS-->
		<link rel="stylesheet" src="js/dashboard/weather-icons/css/weather-icons.min.css">
		<link rel="stylesheet" href="modules/GOagent/css/agentstylev6.css">
		<!-- Datetime picker --> 
        <link rel="stylesheet" src="js/dashboard/eonasdan-bootstrap-datetimepicker/build/css/bootstrap-datetimepicker.min.css">
		<!-- iCheck for checkboxes and radio inputs -->
		<link href="css/iCheck/minimal/blue.css" rel="stylesheet" type="text/css" />
		<!-- iCheck -->
		<script src="js/plugins/iCheck/icheck.min.js" type="text/javascript"></script>
		<!-- SLIMSCROLL-->
		<script src="js/dashboard/slimScroll/jquery.slimscroll.min.js"></script>
		<!-- MD5 HASH-->
		<script src="js/jquery.md5.js" type="text/javascript"></script>
        <!-- Date Picker -->
        <script type="text/javascript" src="js/dashboard/eonasdan-bootstrap-datetimepicker/build/js/moment.js"></script>
        <script type="text/javascript" src="js/dashboard/eonasdan-bootstrap-datetimepicker/build/js/bootstrap-datetimepicker.min.js"></script>		
        <!-- X-Editable -->
        <!--<link rel="stylesheet" src="js/dashboard/x-editable/dist/css/bootstrap-editable.css">-->
        <!--<script type="text/javascript" src="js/dashboard/x-editable/dist/js/bootstrap-editable.min.js"></script>-->

        <!-- preloader -->
        <link rel="stylesheet" href="css/customizedLoader.css">
		
		<!-- flag sprites -->
		<link rel="stylesheet" href="css/flags/flags.min.css">

		<!-- CHAT -->	
	        <!-- <script src="modules/GoChat/js/chat.js"></script> -->

        <script type="text/javascript">
			history.pushState('', document.title, window.location.pathname);
			
			$(window).load(function() {
				$(".preloader").fadeOut("slow", function() {
					if (use_webrtc && (!!$.prototype.snackbar) && phone.isConnected()) {
						$.snackbar({content: "<i class='fa fa-exclamation-circle fa-lg text-warning' aria-hidden='true'></i>&nbsp; Please wait while we register your phone extension to the dialer...", timeout: 3000, htmlAllowed: true});
					}
				});
				
				$('#callback-list')
					.removeClass( 'display' )
					.addClass('table table-striped table-bordered');
				
				if (typeof country_codes !== 'undefined') {
					$("#country_code").append('<option value="">United States of America</option>');
					$("#country_code").append('<option value="">Canada</option>');
					$("#country_code").append('<option value="">Philippines</option>');
					$("#country_code").append('<option value="">Australia</option>');
					$("#country_code").append('<option value="">United Kingdom of Great Britain and Northern Ireland</option>');
					$.each(country_codes, function(key, value) {
						if (! /^(USA_1|CAN_1|PHL_63|AUS_61|GBR_44)$/g.test(key)) {
							$("#country_code").append('<option value="">'+value.name+'</option>');

						}
					});
				}
			});
			
		</script>





	<!-- ECCS Customiztion -->
	<?php 
		if(ECCS_BLIND_MODE === 'y'){
	?>

		<!-- ECCS CSS -->
	<!--	<link href="./css/bootstrap-toggle.min.css" type="text/css" /> -->
		<link href="./css/eccs4.css" rel="stylesheet" type="text/css"/>
		
	<!--	<script src="./js/bootstrap-toggle.min.js" type="text/javascript"></script> -->
	<?php }// end if ?>
	<!-- /. ECCS Customization -->


    </head>
    
    <?php print $ui->creamyAgentBody(); ?>
    
    <div class="wrapper">
        <!-- header logo: style can be found in header.less -->
		<?php print $ui->creamyAgentHeader($user); ?>
            <!-- Left side column. contains the logo and sidebar -->
		<?php print $ui->getAgentSidebar($user->getUserId(), $user->getUserName(), $user->getUserRole(), $user->getUserAvatar()); ?>

            <!-- Right side column. Contains the navbar and content of the page -->
            <aside class="content-wrapper" style="padding-left: 0 !important; padding-right: 0 !important;" >
                <!-- Content Header (Page header) -->
  			<!-- ECCS Customization -->
                	<?php if(ECCS_BLIND_MODE !== 'y')
			{ ?> 
				
                <!-- Content Header (Page header) 
                <section id="contact_info_crumbs" class="content-heading">
                <span id="contact_info_bar"><?php $lh->translateText("contact_information"); ?></span>
                    <ol class="breadcrumb hidden-xs pull-right">
                          <li class="active"><i class="fa fa-home"></i> <?php $lh->translateText('home'); ?></li>
                    </ol>
                </section> -->
			<?php }//end if ?>
                        <!-- /.ECCS Customization -->

<style>
  /* Make table text dark and readable */
#callback-list th, #callback-list td {
  color: #222 !important;
  font-weight: 600;
  background: #fff !important;
}

/* Zebra striping for better readability */
#callback-list tbody tr:nth-child(odd) td {
  background: #f6f8fa !important;
}

/* Highlight on hover */
#callback-list tbody tr:hover td {
  background: #e3f0ff !important;
  color: #0b6fd6 !important;
}

			#btn-dialer-tab, #btn-whatsapp-tab{
		box-shadow: 0 0 black;
		color: black;
		margin: 5px 50px 0px 0px;
		background: lightgrey;
	}
	section#contact_info_crumbs{
		margin-bottom: 0px; /*5px;*/
		padding-left: 40px;
		padding-right: 40px;
	}
	div.tab-content, div.tab-pane section.content{
		/*padding-top: 2.5px;*/
		padding-top: 0px !important;
	}
	body,
.navbar-agent,
.custom-tabpanel,
.panel,
.modal-content,
.dropdown-menu,
.table,
.form-control,
.form-group,
.nav-tabs .nav-link,
.card-header,
.panel-heading,
.modal-header,
.card,
.card-body,
.card-heading,
.panel-footer,
.panel-body,
input,
textarea,
label,
th,
td,
h1, h2, h3, h4, h5, h6,
button,
a,
span,
div,
ul,
li {
    font-family: 'Poppins', sans-serif !important;
    font-weight: bold !important;
}
body {
    color: #333 !important;
    background: #000000 !important;
}
/* Make user and phone avatars black if they are icons */
.avatar,
.fa-user,
.fa-phone,
.icon-user,
.icon-phone {
    color: #000 !important;
    background: none !important;
    filter: none !important;
}

/* Make user and phone avatars black if they are images */
.avatar img,
#cust_avatar img,
.vue-avatar img {
    filter: grayscale(100%) brightness(0) !important;
    background: #fff !important;
}

/* Remove logo filter so logo stays original color */
header.main-header .logo img {
    filter: none !important;
    background: none !important;
}
</style>
                <!-- Main content -->
<!-- <ul class="nav nav-tabs nav-justified content-tabs" style="position: absolute; top: 120px; width: 84%; left: 55px;"> -->
    <!-- <li id="dialer-tab" class="active"><a href="#control-dialer-tab" data-toggle="tab">Dialer</a></li>
    <li id="whatsapp-tab" class=""><a href="#control-whatsapp-tab" data-toggle="tab">Whatsapp</a></li>-->
 
					<!-- standard custom edition form -->
					<div class="container-custom ng-scope">
						<div id="cust_info" class="card">
							<?php if(SHOW_AGENT_HEADER === 'y'){?>
								<!-- ECCS Customization -->
								<?php // if(ECCS_BLIND_MODE === 'y'){?>
								 <div style="background-image:;" class="card-heading bg-inverse">
									<div class="row">
										<div id="cust_avatar" class="col-lg-1 col-md-1 col-sm-2 text-center hidden-xs" style="height: 64px;">
											<avatar username="Dialed Client" src="<?php echo CRM_DEFAULTS_USER_AVATAR;?>" :size="64"></avatar>
										</div>
										<div class="<?php if (ECCS_BLIND_MODE === 'n') { echo "col-lg-9 col-md-9 col-sm-7"; } else { echo "col-lg-11 col-md-11 col-sm-10"; } ?>">
								<!-- ECCS Customization-->
						   <h4 id="cust_full_name" class="isDisabled">
									<?php if(ECCS_BLIND_MODE === 'n'){ ?>
									<span id="first_name_label" class="hidden"><?=$lh->translationFor('first_name')?>: </span><a href="#" id="first_name">Firstname</a> <span id="middle_initial_label" class="hidden"><?=$lh->translationFor('middle_initial')?>: </span><a href="#" id="middle_initial">M.I.</a> <span id="last_name_label" class="hidden"><?=$lh->translationFor('last_name')?>: </span><a href="#" id="last_name">Lastname</a>
									<?php } ?>
									<!-- ECCS Customization -->
									<?php if(ECCS_BLIND_MODE === 'y'){ ?>
									 <span id="cust_campaign_name"></span>
									<span id="first_name_label" class="hidden"><?=$lh->translationFor('first_name')?>: </span><a href="#" id="first_name"></a> <span id="middle_initial_label" class="hidden"><?=$lh->translationFor('middle_initial')?>: </span><a href="#" id="middle_initial"></a> <span id="last_name_label" class="hidden"><?=$lh->translationFor('last_name')?>: </span><a href="#" id="last_name"></a>
									<span id="cust_call_type"></span>
									<?php }//end if ?>
         <!-- /.ECCS Customization -->
								</h4>
						                <p class="ng-binding animated fadeInUpShort">
									 <!-- ECCS Customization -->
                                                                        <?php if(ECCS_BLIND_MODE === 'y'){ ?> 
										<span id="span-cust-number" class="hidden"><label for="cust_number"> Client Number[#CN]: </label> <input type="text" id="cust_number" style="background-color:; border:; color:black; margin-top: 5px; padding-left: 5px; font-size: 14pt; font-weight: 600;" onclick="this.setSelectionRange(0, this.value.length)" readonly/>"Ctrl+C" to Copy Number.</span>

									<?php } else { ?>
                                                                        <!-- /.ECCS Customization -->
									<span id="cust_number"></span>
									<?php } ?>
								</p>
						    </div>
										<?php if (STATEWIDE_SALES_REPORT === 'y') { ?>
										<div id="agent_stats" class="col-lg-2 col-md-2 col-sm-3 hidden-xs" style="font-size: 18px; display: none; float:right;">
											<p style="margin: 0;">Sales: <span id="agent_sales_count" style="float: right;">0</span></p>
											<p id="amount_container" style="margin: 0; display: none;">Amount: <span id="agent_total_amount" style="float: right;">0</span></p>
										</div>
										<?php } ?>
									</div>
								</div>
							<!-- /.card heading -->
						<?php }?>				
							<!-- ---------- NEW AGENT PANEL LAYOUT (replace your old tab HTML) ---------- -->
<style>
/* ===========================
   MASTER STYLES (merged)
   =========================== */

/* layout */
.agent-panel-grid {
  display: grid;
  grid-template-columns: 2fr 1fr; /* left = merged two cols, right = single column */
  gap: 18px;
  align-items: start;
}


/* Force top bar to stay fixed no matter what UI handler does */
body .main-header,
body .navbar,
body .top-nav,
body #topNav {
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 99999 !important;
}

/* Prevent content from hiding behind the fixed header */
body {
    padding-top: 60px !important;   /* adjust to your header height */
}

/* ---------- Header container (3D blue look) ---------- */
.agent-header.card {
  border-radius: 12px;
  overflow: visible;
  margin-bottom: 14px;
  padding: 0;
  box-shadow: 0 12px 28px rgba(8,30,70,0.12);
  border: none;
  background: transparent;
  font-family: "Helvetica Neue", Arial, sans-serif;
}

.agent-header .card-heading {
  display: flex;
  gap: 16px;
  align-items: center;
  padding: 14px 18px;
  border-radius: 12px;
  background: linear-gradient(135deg, #0b6fd6 0%, #0a58c0 60%, #073f8a 100%);
  color: #fff;
  box-shadow: 0 10px 28px rgba(10,40,90,0.18), inset 0 -3px 0 rgba(255,255,255,0.06);
}

/* avatar area */
.agent-header .avatar-wrap {
  flex: 0 0 64px;
  width: 64px;
  height: 64px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: rgba(255,255,255,0.06);
  box-shadow: inset 0 -2px 0 rgba(255,255,255,0.02);
}
.agent-header avatar { display:block; }

/* main info area */
.agent-header .agent-main {
  flex: 1 1 auto;
  min-width: 200px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.agent-header .name-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.agent-header .cust-name {
  font-size: 18px; font-weight: 800; color: #ffffff;
  text-shadow: 0 2px 6px rgba(6,30,70,0.25); margin: 0;
}
.agent-header .cust-meta { font-size: 13px; font-weight:600; color: rgba(255,255,255,0.92); }

/* small pill */
.agent-header .pill { display:inline-block; padding:4px 8px; border-radius: 999px;
  background: rgba(255,255,255,0.08); color: #ffffff; font-weight:700; font-size:12px; }

/* number / copy line */
.agent-header .number-line { display:flex; align-items:center; gap:8px; font-size:14px; color: rgba(255,255,255,0.95); margin-top:2px; }
.agent-header .number-line input[type="text"] {
  -webkit-appearance:none; appearance:none; border: none; background: rgba(255,255,255,0.06);
  color: #ffffff; padding: 6px 8px; border-radius: 8px; font-weight:700; font-size:14px;
  min-width: 160px; outline: none;
}
.agent-header .number-line input[readonly] { cursor: text; }

/* right stats area */
.agent-header .agent-stats {
  flex: 0 0 220px; min-width: 140px; display:flex; flex-direction:column; align-items:flex-end;
  gap:6px; color: rgba(255,255,255,0.95); font-weight:700;
}
.agent-header .agent-stats p { margin:0; font-size:14px; display:flex; gap:6px; align-items:center; }
.agent-header .stat-value { background: rgba(255,255,255,0.08); padding: 6px 10px; border-radius: 10px; font-weight:800; }

/* subtle responsive behavior for header */
@media (max-width: 880px) {
  .agent-header .card-heading { flex-direction: column; align-items: flex-start; gap:12px; padding:12px; }
  .agent-header .agent-stats { align-self: stretch; flex-direction:row; justify-content:space-between; width:100%; }
  .agent-header .avatar-wrap { width:56px; height:56px; }
  .agent-header .cust-name { font-size:16px; }
}

/* Panel / 3D header styles */
.panel-3d-header {
  background: #2f6b94!important; border-radius: 12px; padding: 14px 18px;
  box-shadow: 0 10px 28px rgba(10,40,90,0.18), inset 0 -3px 0 rgba(255,255,255,0.06);
  font-weight: 800; font-size: 18px; color: #ffffff; display:flex; align-items:center;
  text-shadow: 0 1px 0 rgba(0,0,0,0.15);
}
.panel-3d-subtitle { margin-left: 12px; font-weight:600; color: rgba(255,255,255,0.92); font-size: 13px; }
.panel-3d-header .fa { color: #ffffff; text-shadow: 0 2px 8px rgba(11,111,214,0.18); }

/* right-card 3D headers & bodies */
.right-card { position: relative; overflow: visible; }
.bg-inverse { background: #2f6b94!important; font-size: 18px; font-weight: 800; color: #ffffff; text-shadow: 0 2px 6px rgba(6,30,70,0.25); }
.right-card .section-title {
  background: #2f6b94!important; border-radius: 12px; padding: 14px 18px;
  box-shadow: 0 12px 24px rgba(8,30,70,0.18), inset 0 -2px 0 rgba(255,255,255,0.08);
  font-weight: 800; font-size: 18px; color: #ffffff; display:flex; align-items:center;
  transform: translateY(-6px); z-index: 2; transition: transform .18s ease, box-shadow .18s ease;
}
.right-card .section-title:hover { transform: translateY(-8px); box-shadow: 0 18px 36px rgba(8,30,70,0.22); }
.right-card .section-title h5 { margin: 0; font-size: 14px; font-weight: 800; color: #ffffff; }
.right-card .section-title .fa { margin-right: 8px; color: #ffffff; font-size: 15px; }
.right-card .section-body {
  margin-top: -4px; padding: 14px; border-radius: 8px; border: 1px dashed rgba(190,200,210,0.4);
  background: #ffffff; box-shadow: 0 6px 20px rgba(18,35,60,0.03); position: relative; z-index: 1;
}

/* spacing between stacked right-cards */
.agent-panel-grid > div > .right-card + .right-card { margin-top: 12px; }

/* soften lift on smaller screens */
@media (max-width: 900px) {
  .right-card .section-title { transform: translateY(-4px); box-shadow: 0 10px 22px rgba(8,30,70,0.16); }
  .right-card .section-body { margin-top: -2px; }
}

/* Base card styling (keeps 3D look) */
.left-card, .right-card {
  background: white; border-radius: 10px; padding: 16px;
  box-shadow: 0 6px 18px rgba(32,47,60,0.04);
  border: 1px solid rgba(200,210,215,0.6);
}

/* customer field rows (3D look) */
.cust-field {
  display: grid;
  grid-template-columns: 160px 1fr;
  column-gap: 12px;
  align-items: center;
  padding: 8px 6px;
  border-radius: 6px;
  background: linear-gradient(180deg, #ffffff, #fbfdff);
  border: 1px solid rgba(210,220,225,0.6);
  box-shadow: inset 0 -1px 0 rgba(255,255,255,0.6), 0 4px 10px rgba(8,30,70,0.02);
}
.cust-field + .cust-field { margin-top: 8px; }

.cust-field label { font-size: 13px; color: #354b5b; font-weight: 600; }

.cust-field input[type="text"],
.cust-field input[type="number"],
.cust-field textarea,
.cust-field select {
  width: 100%; padding: 8px 10px; border-radius: 8px;
  border: 1px solid rgba(180,190,195,0.7); background: #fbfdff; font-size: 13px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}

/* focus highlight (keeps 3D feel) */
.cust-field input:focus, .cust-field textarea:focus, .cust-field select:focus {
  outline: none; box-shadow: 0 6px 18px rgba(11,111,214,0.08); border-color: rgba(11,111,214,0.9);
}

/* comment/script sections on right */
.right-card .section-title { display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px; }
.right-card .section-title h5 { margin:0; font-size:14px; font-weight:700; color:#ffffff; }
.right-card .section-body { min-height: 160px; border-radius:8px; padding:10px; border: 1px dashed rgba(190,200,210,0.4); background: #fff; }

/* script container */
#ScriptContents { min-height: 180px; max-height: 420px; overflow:auto; background: #fff; }

/* form footer */
.agent-panel-footer { margin-top: 14px; display:flex; justify-content:flex-end; }

/* keep old hidden/edit button styles compatible */
.edit-profile-button { font-size: 13px; padding: 6px 12px; border-radius: 8px; }

/* modern contacts & tables (unchanged logic) */
.modern-contacts-card { padding: 16px; border-radius: 12px; background: #ffffff; box-shadow: 0 10px 22px rgba(12,35,64,0.06); border: 1px solid rgba(220,230,235,0.9); margin-bottom: 12px; }
.modern-contacts-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; }
.modern-contacts-table { width: 100% !important; border-collapse: separate; border-spacing: 0; min-width: 780px; background: #ffffff; }
.modern-contacts-table th, .modern-contacts-table td, .modern-contacts-table caption { color: #0b0b0b !important; font-family: "Inter","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif; font-size: 13px; }
.modern-contacts-table thead th { background: linear-gradient(180deg,#f7fbff 0%,#eef6ff 100%); font-weight:700; text-transform:uppercase; font-size:12px; letter-spacing:0.6px; padding:12px 14px; border-bottom:1px solid rgba(220,230,235,0.9); color:#0b0b0b !important; }
.modern-contacts-table tbody td { padding:10px 12px; vertical-align: middle; border-bottom:1px solid rgba(240,245,248,0.9); background: transparent; }
.modern-contacts-table tbody tr:nth-child(odd) td { background: rgba(248,250,252,0.65); }
.modern-contacts-table tbody tr:hover td { background: rgba(11,111,214,0.04); transition: background .12s ease; }

/* modal header 3D */
.modal-3d-header { background: linear-gradient(135deg,#0b6fd6 0%,#0a58c0 60%,#073f8a 100%); padding:12px 18px; box-shadow:0 12px 28px rgba(8,30,70,0.12), inset 0 -3px 0 rgba(255,255,255,0.06); color:#fff; border-radius:12px 12px 0 0; }

/* ensure loaders & iframe visuals */
#agentTasksModalLoader { display:none; }
#agentTasksIframe { background: #fff; }

/* responsive: full width on small modals */
@media (max-width: 720px) {
  .modal-dialog { max-width: 100% !important; margin: 6px; }
  #agentTasksIframe { height: 70vh; }
}

/* agent topbar modernization (unchanged) */
header.main-header { background: linear-gradient(135deg,#2a3f54,#1a2733); box-shadow: 0 3px 8px rgba(0,0,0,0.25); border-bottom: 2px solid #0d6efd; position: relative; z-index: 1030; }
header.main-header .logo img { display: block; max-height: 40px; transition: transform 0.2s ease-in-out; }
header.main-header .logo img:hover { transform: scale(1.05); }
header.main-header .agent-topbar .navbar-nav > li > a, header.main-header .agent-topbar .navbar-nav .nav-link { color: #f1f1f1 !important; font-weight: 300; padding: 10px 12px; transition: background 0.2s, color 0.2s; }

/* preloader & pause code buttons */
.preloader { background: #1a2733; position: fixed; top:0; left:0; right:0; bottom:0; z-index: 2000; }
.preloader .dots div { width: 12px; height: 12px; margin: 4px; border-radius: 50%; display: inline-block; animation: bounce 0.8s infinite alternate; background: #0d6efd; }
@keyframes bounce { from { transform: translateY(0); opacity: 0.6; } to { transform: translateY(-8px); opacity: 1; } }
.pause-code-modal { max-width: 600px !important; border-radius: 12px; }
.pause-code-btn { min-width: 120px; min-height: 80px; font-size: 14px; border-radius: 10px; transition: all 0.2s ease-in-out; }
.pause-code-btn:hover { background-color: #3c8dbc; color: #fff; transform: translateY(-2px); }

/* panel container padding & grid reiteration (keeps original desktop behavior) */
.agent-panel-container { padding: 20px; }
.agent-panel-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start; }

/* Main "3D" Header for Customer Info */
.panel-header {
  background-color: #2f6b94!important; color: #ffffff; padding: 18px 24px; border-radius: 12px;
  box-shadow: 0 8px 25px rgba(0,86,179,0.2); border-bottom:1px solid rgba(0,0,0,0.15);
  display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem;
}
.panel-header h3 { margin:0; font-weight:700 !important; font-size:20px; }

/* "3D" recessed body */
.panel-body-modern { background-color:#FFFFFF; padding:24px; border-radius:12px; border:1px solid #E4E9F0;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.04); }

/* form groups override (unchanged) */
.mda-form-group { position: relative; margin-bottom: 1.5rem; }
.mda-form-group > label { position: absolute; top: -10px; left: 10px; font-size:12px; font-weight:600 !important; color:#000; background:#fff; padding:0 5px; transition: all .2s ease;}
.mda-form-group > .mda-form-control, .mda-form-group > .form-control {
  width:100%; padding:12px 14px; font-size:14px; font-weight:400 !important; color:#1E293B;
  background:#fff; border:1px solid #DDE2E7; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.02);
}
.mda-form-group > .mda-form-control:focus, .mda-form-group > .form-control:focus {
  outline:none; border-color:#007BFF; box-shadow: 0 0 0 3px rgba(0,123,255,0.15);
}
.mda-form-control[disabled] { background-color:#E9ECEF !important; cursor:not-allowed; }

/* RIGHT column cards */
.right-column-card { background-color:#fff; border-radius:12px; border:1px solid #E4E9F0; box-shadow:0 6px 18px rgba(32,47,60,0.06); margin-bottom:24px; }
.right-card-header { padding:14px 18px; border-bottom:1px solid #E4E9F0; background-color:#2f6b94!important; display:flex; justify-content:space-between; align-items:center; color:#fff!important; }
.right-card-header h4 { margin:0; font-size:16px; font-weight:700 !important; color:#fff; }
.right-card-body { padding:18px; border-radius:8px; border:1px dashed rgba(190,200,210,0.4); background:#fff; box-shadow:0 6px 20px rgba(18,35,60,0.03); }

/* script contents */
#ScriptContents { min-height:200px; max-height:400px; overflow-y:auto; padding:15px; border-radius:8px; background:#F9FAFB; color:#334155; border:1px dashed #c0c0c0; }

/* sidebar link */
.sidebar-link { display:flex; align-items:center; padding:8px 14px; text-decoration:none; color:#fff; font-size:15px; transition: background 0.2s ease, transform 0.1s ease; }
.sidebar-link:hover { background: rgba(255,255,255,0.1); transform: translateX(3px); }
.sidebar-link i { width:20px; text-align:center; }

/* agent-call modal */
.agent-call-modal { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.6); display:flex; justify-content:center; align-items:center; z-index: 30000; }
.agent-call-modal.hidden { display:none; }
.agent-call-modal-content { background:#1f2937; color:white; padding:2rem; border-radius:1rem; text-align:center; min-width:320px; animation: agent-fadeIn 0.3s ease-out; z-index:30010; }
.agent-ringing-pulse { animation: agent-pulse 1.5s infinite; }
@keyframes agent-pulse { 0%{transform:scale(1);} 70%{transform:scale(1.05);} 100%{transform:scale(1);} }
@keyframes agent-fadeIn { from { opacity:0; transform: translateY(-20px);} to { opacity:1; transform: translateY(0);} }

/* ===================================================================
   DIALPAD / KEYPAD RULES (prevent takeover and preserve 3D)
   =================================================================== */

/* Common dialpad selectors used by many implementations:
   adjust these if your dialpad uses different classes/IDs */
.dialpad, .phone-pad, .keypad, .phone-keys, .dialer-panel {
  display: block;
  width: 100%;
  border-radius: 10px;
  padding: 12px;
  border: 1px solid rgba(200,210,215,0.75);
  box-shadow: 0 8px 22px rgba(8,30,70,0.04);
  background: linear-gradient(180deg,#ffffff,#fbfdff);
  min-height: 200px;
  max-height: 420px;           /* <--- prevents infinite vertical growth */
  overflow-y: auto;            /* <--- makes dialpad scroll internally */
  box-sizing: border-box;
  position: relative;          /* avoid fixed/absolute takeover */
  z-index: 10;                 /* low so modals sit above it */
}

/* dialpad keys layout helpers (no forced positioning) */
.dialpad .key, .keypad .key, .phone-pad .key {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 54px;
  height: 54px;
  margin: 6px;
  border-radius: 8px;
  border: 1px solid rgba(200,210,215,0.85);
  background: linear-gradient(180deg,#fff,#f6f8fa);
  box-shadow: 0 4px 12px rgba(8,30,70,0.04);
}

/* If your dialer mistakenly uses fixed/absolute positioning in other CSS,
   these rules force it back into normal flow for phones/tablets & desktop */
.dialpad[style], .phone-pad[style], .keypad[style] { position: relative !important; top: auto !important; left: auto !important; right: auto !important; }

/* When a modal/popup opens (bootstrap adds body.modal-open), make the dialpad
   non-interactive to avoid it capturing clicks and to visually prioritize modal */
body.modal-open .dialpad,
body.modal-open .phone-pad,
body.modal-open .keypad {
  opacity: 0.45;
  pointer-events: none;
  filter: blur(0.2px);
}

/* Ensure any dropdowns / tooltips / modal content appear above dialpad */
.modal, .modal-backdrop, .dropdown-menu, .popover, .tooltip, .agent-call-modal {
  z-index: 20050 !important;
}
.modal-dialog { z-index: 20060 !important; }

/* ===================================================================
   MOBILE / RESPONSIVE (preserve side-by-side behavior & 3D)
   =================================================================== */

/* Default small-medium screens: keep two columns so data & dialer are side-by-side */
@media (max-width: 992px) {
  .agent-panel-grid {
    grid-template-columns: 1fr 1fr; /* keep side-by-side on tablets & phones */
    gap: 12px;
    align-items: start;
  }

  .left-card, .right-card, .right-column-card {
    padding: 12px;
    border-radius: 10px;
    border: 1px solid rgba(200,210,215,0.75);
    box-shadow: 0 8px 22px rgba(8,30,70,0.04);
    background: linear-gradient(180deg,#ffffff,#fbfdff);
  }

  .right-card .section-title { transform: translateY(-4px); box-shadow: 0 10px 22px rgba(8,30,70,0.14); }

  .cust-field {
    border: 1px solid rgba(200,210,215,0.75);
    box-shadow: inset 0 -1px 0 rgba(255,255,255,0.6), 0 6px 16px rgba(8,30,70,0.03);
    background: linear-gradient(180deg,#ffffff,#fbfdff);
  }

  .dialpad, .phone-pad, .keypad { min-height: 220px; max-height: 520px; overflow-y: auto; }
}

/* Very narrow phones: keep two panels visible via horizontal scrolling
   (so dialer will not overlay) and set min-width so layout usable */
@media (max-width: 420px) {
  .agent-panel-container { overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 8px; }
  .agent-panel-grid {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(280px, 1fr); /* each column at least 280px */
    gap: 12px;
    grid-template-columns: none;
  }
  .agent-panel-grid > * { min-width: 280px; box-sizing: border-box; }

  .cust-field { grid-template-columns: 1fr; padding: 10px; }
  .cust-field label { margin-bottom: 6px; }

  .dialpad, .phone-pad, .keypad { min-height: 200px; max-height: 400px; }
  .agent-header .agent-stats { align-items: flex-start; width:100%; }
  .modal-dialog { margin: 6px; max-width: calc(100% - 12px); }
}

/* If some older rule stacks columns at <=992, this enforces our side-by-side
   intent and is placed later in cascade to win */
@media (max-width: 992px) {
  .agent-panel-container .agent-panel-grid { grid-template-columns: 1fr 1fr !important; }
}

/* ===================================================================
   CALLBACK LIST & global typography / color overrides (from added snippet)
   =================================================================== */

/* Make table text dark and readable */
#callback-list th, #callback-list td {
  color: #222 !important;
  font-weight: 600;
  background: #fff !important;
}

/* Zebra striping for better readability */
#callback-list tbody tr:nth-child(odd) td { background: #f6f8fa !important; }

/* Highlight on hover */
#callback-list tbody tr:hover td { background: #e3f0ff !important; color: #0b6fd6 !important; }

/* Button custom */
#btn-dialer-tab, #btn-whatsapp-tab {
  box-shadow: 0 0 black; color: black; margin: 5px 50px 0 0; background: lightgrey;
}

/* crumbs & tab content spacing */
section#contact_info_crumbs { margin-bottom: 0px; padding-left: 40px; padding-right: 40px; }
div.tab-content, div.tab-pane section.content { padding-top: 0px !important; }

/* Global typography (user-provided) */
body,
.navbar-agent,
.custom-tabpanel,
.panel,
.modal-content,
.dropdown-menu,
.table,
.form-control,
.form-group,
.nav-tabs .nav-link,
.card-header,
.panel-heading,
.modal-header,
.card,
.card-body,
.card-heading,
.panel-footer,
.panel-body,
input,
textarea,
label,
th,
td,
h1, h2, h3, h4, h5, h6,
button,
a,
span,
div,
ul,
li {
  font-family: 'Poppins', sans-serif !important;
  font-weight: bold !important;
}

/* page colors */
body { color: #333 !important; background: #000000 !important; }

/* avatar/icon color rules */
.avatar, .fa-user, .fa-phone, .icon-user, .icon-phone { color: #000 !important; background: none !important; filter: none !important; }
.avatar img, #cust_avatar img, .vue-avatar img { filter: grayscale(100%) brightness(0) !important; background: #fff !important; }

/* keep logo original color */
header.main-header .logo img { filter: none !important; background: none !important; }

/* ===================================================================
   Z-INDEX / POPUP SAFEGUARDS
   =================================================================== */

/* ensure modals & popups are above dialpad */
.modal, .modal-backdrop, .dropdown-menu, .popover, .tooltip, .agent-call-modal, .toast {
  z-index: 20050 !important;
}
.modal-dialog { z-index: 20060 !important; }
.agent-call-modal-content { z-index: 30010 !important; }


/* ===================================================================
   LAST-RESORT SAFETY OVERRIDES (use sparingly)
   If your dialer still uses fixed/absolute positioning from other CSS,
   the following rule will ensure it stays in flow and not full-screen.
   =================================================================== */
.dialpad, .phone-pad, .keypad {
  position: relative !important;
  top: auto !important;
  left: auto !important;
  right: auto !important;
  height: auto !important;
  max-height: 520px !important;
  overflow-y: auto !important;
}

/* ===== NAMESPACE: agw- ===== */
/* ===== AGW STATUS: enhanced visuals & animations ===== */

/* ===== REALTIME STATUS BOX (styled like pause box) ===== */ 
.agw-widget { width: 100%; max-width: 420px; font-family: 'Poppins', sans-serif; margin: 15px auto; background: #fff; border-radius: 8px; box-shadow: 0 3px 12px rgba(0,0,0,0.15); overflow: hidden; transition: all 0.3s ease; } 
/* Body layout */ 
.agw-body { display: flex; align-items: center;   min-height: 140px;  padding: 10px 12px; background: #fff; 
/* always white */ 
border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

/* left: icon + timer (unchanged but slightly bigger) */
.agw-left { display:flex; align-items:center; justify-content:center; padding-right: 12px; flex: 0 0 92px; }
.agw-icon-box {
  width: 78px;
  height: 84px;  /* slightly taller to match new body height */
  border-radius: 8px;
  background: #fff;
  display:flex; align-items:center; justify-content:center;
  box-shadow: 0 3px 10px rgba(0,0,0,0.06);
  position: relative;
}
.agw-timer-ring {
  width: 64px; height: 64px;
  border-radius: 50%;
  display:flex; align-items:center; justify-content:center;
  border: 4px solid #000;   /* always black ring base when idle */
  box-sizing: border-box;
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

/* clock icon & spin */
@keyframes agw-spin { 0%{transform:rotate(0)} 100%{transform:rotate(360deg)} }
.agw-clock { font-size:22px; color:#000; display:inline-block; animation: agw-spin 2s linear infinite; }

/* seconds bubble */
.agw-seconds {
  position: absolute;
  bottom: -12px;
  font-size: 12px;
  font-weight: 700;
  color: #000;         /* black seconds */
  background: #fff;
  padding: 3px 7px;
  border-radius: 999px;
  border: 1px solid rgba(0,0,0,0.06);
  box-shadow: 0 2px 6px rgba(0,0,0,0.06);
  z-index: 3;
}

/* divider */
.agw-divider { width:1px; height: 72px; background: #f1f1f1; margin:0 12px; }

/* right text */
.agw-right { display:flex; flex-direction:column; justify-content:center; flex:1; }
.agw-status-text { font-weight:600; font-size:13px; color:#000; margin:3px 0; }

/* keep everything text/icon black even when backgrounds applied */
#agw-widget, #agw-widget .agw-body, #agw-widget .agw-status-text, #agw-widget .agw-seconds, #agw-widget .agw-clock {
  color: #000 !important;
}

/* ----- Ring blink animations for timer ring (only ring) ----- */
@keyframes agw-pulse-orange {
  0%{ box-shadow:0 0 0 0 rgba(230,126,34,0.22) }
  50%{ box-shadow:0 0 28px 12px rgba(230,126,34,0.12); transform: scale(1.04); }
  100%{ box-shadow:0 0 0 0 rgba(230,126,34,0.22) }
}
@keyframes agw-pulse-blue {
  0%{ box-shadow:0 0 0 0 rgba(11,111,214,0.20) }
  50%{ box-shadow:0 0 28px 12px rgba(11,111,214,0.10); transform: scale(1.03); }
  100%{ box-shadow:0 0 0 0 rgba(11,111,214,0.20) }
}
@keyframes agw-pulse-green {
  0%{ box-shadow:0 0 0 0 rgba(46,204,113,0.20) }
  50%{ box-shadow:0 0 28px 12px rgba(46,204,113,0.10); transform: scale(1.03); }
  100%{ box-shadow:0 0 0 0 rgba(46,204,113,0.20) }
}
@keyframes agw-pulse-purple {
  0%{ box-shadow:0 0 0 0 rgba(155,89,182,0.20) }
  50%{ box-shadow:0 0 28px 12px rgba(155,89,182,0.10); transform: scale(1.03); }
  100%{ box-shadow:0 0 0 0 rgba(155,89,182,0.20) }
}
@keyframes agw-pulse-yellow {
  0%{ box-shadow:0 0 0 0 rgba(241,196,15,0.20) }
  50%{ box-shadow:0 0 28px 12px rgba(241,196,15,0.10); transform: scale(1.03); }
  100%{ box-shadow:0 0 0 0 rgba(241,196,15,0.20) }
}

/* stronger card pulse for RINGING to grab attention until answered */
@keyframes agw-card-pulse {
  0%{ box-shadow: 0 6px 18px rgba(0,0,0,0.06) }
  50%{ box-shadow: 0 12px 36px rgba(0,0,0,0.12); transform: translateY(-2px) }
  100%{ box-shadow: 0 6px 18px rgba(0,0,0,0.06); transform: translateY(0) }
}

/* ----- Status mappings (background tint + accent + ring animation) ----- */

/* IDLE (default) - keep white and neutral accent */
#agw-widget.agw-idle { --agw-accent: transparent; }
#agw-widget.agw-idle .agw-body { background: #ffffff; }

/* AVAILABLE - soft green tint */
#agw-widget.agw-available { --agw-accent: #2ecc71; }
#agw-widget.agw-available .agw-body { background: #ecfbef; }

/* RINGING - soft orange tint + stronger card pulse + ring blink */
#agw-widget.agw-ringing { --agw-accent: #e67e22; }
#agw-widget.agw-ringing .agw-body { background: #fff5e6; }
#agw-widget.agw-ringing.agw-blink .agw-timer-ring { animation: agw-pulse-orange 1s linear infinite; border-color: #e67e22; }
#agw-widget.agw-ringing.agw-blink { animation: agw-card-pulse 1.0s ease-in-out infinite; }

/* INCALL (accepted) - soft blue tint + blue ring */
#agw-widget.agw-incall { --agw-accent: #0b6fd6; }
#agw-widget.agw-incall .agw-body { background: #eef6ff; }
#agw-widget.agw-incall.agw-blink .agw-timer-ring { animation: agw-pulse-blue 1.6s linear infinite; border-color: #0b6fd6; }

/* PAUSED - soft purple tint */
#agw-widget.agw-paused { --agw-accent: #9b59b6; }
#agw-widget.agw-paused .agw-body { background: #faf0ff; }
#agw-widget.agw-paused.agw-blink .agw-timer-ring { animation: agw-pulse-purple 1.6s linear infinite; border-color: #9b59b6; }

/* WRAPUP - soft yellow tint */
#agw-widget.agw-wrapup { --agw-accent: #f1c40f; }
#agw-widget.agw-wrapup .agw-body { background: #fffbdf; }
#agw-widget.agw-wrapup.agw-blink .agw-timer-ring { animation: agw-pulse-yellow 1.6s linear infinite; border-color: #f1c40f; }

/* AVAILABLE (alternate class agw-ready) */
#agw-widget.agw-ready { --agw-accent: #2ecc71; }
#agw-widget.agw-ready .agw-body { background: #ecfbef; }

/* FALLBACK: if classes are applied to .agw-body instead of wrapper */
.agw-body.agw-ringing { background: #fff5e6; --agw-accent: #e67e22; }
.agw-body.agw-incall { background: #eef6ff; --agw-accent: #0b6fd6; }

/* keep text black in all states � enforce */
#agw-widget .agw-status-text, #agw-widget .agw-seconds, #agw-widget .agw-clock { color: #000 !important; }

/* keep timer ring border black when idle, changes when status class sets border-color */
#agw-widget .agw-timer-ring { border-color: #000; }

/* text fade animation during blink (subtle) */
@keyframes agw-text-fade { 0%{opacity:1}50%{opacity:.7}100%{opacity:1} }
#agw-widget.agw-blink .agw-status-text { animation: agw-text-fade 1.2s linear infinite; }

/* responsive: stack vertically on very small screens */
@media (max-width: 480px) {
  #agw-widget { max-width: 360px; }
  .agw-body { flex-direction: column; align-items:center; min-height: 160px; padding: 12px; }
  .agw-left { margin-bottom: 8px; }
  .agw-divider { display:none; }
  .agw-seconds { bottom: -14px; }
}


/* ===================================================================
   End of merged CSS
   =================================================================== */
</style>
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<div class="card-body custom-tabpanel agent-panel-container">
    <div class="agent-panel-grid">
        
        <div class="customer-info-panel">
            <div class="panel-header">
                <h3>Customer Information</h3>
                <a href="#" data-role="button" class="pull-right edit-profile-button hidden" id="edit-profile" style="margin-left: auto;"><?=$lh->translationFor('edit_information')?></a>
            </div>
            
            <div class="panel-body-modern">
                <form role="form" id="name_form" class="formMain form-inline">
                    <input type="hidden" value="<?php echo $lead_id;?>" name="lead_id">
                    <input type="hidden" value="<?php echo $list_id;?>" name="list_id">
                    <input type="hidden" value="<?php echo $entry_list_id;?>" name="entry_list_id">
                    <input type="hidden" value="<?php echo $vendor_lead_code;?>" name="vendor_lead_code">
                    <input type="hidden" value="<?php echo $gmt_offset_now;?>" name="gmt_offset_now">
                    <input type="hidden" value="<?php echo $security_phrase;?>" name="security_phrase">
                    <input type="hidden" value="<?php echo $rank;?>" name="rank">
                    <input type="hidden" value="<?php echo $call_count;?>" name="called_count">
                    <input type="hidden" value="<?php echo $uniqueid;?>" name="uniqueid">
                    <input type="hidden" value="" name="seconds">
                    <input type="hidden" value="0" name="FORM_LOADED">
                    <input type="hidden" value="<?php echo $address3;?>" name="address3">

                    <div class="row">
                        <div class="col-sm-4">
                            <div class="mda-form-group label-floating">
                                <input id="first_name" name="first_name" type="text" maxlength="30" value="<?php echo $first_name;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled required>
                                <label for="first_name"><?=$lh->translationFor('firstt_name')?></label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="mda-form-group label-floating">
                                <input id="middle_initial" name="middle_initial" type="text" maxlength="1" value="<?php echo $middle_initial;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="middle_initial"><?=$lh->translationFor('middlee_initial')?></label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="mda-form-group label-floating">
                                <input id="last_name" name="last_name" type="text" maxlength="30" value="<?php echo $last_name;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled required>
                                <label for="last_name"><?=$lh->translationFor('lastt_name')?></label>
                            </div>
                        </div>
                    </div>
                </form>
                
                <form id="contact_details_form" class="formMain">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="mda-form-group label-floating">
                                <span id="phone_numberDISP" class="hidden"></span>
                                <input id="phone_code" name="phone_code" type="hidden" value="<?php echo $phone_code;?>">
                                <input id="phone_number" name="phone_number" type="number" min="0" maxlength="18" value="<?php echo $phone_number; ?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-phone-disabled" disabled required>
                                <input id="phone_number_DISP" type="number" min="0" maxlength="18" value="" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched hidden" disabled>
                                <label for="phone_number"><?=$lh->translationFor('phone_number')?></label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="mda-form-group label-floating">
                                <input id="alt_phone" name="alt_phone" type="number" min="0" maxlength="12" value="<?php echo $alt_phone;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="alt_phone"><?=$lh->translationFor('alternative_phone_number')?></label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mda-form-group label-floating">
                                <input id="address1" name="address1" type="text" maxlength="100" value="<?php echo $address1;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="address1"><?=$lh->translationFor('address')?></label>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="mda-form-group label-floating">
                                <input id="address3" name="address3" type="text" maxlength="100" value="<?php echo $address3;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="address3"><?=$lh->translationFor('address3')?></label>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="mda-form-group label-floating">
                                <input id="address2" name="address2" type="text" maxlength="100" value="<?php echo $address2;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="address2"><?=$lh->translationFor('address2')?></label>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="mda-form-group label-floating">
                                <input id="date_of_birth" name="date_of_birth" type="text" maxlength="100" value="<?php echo $date_of_birth;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="date_of_birth"><?=$lh->translationFor('date_of_birth')?></label>
                            </div>
                        </div>

                    </div>
                    <div class="row">
                        <div class="col-sm-4">
                            <div class="mda-form-group label-floating">
                                <input id="state" name="state" type="text" maxlength="20" value="<?php echo $state;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="state"><?=$lh->translationFor('state')?></label>
                            </div>
                        </div>
                        <div class="col-sm-8">
                            <div class="mda-form-group label-floating">
                                <input id="email" name="email" type="text" value="<?php echo $email;?>" class="mda-form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched input-disabled" disabled>
                                <label for="email"><?=$lh->translationFor('email_add')?></label>
                            </div>
                        </div>
                    </div>
                </form>

                
                <form role="form" id="gender_form" class="formMain form-inline">
                    <div id="call_notes_content" class="col-sm-12" style="padding: 0;">
                        <div class="mda-form-group label-floating" style="margin-bottom: 0;">
                            <textarea rows="3" id="call_notes" name="call_notes" class="form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched textarea note-editor note-editor-margin" style="resize:vertical;"></textarea>
                            <label for="call_notes"><?=$lh->translationFor('call_notes')?></label>
                        </div>
                    </div>
                </form>

<div class="hide_div">
  <button type="button" name="submit" id="submit_edit_form" class="btn btn-primary btn-block btn-flat"><?=$lh->translationFor('submit')?></button>
</div>
                </div>
        </div>



    <!-- Incoming Call Modal (namespaced) -->
<!-- Incoming Call Modal (namespaced) -->
<div id="incomingCallModal" class="agent-call-modal hidden">
    <div class="agent-call-modal-content agent-ringing-pulse">
        <div class="text-6xl mb-4">??</div>
        <h2 class="text-3xl font-bold mb-2">INCOMING CALL</h2>
        <p class="text-lg text-gray-400 mb-6">Action Required</p>
        <div class="flex space-x-4 justify-center">
            <button id="answerButton" class="agent-call-button px-8 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-full shadow-lg transition duration-200 ease-in-out transform hover:scale-105" disabled>
                Answer
            </button>
            <!-- Reject button removed -->
        </div>
    </div>
</div>
    <div class="right-column">
        <div class="right-column-card">
            <div class="right-card-header">
                <h4><?=$lh->translationFor('comments')?></h4>
            </div>
            <div class="right-card-body">
                <form role="form" id="comment_form" class="formMain form-inline">
                    <div class="form-group" style="width:100%;">
                        <textarea rows="8" id="comments" name="comments" maxlength="255" class="form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched textarea input-disabled note-editor note-editor-margin" style="resize:vertical; width: 100%; min-height: 150px;" disabled><?=$comments?></textarea>
                    </div>
                </form>
            </div>
        </div>
    </div>
            <div class="right-column-card">
                <div class="right-card-header">
                    <h4><?=$lh->translationFor('script')?></h4>
                    <a href="#" data-role="button" class="pull-right edit-profile-button hidden" id="reload-script" style="padding: 5px;"><?=$lh->translationFor('reload_script')?></a>
                </div>
                <div class="right-card-body">
                    <div id="ScriptContents">
                        <?php //echo $output_script;?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
						</div>
					<?php if(ECCS_BLIND_MODE === 'y'){ ?>
						<!--span id="shortcut-key-reminder" style="position:absolute; bottom:12px; left:-315px; font-family:calibri, arial, verdana, roboto; font-size:14pt; font-weight:600;" class="hidden-xs">Login to Phone Dialer [Shift + Home]</span-->
						<!--span id="shortcut-key-reminder" style="position: absolute; bottom: 12px; right: 5px; font-family: calibri, arial, verdana, roboto; font-size: 14pt; font-weight: 600;" class="hidden-xs">Shortcut keys to exit [Shift + End]</span-->
					</div>
					<?php } ?>
					
					<div id="loaded-contents" class="container-custom ng-scope" style="display: none;">
						<div id="contents-messages" class="row" style="display: none;">
							<!-- left side folder list column -->
							<div class="col-md-3">
								<a href="composemail.php" class="btn btn-primary btn-block margin-bottom"><?php $lh->translateText("new_message"); ?></a>
								<div class="box box-solid">
									<div class="box-header with-border">
										<h3 class="box-title"><?php print $lh->translationFor("folders"); ?></h3>
									</div>
									<div id="folders-list" class="box-body no-padding">
										<?php print $ui->getMessageFoldersAsList($folder); ?>
									</div><!-- /.box-body -->
								</div><!-- /. box -->
							</div><!-- /.col -->
							
							<!-- main content right side column -->
							<div id="mail-messages" class="col-md-9">
								<div class="box box-default">
									<div class="box-header with-border">
										<h3 class="box-title"><?php $lh->translateText("messages"); ?></h3>
									</div><!-- /.box-header -->
									<div class="box-body no-padding">
										<div class="mailbox-controls">
											<?php print $ui->getMailboxButtons($folder); ?>
										</div>
										<div class="table-responsive mailbox-messages">
											<?php print $ui->getMessagesFromFolderAsTable($user->getUserId(), $folder); ?>
										</div><!-- /.mail-box-messages -->
										
										<div class="mail-preloader" style="margin: 30px 0 10px; text-align: center; display: none;">
											<span class="dots">
												<div class="circ1"></div><div class="circ2"></div><div class="circ3"></div><div class="circ4"></div>
											</span>
										</div>
									</div><!-- /.box-body -->
									<div class="box-footer no-padding">
										<div class="mailbox-controls">
											<?php print $ui->getMailboxButtons($folder); ?>
										</div>
									</div>
								</div><!-- /. box -->
							</div><!-- /.col -->
							
							<div id="mail-composemail" class="col-md-9" style="display: none;">
								<div class="box box-default">
									<form method="POST" id="send-message-form" enctype="multipart/form-data">
										<div class="box-header with-border">
											<h3 class="box-title"><?php $lh->translateText("compose_new_message"); ?></h3>
										</div><!-- /.box-header -->
										<div class="box-body">
											<input type="hidden" id="fromuserid" name="fromuserid" value="<?php print $user->getUserId(); ?>">
											<div class="form-group">
												<?php print $ui->generateSendToUserSelect($user->getUserId(), false, null, $reply_user); ?>
												<label for="touserid">Recipients</label>
											</div>
											<div class="form-group hidden">
												<input id="external_recipients" name="external_recipients" class="form-control" placeholder="<?php $lh->translateText("external_message_recipients"); ?>"/>
												<label for="external_recipients">External Recipients</label>
											</div>
											<div class="form-group">
												<input id="subject" name="subject" class="form-control required" value="<?php print $reply_subject; ?>"/>
												<label for="subject">Subject</label>
											</div>
											<div class="form-group">
												<textarea id="compose-textarea" name="message" class="form-control ng-pristine ng-empty ng-invalid ng-invalid-required ng-touched textarea required" style="height: 200px" placeholder="<?php $lh->translateText("write_your_message_here"); ?>"></textarea>
												<!--<label for="compose-textarea">Message</label>-->
											</div>
											<div class="form-group" style="padding: 0px;">
												<div class="btn btn-default btn-file">
													<i class="fa fa-paperclip"></i> <?php $lh->translateText("attachment"); ?>
													<input type="file" class="attachment" name="attachment[]"/>
												</div>
												<p class="help-block"><?php print $lh->translationFor("max")." ".CRM_MAX_ATTACHMENT_FILESIZE; ?>MB</p>
											</div>
										</div><!-- /.box-body -->
										<div class="box-footer" id="attachment-list">
											<label><?php $lh->translateText("attachments"); ?>: </label>
										</div>
										<div class="box-footer" id="compose-mail-results">
										</div>
										<div class="box-footer">
											<div class="pull-right">
												<button class="btn btn-primary" id="compose-mail-submit"><i class="fa fa-envelope-o"></i> <?php $lh->translateText("send"); ?></button>
											</div>
											<button class="btn btn-default" id="compose-mail-discard"><i class="fa fa-times"></i> <?php $lh->translateText("discard"); ?></button>
											<!-- Module hook footer -->
											<?php print $ui->getComposeMessageFooter(); ?>
										</div><!-- /.box-footer -->
									</form> <!-- /.form -->
								</div><!-- /. box -->
							</div><!-- /.col -->
							
							<div id="mail-readmail" class="col-md-9" style="display: none;">
								<div class="box box-default" id="message-full-box">
									<div class="box-header with-border non-printable">
										<h3 class="box-title"><?php print $lh->translationFor("read_message"); ?></h3>
									</div><!-- /.box-header -->
									<div class="box-body no-padding">
										<div class="mailbox-read-info">
											<h3 id="read-message-subject"></h3>
											<h5><?php print $lh->translationFor("from"); ?> <span id="read-message-from"></span>
											<span id="read-message-from-id" class="hidden"></span>
											<span id="read-message-from-name" class="hidden"></span>
											<span id="read-message-date" class="mailbox-read-time pull-right"></span></h5>
										</div><!-- /.mailbox-read-info -->
										<div class="mailbox-controls with-border text-center non-printable">
											<div class="btn-group">
												<button class="btn btn-default btn-sm mail-delete" style="font-size: 12px;" data-toggle="tooltip" title="Delete"><i class="fa fa-trash-o"></i></button>
												<button class="btn btn-default btn-sm mail-reply hidden" data-toggle="tooltip" title="Reply"><i class="fa fa-reply"></i></button>
												<button class="btn btn-default btn-sm mail-forward hidden" data-toggle="tooltip" title="Forward"><i class="fa fa-share"></i></button>
											</div><!-- /.btn-group -->
											<button class="btn btn-default btn-sm mail-print" data-toggle="tooltip" title="Print"><i class="fa fa-print"></i></button>
										</div><!-- /.mailbox-controls -->
										<div class="mailbox-read-message" id="mailbox-message-text">
											&nbsp;
										</div><!-- /.mailbox-read-message -->
										<div class="mail-preloader non-printable" style="margin: 30px 0 10px; text-align: center; display: none;">
											<span class="dots">
												<div class="circ1"></div><div class="circ2"></div><div class="circ3"></div><div class="circ4"></div>
											</span>
										</div>
									</div><!-- /.box-body -->
									<!-- Attachments (if any) -->
									<div id="read-message-attachment"></div>
									<div class="box-footer">
										<div class="pull-right">
											<button class="btn btn-default mail-reply hidden"><i class="fa fa-reply"></i> <?=$lh->translationFor('reply')?></button>
											<button class="btn btn-default mail-forward hidden"><i class="fa fa-share"></i> <?=$lh->translationFor('forward')?></button>
										</div>
										<button class="btn btn-default mail-delete"><i class="fa fa-trash-o"></i> <?=$lh->translationFor('delete')?></button>
										<button class="btn btn-default mail-print"><i class="fa fa-print"></i> <?=$lh->translationFor('print')?></button>
									</div><!-- /.box-footer -->
								</div><!-- /. box -->
							</div><!-- /.col -->
						</div><!-- /.row -->
						
						
						<div id="contents-callbacks" class="row" style="display: none;">
							<div class="card col-md-12" style="padding: 15px;">
								<table id="callback-list" class="display" style="border: 1px solid #f4f4f4">
									<thead>
										<tr>
											<th>
												<?=$lh->translationFor('customer_name')?>
											</th>
											<th>
												<?=$lh->translationFor('phone_number')?>
											</th>
											<th>
												<?=$lh->translationFor('last_call_time')?>
											</th>
											<th>
												<?=$lh->translationFor('callback_time')?>
											</th>
											<th>
												<?=$lh->translationFor('campaign')?>
											</th>
											<th>
												<?=$lh->translationFor('comments')?>
											</th>
											<th>
												<?=$lh->translationFor('action')?>
											</th>
										</tr>
									</thead>
									<tbody>
										
									</tbody>
								</table>
							</div>
						</div><!-- /.row -->
						
						
						
						<!-- Contacts -->
<div id="contents-contacts" class="row" style="display: none;">
  <div class="card col-md-12 modern-contacts-card">
    <div class="modern-contacts-wrap">
      <table id="contacts-list" class="display modern-contacts-table" style="width:100%;">
        <thead>
          <tr>
            <th><?=$lh->translationFor('lead_id')?></th>
            <th><?=$lh->translationFor('customer_name')?></th>
            <th><?=$lh->translationFor('phone_number')?></th>
            <th><?=$lh->translationFor('last_call_time')?></th>
            <th><?=$lh->translationFor('campaign')?></th>
            <th><?=$lh->translationFor('status')?></th>
            <th><?=$lh->translationFor('comments')?></th>
            <th><?=$lh->translationFor('action')?></th>
          </tr>
        </thead>
        <tbody>
          <!-- rows injected by server or DataTables -->
        </tbody>
      </table>
    </div>
  </div>

  <?php
  //var_dump($ui->API_GetLeads($user->getUserName()));
  ?>
</div><!-- /.row -->
<!-- End Contacts -->

<!-- Call Logs -->
<div id="contents-call-logs" class="row" style="display: none;">
  <div class="card col-md-12">
    <div class="panel panel-default" style="margin-top: 30px;">
      <div class="panel-heading">
        <strong>Call Logs</strong>
      </div>
      <div class="panel-body">
        <div class="modern-contacts-wrap row" style="margin-bottom: 15px;">
          <div class="col-sm-5">
            <label for="startDatetime">Start Date & Time:</label>
            <input type="datetime-local" id="startDatetime" class="form-control" />
          </div>
          <div class="col-sm-5">
            <label for="endDatetime">End Date & Time:</label>
            <input type="datetime-local" id="endDatetime" class="form-control" />
          </div>
          <div class="col-sm-2" style="padding-top: 24px;">
            <button id="loadCallLogsBtn" class="btn btn-primary btn-block">Load Call Logs</button>
          </div>
        </div>

        <div class="table-responsive">
          <table id="call-logs-list" class="table table-striped table-bordered">
            <thead>
              <tr>
                <th>Call Type</th>
                <th>Call Date</th>
                <th>Phone Number</th>
                <th>First Name</th>
                <th>Campaign Name</th>
                <th>List Status</th>
                <th>Modify Date</th>
              </tr>
            </thead>
            <tbody>
              <!-- Filled dynamically -->
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- End Call Logs -->
					</div>
					<!-- popup-hotkeys -->	
					<!--div id="popup-hotkeys" class="panel clearfix">
						<div class="panel-heading"><b><?=$lh->translationFor('available_hotkeys')?></b></div>
						<div class="panel-body"><?=$lh->translationFor('no_available_hotkeys')?></div>
						<div class="panel-footer clearfix">
							<div class="text-danger sidecolor" style="padding-right: 5px; background-color: inherit;">
								<small><b><?=$lh->translationFor('note')?>:</b> <?=$lh->translationFor('hotkeys_note')?></small>
							</div>
						</div>
					</div-->
			
			<!-- AGENT CHAT -->
			<?php if($agent_chat_status) include("includes/chatapp.php");?>
                </section><!-- /.content -->
	</div>
</div>	
            </aside><!-- /.right-side -->

            <?php //print $ui->creamyFooter(); ?>

            <!-- Control Sidebar -->
<aside class="control-sidebar ">
    <!-- Create the tabs -->
    <ul class="nav nav-tabs nav-justified control-sidebar-tabs">
      <li id="dialer-tab" class="active"><a href="#control-sidebar-dialer-tab" data-toggle="tab"><i class="fa fa-phone"></i></a></li>
      <?php if($agent_chat_status) echo '<li id="chat-tab"><a href="#control-sidebar-chat-tab" data-toggle="tab"><i class="fa fa-comments-o"></i></a></li>'; ?> 
      <li id="agents-tab" class="hidden"><a href="#control-sidebar-users-tab" data-toggle="tab"><i class="fa fa-users"></i></a></li>
      <li id="settings-tab"><a href="#control-sidebar-settings-tab" data-toggle="tab"><i class="fa fa-user"></i></a></li>
    </ul>
    <!-- Tab panes -->
    <div class="tab-content" style="border-width:0; overflow-y: auto;">
      <!-- Home tab content -->
      <div class="tab-pane active" id="control-sidebar-dialer-tab">
        <ul class="control-sidebar-menu" id="go_agent_dialer">
			
        </ul>
        <!-- /.control-sidebar-menu -->

        <ul class="control-sidebar-menu" id="go_agent_status" style="margin: 0 0 15px;padding: 0 0 10px;">
			
        </ul>
		
        <ul class="control-sidebar-menu" id="go_agent_manualdial" style="margin-top: -10px;padding: 0 15px;">
			
        </ul>

        <ul class="control-sidebar-menu" id="go_agent_dialpad" style="margin-top: 15px;padding: 0 15px;">
			
        </ul>

        <ul class="control-sidebar-menu" id="go_agent_other_buttons" style="margin-top: 15px;padding: 0 15px;">
			<li style="margin-bottom: -5px;">
				<p><strong><?=$lh->translateText("Call Duration")?>:</strong> <span id="SecondsDISP">0</span> <?=$lh->translationFor('second')?></p>
				<span id="session_id" class="hidden"></span>
				<span id="callchannel" class="hidden"></span>
				<input type="hidden" id="callserverip" value="" />
				<span id="custdatetime" class="hidden"></span>
			</li>
			<li style="font-size: 5px;">
				&nbsp;
			</li>
			<li style="padding: 0 5px 15px;">
				<div class="material-switch pull-right">
					<input id="LeadPreview" name="LeadPreview" value="0" type="checkbox"/>
					<label for="LeadPreview" class="label-primary"></label>
				</div>
				<div  class="sidebar-toggle-labels" style="font-weight: bold; text-transform: uppercase;"><label for="LeadPreview"><?=$lh->translationFor('lead_preview')?></label></div>
			</li>
			<li id="DialALTPhoneMenu" style="padding: 0 5px 15px; display: none;">
				<div class="material-switch pull-right">
					<input id="DialALTPhone" name="DialALTPhone" value="0" type="checkbox"/>
					<label for="DialALTPhone" class="label-primary"></label>
				</div>
				<div  class="sidebar-toggle-labels" style="font-weight: bold; text-transform: uppercase;"><label for="DialALTPhone"><?=$lh->translationFor('alt_phone_dial')?></label></div>
			</li>
			<li id="toggleHotkeys" style="padding: 0 5px 15px;">
				<div class="material-switch pull-right">
					<input id="enableHotKeys" name="enableHotKeys" type="checkbox"/>
					<label for="enableHotKeys" class="label-primary"></label>
				</div>
				<div class="sidebar-toggle-labels" style="font-weight: bold; text-transform: uppercase;"><label for="enableHotKeys"><?=$lh->translationFor('enable_hotkeys')?></label></div>
			</li>
			<li id="toggleMute" style="padding: 0 5px 15px;">
				<div class="material-switch pull-right">
					<input id="muteMicrophone" name="muteMicrophone" type="checkbox"  checked/><label for="muteMicrophone" class="label-primary"></label>
				</div>

				<div class="pull-right">
				<div  class="sidebar-toggle-labels" style="font-weight: bold; text-transform: uppercase;"><label for="muteMicrophone"><?=$lh->translationFor('microphone')?></label></div>
			</li>
			<li style="font-size: 5px;">
				<div id="GOdebug" class="material-switch pull-right">&nbsp;</div>
			</li>
			<li class="hidden">
				<button type="button" id="show-callbacks-active" class="btn btn-link btn-block btn-raised"><?=$lh->translateText('Active Callback(s)')?> <span id="callbacks-active" class='badge pull-right bg-red'>0</span></button>
				<button type="button" id="show-callbacks-today" class="btn btn-link btn-block btn-raised"><?=$lh->translateText('Callbacks For Today')?> <span id="callbacks-today" class='badge pull-right bg-red'>0</span></button>
			</li>
        </ul>
		
        <ul class="control-sidebar-menu" id="go_agent_login" style="width: 100%; margin: 15px auto 15px; text-align: center;">
		
	


        </ul>


<!-- REALTIME STATUS BOXES -->

<div id="agw-widget" class="agw-widget">
  <div class="agw-body agw-idle" role="status" aria-live="polite">
 
        <div id="status-card" class="agw-right">
            <p class="agw-status-text">Current Agent Status:</p>
            <p id="currentStatus" class="agw-status-text">Awaiting Initialization...</p>
        </div>
    <div class="agw-divider" aria-hidden="true"></div>

    <div class="agw-left">
      <div class="agw-icon-box" title="timer">
        <div class="agw-timer-ring">
          <i class="fa-solid fa-clock agw-clock"></i>
          <div class="agw-seconds" id="agwSeconds">0s</div>
        </div>
      </div>
    </div>

  </div>
</div>
    <!-- Audio for Ringing -->
    <audio id="ringtone" loop preload="auto">
        <source src="/audio/ring.wav" type="audio/wav">
    </audio>



<!-- STATUS BOXES -->
<div class="tab-pane" id="control-counter" style="width: 100%; margin: 15px auto 15px; text-align: center;">
    <div class="row">
        <a href="#" data-toggle="modal" data-target="#realtime_agents_monitoring" data-status="ACTIVE" data-id="" style="text-decoration: none">
            <div class="panel widget bg-purple status-box" style="height: 95px; display: none;">
                <div class="row">
                    <div class="col-xs-8 text-center bg-purple-dark pv-md">
                        <div class="h2 mt0"><span class="text-lg" id="refresh_totalagentscall">00:00</span></div>
                    </div>
                    <div class="col-xs-4 pv-lg" style="padding-top:10px !important;">
                        <em class="icon-clock fa-3x"></em>
                        <div class="text-sm" style="padding-top:5px;font-weight: bold;">Current Status</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>


		
        <ul class="control-sidebar-menu " id="go_agent_logout">
			
        </ul>
        <!-- /.control-sidebar-menu -->

      </div>
      <!-- /.tab-pane -->
      <!-- Agents View tab content -->
      <div class="tab-pane" id="control-sidebar-users-tab">
		<h4><?=$lh->translationFor('other_agent_status')?></h4>
		<ul class="control-sidebar-menu" id="go_agent_view_list" style="padding: 0px 15px;">
			<li><div class="text-center"><?=$lh->translationFor('loading_agents')?>...</div></li>
		</ul>
	  </div>
      <!-- /.tab-pane -->
      <!-- Settings tab content -->
      <div class="tab-pane" id="control-sidebar-settings-tab">
		<ul class="control-sidebar-menu" id="go_agent_profile">
			<li>
				<div class="center-block" style="text-align: center; background: #181f23 none repeat scroll 0 0; margin: 0 10px; padding-bottom: 1px; padding-top: 10px;">
					<a href="#" class="dropdown-toggle" data-toggle="dropdown">
						<p><?=$ui->getVueAvatar($user->getUserName(), $user->getUserAvatar(), 96, false, true, false)?></p>
						<p style="color:white;"><?=$user->getUserName()?><br><small><?=$lh->translationFor("nice_to_see_you_again")?></small></p>
					</a>
				</div>
			</li>
			<li>
				<div>&nbsp;</div>
			</li>

<script>
/* Backwards-compatible Swal wrapper
   Place immediately after including SweetAlert2 and BEFORE other scripts that call Swal().
   This prevents "Class constructor ... cannot be invoked without 'new'".
*/
(function() {
  if (!window.Swal) return; // no Swal present

  try {
    // Attempt to call without new to detect class-style constructor behavior.
    // This will throw for classes; for function-style Swal it may do something harmless.
    // We call in a try/catch and ignore the error � the goal is detection only.
    window.Swal(); 
  } catch (err) {
    // If the error indicates "Class constructor ... cannot be invoked without 'new'"
    if (err && err.message && /Class constructor .* cannot be invoked without 'new'/.test(err.message)) {
      // Preserve original class reference
      const SwalClass = window.Swal;

      // Create a function wrapper that maps old calls to Swal.fire(...)
      const wrapper = function(...args) {
        // If someone calls Swal('title', 'text', 'icon') (old style),
        // map it to Swal.fire({ title, text, icon })
        if (args.length > 0 && typeof args[0] === 'string') {
          const [title, text, icon] = args;
          return SwalClass.fire({ title, text, icon });
        }
        // Otherwise try to pass object to fire
        return SwalClass.fire(...args);
      };

      // Copy static props/methods so wrapper acts like Swal (e.g., Swal.fire, Swal.close, etc.)
      Object.keys(SwalClass).forEach(k => {
        try { wrapper[k] = SwalClass[k]; } catch(e){}
      });

      // Ensure wrapper.fire is mapped to the original
      if (!wrapper.fire && SwalClass.fire) wrapper.fire = SwalClass.fire.bind(SwalClass);

      // Replace global
      window.Swal = wrapper;

      console.info('Swal wrapper installed for compatibility (class -> function).');
    } else {
      // Not the class error - ignore; other errors may occur from calling Swal() but don't indicate class conflict
    }
  }
})();
</script>

<script>
/* --- set default date/time inputs to today's full day (LOCAL time) --- */
(function setDefaultDateRange() {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');

  const start = new Date(now);
  start.setHours(0, 0, 0, 0);

  const end = new Date(now);
  end.setHours(23, 59, 59, 999);

  const toLocalDateTimeInput = (d) => {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  };

  const s = document.getElementById('startDatetime');
  const e = document.getElementById('endDatetime');
  if (s) s.value = toLocalDateTimeInput(start);
  if (e) e.value = toLocalDateTimeInput(end);
})();

/* --- fetch and render call logs --- */
async function fetchAgentCallLogs() {
  // server-inserted username (ensure this runs inside PHP page)
  const agentNameRaw = '<?= $_SESSION['user'] ?>';

  const startInput = document.getElementById('startDatetime');
  const endInput   = document.getElementById('endDatetime');
  if (!startInput || !endInput) {
    return alert('Missing date/time inputs on the page.');
  }

  const startRaw = startInput.value; // "YYYY-MM-DDTHH:MM"
  const endRaw   = endInput.value;

  if (!startRaw || !endRaw) return alert('Please provide both start and end date/time.');

  // convert to "YYYY-MM-DD HH:MM:SS"
  const startDatetime = startRaw.replace('T', ' ') + ':00';
  const endDatetime   = endRaw.replace('T', ' ') + ':00';

  // Build URL-encoded form data
  const params = new URLSearchParams();
  params.append('user', agentNameRaw);
  params.append('startDatetime', startDatetime);
  params.append('endDatetime', endDatetime);
  params.append('t', Date.now());

  try {
    const resp = await fetch('getAgentCalls.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    });

    if (!resp.ok) throw new Error(`Network error: ${resp.status} ${resp.statusText}`);

    // read raw text for debugging then parse JSON (keeps behavior clear if server sends text)
    const rawText = await resp.text();
    console.log('RAW RESPONSE:', rawText);

    let parsed;
    try {
      parsed = JSON.parse(rawText);
    } catch (err) {
      console.error('Failed to parse JSON:', err);
      return alert('Invalid JSON returned from server. See console for raw response.');
    }

    // Accept either an array (root) or { data: [...] }
    let rows = [];
    if (Array.isArray(parsed)) {
      rows = parsed;
    } else if (parsed && Array.isArray(parsed.data)) {
      rows = parsed.data;
    } else {
      rows = [];
    }

    // Sort newest first (safely)
    rows.sort((a, b) => {
      const da = a && a.call_date ? new Date(a.call_date) : new Date(0);
      const db = b && b.call_date ? new Date(b.call_date) : new Date(0);
      return db - da;
    });

    const tbody = document.querySelector('#call-logs-list tbody');
    if (!tbody) {
      return alert('Table element #call-logs-list tbody not found.');
    }
    tbody.innerHTML = '';

    // Render rows using textContent to avoid accidental HTML injection
    rows.forEach(log => {
      const tr = document.createElement('tr');

      const makeTd = (val) => {
        const td = document.createElement('td');
        td.textContent = (val === null || val === undefined) ? '' : String(val);
        return td;
      };

      tr.appendChild(makeTd(log.call_type ?? ''));
      tr.appendChild(makeTd(log.call_date ?? ''));
      tr.appendChild(makeTd(log.phone_number ?? ''));
      tr.appendChild(makeTd(log.first_name ?? ''));
      tr.appendChild(makeTd(log.campaign_name ?? ''));
      tr.appendChild(makeTd(log.list_status ?? ''));
      tr.appendChild(makeTd(log.modify_date ?? ''));

      tbody.appendChild(tr);
    });

  } catch (err) {
    console.error('Error fetching agent call logs:', err);
    alert('Error loading call logs. Check console for details.');
  }
}

// Bind click event (defensive: ensure element exists)
const btn = document.getElementById('loadCallLogsBtn');
if (btn) btn.addEventListener('click', fetchAgentCallLogs);
</script>
	<?php
    // Home
    echo '<a href="./agent.php" class="sidebar-link">
        <i class="fa fa-home" style="color:#4caf50;"></i>
        <span>' . $lh->translationFor("Home") . '</span>
    </a>';

if ($user->userHasBasicPermission()) {
    echo '<li>
        <div class="text-center">
            <a href="#" data-toggle="modal" id="change-password-toggle" data-target="#change-password-dialog-modal" class="sidebar-link">
                <i class="fa fa-key" style="color:#ff9800;"></i>
                <span>' . $lh->translationFor("change_password") . '</span>
            </a>
        </div>
    </li>';


    // Messages
    $numMessages = $db->getUnreadMessagesNumber($user->getUserId());
    echo '<a href="#messages" class="sidebar-link">
        <i class="fa fa-envelope" style="color:#2196f3;"></i>
        <span>' . $lh->translationFor("messages") . 
        ($numMessages > 0 ? ' <span class="badge">'.$numMessages.'</span>' : '') .
        '</span>
    </a>';

    // Callbacks
    echo '<a href="#callbackslist" class="sidebar-link">
        <i class="fa fa-mobile" style="color:#9c27b0;"></i>
        <span>' . $lh->translationFor("callbacks") . '</span>
    </a>';

    // Tasks
    echo '<a href="agent-tasks.php" class="sidebar-link agent-tasks">
        <i class="fa fa-tasks" style="color:#ff5722;"></i>
        <span>' . $lh->translationFor("tasks") . '</span>
    </a>';

    // Contacts
    if ($user_info->data->agent_lead_search_override != 'DISABLED') {
        echo '<a href="#customerslist" class="sidebar-link agent-lead-search">
            <i class="fa fa-users" style="color:#00bcd4;"></i>
            <span>' . $lh->translationFor("contacts") . '</span>
        </a>';
    }

    // Call Logs
    echo '<a href="#agentcallslog" class="sidebar-link agent-call-logs">
        <i class="fa fa-folder" style="color:#607d8b;"></i>
        <span>' . $lh->translationFor("Call Logs") . '</span>
    </a>';
}
?>

<!-- Pause Code -->
<li id="pause_code_link" class="hidden">
    <a onclick="PauseCodeSelectBox();" class="sidebar-link">
        <i class="fa fa-pause-circle" style="color:#f44336;"></i>
        <span><?=$lh->translationFor('enter_pause_code')?></span>
    </a>
</li>
<!-- modified HTML -->
<ul class="control-sidebar-menu control-sidebar-footer">
  <li>
    <div class="footer-actions text-center">
      <a href="#profile" class="btn btn-warning">
        <i class="fa fa-user"></i> <?=$lh->translationFor("my_profile")?>
      </a>
      <a href="./logout.php" id="cream-agent-logout" class="btn btn-warning">
        <i class="fa fa-sign-out"></i> <?=$lh->translationFor("exit")?>
      </a>
    </div>
  </li>
</ul>
      </div>
      <!-- /.tab-pane -->
	<?php if($agent_chat_status){ ?>
      <!-- tab-pane -->
      <!-- chat tab -->
      <div class="tab-pane" id="control-sidebar-chat-tab">
	<ul class="contacts-list">
	<li>

           <div class="center-block" style="text-align: center; /*background: #181f23 none repeat scroll 0 0;*/ margin: 0 10px; padding-bottom: 1px; pa
dding-top: 10px;">
               <a href="#" class="dropdown-toggle" data-toggle="dropdown">
               <p><?=$ui->getVueAvatar($user->getUserName(), $user->getUserAvatar(), 96, false, true, false)?></p>
               <p style="color:white;"><?=$user->getUserName()?><br><small><?=$lh->translationFor("nice_to_see_you_again")?></small></p>
               </a>
           </div>
       </li>
       <li>
	Contact List
           <!--<div>&nbsp;</div>-->
       </li>
			  	
	<?php
	   include('includes/chat-tab.php');
	?>
	</ul>	
      </div>
      <!-- /. tab-pane -->
	<?php } ?>
    </div>
  </aside>

  <!-- /.control-sidebar -->
  <!-- Add the sidebar's background. This div must be placed
       immediately after the control sidebar -->
  <div class="control-sidebar-bg" style="position: fixed; height: auto;"></div>

        </div><!-- ./wrapper -->

		<!-- Modal Dialogs -->
		<?php include_once "./php/ModalPasswordDialogs.php" ?>

		<?php print $ui->standardizedThemeJS();?>
		<script type="text/javascript">	
			var rcToken = "";								
			$(document).ready(function() {
		               <?php if(ROCKETCHAT_ENABLE === 'y'){?> 
				//Initialize RocketChat
				$('<iframe>', {
		                   src: '<?php echo ROCKETCHAT_URL;?>?layout=embedded',
		                   id:  'rc_frame',
				   name: 'rc_frame',
		                   frameborder: 0,
                		   width: '100%',
		                   height: '100%',
                		   scrolling: 'no'
		                   }).appendTo('#rc_div');

				var rcUser = '<?php echo $_SESSION['user']?>';
				var rcHandshake = '<?php echo $_SESSION['phone_this'];?>';	
				$.ajax({
					url: "./php/LoginRocketChat.php",
					type: 'POST',
					dataType: "json",
					data: {user: rcUser, pass: rcHandshake},
					success: function(data) {
						rcToken = data.data.authToken;
						console.log(data.data);
						$("#rc-user-id").val(data.data.userId);
						$("#rc-auth-token").val(rcToken);
						rcWin = document.getElementById('rc_frame').contentWindow;
						 <?php echo ROCKETCHAT_URL;?>');
						setTimeout(function() {
							rcWin.postMessage({
								event: 'log-me-in-iframe',
								user: rcUser,
								pass: rcHandshake
							}, '<?php echo ROCKETCHAT_URL;?>');
						}, 3000);
					}
				});

				//btnLogMeIn
				$("#loginRC").click(function(e) {
					var rcUser = '<?php echo $_SESSION['user']?>';
	                                var rcHandshake = '<?php echo $_SESSION['phone_this'];?>';
					var rcWin = document.getElementById('rc_frame').contentWindow
					rcWin.postMessage({
                                             event: 'log-me-in-iframe',
                                             user: rcUser,
                                             pass: rcHandshake
                                        }, '<?php echo ROCKETCHAT_URL;?>');
				});

				
				<?php } ?>				

				var folder = <?php print $folder; ?>;
				var selectedAll = false;
				var selectedMessages = [];
				
				$("#contacts-list").DataTable();
				
				$("#compose-textarea").wysihtml5();
				
				$('.select2').select2({theme: 'bootstrap'});
				$.fn.select2.defaults.set("theme", "bootstrap");
				
			    //iCheck for checkbox and radio inputs
		        $('input[type="checkbox"].message-selection-checkbox').iCheck({
					checkboxClass: 'icheckbox_minimal-blue',
					radioClass: 'iradio_minimal-blue'
		        });
		        
			    // check individual message
				$('input[type=checkbox].message-selection-checkbox').on("ifUnchecked", ifUnchecked);
			    
			    // uncheck individual message
				$('input[type=checkbox].message-selection-checkbox').on("ifChecked", ifChecked);

			    // uncheck/check all messages
				$(".checkbox-toggle").click(function() {
					if (selectedAll) { $("input[type='checkbox'].message-selection-checkbox", ".mailbox").iCheck("uncheck"); }
					else { $("input[type='checkbox'].message-selection-checkbox", ".mailbox").iCheck("check"); }
					selectedAll = !selectedAll;
				});

				// next button for table.
				$(".mailbox-next").click(function() { datatable.fnPageChange('next'); });

				// previous button for table
				$(".mailbox-prev").click(function() { datatable.fnPageChange('previous'); });

			    // de-star a starred video / star a de-stared video.
			    $("td .fa-star, td .fa-star-o").click(function(e) {
			        e.preventDefault();
			        
			        // Detect type: e.currentTarget.id contains the message id.
					var starred = $(this).hasClass("fa-star");
					var favorite = 1;
					var selectedItem = this;
					
					if (starred) { // unmark message as favorite
						favorite = 0;   
					} // else mark message as favorite
					
					$("#messages-message-box").hide();
					$.post("./php/MarkMessagesAsFavorite.php", { "favorite": favorite, "messageids": [e.currentTarget.id], "folder": folder }, function(data) {
						if (data == "<?php print CRM_DEFAULT_SUCCESS_RESPONSE; ?>") { 
							// toggle visual change.
				            $(selectedItem).toggleClass("fa-star");
				            $(selectedItem).toggleClass("fa-star-o");
							updateMessages(<?=$user->getUserId()?>, folder);
						} else {
							<?php
								$msg = $ui->calloutErrorMessage($lh->translationFor("message")); 
								print $ui->fadingInMessageJS($msg, "messages-message-box");
							?>
						}
					});
			    });
				
				$("li a[href^='messages.php?']").click(function(e) {
					if (typeof e.target.search !== 'undefined') {
						var thisFolder = e.target.search.replace("?", "");
						thisFolder = thisFolder.split("=");
						updateMessages(<?=$user->getUserId()?>, thisFolder[1]);
					}
				});
				
				$("td a[href^='readmail.php?']").click(function(e) {
					if (typeof e.target.search !== 'undefined') {
						var thisURI = e.target.search.replace("?", "").split("&");
						thisFolder = thisURI[0].split("=");
						thisMessage = thisURI[1].split("=");
						readMessage(thisMessage[1], thisFolder[1]);
					}
				});
				
				
				<?php
				// mark messages as favorite.
				$unableFavoriteCode = $ui->calloutErrorMessage($lh->translationFor("unable_set_favorites"));
				print $ui->mailboxAction(
					"messages-mark-as-favorite", 											// classname
					"php/MarkMessagesAsFavorite.php", 										// php to request
					'updateMessages('.$user->getUserId().', folder); for (i=0; i<selectedMessages.length; i++) { $("td.mailbox-star i#"+selectedMessages[i]).removeClass("fa-star-o").addClass("fa-star"); }', // success js
					$ui->fadingInMessageJS($unableFavoriteCode, "messages-message-box"),	// failure js
					array("favorite" => 1));												// custom parameters
				?>
				
				<?php
				// mark messages as read
				$unableReadCode = $ui->calloutErrorMessage($lh->translationFor("unable_set_read"));
				print $ui->mailboxAction(
					"messages-mark-as-read", 												// classname
					"php/MarkMessagesAsRead.php", 											// php to request
					'updateMessages('.$user->getUserId().', folder); for (i=0; i<selectedMessages.length; i++) { $("td.mailbox-star i#"+selectedMessages[i]).parents("tr").removeClass("unread"); }', 												// success js
					$ui->fadingInMessageJS($unableReadCode, "messages-message-box")); 		// failure js
				?>
				
				<?php
				// mark messages as unread
				$unableUnreadCode = $ui->calloutErrorMessage($lh->translationFor("unable_set_unread"));
				print $ui->mailboxAction(
					"messages-mark-as-unread", 												// classname
					"php/MarkMessagesAsUnread.php", 										// php to request
					'updateMessages('.$user->getUserId().', folder); for (i=0; i<selectedMessages.length; i++) { $("td.mailbox-star i#"+selectedMessages[i]).parents("tr").addClass("unread"); }', // success js
					$ui->fadingInMessageJS($unableUnreadCode, "messages-message-box")); 	// failure js
				?>
				
				<?php
				// send to junk mail
				$junkText = 'data+" '.$lh->translationFor("out_of").' "+selectedMessages.length+" '.
					$lh->translationFor("messages_sent_trash").'"';
				print $ui->mailboxAction(
					"messages-send-to-junk",					// classname
					"php/JunkMessages.php",						// php to request
					"updateMessages(".$user->getUserId().", folder); swal($junkText);");		// result js
				?>
				
				<?php
				// restore mail from junk
				$unjunkText = 'data+" '.$lh->translationFor("out_of").' "+selectedMessages.length+" '.
					$lh->translationFor("messages_recovered_trash").'"';
				print $ui->mailboxAction(
					"messages-restore-message",					// classname
					"php/UnjunkMessages.php",					// php to request
					"updateMessages(".$user->getUserId().", folder); swal($unjunkText);");		// result js
				?>
				
				<?php
				// delete messages.
				$unableDeleteCode = $ui->calloutErrorMessage($lh->translationFor("unable_delete_messages"));
				print $ui->mailboxAction(
					"messages-delete-permanently", 											// classname
					"php/DeleteMessages.php", 												// php to request
					"updateMessages(".$user->getUserId().", folder);", 												// success js
					$ui->fadingInMessageJS($unableDeleteCode, "messages-message-box")); 	// failure js
				?>
				
				// === copy/paste replacement start ===

				
				// Start Mail Composer
				// external recipients
				$('#external_recipients').multiple_emails();
	
				// attachments
				$('.attachment').MultiFile({
					max: 5,
					//accept: 'jpg|jpeg|png|gif|pdf|doc|pages|numbers|xls|docx|xlsx|mp4|mpg|mpeg|avi|m4v|txt|rdf|mp3|ogg|zip|html',
					list: '#attachment-list',
					STRING: {
						remove: '<i class="fa fa-times"></i>'
					}
				});
				
				// send a message
				$("#send-message-form").validate({
					errorElement: "small",
					rules: {
						mimeType: "multipart/form-data",
						subject: "required",
						message: "required",
						touserid: {
							required: true,
							min: 1,
							number: true
						}
					},
					messages: {
						touserid: "<?php $lh->translateText("you_must_choose_user"); ?>",
					},
					submitHandler: function() {
						// file uploads only allowed on modern browsers (sorry IE < 10).
						var form = $("#send-message-form");
						var formdata = false;
						if (window.FormData){
							formdata = new FormData(form[0]);
						}
						<?php
							$okMsg = $ui->dismissableAlertWithMessage($lh->translationFor("message_successfully_sent"), true, false);
							$koMsg = $ui->dismissableAlertWithMessage($lh->translationFor("unable_send_message"), false, true);
						?>
						//submit the form
						$("#compose-mail-results").html();
						$("#compose-mail-results").hide();
						$.ajax({
							url         : 'php/SendMessage.php',
							data        : formdata ? formdata : form.serialize(),
							cache       : false,
							contentType : false,
							processData : false,
							type        : 'POST',
							success     : function(data, textStatus, jqXHR){
								if (data == '<?php print CRM_DEFAULT_SUCCESS_RESPONSE; ?>') {
									$("#compose-mail-results").html('<?php print $okMsg; ?>');
									$("#compose-mail-results").fadeIn(); //show confirmation message
									$("#send-message-form")[0].reset();
									$(".MultiFile-label").remove();
									
									setTimeout(function() {
										$("#compose-mail-results").fadeOut();
									}, 3000);
								} else { // failure
									$("#compose-mail-results").html('<?php print $koMsg; ?>');
									$("#compose-mail-results").fadeIn(); //show confirmation message
								}
							}, error: function(jqXHR, textStatus, errorThrown) {
								$("#compose-mail-results").html('<?php print $koMsg; ?>');
								$("#compose-mail-results").fadeIn(); //show confirmation message
							}
						});
	
						return false; //don't let the form refresh the page...
					}					
				});
				
				// discard message
				$('#compose-mail-discard').click(function(e) {
					e.preventDefault();
					$("#mail-composemail").hide();
					$("#mail-messages").show();
					updateMessages(<?=$user->getUserId()?>, 0);
				});
				// End Mail Composer
				
				// Start Read Messages
				// print.
				$('.mail-print').click(function() {
					var headerLogo = $("header.main-header img").prop('src');
					$('#message-full-box').printThis({
						loadCSS: [
							"<?php print GO_BASE_DIRECTORY; ?>/css/printpage.css"
						],
						importCSS: false,
						pageTitle: $("#read-message-subject").html(),
						header: '<div class="print-logo"><img src="'+headerLogo+'" height="32"></div>'
					});
				});
				
				<?php 
				// delete
				print $ui->mailboxAction(
					"mail-delete", 																		// class name
					"php/DeleteMessages.php", 															// POST Request URL
					"$('#mail-readmail').hide(); $('#mail-messages').show(); updateMessages(".$user->getUserId().", folder); swal('".$lh->translationFor("message_successfully_deleted")."');", 													// Success JS				
					$ui->showCustomErrorMessageAlertJS($lh->translationFor("unable_delete_messages")),  // Failure JS
					null,																				// custom params 
					true,																				// confirmation ?
					true);																				// check selected messages?
				?>
				
				// reply
				$('.mail-reply').click(function () {
					var text = $('#mailbox-message-text').html();
					var reply_text = responseEncodedMessageText(text, $("#read-message-from-name").html());
					var reply_subject = "Re: " + $("#read-message-subject").html();
					var reply_user = $("#read-message-from-id").html();
					
					$("#touserid").val(reply_user);
					$("#subject").val(reply_subject);
					$("#compose-textarea").val(reply_text);
					$("#compose-textarea").show();
					$(".wysihtml5-sandbox").remove();
					$(".wysihtml5-toolbar").remove();
					$("input[name='_wysihtml5_mode']").remove();
					$("#compose-textarea").wysihtml5();
					
					setTimeout(function() {
						$("#mail-composemail").show();
						$("#mail-readmail").hide();
					}, 1000);
				});
				
				// forward
				$('.mail-forward').click(function () {
					var text = $('#mailbox-message-text').html();
					var forward_text = responseEncodedMessageText(text, $("#read-message-from-name").html());
					var forward_subject = "Fwd: " + $("#read-message-subject").html();
					
					$("#touserid").val(0);
					$("#subject").val(forward_subject);
					$("#compose-textarea").val(forward_text);
					$("#compose-textarea").show();
					$(".wysihtml5-sandbox").remove();
					$(".wysihtml5-toolbar").remove();
					$("input[name='_wysihtml5_mode']").remove();
					$("#compose-textarea").wysihtml5();
					
					setTimeout(function() {
						$("#mail-composemail").show();
						$("#mail-readmail").hide();
					}, 1000);
				});
				
				$("div a[href='composemail.php']").click(function() {
					$("#touserid").val(0);
					$("#subject").val('');
					$("#compose-textarea").val('');
					$("#compose-textarea").show();
					$(".wysihtml5-sandbox").remove();
					$(".wysihtml5-toolbar").remove();
					$("input[name='_wysihtml5_mode']").remove();
					$("#compose-textarea").wysihtml5();
					
					setTimeout(function() {
						$("#mail-composemail").show();
						$("#mail-readmail").hide();
						$("#mail-messages").hide();
					}, 1000);
				});
				
				/**
				 * Deletes a customer
				 */
				$("#modifyCustomerDeleteButton").click(function (e) {
					var r = confirm("<?php $lh->translateText("are_you_sure"); ?>");
					e.preventDefault();
					if (r === true) {
						//var customerid = $(this).attr('href');
						$.post("./php/DeleteContact.php", $("#modifycustomerform").serialize() ,function(data){
							if (data == "<?php print CRM_DEFAULT_SUCCESS_RESPONSE; ?>") { 
								alert("<?php $lh->translateText("Contact Successfully Deleted"); ?>");
								window.location = "index.php";
							}
							else { alert ("<?php $lh->translateText("Unable to Delete Contact"); ?>: "+data); }
						});
					}
				});
				
				$('.form-control').on('focus blur', function (e) {
					$(this).parents('.label-floating').toggleClass('focused', (e.type === 'focus' || this.value.length > 0));
				}).trigger('blur');
				
				$('.label-floating .form-control').change(function() {
					var thisVal = $(this).val();
					$(this).parents('.label-floating').toggleClass('focused', (thisVal.length > 0));
				});
				
				setInterval(function() {
					if (!$("#contents-messages").is(':visible') && (typeof refresh_interval !== 'undefined' && refresh_interval < 5000) && (typeof window_focus !== 'undefined' && window_focus)) {
						updateMessages(<?=$user->getUserId()?>, 0);
					}
				}, 5000);
				
			});
			
			// generates the reply-to or forward message text. This text will be suitable for placing in the reply-to/forward content
			// of a message. It will be:
			// 1. stripped of all html entities
			// 2. Added --- Original message from "replyUser" --- 
			// 3. cut down to 512 characters (added ...)
			// 4. wrapped in <pre>...</pre>
			// 5. encoded to be passed as URI
			function responseEncodedMessageText(text, replyUser) {
				result = text.trim().substr(0, 512);
				result = "-------- <?php $lh->translateText("original_message_from"); ?> "+replyUser+" --------\n"+result;
				result = "<br/><br/><pre>"+result+"</pre>";
				//result = encodeURI(result);
				return result;				
			}
			// End Read Messages
			
			function updateMessages(user_id, folder_id) {
				$("#mail-messages div.mailbox-messages").hide();
				$(".mail-preloader").show();
				
				var postData = {
					module_name: 'GOagent',
					action: 'UpdateMessages',
					user_id: user_id,
					folder: folder_id
				};
				
				$.ajax({
					type: 'POST',
					url: 'modules/GOagent/GOagentJS.php',
					processData: true,
					data: postData,
					dataType: "json",
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded'
					}
				})
				.done(function (result) {
					if (result.result == 'success') {
						selectedMessages = [];
						selectedAll = false;
						folder = folder_id;
						
						$("#mail-messages div.mailbox-controls").html(result.controls);
						$("#folders-list").html(result.folders);
						var thisTopBar = result.topbar;
						$("li.messages-menu").html($(thisTopBar).html());
						$("li.messages-menu ul.menu").slimScroll({
							height: '200px'
						});

						//ECCS Customization
						<?php if(ECCS_BLIND_MODE === 'y'){ ?>
							$("li.dropdown.messages-menu a.dropdown-toggle").append('<br><span class="sr-only">Messages</span><span>#VM</span>');
							$("li.dropdown.messages-menu a.dropdown-toggle").attr("data-tooltip", "tooltip");
			                                $("li.dropdown.messages-menu a.dropdown-toggle").attr("title", "<?=$lh->translationFor('messages')?>");
						<?php } //end if ECCS_BLIND_MODE?>
						//./

						$("div.mailbox-messages").html(result.messages);						

						$(".mail-preloader").hide();
						$("#mail-messages div.mailbox-messages").slideDown();
						
						//iCheck for checkbox and radio inputs
						$('input[type="checkbox"].message-selection-checkbox').iCheck({
							checkboxClass: 'icheckbox_minimal-blue',
							radioClass: 'iradio_minimal-blue'
						});
						
						// check individual message
						$('input[type=checkbox].message-selection-checkbox').off("ifUnchecked", ifUnchecked).on("ifUnchecked", ifUnchecked);
						
						// uncheck individual message
						$('input[type=checkbox].message-selection-checkbox').off("ifChecked", ifChecked).on("ifChecked", ifChecked);
						
						// next button for table.
						$(".mailbox-next").off('click');
						$(".mailbox-next").click(function() { datatable.fnPageChange('next'); });
						
						// previous button for table
						$(".mailbox-prev").off('click');
						$(".mailbox-prev").click(function() { datatable.fnPageChange('previous'); });
						
						// uncheck/check all messages
						$("button.messages-mark-as-read").off('click');
						$(".checkbox-toggle").click(function() {
							if (selectedAll) { $("input[type='checkbox'].message-selection-checkbox", ".mailbox").iCheck("uncheck"); }
							else { $("input[type='checkbox'].message-selection-checkbox", ".mailbox").iCheck("check"); }
							selectedAll = !selectedAll;
						});
						
						// de-star a starred video / star a de-stared video.
						$("td .fa-star, td .fa-star-o").off('click');
						$("td .fa-star, td .fa-star-o").click(function(e) {
							e.preventDefault();
							
							// Detect type: e.currentTarget.id contains the message id.
							var starred = $(this).hasClass("fa-star");
							var favorite = 1;
							var selectedItem = this;
							
							if (starred) { // unmark message as favorite
								favorite = 0;   
							} // else mark message as favorite
							
							$("#messages-message-box").hide();
							$.post("./php/MarkMessagesAsFavorite.php", { "favorite": favorite, "messageids": [e.currentTarget.id], "folder": folder_id }, function(data) {
								if (data == "<?php print CRM_DEFAULT_SUCCESS_RESPONSE; ?>") { 
									// toggle visual change.
									$(selectedItem).toggleClass("fa-star");
									$(selectedItem).toggleClass("fa-star-o");
									updateMessages(user_id, folder);
								} else {
									<?php
										$msg = $ui->calloutErrorMessage($lh->translationFor("message")); 
										print $ui->fadingInMessageJS($msg, "messages-message-box");
									?>
								}
							});
						});
						
						$("li a[href^='messages.php?']").off('click');
						$("li a[href^='messages.php?']").click(function(e) {
							if (typeof e.target.search !== 'undefined') {
								var thisFolder = e.target.search.replace("?", "");
								thisFolder = thisFolder.split("=");
								updateMessages(<?=$user->getUserId()?>, thisFolder[1]);
							}
						});
						
						$("td a[href^='readmail.php?']").off('click');
						$("td a[href^='readmail.php?']").click(function(e) {
							if (typeof e.target.search !== 'undefined') {
								var thisURI = e.target.search.replace("?", "").split("&");
								thisFolder = thisURI[0].split("=");
								thisMessage = thisURI[1].split("=");
								readMessage(thisMessage[1], thisFolder[1]);
							}
						});
						
						$("button.messages-mark-as-favorite").off('click');
						<?php
						// mark messages as favorite.
						$unableFavoriteCode = $ui->calloutErrorMessage($lh->translationFor("unable_set_favorites"));
						print $ui->mailboxAction(
							"messages-mark-as-favorite", 											// classname
							"php/MarkMessagesAsFavorite.php", 										// php to request
							'updateMessages('.$user->getUserId().', folder); for (i=0; i<selectedMessages.length; i++) { $("td.mailbox-star i#"+selectedMessages[i]).removeClass("fa-star-o").addClass("fa-star"); }', // success js
							$ui->fadingInMessageJS($unableFavoriteCode, "messages-message-box"),	// failure js
							array("favorite" => 1));												// custom parameters
						?>
						
						$("button.messages-mark-as-read").off('click');
						<?php
						// mark messages as read
						$unableReadCode = $ui->calloutErrorMessage($lh->translationFor("unable_set_read"));
						print $ui->mailboxAction(
							"messages-mark-as-read", 												// classname
							"php/MarkMessagesAsRead.php", 											// php to request
							'updateMessages('.$user->getUserId().', folder); for (i=0; i<selectedMessages.length; i++) { $("td.mailbox-star i#"+selectedMessages[i]).parents("tr").removeClass("unread"); }', 												// success js
							$ui->fadingInMessageJS($unableReadCode, "messages-message-box")); 		// failure js
						?>
						
						$("button.messages-mark-as-unread").off('click');
						<?php
						// mark messages as unread
						$unableUnreadCode = $ui->calloutErrorMessage($lh->translationFor("unable_set_unread"));
						print $ui->mailboxAction(
							"messages-mark-as-unread", 												// classname
							"php/MarkMessagesAsUnread.php", 										// php to request
							'updateMessages('.$user->getUserId().', folder); for (i=0; i<selectedMessages.length; i++) { $("td.mailbox-star i#"+selectedMessages[i]).parents("tr").addClass("unread"); }', // success js
							$ui->fadingInMessageJS($unableUnreadCode, "messages-message-box")); 	// failure js
						?>
						
						$("button.messages-send-to-junk").off('click');
						<?php
						// send to junk mail
						$junkText = 'data+" '.$lh->translationFor("out_of").' "+selectedMessages.length+" '.
							$lh->translationFor("messages_sent_trash").'"';
						print $ui->mailboxAction(
							"messages-send-to-junk",					// classname
							"php/JunkMessages.php",						// php to request
							"updateMessages(".$user->getUserId().", folder); swal($junkText);");		// result js
						?>
						
						$("button.messages-restore-message").off('click');
						<?php
						// restore mail from junk
						$unjunkText = 'data+" '.$lh->translationFor("out_of").' "+selectedMessages.length+" '.
							$lh->translationFor("messages_recovered_trash").'"';
						print $ui->mailboxAction(
							"messages-restore-message",					// classname
							"php/UnjunkMessages.php",					// php to request
							"updateMessages(".$user->getUserId().", folder); swal($unjunkText);");		// result js
						?>
						
						$("button.messages-delete-permanently").off('click');
						<?php
						// delete messages.
						$unableDeleteCode = $ui->calloutErrorMessage($lh->translationFor("unable_delete_messages"));
						print $ui->mailboxAction(
							"messages-delete-permanently", 											// classname
							"php/DeleteMessages.php", 												// php to request
							"updateMessages(".$user->getUserId().", folder);", 												// success js
							$ui->fadingInMessageJS($unableDeleteCode, "messages-message-box")); 	// failure js
						?>
						
						// Hijack links on left menu
						$("a:regex(href, messages|composemail|readmail)").off('click', hijackThisLink).on('click', hijackThisLink);
					}
				});
			}
			
			function readMessage(message_id, folder_id) {
				$("#mailbox-message-text, #read-message-attachment, #mail-readmail div.mailbox-read-info, #mail-readmail div.mailbox-controls").hide();
				$(".mail-preloader").show();
				
				var postData = {
					module_name: 'GOagent',
					action: 'ReadMessage',
					user_id: <?=$user->getUserId()?>,
					messageid: message_id,
					folder: folder_id
				};
				
				$.ajax({
					type: 'POST',
					url: 'modules/GOagent/GOagentJS.php',
					processData: true,
					data: postData,
					dataType: "json",
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded'
					}
				})
				.done(function (result) {
					if (result.result == 'success') {
						selectedMessages = [message_id];
						$("#read-message-subject").html(result.message.subject);
						$("#read-message-from").html(result.from.user);
						$("#read-message-from-id").html(result.from.id);
						$("#read-message-from-name").html(result.from.name);
						$("#read-message-date").html(result.message.date);
						$("#mailbox-message-text").html(result.message.message);
						$("#read-message-attachment").html(result.attachments);
						
						$(".mail-preloader").hide();
						$("#mailbox-message-text, #read-message-attachment, #mail-readmail div.mailbox-read-info, #mail-readmail div.mailbox-controls").slideDown();
						
						if (result.from.user != '' || result.from.user != 'Unknown') {
							$("button.mail-reply, button.mail-forward").removeClass('hidden');
						}
					}
				});
			}
			
			function ifUnchecked(e) {
				var index = selectedMessages.indexOf(e.currentTarget.value);
				if (index >= 0) selectedMessages.splice(index, 1);
			}
			function ifChecked(e) {
				if (e.currentTarget.value != 'on') selectedMessages.push(e.currentTarget.value);
			}
			
			//Clickable Hotkeys
                        function triggerHotkey(numkey){
	                var e = $.Event('keypress');
		                e.which = numkey;
                                $('#freeTestField').trigger(e);
                        }


/* ---------- Edit profile button ---------- */
$("#edit-profile").click(function(){
    $('.input-disabled').prop('disabled', false);

    if (typeof disable_alter_custphone !== 'undefined') {
        if (disable_alter_custphone == 'N') {
            $('.input-phone-disabled').prop('disabled', false);
        }
    } else {
        $('.input-phone-disabled').prop('disabled', false);
    }

    $("input:required, select:required").addClass("required_div");
    $('#edit-profile').addClass('hidden');

    var txtBox = document.getElementById("first_name");
    if (txtBox) txtBox.focus();

    editProfileEnabled = true;
});

/* ---------- Submit edited profile ---------- */
$("#submit_edit_form").click(function(){
    var validate = 0;

    // ensure elements exist before calling checkValidity()
    var nameForm = $('#name_form')[0];
    var genderForm = $('#gender_form')[0];
    var contactForm = $('#contact_details_form')[0];

    if (nameForm && nameForm.checkValidity()) {
        if (genderForm && genderForm.checkValidity()) {
            if (contactForm && contactForm.checkValidity()) {
                var log_user = '<?=$_SESSION['user']?>';
                var log_group = '<?=$_SESSION['usergroup']?>';

                $.ajax({
                    url: "./php/ModifyCustomer.php",
                    type: 'POST',
                    data: $("#name_form, #gender_form, #contact_details_form, #comment_form").serialize() + '&log_user=' + encodeURIComponent(log_user) + '&log_group=' + encodeURIComponent(log_group),
                    success: function(data) {
                        if (data == 1) {
                            $('.output-message-success').show().focus().delay(2000).fadeOut(function(){ $(this).hide(); });
                            window.setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            $('.output-message-error').show().focus().delay(5000).fadeOut(function(){ $(this).hide(); });
                        }
                    },
                    error: function(xhr, status, err) {
                        // helpful for debugging failed requests
                        console.error("ModifyCustomer AJAX error:", status, err);
                        $('.output-message-error').show().focus().delay(5000).fadeOut(function(){ $(this).hide(); });
                    }
                });

            } else {
                validate = 1;
            }
        } else {
            validate = 1;
        }
    } else {
        validate = 1;
    }

    if (validate == 1) {
        $('.output-message-incomplete').show().focus().delay(5000).fadeOut(function(){ $(this).hide(); });
        validate = 0;
    }
});
</script>
<script>
// IMPORTANT: Make sure you have the SweetAlert2 library included in your HTML file's <head> section
// <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</script>

		<!-- SnackbarJS -->
        <script src="js/snackbar.js" type="text/javascript"></script>
		<!-- Vue Avatar -->
        <!--<script src="js/vue-avatar/vue.min.js" type="text/javascript"></script>
        <script src="js/vue-avatar/vue-avatar.min.js" type="text/javascript"></script> -->
		<script type='text/javascript'>
			/*var goOptions = {
				el: 'body',
				components: {
					'avatar': Avatar.Avatar,
					'rules': {
						props: ['items'],
						template: 'For example:' +
							'<ul id="example-1">' +
							'<li v-for="item in items"><b>{{ item.username }}</b> becomes <b>{{ item.initials }}</b></li>' +
							'</ul>'
					}
				},
		
				data: {
					items: []
				},
		
				methods: {
					initials: function(username, initials) {
						this.items.push({username: username, initials: initials});
					}
				}
			};
			var goAvatar = new Vue(goOptions);
			
			goAvatar._init();*/
		</script>

		<!-- ECCS Customization -->
		<?php
		if(ECCS_BLIND_MODE === 'y'){
		?>
		<!--<script type="text/javascript" src="js/bootstrap-toggle.min.js"></script> -->
		<script type="text/javascript">
			//tooltips
			$(document).ready(function(){
				
				$('[data-tooltip="tooltip"]').tooltip();
			//	$('#muteMicrophone').bootstrapToggle();

				$('header.main-header a.logo').attr("title", "<?=$lh->translationFor('home')?>");
				
				$('#edit-profile').attr("title", "Enable Edit Contact Information");
                                $('label[for="phone_number"]').attr("title", "<?=$lh->translationFor('phone_number')?>");
                                $('label[for="alt_phone"]').attr("title", "<?=$lh->translationFor('alternative_phone_number')?>");
                                $('label[for="address1"]').attr("title", "<?=$lh->translationFor('address')?>");
                                $('label[for="address2"]').attr("title", "<?=$lh->translationFor('address2')?>");
                                $('label[for="city"]').attr("title", "<?=$lh->translationFor('city')?>");
                                $('label[for="state"]').attr("title", "<?=$lh->translationFor('state')?>");
                                $('label[for="postal_code"]').attr("title", "<?=$lh->translationFor('postal_code')?>");
				$('label[for="country_code"]').attr("title", "<?=$lh->translationFor('country_code')?>");
                                $('label[for="email"]').attr("title", "<?=$lh->translationFor('email')?>");
                                $('label[for="title"]').attr("title", "<?=$lh->translationFor('title')?>");
                                $('label[for="gender"]').attr("title", "<?=$lh->translationFor('gender')?>");
                                $('label[for="date_of_birth"]').attr("title", "<?=$lh->translationFor('date_of_birth')?>");

                                $('input#phone_number').attr("title", "<?=$lh->translationFor('phone_number')?>");
				$('input#alt_phone').attr("title", "<?=$lh->translationFor('alternative_phone_number')?>");
                                $('input#address1').attr("title", "<?=$lh->translationFor('address')?>");
                                $('input#address2').attr("title", "<?=$lh->translationFor('address2')?>");
                                $('input#city').attr("title", "<?=$lh->translationFor('city')?>");
                                $('input#state').attr("title", "<?=$lh->translationFor('state')?>");
                                $('input#postal_code').attr("title", "<?=$lh->translationFor('postal_code')?>");
                                $('input#email').attr("title", "<?=$lh->translationFor('email')?>");
                                $('input#title').attr("title", "<?=$lh->translationFor('title')?>");
                                $('select#gender').attr("title", "<?=$lh->translationFor('gender')?>");
                                $('input#date_of_birth').attr("title", "<?=$lh->translationFor('date_of_birth')?>");
				
				$('button#btnLogMeIn').attr("data-tooltip", "tooltip");
                                $('button#btnLogMeIn').attr("title", "<?=$lh->translationFor('Login to Dialer')?>");

                                $('button#btnLogMeOut').attr("data-tooltip", "tooltip");
                                $('button#btnLogMeOut').attr("title", "<?=$lh->translationFor('Logout from Phone')?>");

				$('#topbar-callbacks a.dropdown-toggle').attr("data-tooltip", "tooltip");
				$('#topbar-callbacks a.dropdown-toggle').attr("title", "<?=$lh->translationFor('callbacks')?>");

				$('li.dropdown.messages-menu a.dropdown-toggle').attr("data-tooltip", "tooltip");
				$('li.dropdown.messages-menu a.dropdown-toggle').attr("title", "<?=$lh->translationFor('messages')?>");

				$('li#dialer-tab a').append("<span class='eccs-icon-lock'>Phone Tab</span>");
				$('li#dialer-tab').attr("data-tooltip", "tooltip");
                                $('li#dialer-tab').attr("title", "<?=$lh->translationFor('Phone Tab')?>");

				$('li#settings-tab a').append("<span class='eccs-icon-lock'>Profile Tab</span>");
				$('li#settings-tab').attr("data-tooltip", "tooltip");
                                $('li#settings-tab').attr("title", "<?=$lh->translationFor('Profile Tab')?>");

				$('button#btnLogMeIn').attr("data-tooltip", "tooltip");
                                $('button#btnLogMeIn').attr("title", "<?=$lh->translationFor('Login To Dialer')?>");

                                $('ul.control-sidebar-menu:nth-of-type(2) a:nth-of-type(1)').attr("data-tooltip", "tooltip");
                                $('ul.control-sidebar-menu:nth-of-type(2) a:nth-of-type(1)').attr("title", "<?=$lh->translationFor('Profile')?>");

                                $('ul.control-sidebar-menu:nth-of-type(2) a:nth-of-type(2)').attr("data-tooltip", "tooltip");
                                $('ul.control-sidebar-menu:nth-of-type(2) a:nth-of-type(2)').attr("title", "<?=$lh->translationFor('exit')?>");

				$('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(3)').attr("data-tooltip", "tooltip");
                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(3)').attr("title", "<?=$lh->translationFor('messages')?>");

                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(4)').attr("data-tooltip", "tooltip");
                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(4)').attr("title", "<?=$lh->translationFor('callbacks')?>");

                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(5)').attr("data-tooltip", "tooltip");
                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(5)').attr("title", "<?=$lh->translationFor('contacts')?>");

                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(6)').attr("data-tooltip", "tooltip");
                                $('ul.control-sidebar-menu#go_agent_profile li:nth-of-type(6)').attr("title", "<?=$lh->translationFor('Enter Pause Codes')?>");

				 $('div#MainStatusSpan a:nth-of-type(1)').attr("title", "<?=$lh->translationFor('dial_lead')?>");
                                 $('div#MainStatusSpan a:nth-of-type(2)').attr("title", "<?=$lh->translationFor('skip_lead')?>");

				$('#callback-list th:nth-of-type(1)').attr("title", "<?=$lh->translationFor('customer_name')?>");
                                $('#callback-list th:nth-of-type(2)').attr("title", "<?=$lh->translationFor('phone_number')?>");
                                $('#callback-list th:nth-of-type(3)').attr("title", "<?=$lh->translationFor('last_call_time')?>");
                                $('#callback-list th:nth-of-type(4)').attr("title", "<?=$lh->translationFor('callback_time')?>");
                                $('#callback-list th:nth-of-type(5)').attr("title", "<?=$lh->translationFor('campaign')?>");
                                $('#callback-list th:nth-of-type(6)').attr("title", "<?=$lh->translationFor('comments')?>");
                                $('#callback-list th:nth-of-type(7)').attr("title", "<?=$lh->translationFor('action')?>");



				 $('button#manual-dial-now').attr("data-tooltip", "tooltip");
                                 $('button#manual-dial-now').attr("title", "Manual Dial");

                                 $('button#manual-dial-dropdown').attr("data-tooltip", "tooltip");
                                 $('button#manual-dial-dropdown').attr("title", "Country Codes");

				for(var a=0; a<=9; a++){
					$('button#dialer-pad-' + a).attr("data-tooltip", "tooltip");
        	                        $('button#dialer-pad-' + a).attr("title", a);
				}
			
				$('li#toggleWebForm').attr("data-tooltip", "tooltip");
                                $('li#toggleWebForm').attr("title", "<?=$lh->translationFor('Web Form')?>");
				
				$('li#toggleWebFormTwo').attr("data-tooltip", "tooltip");
                                $('li#toggleWebFormTwo').attr("title", "<?=$lh->translationFor('Web Form')?>");

				$('ul#go_agent_other_buttons li:nth-of-type(4)').attr("data-tooltip", "tooltip");
                                $('ul#go_agent_other_buttons li:nth-of-type(4)').attr("title", "<?=$lh->translationFor('Lead Preview')?>");
	
				$('li#DialALTPhoneMenu').attr("data-tooltip", "tooltip");
                                $('li#DialALTPhoneMenu').attr("title", "<?=$lh->translationFor('ALT Phone Dial')?>");

                                $('li#toggleHotkeys').attr("data-tooltip", "tooltip");
                                $('li#toggleHotkeys').attr("title", "<?=$lh->translationFor('Enable Hotkeys')?>");

				$('li#toggleMute').attr("data-tooltip", "tooltip");
                                $('li#toggleMute').attr("title", "<?=$lh->translationFor('Toggle Mute')?>");

				
				// Content Tabs
				$('#agent_tablist li:nth-of-type(1)>a.bb0').attr("data-tooltip", "tooltip");
				$('#agent_tablist li:nth-of-type(1)>a.bb0').attr("title", "<?=$lh->translationFor('contact_information')?>");

                                $('#agent_tablist li:nth-of-type(2)>a.bb0').attr("data-tooltip", "tooltip");
                                $('#agent_tablist li:nth-of-type(2)>a.bb0').attr("title", "<?=$lh->translationFor('comments')?>");

                                $('#agent_tablist li:nth-of-type(3)>a.bb0').attr("data-tooltip", "tooltip");
                                $('#agent_tablist li:nth-of-type(3)>a.bb0').attr("title", "<?=$lh->translationFor('script')?>");

				// Hastag Formats
				$('header.main-header a.logo').append("<label for='logo-home' id='hash-home'>#HOME</label>");
				$('button#btnLogMeIn').append(" [#LI] ");
				$('button#btnLogMeOut').append(" [#LP] ");
				
				$('#agent_tablist li:nth-of-type(1)>a.bb0').html(" Contact Info [#CI] ");
	                        $('#agent_tablist li:nth-of-type(2)>a.bb0').append(" [#CM] ");
                        	$('#agent_tablist li:nth-of-type(3)>a.bb0').append(" [#SC] ");

				$('#edit-profile').append(" [#EI] ");
				$('form#contact_details_form label[for="phone_number"]').append(" [#PN] ");
				$('form#contact_details_form label[for="alt_phone"]').html("Alt Phone Number [#APN] ");
				$('form#contact_details_form label[for="address1"]').append(" [#A1] ");
				$('form#contact_details_form label[for="address2"]').append(" [#A2] ");
                	        $('form#contact_details_form label[for="city"]').append(" [#CT] ");
        	                $('form#contact_details_form label[for="state"]').append(" [#ST] ");
	                        $('form#contact_details_form label[for="postal_code"]').append(" [#PC] ");
                	        $('form#contact_details_form label[for="country_code"]').append(" [#CC] ");
        	                $('form#contact_details_form label[for="email"]').append(" [#EM] ");
	                        $('form#gender_form label[for="title"]').append(" [#TI] ");
                        	$('form#gender_form label[for="gender"]').append(" [#GE] ");
                	        $('form#gender_form label[for="date_of_birth"]').append(" [#DB] ");
        	                $('form#gender_form label[for="call_notes"]').append(" [#CN] ");

				$("[data-toggle='control-sidebar']").append("<br><span>#CONF</span>");

				$('li#topbar-callbacks a.dropdown-toggle').append('<span class="sr-only">Callbacks</span><span>&nbsp; #CB</span>');
				$('li.dropdown.messages-menu a.dropdown-toggle').append('<br><span class="sr-only">Messages</span><span>#VM</span>');

				$('button#btnDialHangup').append('<br><span id="hash-dial-hangup"></span>');
                                $('button#btnResumePause').append('<br><span>#PR</span>');
                                $('button#btnParkCall').append('<br><span class="sr-only">Park Call</span><span>#PA</span>');
                                $('button#btnTransferCall').append('<br><span class="sr-only">Transfer Call</span><span>#TC</span>');
                                $('button#manual-dial-now').append('<br><span class="hash-call-now">#CALL</span>');

				$('li#toggleWebForm button#openWebForm').append(" [#WF] ");
				$('.sidebar-toggle-labels label[for="LeadPreview"]').append(" [#LE] ");
                                $('.sidebar-toggle-labels label[for="DialALTPhone"]').append(" [#DALTP] ");
                                $('.sidebar-toggle-labels label[for="enableHotKeys"]').append(" [#EH] ");
                                $('.sidebar-toggle-labels label[for="muteMicrophone"]').append(" [#MIC] ");

				$('ul.control-sidebar-menu:nth-of-type(2) a:nth-of-type(1)').append(" [#PR] ");
                                $('ul.control-sidebar-menu:nth-of-type(2) a:nth-of-type(2)').append(" [#EX] ");

				$('div#MainStatusSpan a:nth-of-type(1)').append(" [#DL] ");
                                $('div#MainStatusSpan a:nth-of-type(2)').append(" [#SL] ");

				// Focus on Input on enter
				$('form#contact_details_form label[for="alt_phone"]').keypress(function(event){
					var keycode = (event.keyCode ? event.keyCode : event.which);
					if(keycode == 13){
					$('input#alt_phone').focus();
					}
					event.stopPropagation();
				});
			
				//Remove Class Absolute Logout Button Dialer
				$('ul#go_agent_logout').css("position", "static");
				$('li#toggleHotkeys').css("display", "block!important");
				//Remove hidden-xs on agent other buttons
				$('ul#go_agent_other_buttons').removeClass('hidden-xs');
				//Remove hidden-xs on MainStatusSpan
				$('div#MainStatusSpan').removeClass('hidden-xs');
				
				//Enable Hotkeys by default
				$('#enableHotKeys').attr("checked","");
				if ($('#enableHotKeys').is(':checked')) {
		                        $(document).on('keydown', 'body', hotKeysAvailable);
                	                $("#popup-hotkeys").fadeIn("fast");
                                } else {
                        	        $(document).off('keydown', 'body', hotKeysAvailable);
                                	$("#popup-hotkeys").fadeOut("fast");
                                }
			
				// Enable Dial Pad on Mobile
				$('ul#go_agent_dialpad').removeClass('hidden-xs');

				if($('aside.control-sidebar').hasClass("control-sidebar-open")){
					$("[data-toggle='control-sidebar']").attr("title", "Enter to Hide Login Tab");
				} else {
					$("[data-toggle='control-sidebar']").attr("title", "Enter to Show Login Tab");
				}

			
           }
        });
		// Add this to your existing JavaScript code
$(document).ready(function() {
  function updateLiveCallVisibility() {
    if ($('.live-call-data').length) {
      $('.live-call-data').addClass('live-call-active');
      
      // Adjust content wrapper padding to accommodate live call data
      $('.content-wrapper').css('padding-top', 
        $('.live-call-data').outerHeight() + 48 + 'px'
      );
    }
  }

  // Run on page load
  updateLiveCallVisibility();

  // Run when call status changes
  $(document).on('callStatusChanged', function() {
    updateLiveCallVisibility();
  });
  

  // Handle orientation change
  $(window).on('orientationchange', function() {
    setTimeout(updateLiveCallVisibility, 100);
  });
  
});
// Add data-key attributes to dialpad buttons
document.querySelectorAll('#go_agent_dialpad .key').forEach(key => {
  key.setAttribute('data-key', key.textContent.trim());
});

// Handle touch events
document.querySelectorAll('#go_agent_dialpad .key').forEach(key => {
  key.addEventListener('touchstart', function(e) {
    e.preventDefault();
    this.classList.add('active');
    // Add your dial tone/haptic feedback here
  });
  
  key.addEventListener('touchend', function(e) {
    e.preventDefault();
    this.classList.remove('active');
  });
});

</script>
		<?php } //end if ECCS_BLIND_MODE ?>
<!-- Agent Tasks Embedded Modal (no iframe) -->
<div id="agentTasksModal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="agentTasksModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document" style="max-width:1100px;">
    <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none;">
      <div class="modal-header modal-3d-header" style="display:flex; align-items:center; gap:12px;">
        <div style="display:flex; align-items:center;">
          <i class="fa fa-tasks" aria-hidden="true" style="font-size:18px; color:#fff; text-shadow:0 2px 6px rgba(6,30,70,0.25)"></i>
          <div style="margin-left:12px;">
            <h4 id="agentTasksModalLabel" style="margin:0; color:#fff; font-weight:800; font-size:18px; line-height:1;">Tasks</h4>
            <div style="font-size:12px; color:rgba(255,255,255,0.9); font-weight:600;">Manage tasks for current agent</div>
          </div>
        </div>

        <div style="margin-left:auto; display:flex; align-items:center; gap:10px;">
          <div id="agentTasksModalStatus" style="font-size:13px; color:rgba(255,255,255,0.9);"></div>
          <button type="button" class="close modal-close-btn" data-dismiss="modal" aria-label="Close" style="color:rgba(255,255,255,0.95); opacity:1; background:transparent; border:none; font-size:26px;">
            &times;
          </button>
        </div>
      </div>

      <div class="modal-body" style="padding:12px; background:#fbfdff; position:relative;">
        <!-- loader overlay -->
        <div id="agentTasksModalLoader" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.85); z-index:50; display:none;">
          <div style="text-align:center;">
            <div class="spinner-border" role="status" style="width:42px;height:42px;color:#0b6fd6;">
              <span class="sr-only">Loading...</span>
            </div>
            <div style="margin-top:8px;color:#0b6fd6;font-weight:700;">Loading tasks�</div>
          </div>
        </div>

        <!-- Embedded tasks content goes here -->
        <div id="agentTasksEmbedContainer" style="min-height:200px;"></div>
      </div>

      <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; background:transparent;">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
  // Sidebar badge (all callbacks)
  fetch('/php/API_getCallbacks.php')
    .then(res => res.json())
    .then(resp => {
      if (resp.result === "success" && typeof resp.count === "number") {
        var badge = document.querySelector("#callbackslist .badge, #callbackslist .sidebar-badge, #callbackslist .bg-blue, #callbackslist .bg-red");
        if (badge) badge.textContent = resp.count;
      }
    });

  // Callbacks for today badge
  function updateCallbacksToday() {
    var today = new Date();
    var yyyy = today.getFullYear();
    var mm = String(today.getMonth() + 1).padStart(2, '0');
    var dd = String(today.getDate()).padStart(2, '0');
    var todayStr = yyyy + '-' + mm + '-' + dd;

    fetch('/php/API_getCallbacks.php?now_date=' + todayStr)
      .then(res => res.json())
      .then(resp => {
        if (resp.result === "success" && typeof resp.count === "number") {
          var badgeToday = document.querySelector("#callbacks-today .badge, #callbacks-today .sidebar-badge, #callbacks-today .bg-blue, #callbacks-today .bg-red");
          if (badgeToday) badgeToday.textContent = resp.count;
          // If you have a popup or notification, update its text too:
          var popupText = document.querySelector("#callbacks-today-popup-text");
          if (popupText) popupText.textContent = "You have " + resp.count + " Callbacks for today";
        }
      });
  }

  updateCallbacksToday();
});
</script>
<script>
(function($){
  
  
  function renderCallbackTable(callbacks) {
  if (!Array.isArray(callbacks) || callbacks.length === 0) {
    return '<tr><td colspan="8" style="text-align:center; color:#888;">No callbacks found.</td></tr>';
  }
  return callbacks.map(cb => `
    <tr>
      <td>${cb.cust_name || ''}</td>
      <td>${cb.phone_number || ''}</td>
      <td>${cb.entry_time || ''}</td>
      <td>${cb.callback_time || ''}</td>
      <td>${cb.campaign_name || ''}</td>
      <td>${cb.comments || ''}</td>
      <td>${cb.status || ''}</td>
      <td>
        <button class="btn btn-xs btn-primary" data-lead="${cb.lead_id}">Call</button>
      </td>
    </tr>
  `).join('');
}

// Fetch and render callbacks for the current agent
function loadAgentCallbacks() {
  fetch(`/php/API_getCallbacks.php`)
    .then(res => res.json())
    .then(resp => {
      if (resp.result === "success" && Array.isArray(resp.data)) {
        document.querySelector("#callback-list tbody").innerHTML = renderCallbackTable(resp.data);
      } else {
        document.querySelector("#callback-list tbody").innerHTML = '<tr><td colspan="8">No callbacks found.</td></tr>';
      }
    })
    .catch(() => {
      document.querySelector("#callback-list tbody").innerHTML = '<tr><td colspan="8">Error loading callbacks.</td></tr>';
    });
}

// Example: Load callbacks for the logged-in agent (replace with your session variable if needed)
document.addEventListener("DOMContentLoaded", function() {
  loadAgentCallbacks(); // or use your session username
});

  
  // utility: load external script if not already present (returns Promise)
  function loadScriptOnce(src) {
    return new Promise(function(resolve, reject){
      if (!src) return resolve();
      // already loaded?
      var loaded = !!document.querySelector('script[src="'+src+'"]');
      if (loaded) return resolve();
      $.getScript(src).done(resolve).fail(function(){ console.warn('Failed to load', src); resolve(); });
    });
  }

  // load scripts sequentially (array of srcs)
  function loadScriptsSequential(scripts) {
    var p = Promise.resolve();
    scripts.forEach(function(s){
      p = p.then(function(){ return loadScriptOnce(s); });
    });
    return p;
  }

  // Extracts content and scripts from HTML string
  function extractContentAndScripts(html) {
    var $doc = $('<div>').append($.parseHTML(html, document, true));
    // prefer <section class="content"> if present
    var $section = $doc.find('section.content').first();
    var $taskContainer = $doc.find('#task-table-container').first();
    var $completed = $doc.find('#completed-task-table-container').first();

    // decide primary container:
    var $content = $section.length ? $section : $('<div>').append($taskContainer).append($completed);
    if (!$content.length) {
      // fallback to body tag or the whole document
      $content = $doc;
    }

    // find scripts (external and inline) that belong to the fetched page
    var external = [];
    var inline = [];
    $doc.find('script').each(function(){
      var src = $(this).attr('src');
      if (src) {
        // only include scripts that are relative or absolute (same as returned)
        external.push(src);
      } else {
        var t = $(this).text();
        if ($.trim(t)) inline.push(t);
      }
    });

    return { $content: $content, externals: external, inlines: inline };
  }

  // main function: fetch agent-tasks.php and embed
  function openAgentTasksModal(evt, opts) {
    opts = opts || {};
    var $modal = $('#agentTasksModal');
    var $container = $('#agentTasksEmbedContainer');
    var $loader = $('#agentTasksModalLoader');
    var status = $('#agentTasksModalStatus');

    var src = opts.src || 'agent-tasks.php';
    // forward data-* from clicked element as query params
    if (evt && evt.currentTarget) {
      var $el = $(evt.currentTarget);
      var params = [];
      $.each($el.data() || {}, function(k,v){
        if (typeof v === 'undefined' || v === null) return;
        params.push( encodeURIComponent(k) + '=' + encodeURIComponent(v) );
      });
      if (params.length) src += (src.indexOf('?') === -1 ? '?' : '&') + params.join('&');
    }

    status.text('');
    $container.empty();
    $loader.show();
    $modal.modal({ backdrop: 'static', keyboard: true });

    // fetch HTML
    $.get(src).done(function(html){
      // parse and extract content/scripts
      var res = extractContentAndScripts(html);

      // insert content HTML into modal container
      $container.html(''); // clear
      // append the content's inner HTML (avoid preserving outer section wrapper)
      $container.append(res.$content.contents());

      // next: load external scripts sequentially (but avoid reloading common libs like jquery)
      // Normalize src paths to absolute if needed - use as given
      var externals = res.externals || [];
      // filter out jquery duplicates and things already present
      externals = externals.filter(function(s){
        if (!s) return false;
        // skip jquery (already present)
        if (s.toLowerCase().indexOf('jquery') !== -1) return false;
        return true;
      });

      loadScriptsSequential(externals).then(function(){
        // run inline scripts
        (res.inlines || []).forEach(function(code){
          try { $.globalEval(code); } catch(e){ console.warn('inline script error', e); }
        });

        // run your attachCommentBoxes if available, otherwise expose global
        if (typeof window.attachTaskCommentBoxes === 'function') {
          try { window.attachTaskCommentBoxes(); } catch(e){ console.warn(e); }
        } else {
          // we also try to run attachCommentBoxes defined inside this file if exists
          if (typeof attachCommentBoxes === 'function') {
            try { attachCommentBoxes(); } catch(e) { console.warn(e); }
          }
        }

        // hide loader and set status
        setTimeout(function(){ $loader.fadeOut(160); }, 180);
        status.text('Loaded');
      }).catch(function(){
        $loader.hide();
        status.text('Loaded (with warnings)');
      });

    }).fail(function(){
      $loader.hide();
      $container.html('<div class="alert alert-danger">Failed to load tasks content.</div>');
      status.text('Error');
    });
  }

  // intercept clicks on #agent-tasks or .agent-tasks
  $(document).on('click', '#agent-tasks, .agent-tasks', function(e){
    e.preventDefault();
    openAgentTasksModal(e, { src: $(this).attr('href') || 'agent-tasks.php' });
  });

  // Expose for programmatic open: openAgentTasksModal(null, { src:'agent-tasks.php?leadid=123' })
  window.openAgentTasksModal = openAgentTasksModal;

  // cleanup when modal closes
  $('#agentTasksModal').on('hidden.bs.modal', function(){
    $('#agentTasksEmbedContainer').empty();
    $('#agentTasksModalLoader').hide();
    $('#agentTasksModalStatus').text('');
  });

})(jQuery);
</script>

<script>
const AGENT_ID = (typeof uName !== 'undefined' && uName) ? uName : (typeof user !== 'undefined' ? user : null);

const gocheckconference = (function(){
    const DEFAULTS = { interval: 1500, idleThreshold: 120, notifyTimeout: true, maxConsecutiveErrors: 6 };
    let cfg = {}, timer = null, isRunning = false, inFlight = false, errors = 0;
    let statusDisplay, statusCard, ringtone, modal, answerBtn;
    let isRinging = false, currentStatus = 'IDLE', forceRinging = false;

    function _getEl(id){ return document.getElementById(id); }
    function _normalize(s) { return (s || '').toString().trim().toUpperCase(); }

    function startRinging() {
        if (!isRinging && currentStatus !== 'INCALL') {
            isRinging = true;
            forceRinging = true;
            if (modal) modal.classList.remove('hidden');
            if (answerBtn) answerBtn.disabled = false;
            if (ringtone) {
                ringtone.loop = true;
                try { ringtone.currentTime = 0; } catch(e) {}
                ringtone.play().catch(()=>{ console.debug('ring play blocked'); });
            }
            console.debug('startRinging()');
        }
    }

    function stopRinging() {
        if (isRinging) {
            isRinging = false;
            forceRinging = false;
            if (modal) modal.classList.add('hidden');
            if (answerBtn) answerBtn.disabled = true;
            if (ringtone) { try { ringtone.pause(); ringtone.currentTime = 0; } catch(e) {} }
            console.debug('stopRinging()');
        }
    }

    async function _sendStatus(status) {
        if (!API_UPDATE_URL) { console.error('API_UPDATE_URL is not defined'); return; }

        if (answerBtn) answerBtn.disabled = true;

        if (statusDisplay) statusDisplay.textContent = 'Sending ' + status + '...';
        const form = new FormData();
        form.append('status', status);
        if (AGENT_ID) form.append('agent', AGENT_ID);

        try {
            const resp = await fetch(API_UPDATE_URL, { method:'POST', body: form, credentials:'same-origin' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();
            console.log('update result', json);

            const serverAgentStatus = _normalize(json.agent_status || json.status || '');
            const serverRing = _normalize(json.ring_request_status || json.ring_status || '');

            if (status === 'ACCEPTED') {
                if (serverAgentStatus === 'INCALL' || serverRing === 'INCALL') {
                    currentStatus = 'INCALL';
                    stopRinging();
                    if (statusDisplay) statusDisplay.textContent = 'IN CALL';
                    if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-blue-100';
                    return;
                }
                if (serverRing === 'ACCEPTED') {
                    currentStatus = 'INCALL';
                    stopRinging();
                    if (statusDisplay) statusDisplay.textContent = 'IN CALL (accepted)';
                    if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-blue-100';
                    return;
                }

                currentStatus = 'INCALL';
                stopRinging();
                if (statusDisplay) statusDisplay.textContent = 'IN CALL (pending)';
                if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-blue-100';
            }

        } catch(e) {
            console.error('sendStatus failed', e);
            if (status === 'ACCEPTED') {
                if (answerBtn) answerBtn.disabled = false;
            }
            if (statusDisplay) statusDisplay.textContent = 'ERROR';
        }
    }

    function _effectiveStatusFromData(data) {
        const ring = _normalize(data.ring_request_status || data.ring_status || '');
        const agentStatus = _normalize(data.agent_status || '');
        if (ring === 'RINGING' || ring === 'ACCEPTED' || ring === 'INCALL') return ring;
        if (agentStatus) return agentStatus;
        return 'IDLE';
    }

    async function _tick() {
        if (inFlight) return;
        inFlight = true;
        try {
            if (!API_CHECK_URL) throw new Error('API_CHECK_URL is not defined');
            const resp = await fetch(API_CHECK_URL, { credentials: 'same-origin', cache: 'no-store' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            errors = 0;

            const idleSeconds = Number(data.idle_seconds || 0);
            const effective = _effectiveStatusFromData(data);

            if (effective === 'INCALL' || effective === 'ACCEPTED') {
                currentStatus = 'INCALL';
                stopRinging();
                if (statusDisplay) statusDisplay.textContent = 'IN CALL';
                if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-blue-100';
                return;
            }

            if (effective === 'RINGING') {
                if (currentStatus !== 'INCALL') {
                    startRinging();
                    if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-red-100';
                    if (statusDisplay) statusDisplay.textContent = 'RINGING' + (idleSeconds ? ` (${idleSeconds}s idle)` : '');
                }
                return;
            }

            if (effective === 'READY' || effective === 'IDLE' || effective === '') {
                if (idleSeconds >= cfg.idleThreshold && (effective === 'IDLE' || effective === 'READY')) {
                    stopRinging();
                    if (cfg.notifyTimeout && typeof onIdleTimeout === 'function') await onIdleTimeout();
                    stop();
                    return;
                }
                stopRinging();
                currentStatus = 'IDLE';
                if (statusDisplay) statusDisplay.textContent = (effective === 'READY' ? 'READY' : 'IDLE') + (idleSeconds ? ` (${idleSeconds}s idle)` : '');
                if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-yellow-50';
                return;
            }

            stopRinging();
            currentStatus = effective || 'IDLE';
            if (statusDisplay) statusDisplay.textContent = currentStatus + (idleSeconds ? ` (${idleSeconds}s idle)` : '');
            if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-yellow-50';

        } catch(err) {
            errors++;
            console.error('gocheckconference tick error', err, errors);
            if (errors >= cfg.maxConsecutiveErrors) stop();
        } finally {
            inFlight = false;
        }
    }

    function start(options={}) {
        if (isRunning) return;
        cfg = {...DEFAULTS, ...options};

        statusDisplay = _getEl('currentStatus');
        statusCard = _getEl('status-card');
        ringtone = _getEl('ringtone');
        modal = _getEl('incomingCallModal');
        answerBtn = _getEl('answerButton');

        if (!statusDisplay || !statusCard || !ringtone || !modal || !answerBtn) {
            console.error('gocheckconference init failed - missing DOM elements');
            return;
        }

        answerBtn.addEventListener('click', ()=> {
            answerBtn.disabled = true;
            _sendStatus('ACCEPTED');
        });

        _tick();
        timer = setInterval(_tick, cfg.interval);
        isRunning = true;
    }

    function stop() {
        if (!isRunning) return;
        if (timer) { clearInterval(timer); timer = null; }
        isRunning = false;
        stopRinging();
        currentStatus = 'IDLE';
        if (statusDisplay) statusDisplay.textContent = 'IDLE';
        if (statusCard) statusCard.className = 'p-4 border border-gray-200 rounded-lg bg-yellow-50';
    }

    return { start, stop, _tick };
})();

document.addEventListener('DOMContentLoaded', ()=> gocheckconference.start({ interval:1500, idleThreshold:120, notifyTimeout:true }));
</script>

    </body>
</html>
