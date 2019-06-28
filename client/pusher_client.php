<?php
include_once "cls_madxpusher_client.php";

define('MADXAPI_DOMAIN','pusher_api.madxpanel.com:2053');
define("MADXPUSHER_DOMAIN", "pusher.madxpanel.com:2053");
define('MADXPUSHER_SECRET','aguvwnQl}(qa6E3~');
define('MADXPUSHER_API_KEY','82adf61cc5ab4c893c32a9a05895ffc2');

$o_madxpusher = new \MadxPusher\MadxPusher_Client(MADXAPI_DOMAIN,MADXPUSHER_API_KEY,MADXPUSHER_SECRET,MADXPUSHER_DOMAIN);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	if ($_POST['action'] == 'push') {
		$push_result = $o_madxpusher->push($_POST['message'],1,2);
		
		$return = new stdClass;
		$return->status = $push_result['status'] ? 1 : 0;
		$return->error =  $push_result['error'];
		
		die(json_encode($return));
	} else if ($_POST['action'] == 'reconnect') {
		$token_result  =  $o_madxpusher->get_ws_token(1,2);
		$_SESSION['madxpusher_token'] = $token_result['ws_token'];
		
		$ws_conn = $o_madxpusher->get_ws_conn($_SESSION['madxpusher_token']);
		
		$return = new stdClass;
		
		$return->status = $token_result['status'] ? 1 : 0;
		$return->error = $token_result['error'];
		$return->ws_conn = $ws_conn;
		
		die(json_encode($return));
	} 
}
	
if (!$_SESSION['madxpusher_token'] ) {
	$token_result  =  $o_madxpusher->get_ws_token(1,2);	
	$_SESSION['madxpusher_token'] = $token_result['ws_token'];
}

$ws_conn = $o_madxpusher->get_ws_conn($_SESSION['madxpusher_token']);

header('Content-Type: text/html; charset=UTF-8');
?>
<html>
	<head>
		<script src="https://code.jquery.com/jquery-latest.min.js"></script>
		<script type="text/javascript" charset='UTF-8'>
			
			$( document ).ready(function() {
								
				function startMadxPusher(url,retryInterval/*ms*/) {
					
					var websocket =new WebSocket(url);
					
					var reconnect = function(thisRetryInterval){  
						$.ajax({
							type: "POST",
							url: '',
							data: {'action':'reconnect'},
							success: function(data)
							{
								websocket = null;//destroy ws client
								
								var j = eval('(' + data + ')');
								
								if (j.status==0) {
									alert(j.error);
								} else {
									
									startMadxPusher(j.ws_conn,thisRetryInterval);
								}
							},
							error: function() {
								
							}
						});
					}

					websocket.onopen = function (evt) {
						alert('Onopen: Connection setup');
						retryInterval = 5000; //reset to default
					};
					
					websocket.onclose = function (evt) {
						
						var code = parseInt(evt.code);
						var reason = evt.reason;
						
						console.log('Onclose code: ' + code + ', reason: ' + reason);

						if (code==4002/*token expired*/|| code==4003/*idle connection*/) {
							
							alert('Onclose code: ' + code + ', reason: ' + reason);
							
							setTimeout(reconnect, retryInterval,retryInterval*2);
						}
					};
					
					websocket.onmessage = function (evt) {
						var prevContent = $("textarea[name=srv_message]").val();
						$("textarea[name=srv_message]").val(evt.data + '\n' + prevContent);
					};

					websocket.onerror = function (evt) {
						var readyState = parseInt(this.readyState);
						console.log( evt);
						
						if (readyState != 1) {
							alert("Onerror, attemp to reconnect, wait for "+retryInterval);
							/* few possibilities trigger this
							
							1. reach max connection
							2. ws token was expired
							*/
							
							setTimeout(reconnect, retryInterval,retryInterval*2);
						}
					};
				}
				
				startMadxPusher('<?php echo $ws_conn?>',5000);

				$("#form_push").submit(function(e) {

					e.preventDefault(); // avoid to execute the actual submit of the form.

					var form = $(this);
					var url = form.attr('action');
					
					$.ajax({
						type: "POST",
						url: url,
						data: form.serialize(), // serializes the form's elements.
						success: function(data)
						{
							var j = eval('(' + data + ')');
						   
							var prevContent = $("div#trace").html();
							if (j.status==0) {
								$("div#trace").html(j.error + '<br/>' + prevContent);
							} else {
								$("div#trace").html("Push Success"+ '<br/>' + prevContent);
							}
						}
					});
				});
			});

		</script>
		
	</head>
	<body>
		<h1>Test Form</h1>
		<form id='form_push' method="post" action="">
			Server response:<br/><textarea name='srv_message' cols=70 rows=10></textarea><br/>
			Push Message:<br/><input type='text' name='message' size=76/><br/>
			<input type='submit' name='btn_push' value='Push msg via http'/> 
			<input type='hidden' name='action' value='push'/>
		</form>
		
		<h1>Trace</h1>
		<div id='trace'>
		
		</div>
	</body>
</html>
