<?php

namespace MadxPusher;

class MadxPusher_Client {
	
	private $api_key;
	private $api_secret;
	private $api_host;
	private $ws_host;
	private $secure;
	
	public function __construct($api_host, $api_key, $api_secret, $ws_host = null,$secure = true) {
		$this->api_host = $api_host;
		$this->api_key = $api_key;
		$this->api_secret = $api_secret;
		$this->ws_host = $ws_host ? $ws_host : $api_host;
		$this->secure = $secure;
	}
	
	/**
     * Get MadxPusher's token via http request
     *
     * @return array
     */
	public function get_ws_token($field1,$field2="",$field3="") {
		
		
		$param = array('query'=>'get_ws_token','api_key'=>$this->api_key,'field1'=>$field1);
		
		if (strlen($field2) > 0) {
			$param['field2'] = $field2;
		}
		
		if (strlen($field3) > 0) {
			$param['field3'] = $field3;
		}
		
		$param['sign'] = md5(implode("", $param) . $this->api_secret);
		
		$protocol = $this->secure ? "https://" : "http://";
		$http_result = $this->call_http_api($api_url = $protocol.$this->api_host."/?".http_build_query($param));
		
		if ($http_result['header']['http_code']!='200') {
			$status = false;
			$error = "Invalid http status code ({$http_result['header']['http_code']})";
		} else if (!($token = json_decode($http_result['html']))  OR json_last_error()!=JSON_ERROR_NONE) {
			$status = false;
			$error = "Invalid json string";
		} else if ($token->errno > 0) {
			$status = false;
			$error = "Error found (Code: {$token->errno})";
		} else {
			$status = true;
			
			$secret_seed  = $token->secret_seed;
			$access_id    = $token->access_id;
		
			$ws_token = $access_id . ":" . $secret_seed;
		}
		
		return array("error"=>$error,"status"=> $status, "ws_token"=> $ws_token);
	}
	
	/**
     * Push message into MadxPusher
     *
	 * @param string $msg
     * @return array
     */
	public function push($msg,$field1, $field2="", $field3="") {
		
		$param = array('query'=>'push_msg','api_key'=>$this->api_key,"msg"=>$msg, 'field1'=>$field1);
		
		if (strlen($field2) > 0) {
			$param['field2'] = $field2;
		}
		
		if (strlen($field3) > 0) {
			$param['field3'] = $field3;
		}
		
		$param['sign'] = md5(implode("", $param) . $this->api_secret);
		
		$protocol = $this->secure ? "https://" : "http://";
		$http_result = $this->call_http_api($api_url = $protocol.$this->api_host."/?".http_build_query($param));
		
		if ($http_result['header']['http_code']!='200') {
			$status = false;
			$error = "Invalid http status code ({$http_result['header']['http_code']})";
		} else if (!($push_result = json_decode($http_result['html']))  OR json_last_error()!=JSON_ERROR_NONE) {
			$status = false;
			$error = "Invalid json string";
		} else if ($push_result->errno > 0) {
			$status = false;
			$error = "Error found (Code: {$push_result->errno})";
		} else {
			$status = true;
		}
		
		return array("error"=>$error,"status"=> $status);
		
	}
	
	/**
     * Generate connection string for javascript web socket
     *
	 * @param string $ws_token
     * @return array
     */
	public function get_ws_conn($ws_token) {
		$o_auth = new Auth();
	
		list($access_id, $secret_seed) = explode(":", $ws_token);
		$code = $o_auth->getCode($secret_seed);
		
		$encrypted = null;
	
		$param = array('access_id'=>$access_id,'verify'=>$code);
		$param['sign'] = md5(implode("", $param) . $this->api_secret);
	
		$ws_qs = http_build_query($param);
		$protocol = $this->secure ? "wss://" : "ws://";
		return $protocol.$this->ws_host."/?{$ws_qs}";
	}
	
	/**
     * Http client
     *
	 * @param string $url
     * @return array
     */
	public function call_http_api($url) {
		$user_agent='Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0';

		$options = array(
			/*CURLOPT_SSL_VERIFYPEER => false, *//*dirty hack*/
			CURLOPT_CUSTOMREQUEST  =>"GET",        //set request type post or get
			CURLOPT_POST           =>false,        //set to GET
			CURLOPT_USERAGENT      => $user_agent, //set user agent
			CURLOPT_RETURNTRANSFER => true,     // return web page
			CURLOPT_HEADER         => false,    // don't return headers
			CURLOPT_FOLLOWLOCATION => true,     // follow redirects
			CURLOPT_ENCODING       => "",       // handle all encodings
			CURLOPT_AUTOREFERER    => true,     // set referer on redirect
			CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
			CURLOPT_TIMEOUT        => 120,      // timeout on response
			CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
		);

		$ch      = curl_init( $url );
		curl_setopt_array( $ch, $options );
		$html = curl_exec( $ch );
		$curl_errno     = curl_errno( $ch );
		$curl_error  = curl_error( $ch );
		$header  = curl_getinfo( $ch );
		curl_close( $ch );

		return array("header"=>$header, "curl_errno"=>$curl_errno, "curl_error"=>$curl_error, "html"=>$html);
	}
}

/**
 * PHP Class for handling Google Authenticator 2-factor authentication
 *
 * @author Michael Kliewe
 * @copyright 2012 Michael Kliewe
 * @license http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link http://www.phpgangsta.de/
	
    Modification
	------------
	Replace all time() to gmTime() @Chiew, 2019-04-01
*/

class Auth {
    protected $_codeLength = 6;

	public function __construct() { }
	
	protected function gmTime() {
		return strtotime(gmdate("Y:m:d H:i:s"));
	}
	
    /**
     * Create new secret.
     * 16 characters, randomly chosen from the allowed base32 characters.
     *
     * @param int $secretLength
     * @return string
     */
	 
    public function createSecret($secretLength = 16)
    {
        $validChars = $this->_getBase32LookupTable();
        unset($validChars[32]);

        $secret = '';
        for ($i = 0; $i < $secretLength; $i++) {
            $secret .= $validChars[array_rand($validChars)];
        }
        return $secret;
    }

    /**
     * Calculate the code, with given secret and point in time
     *
     * @param string $secret
     * @param int|null $timeSlice
     * @return string
     */
    public function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor($this->gmTime() / 30);
        }

        $secretkey = $this->_base32Decode($secret);

        // Pack time into binary string
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);
        // Hash it with users secret key
        $hm = hash_hmac('SHA1', $time, $secretkey, true);
        // Use last nipple of result as index/offset
        $offset = ord(substr($hm, -1)) & 0x0F;
        // grab 4 bytes of the result
        $hashpart = substr($hm, $offset, 4);

        // Unpak binary value
        $value = unpack('N', $hashpart);
        $value = $value[1];
        // Only 32 bits
        $value = $value & 0x7FFFFFFF;

        $modulo = pow(10, $this->_codeLength);
        return str_pad($value % $modulo, $this->_codeLength, '0', STR_PAD_LEFT);
    }

    /**
     * Get QR-Code URL for image, from google charts
     *
     * @param string $name
     * @param string $secret
     * @param string $title
     * @return string
     */
    public function getQRCodeGoogleUrl($name, $secret, $title = null) {
        $urlencoded = urlencode('otpauth://totp/'.$name.'?secret='.$secret.'');
		if(isset($title)) {
            $urlencoded .= urlencode('&issuer='.urlencode($title));
        }
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl='.$urlencoded.'';
    }

    /**
     * Check if the code is correct. This will accept codes starting from $discrepancy*30sec ago to $discrepancy*30sec from now
     *
     * @param string $secret
     * @param string $code
     * @param int $discrepancy This is the allowed time drift in 30 second units (8 means 4 minutes before or after)
     * @param int|null $currentTimeSlice time slice if we want use other that time()
     * @return bool
     */
    public function verifyCode($secret, $code, $discrepancy = 1, $currentTimeSlice = null)
    {
        if ($currentTimeSlice === null) {
            $currentTimeSlice = floor($this->gmTime() / 30);
        }

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if ($calculatedCode == $code ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the code length, should be >=6
     *
     * @param int $length
     * @return GoogleAuthenticator
     */
    public function setCodeLength($length)
    {
        $this->_codeLength = $length;
        return $this;
    }

    /**
     * Helper class to decode base32
     *
     * @param $secret
     * @return bool|string
     */
    protected function _base32Decode($secret)
    {
        if (empty($secret)) return '';

        $base32chars = $this->_getBase32LookupTable();
        $base32charsFlipped = array_flip($base32chars);

        $paddingCharCount = substr_count($secret, $base32chars[32]);
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues)) return false;
        for ($i = 0; $i < 4; $i++){
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat($base32chars[32], $allowedValues[$i])) return false;
        }
        $secret = str_replace('=','', $secret);
        $secret = str_split($secret);
        $binaryString = "";
        for ($i = 0; $i < count($secret); $i = $i+8) {
            $x = "";
            if (!in_array($secret[$i], $base32chars)) return false;
            for ($j = 0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= ( ($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48 ) ? $y:"";
            }
        }
        return $binaryString;
    }

    /**
     * Helper class to encode base32
     *
     * @param string $secret
     * @param bool $padding
     * @return string
     */
    protected function _base32Encode($secret, $padding = true)
    {
        if (empty($secret)) return '';

        $base32chars = $this->_getBase32LookupTable();

        $secret = str_split($secret);
        $binaryString = "";
        for ($i = 0; $i < count($secret); $i++) {
            $binaryString .= str_pad(base_convert(ord($secret[$i]), 10, 2), 8, '0', STR_PAD_LEFT);
        }
        $fiveBitBinaryArray = str_split($binaryString, 5);
        $base32 = "";
        $i = 0;
        while ($i < count($fiveBitBinaryArray)) {
            $base32 .= $base32chars[base_convert(str_pad($fiveBitBinaryArray[$i], 5, '0'), 2, 10)];
            $i++;
        }
        if ($padding && ($x = strlen($binaryString) % 40) != 0) {
            if ($x == 8) $base32 .= str_repeat($base32chars[32], 6);
            elseif ($x == 16) $base32 .= str_repeat($base32chars[32], 4);
            elseif ($x == 24) $base32 .= str_repeat($base32chars[32], 3);
            elseif ($x == 32) $base32 .= $base32chars[32];
        }
        return $base32;
    }

    /**
     * Get array with all 32 characters for decoding from/encoding to base32
     *
     * @return array
     */
    protected function _getBase32LookupTable()
    {
        return array(
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
            'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
            'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
            'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
            '='  // padding char
        );
    }
}
