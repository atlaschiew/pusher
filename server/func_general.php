<?php

function handleFatal(){
    $error = error_get_last();
    if (isset($error['type']))
    {
        switch ($error['type'])
        {
        case E_ERROR :
        case E_PARSE :
        case E_CORE_ERROR :
        case E_COMPILE_ERROR :
            $message = $error['message'];
            $file = $error['file'];
            $line = $error['line'];
            $log = "$message ($file:$line)\nStack trace:\n";
            $trace = debug_backtrace();
            foreach ($trace as $i => $t)
            {
                if (!isset($t['file']))
                {
                    $t['file'] = 'unknown';
                }
                if (!isset($t['line']))
                {
                    $t['line'] = 0;
                }
                if (!isset($t['function']))
                {
                    $t['function'] = 'unknown';
                }
                $log .= "#$i {$t['file']}({$t['line']}): ";
                if (isset($t['object']) and is_object($t['object']))
                {
                    $log .= get_class($t['object']) . '->';
                }
                $log .= "{$t['function']}()\n";
            }
            if (isset($_SERVER['REQUEST_URI']))
            {
                $log .= '[QUERY] ' . $_SERVER['REQUEST_URI'];
            }
            
			emailMe(PROJECT_NAME." [{$_SERVER['SERVER_ADDR']}] - fatal handler", $log);
        default:
            break;
        }
    }
}

function logError( $num, $str, $file, $line, $context = null ) { logException( new ErrorException( $str, 0, $num, $file, $line ) );}
function logUncaughtFatal(){$error = error_get_last();logError( $error["type"], $error["message"], $error["file"], $error["line"] );}
function logException( $e )
{
	global $vars, $req;
	
	$error_name = array(1 => 'E_ERROR',2 => 'E_WARNING',4 => 'E_PARSE',8 => 'E_NOTICE',16 => 'E_CORE_ERROR',32 => 'E_CORE_WARNING',64 => 'E_COMPILE_ERROR',128 => 'E_COMPILE_WARNING',256 => 'E_USER_ERROR',512 => 'E_USER_WARNING',1024 => 'E_USER_NOTICE',2048 => 'E_STRICT',4096 => 'E_RECOVERABLE_ERROR',8192 => 'E_DEPRECATED',16384 => 'E_USER_DEPRECATED'
	);
	
	if (method_exists($e, "getSeverity") AND in_array($e->getSeverity(),array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_RECOVERABLE_ERROR ))) {
		$message = "Message: {$e->getMessage()}; File: {$e->getFile()}; Line: {$e->getLine()}; Error Level Constants: " . $error_name[$e->getSeverity()];
		$now_date = date("Y-m-d");
		$now_time = date("Y-m-d H:i:s");
		$file = "{$now_date}.php";
		$a_sub = PROJECT_NAME." [{$_SERVER['SERVER_ADDR']}] - System's PHP Error Message (".$error_name[$e->getSeverity()].")";
		$a_msg = "[{$now_time}] {$message}\n\n{$_SERVER['PHP_SELF']}?{$_SERVER['QUERY_STRING']}\n";
		$post_data = trim(print_r($_POST,true));
		$get_data = trim(print_r($_GET,true));
		$file_data = trim(print_r($_FILES,true));	
		$req_data = trim(print_r($req,true));	
		
		$a_msg .= ($post_data ? " (POST){$post_data}" : "") . ($get_data  ? " (GET){$get_data}" : "") . ($file_data  ? " (FILES){$file_data}" : ""). ($req_data  ? " (REQ){$req_data}" : "");

		$handle = fopen($file, 'r');
		$found = false;
		while (($buffer = fgets($handle)) !== false) {
			if (strpos($buffer, $message) !== false) {
				$found = TRUE;
				break;
			}      
		}
		
		fclose($handle);

		$admin_emails = $vars['admin_email'];
		if (!is_array($vars['admin_email'])) {
			$admin_emails = array($vars['admin_email']);
		}
		
		$send = false;
		if (!$found) {
			file_put_contents($vars['home'] . '/error/'.$file,$a_msg,FILE_APPEND);
			$send = true;
		} else {
			if (rand(1, 10) == 10) {
				$send = true;
			}
		}
		
		if ($send) {
			emailMe($a_sub, $a_msg);
		}
	} 
}

function emailMe($a_sub, $a_msg) {
	global $vars;
	$ch = curl_init($url="https://websend.sendgird.net/error_sendmail.php?email=".implode(",", $vars['admin_email'])."&subject=".base64_encode($a_sub)."&message=".base64_encode($a_msg));
		
	curl_exec($ch);
	curl_close($ch);
}

function isInteger($input){
    return(ctype_digit(strval($input)));
}

function verifyFieldData($rule, $value){

	$errmsg = "";
	if($rule){
		$check_data_type=false;
		$type=explode("#", $rule);
		if($type[3]=="m"){//type[3]=mandatory/optional (m/o)
			if(!strlen($value)){
				$errmsg.=replace_tag(__("'<%field%>' is a required field."), array("<%field%>"=>__($type[4])));
			}else{
				$check_data_type=true;
			}

		}elseif(strlen($value)){
			$check_data_type=true;
		}
		
		if($check_data_type){
			if($type[0]=="str"){
				if(strlen($value)<$type[1] || strlen($value)>$type[2]){//type[1]=min value, type[2]=max value, type[4]=label for field
					$errmsg.=replace_tag(__("'<%field%>' must between <%min%> to <%max%> characters only. You have entered <%no%> character(s)."), array("<%field%>"=>__($type[4]), "<%min%>"=>$type[1], "<%max%>"=>$type[2], "<%no%>"=>strval(strlen($value))));
				}
			}elseif($type[0]=="dec"){
				/*/decimal - type[0]=decimal type[1]=max digit type[2]=decimal point type[3]=mandatory/optional type[4]=field label type[4]=field desc in 
				english type[5]=min value type[6]=max value*/
				
				if (!preg_match('/^(\-)?[0-9]+(\\.[0-9]+)?$/', $value)) {
					$errmsg.=replace_tag(__("'<%field%>' accepts only numerical value."), array("<%field%>"=>__($type[4])));
				} else if(!is_numeric($value)){
					$errmsg.=replace_tag(__("'<%field%>' accepts only numerical value."), array("<%field%>"=>__($type[4])));
				}elseif(strlen(number_format($value, $type[2], "", ""))>$type[1]){//check total length
					$errmsg.=replace_tag(__("'<%field%>' value is too large, maximum acceptable is <%no%>."), 
					array("<%field%>"=>__($type[4]), "<%no%>"=>number_format(pow(10, (($pow=$type[1]-$type[2])))-1).($type[2]>0? ".".(pow(10, 
					$type[2])-1) : "")));
				}elseif(strlen(substr(strrchr($value, "."), 1))>$type[2]){
					if($type[2] == "0"){
						
						$errmsg.=replace_tag(__("'<%field%>' accepts only integer value."), array("<%field%>"=>__($type[4])));
					}else{
						$errmsg .= replace_tag( __("'<%field%>' decimal point is too large, maximum acceptable decimal point is <%no%>."), array("<%field%>"=>__($type[4]),"<%no%>"=>$type[2]));
					}	
				}else{

					if(substr($type[5], 0, 2)!="na"){//check min

						if(substr($type[5], 0, 2)==">=" && doubleval($value)<doubleval(substr($type[5], 2))){

							$errmsg.=replace_tag(__("'<%field%>' must greater than or equal to <%no%>."), array("<%field%>"=>__($type[4]), 
							"<%no%>"=>number_format(substr($type[5], 2), $type[2])));

						}elseif(substr($type[5], 0, 1)==">" && substr($type[5], 0, 2)!=">=" && doubleval($value)<=doubleval(substr($type[5], 1))){
							$errmsg.=replace_tag(__("'<%field%>' must greater than <%no%>."), array("<%field%>"=>__($type[4]), 
							"<%no%>"=>number_format(substr($type[5], 1), $type[2])));

						}elseif(is_numeric($type[5]) && doubleval($value)<doubleval($type[5])){
							$errmsg.=replace_tag(__("'<%field%>' must greater than or equal to <%no%>."), array("<%field%>"=>__($type[4]), 
							"<%no%>"=>number_format($type[5], $type[2])));
						}
					}

					if(substr($type[6], 0, 2)!="na"){//check max
						if(substr($type[6], 0, 2)=="<=" && doubleval($value)>doubleval(substr($type[6], 2))){
							$errmsg.=replace_tag(__("'<%field%>' must less than or equal to <%no%>."), array("<%field%>"=>__($type[4]), 
							"<%no%>"=>number_format(substr($type[6], 2), $type[2])));
						}elseif(substr($type[6], 0, 1)=="<" && substr($type[6], 0, 2)!="<=" && doubleval($value)>=doubleval(substr($type[6], 1))){
							$errmsg.=replace_tag(__("'<%field%>' must less than <%no%>."), array("<%field%>"=>__($type[4]), 
							"<%no%>"=>number_format(substr($type[6], 1), $type[2])));
						}elseif(is_numeric($type[6]) && doubleval($value)>doubleval($type[6])){
							$errmsg.=replace_tag(__("'<%field%>' must less than or equal to <%no%>."), array("<%field%>"=>__($type[4]), 
							"<%no%>"=>number_format($type[6], $type[2])));

						}

					}

				}

			}

		}

	}
			
	return $errmsg;
}

function validateJson($str) {
	$first2 = substr($str, 0, 2);
	$last2  = substr($str, -2);
	
	if (in_array($first2, array("[{","[[",'{"')) AND in_array($last2, array("}]","]]",'"}'))) {
		return true;
	} else {
		return false;
	}
}

function parse_argv($argvs) {
	$new_argv = array();
	foreach($argvs as $argv) {
		if(preg_match('/^\-\-[A-Za-z0-9]+\=/', $argv)) {
			list($k,$v) = explode("=",$argv);
			$new_argv[ltrim($k,"--")] = $v; 
		}
	}
	
	return $new_argv;
}
