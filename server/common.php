<?php

define("PROJECT_NAME", "MADXWS");
define("DB_HOST", "YOUR_DB_HOST");
define("DB_USER", "YOUR_DB_USER");
define("DB_PASS", "YOUR_DB_PASS");
define("DB_NAME", "YOUR_DB_NAME");

list($ignore,$ignore, $cpanel_username) = explode("/", dirname(__FILE__));

$vars['root'] = rtrim(DIRNAME(__FILE__),"/");
$vars['home'] = '/home/'.$cpanel_username;
$vars["admin_email"]=array("jawisoft@gmail.com");

include_once "func_general.php";
include_once "cls_auth.php";
include_once "cls_db.php";
include_once "cls_ws.php";

ini_set('memory_limit','-1');
ini_set('max_execution_time', 0);
ini_set("log_errors", 1);
ignore_user_abort(true);

set_time_limit(0); 
// Report all errors
error_reporting(E_ALL);
// Same as error_reporting(E_ALL);
ini_set("error_reporting", E_ALL);

if (!date_default_timezone_set('Asia/Kuala_Lumpur')) {
	die('Server is not support default time zone set function');
}

if (!isset($_SERVER['REQUEST_METHOD'])) {
	$_SERVER['SERVER_ADDR'] = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : "";
	if (!$_SERVER['SERVER_ADDR']) {
		$host= gethostname();
		$ip = gethostbyname($host);
		$_SERVER['SERVER_ADDR'] = $ip;
	}
}


set_error_handler( "logError" );
set_exception_handler( "logException" );
register_shutdown_function( "logUncaughtFatal" );
register_shutdown_function(	"handleFatal");
register_shutdown_function(function () {
   @mysqli_close(DB::$conn);
});
