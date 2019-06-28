<?php
namespace MadxWS;

use Swoole;

class Main {
    private $server;
	private $argv;
	private $vars;
	private $byteFrameHeader = 10; //in bytes, 80bits
	private $maxBytesPerFrame = 2048;//(2 * 1024 * 1024);
	private $maxBytesPerFrameBody = null;
	private $codeReason = [4000=>'Push failure',4001=>'Client requests for close frame', 4002=>"Token expired", 4003=>"Idle connection", 4004=>"Message rejected", 4005=>"Handshake rejected", 4006=>"Unknown Connection", 4007=>"Handshake rejected: Account reach max connection", 4008=>"Handshake rejected: Account reach max message/day"];
	private $accessLifeTime =  7200; //second
	
    public function __construct(array $argv, array $vars) {
		
		$this->vars = $vars;
		$log_file = "{$this->vars['home']}/trace/madxws.log";
		
		$this->maxBytesPerFrameBody = $this->maxBytesPerFrame - $this->byteFrameHeader;
		
		$this->argv = parse_argv($argv);
		
        $this->server = new Swoole\WebSocket\Server("0.0.0.0", 2053, SWOOLE_PROCESS,  SWOOLE_SOCK_TCP | SWOOLE_SSL);
		#$this->server->addlistener("0.0.0.0", 2053,  SWOOLE_SOCK_TCP | SWOOLE_SSL);
		$this->server->set($sets = array(
			'log_file'=>$log_file,
			/*'worker_num'=>1,*/
			'max_request'=>10000,
			
			'buffer_output_size'=>$this->maxBytesPerFrame,
			'socket_buffer_size' =>$this->maxBytesPerFrame,
			"open_websocket_close_frame" => true, /*receive opcode 0x8 from client*/
			
			'ssl_cert_file' =>'ssl/ssl.crt',
			'ssl_key_file' => 'ssl/ssl.key',
			'daemonize'=>true,
			'pid_file' => $this->argv['pidfile'],
		));
		
		$this->server->on("start",        array($this, 'onMasterStart'));		
		$this->server->on('ManagerStart', array($this, 'onManagerStart'));
		$this->server->on('workerstart',  array($this, 'onWorkerStart') );
		$this->server->on('handshake',    array($this, 'onHandShake') );
        $this->server->on('open',         array($this, 'onOpen') );
        $this->server->on('message',      array($this, 'onMessage'));
        $this->server->on('close',        array($this, 'onClose') );
        $this->server->on('request',      array($this, 'onRequest'));
		
		$this->server->PROCESS_HOUSE_KEEPING = new Swoole\Process(array($this,'onHouseKeeping'));
		$this->server->addProcess($this->server->PROCESS_HOUSE_KEEPING);
		
        $this->server->start();
    }
	
	public function onMasterStart(Swoole\Server $server) {
		echo "Server: on master start.\n";
		swoole_set_process_name("php " .implode(" ", $this->argv) . " [MASTER]");
		
		try {
			$conn_result = \MadxWS\DB::connect(DB_HOST,DB_USER,DB_PASS,DB_NAME);
		
			if (!$conn_result['status']) {
				throw new Exception($conn_result['errmsg']);
			}
			
			$server->MYSQL_CONN = \MadxWS\DB::$conn; //$server and $this->server are equipvalent
			
			#swoole_timer_tick(1000, array($this,'onMonitor'));
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}

	//process server's connections other than recorded fd
	public function onMonitor(int $timerID) {
		
		\MadxWS\DB::$conn = $this->server->MYSQL_CONN;
		
		$t = count($this->server->connections);
		if ($t > 0) {
			echo "total connections: {$t}\n";
		}
	}
	
	//process expired tokens
	public function onHouseKeeping(Swoole\Process $process) {
		
		swoole_set_process_name("php " .implode(" ", $this->argv) . " [UD-PROCESS#".__FUNCTION__."]");
		$httpConns = 0;
		try {
			$conn_result = \MadxWS\DB::connect(DB_HOST,DB_USER,DB_PASS,DB_NAME);
		
			if (!$conn_result['status']) {
				throw new Exception($conn_result['errmsg']);
			}
			
			$this->server->MYSQL_CONN = \MadxWS\DB::$conn;
			$idleOTHConns = $idleWSConns = [];
			
			while (true) {
				//process expired token
				$r = \MadxWS\DB::query($sql="SELECT * FROM madxws_db.tokens WHERE expiry_date <= '" . \MadxWS\DB::esc(date("Y-m-d H:i:s", time() - $this->accessLifeTime)) . "'");
				$processedFd = [];
				while($row = mysqli_fetch_assoc($r)) {
					
					$fd = \MadxWS\DB::result(\MadxWS\DB::query($sql="SELECT fd FROM madxws_db.fd WHERE access_id='".\MadxWS\DB::esc($row['access_id'])."'"),0,0);
					$processedFd[] = $fd;
					
					if ($this->server->isEstablished($fd)) {
						$this->closeConn($fd,4002);
					}
					
					\MadxWS\DB::query("DELETE FROM madxws_db.fd WHERE fd='".\MadxWS\DB::esc($fd)."'");
					\MadxWS\DB::query("DELETE FROM madxws_db.tokens WHERE access_id='".\MadxWS\DB::esc($row['access_id'])."'");
				}
				
				//process idle connection
				
				foreach($this->server->connections as $fd) {
					
					if (!in_array($fd, $processedFd)) {
						$accessId = (int)@\MadxWS\DB::result(\MadxWS\DB::query($sql="SELECT access_id FROM madxws_db.fd WHERE fd='".\MadxWS\DB::esc($fd)."'"),0,0);
						
						if (!$accessId) {//continue if active fd not found
							if ($this->server->isEstablished($fd)) {//if is ws conn, close it
								$this->closeConn($fd,4003);
							} else {
								$connInfo = $this->server->connection_info($fd);
							
								//if relate ws conn, wait and close
								if ($connInfo['websocket_status'] == WEBSOCKET_STATUS_HANDSHAKE  OR $connInfo['websocket_status'] == WEBSOCKET_STATUS_CONNECTION ) {
									if (!isset($idleWSConns[$fd])) {
										$idleWSConns[$fd] = 1;
									} else {
										$idleWSConns[$fd]++;
									}
									
									if ($idleWSConns[$fd] > 3) {
										$this->closeConn($fd,4003);
										unset($idleWSConns[$fd]);
									}
								} else {
									//recording weird conn for inspection,might be http or tcp
									$httpConns++;
									
									if (!isset($idleOTHConns[$fd])) {
										$idleOTHConns[$fd] = 1;
									} else {
										$idleOTHConns[$fd]++;
									}
									
									if ($idleOTHConns[$fd] > 3) {
										$this->server->close($fd,true);
										unset($idleOTHConns[$fd]);
									}
								}
							}
						}
					}
				}
				
				if ($httpConns) {
					#echo "Http Connections: {$httpConns}\n";
				}
				sleep(1);//trigger every second
			}
		
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}	
	
	public function onManagerStart(Swoole\Server $server) {
		echo "Server: on manager start.\n";
		swoole_set_process_name("php " .implode(" ", $this->argv) . " [MANAGER]");
	}

	public function onWorkerStart(Swoole\Server $server, int $worker_id) {
		echo "Server: on worker#{$worker_id} start.\n";
		swoole_set_process_name("php " .implode(" ", $this->argv) . " [WORKER#{$worker_id}]");
		
		try {
			$conn_result = \MadxWS\DB::connect(DB_HOST,DB_USER,DB_PASS,DB_NAME);
		
			if (!$conn_result['status']) {
				throw new Exception($conn_result['errmsg']);
			}
			
			$server->MYSQL_CONN = \MadxWS\DB::$conn; //$server and $this->server are equipvalent
			
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}
	
	public function closeConn(int $fd, int $code) {
		if (!$this->server->disconnect($fd, $code, $this->codeReason[$code])) {
			$this->server->close($fd,true);
		}
	}
	
	public function pushMessage(int $fd, string $msg) {
		$respFrames = str_split($msg, $this->maxBytesPerFrameBody);
		$totalRespFrames = count($respFrames);
		
		foreach($respFrames as $k=>$respFrame) {
			$lastFrame = ($k + 1 == $totalRespFrames);
			$firstFrame = !$k;
			
			$newFrame = new Swoole\WebSocket\Frame();
			$newFrame->data = $respFrame;
			if ($lastFrame) {
				$newFrame->opcode = $totalRespFrames == 1 ? WEBSOCKET_OPCODE_TEXT : 0x0 /*Continue from previous frame*/;
				$newFrame->finish = true;
			} else {
				$newFrame->opcode = $firstFrame ? WEBSOCKET_OPCODE_TEXT : 0x0;
				$newFrame->finish = false;
			}
			
			$pushResult = $this->server->push($fd, $newFrame);
			#echo "Server push frame#{$k} to client#{$fd}: " . ($push_result ? "success" : "failure") . "\n";
			
			if (!$pushResult) {
				return false;
			}
		}
		
		return true;
	}
	
	public function onHandShake(Swoole\Http\Request $request, Swoole\Http\Response $response) {
		
		try {
			
			$hsRejectCode = 4005;
			\MadxWS\DB::$conn = $this->server->MYSQL_CONN;
			
			#echo "Server: handshake success with request info - ".print_r($request,true).", response info - ".print_r($response,true)."\n";

			$gets = $request->get;
			unset($gets['sign']);
			$unsignStr = implode("",$gets);
			$errno = 0;
			
			
			//check is signature used?
			if (mysqli_num_rows(\MadxWS\DB::query("SELECT * FROM madxws_db.used_wsconn_sign WHERE sign_str='".\MadxWS\DB::esc($request->get['sign'])."'"))) {
				$errno = 401;
			//check access id
			} else if (!mysqli_num_rows($r = \MadxWS\DB::query($sql = "SELECT * FROM madxws_db.tokens WHERE access_id='".\MadxWS\DB::esc($request->get['access_id'])."' LIMIT 1"))) {
				$errno = 400;
			} else if (!($rToken = mysqli_fetch_assoc($r))) {
				$errno = 400;
			} else if (!mysqli_num_rows($r = \MadxWS\DB::query($sql = "SELECT * FROM madxws_db.api WHERE api_id='".\MadxWS\DB::esc($rToken['api_id'])."' LIMIT 1"))) {
				$errno = 409;
			} else if (!($rApi = mysqli_fetch_assoc($r))) {
				$errno = 409;
			} else if (!mysqli_num_rows($r = \MadxWS\DB::query($sql = "SELECT * FROM madxws_db.account WHERE user_id='".\MadxWS\DB::esc($rToken['user_id'])."' LIMIT 1"))) {
				$errno = 415;
			} else if (!($rAccount = mysqli_fetch_assoc($r))) {
				$errno = 415;
			//prevent data tamper
			} else if (md5($unsignStr . $rApi['secret']) != $request->get['sign']) {
				$errno = 403;
			} else if (strtotime($rToken['expiry_date']) + $this->accessLifeTime <= time()) {
				$errno = 402;
			
			} else if ($totalCurrConn = \MadxWS\DB::result(\MadxWS\DB::query("SELECT COUNT(*) FROM madxws_db.fd WHERE user_id='".\MadxWS\DB::esc($rAccount['user_id'])."' LIMIT 1"),0,0) AND $totalCurrConn >= $rAccount['var_max_conn']) {
				$errno = 414;
				$hsRejectCode = 4007;
			} else if ($rAccount['status_msg_day'] >= $rAccount['var_msg_day']) {
				$errno = 416;
				$hsRejectCode = 4008;
			} else {	
				
				#openssl_private_decrypt(hex2bin($request->get['verify']), $code, hex2bin($rToken['private_key']));
				$code = $request->get['verify'];
				$oAuth = new \MadxWS\Auth();
				if (!$oAuth->verifyCode($rToken['secret_seed'], $code)) {
					$errno = 404;
				} else {
					\MadxWS\DB::query("INSERT INTO madxws_db.used_wsconn_sign SET sign_str='".\MadxWS\DB::esc($request->get['sign'])."'");
					
					// websocket握手连接算法验证
					$secWebSocketKey = $request->header['sec-websocket-key'];
					$patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';
					if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
						$errno = 405;
					}
				}
			}
			
			if ($hsRejectCode==4007) {
				\MadxWS\DB::query("UPDATE madxws_db.account SET status_max_conn_day='".\MadxWS\DB::esc($rAccount['var_max_conn'])."', status_reach_max_conn=status_reach_max_conn+1 WHERE user_id='".\MadxWS\DB::esc($rAccount['user_id'])."'");
			} else if ($totalCurrConn+1 > $rAccount['status_max_conn_day']) {
				\MadxWS\DB::query("UPDATE madxws_db.account SET status_max_conn_day='".\MadxWS\DB::esc($totalCurrConn+1)."' WHERE user_id='".\MadxWS\DB::esc($rAccount['user_id'])."'");
			}
			
			if ($rToken['access_id'] > 0) {
				\MadxWS\DB::query($sql="UPDATE madxws_db.tokens SET expiry_date='".\MadxWS\DB::esc(date("Y-m-d H:i:s"))."', handshake_errno='".\MadxWS\DB::esc($errno)."', client_ip='".\MadxWS\DB::esc($request->header['cf-connecting-ip'])."', client_ua='".\MadxWS\DB::esc($request->header['user-agent'])."' WHERE access_id='".\MadxWS\DB::esc($rToken['access_id'])."'");
			}
			
			if ($errno > 0) {
				/*in firefox, use closeconn() explicitly to close conn quickly, while chrome actually need not to do this*/
				$this->closeConn($request->fd, $hsRejectCode);
				$response->end();
				return false;
			}
			
			$key = base64_encode(sha1($request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
			
			$headers = [
				'Upgrade' => 'websocket',
				'Connection' => 'Upgrade',
				'Sec-WebSocket-Accept' => $key,
				'Sec-WebSocket-Version' => '13',
			];
			
			// failed: Error during WebSocket handshake:
			// Response must not include 'Sec-WebSocket-Protocol' header if not present in request: websocket
			if (isset($request->header['sec-websocket-protocol'])) {
				$headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
			}

			foreach ($headers as $key => $val) {
				$response->header($key, $val);
			}
			
			\MadxWS\DB::query("UPDATE madxws_db.fd SET access_id=NULL,user_id=0 WHERE access_id='".\MadxWS\DB::esc($request->get['access_id'])."'");
			
			\MadxWS\DB::query($sql="INSERT INTO madxws_db.fd (fd, access_id,user_id,api_id) VALUES ('".\MadxWS\DB::esc($request->fd)."','".\MadxWS\DB::esc($request->get['access_id'])."','".\MadxWS\DB::esc($rAccount['user_id'])."','".\MadxWS\DB::esc($rApi['api_id'])."') ON DUPLICATE KEY UPDATE api_id='".\MadxWS\DB::esc($rApi['api_id'])."', user_id='".\MadxWS\DB::esc($rAccount['user_id'])."', access_id='".\MadxWS\DB::esc($request->get['access_id'])."'");
			
			$response->status(101);
			$response->end();
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}
	
	public function onOpen(Swoole\Websocket\Server $server, Swoole\Http\Request $request) {
		echo "Server: open handshake success with request info - ".print_r($request,true)."\n";
	}
	
	public function onMessage(Swoole\Websocket\Server $server, Swoole\Websocket\Frame $frame) {
		try {
			#echo "Server: receive info - " . print_r($frame,true)." (mb_len=".mb_strlen($frame->data). ", bytes_len=". strlen($frame->data).")\n";
			\MadxWS\DB::$conn = $this->server->MYSQL_CONN;
			
			if ($server->isEstablished($frame->fd)) {
					
				if ($frame->opcode == 0x8) {
					/*
					If an endpoint receives a Close frame and did not previously send a Close frame, the endpoint MUST send a Close frame in response.
					
					The server MUST close the underlying TCP connection immediately;
					*/
					echo "Close frame#{$frame->fd} received: Code {$frame->code} Reason {$frame->reason}\n";
					\MadxWS\DB::query($sql="UPDATE madxws_db.fd SET access_id=NULL,user_id=0 WHERE fd='".\MadxWS\DB::esc($frame->fd)."'");
					
					$this->closeConn($frame->fd, 4001);
				} else {
					
					//madxws doesnt support message except opcode 0x8 (close frame) 0x9 (ping)
					
					$this->closeConn($frame->fd, 4004);
					#$this->pushMessage($frame->fd, $frame->data);
				}
			} else {
				$this->closeConn($fd,4006);
				\MadxWS\DB::query($sql="UPDATE madxws_db.fd SET access_id=NULL,user_id=0 WHERE fd='".\MadxWS\DB::esc($frame->fd)."'");
			}
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}
	
	public function onClose(Swoole\Server $server, int $fd) {
		try {
			\MadxWS\DB::query($sql="UPDATE madxws_db.fd SET access_id=NULL,user_id=0 WHERE fd='".\MadxWS\DB::esc($fd)."'");
			//echo "Server: client#{$fd} has been closed\n";
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}

	//http request handler
	public function onRequest(Swoole\Http\Request $request, Swoole\Http\Response $response) {
		try {
			
			//reject one of twice call from chrome
			$uri = $request->server['request_uri'];
			if ($uri == '/favicon.ico') {
				$response->status(404);
				$response->end();
			}
			
			\MadxWS\DB::$conn = $this->server->MYSQL_CONN;
			#echo "Server: http request info - ".print_r($request,true).", http response info - ".print_r($response,true)."\n";

			$gets = $request->get;
			unset($gets['sign']);
			$unsignStr = implode("",$gets);
			
			$httpStatusCode = 200;
			$end = "";
			$errno = 0;
			$respParam = new \stdClass; 
			
			if ($request->get['query'] == 'get_ws_token') {
				
				$sql = "SELECT * FROM madxws_db.api WHERE api_key='".\MadxWS\DB::esc($request->get['api_key'])."' LIMIT 1";

				if (!mysqli_num_rows($r = \MadxWS\DB::query($sql))) {
					$errno = 409;
				} else if (!($rApi = mysqli_fetch_assoc($r))) {
					$errno = 409;
				} else if (md5($unsignStr . $rApi['secret']) != $request->get['sign']) {
					$errno = 403;
				} else if (!isInteger($request->get['field1']) OR $request->get['field1'] <= 0) {
					$errno = 406;
				} else if (strlen($request->get['field2']) >0 AND (!isInteger($request->get['field2']) OR $request->get['field2'] <= 0) ) {
					$errno = 407;
				} else if (strlen($request->get['field3']) >0 AND (!isInteger($request->get['field3']) OR $request->get['field3'] <= 0) ) {
					$errno = 408;
				} else if (strlen($request->get['field3']) >0 AND (!$request->get['field2'] or !$request->get['field1'])) {
					$errno = 411;
				} else if (strlen($request->get['field2']) >0 AND (!$request->get['field1'])) {
					$errno = 412;
				} else if (!mysqli_num_rows($r = \MadxWS\DB::query($sql = "SELECT * FROM madxws_db.account WHERE user_id='".\MadxWS\DB::esc($rApi['user_id'])."' LIMIT 1"))) {
					$errno = 415;
				} else if (!($rAccount = mysqli_fetch_assoc($r))) {
					$errno = 415;
				} else if ($rAccount['status_msg_day'] >= $rAccount['var_msg_day']) {
					$errno = 416;
				} else {

					$api_id = $rApi['api_id'];
					
					/*
					$config = array(
						"digest_alg" => "MD5",
						"private_key_bits" => 384 ,
						"private_key_type" => OPENSSL_KEYTYPE_RSA,
					);
						
					// Create the private and public key
					$res = openssl_pkey_new($config);

					// Extract the private key from $res to $privKey
					openssl_pkey_export($res, $privKey);

					// Extract the public key from $res to $pubKey
					$pubKey = openssl_pkey_get_details($res);
					$pubKey = $pubKey["key"];
					$pubKey = implode(unpack('H*', $pubKey));
					$privKey = implode(unpack('H*', $privKey));
					*/

					$oAuth = new \MadxWS\Auth();
					
					$secretSeed = $oAuth->createSecret();
					\MadxWS\DB::query("INSERT INTO madxws_db.tokens SET 
								   expiry_date='".\MadxWS\DB::esc(date("Y-m-d H:i:s"))."', 
								   api_id='".\MadxWS\DB::esc($rApi['api_id'])."', 
								   user_id='".\MadxWS\DB::esc($rApi['user_id'])."',
								   secret_seed='".\MadxWS\DB::esc($secretSeed)."', 
								   field1='".\MadxWS\DB::esc($request->get['field1'])."',
								   field2='".\MadxWS\DB::esc($request->get['field2'])."',
								   field3='".\MadxWS\DB::esc($request->get['field3'])."'
							  ");
					$accessId = \MadxWS\DB::insertID();
					
					#$respParam->pubkey = $pubKey;
					$respParam->secret_seed = $secretSeed;
					$respParam->access_id = $accessId;
					
				}

			} else if ($request->get['query'] == 'push_msg') {
				$sql = "SELECT * FROM madxws_db.api WHERE api_key='".\MadxWS\DB::esc($request->get['api_key'])."' LIMIT 1";
					
				if (!mysqli_num_rows($r = \MadxWS\DB::query($sql))) {
					$errno = 409;
				} else if (!($rApi = mysqli_fetch_assoc($r))) {
					$errno = 409;
				} else if (md5($unsignStr . $rApi['secret']) != $request->get['sign']) {
					$errno = 403;
				} else if (strlen($request->get['msg']) > 1000) {
					$errno = 413;
				} else if (!mysqli_num_rows($r = \MadxWS\DB::query($sql = "SELECT * FROM madxws_db.account WHERE user_id='".\MadxWS\DB::esc($rApi['user_id'])."' LIMIT 1"))) {
					$errno = 415;
				} else if (!($rAccount = mysqli_fetch_assoc($r))) {
					$errno = 415;
				} else if ($rAccount['status_msg_day'] >= $rAccount['var_msg_day']) {
					$errno = 416;
				} else {
					\MadxWS\DB::query("UPDATE madxws_db.account SET status_msg_day=status_msg_day+1 WHERE user_id='".\MadxWS\DB::esc($rApi['user_id'])."'");
					
					\MadxWS\DB::query("UPDATE madxws_db.api SET status_msg_day=status_msg_day+1 WHERE api_id='".\MadxWS\DB::esc($rApi['api_id'])."'");
					
					$checkFields = array();
					if (is_numeric($request->get['field1'])) {
						$checkFields[]  = "field1='".\MadxWS\DB::esc($request->get['field1'])."'";
					}
					
					if (is_numeric($request->get['field2'])) {
						$checkFields[]  = "field2='".\MadxWS\DB::esc($request->get['field2'])."'";
					}
					
					if (is_numeric($request->get['field3'])) {
						$checkFields[]  = "field3='".\MadxWS\DB::esc($request->get['field3'])."'";
					}
					
					$sql = "SELECT * FROM madxws_db.tokens WHERE ".implode(" AND ", $checkFields)." AND api_id='".\MadxWS\DB::esc($rApi['api_id'])."'";
					$r = \MadxWS\DB::query($sql);
					while($row = mysqli_fetch_assoc($r)) {
						
						$fd = \MadxWS\DB::result(\MadxWS\DB::query("SELECT * FROM madxws_db.fd WHERE access_id='".\MadxWS\DB::esc($row['access_id'])."' LIMIT 1"),0,0);
						
						if ($this->server->isEstablished($fd)) {
							
							$pushResult = $this->pushMessage($fd,$request->get['msg']);
						
							if ($pushResult) {
								\MadxWS\DB::query($sql="UPDATE madxws_db.tokens SET expiry_date='".\MadxWS\DB::esc(date("Y-m-d H:i:s"))."' WHERE access_id='".\MadxWS\DB::esc($row['access_id'])."'");
							}
						} else {
							$this->closeConn($fd,4006);
							\MadxWS\DB::query("UPDATE madxws_db.fd SET access_id=NULL,user_id=0 WHERE access_id='".\MadxWS\DB::esc($row['access_id'])."'");
						}
					}
				}
				
				if ($errno == 416) {
					\MadxWS\DB::query("UPDATE madxws_db.account SET status_reach_msg_day=status_reach_msg_day+1 WHERE user_id='".\MadxWS\DB::esc($rApi['user_id'])."'");
				}
			} else if ($request->get['query'] == 'get_api') {
			
			} else if ($request->get['query'] == 'show_all_conn_info') {
				
			} else {
				$errno = 410;
			}
			
			$respParam->errno = $errno;
			
			$end = json_encode($respParam);
			$response->status($httpStatusCode);
			$response->header('content-type', 'application/json', true);
			$response->end($end);
			
		} catch(mysqli_sql_exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		} catch(Exception $e) {
			emailMe(PROJECT_NAME . " [{$_SERVER['SERVER_ADDR']}] - SW ".__FUNCTION__ ,print_r($e,true));
			echo ("[SW ".__FUNCTION__ ."] " . $e->getMessage()) . "\n";
			\MadxWS\DB::rollback();
		}
	}

}
