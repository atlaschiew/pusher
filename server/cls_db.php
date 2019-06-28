<?php
namespace MadxWS;

class DB {
	public static $conn = null;
	public static $host = null;
	public static $user = null;
	public static $pass = null;
	public static $dbname = null;
	
	public static function connect($host, $user, $pass, $dbname = null) {
		
		self::$host = $host;
		self::$user = $user;
		self::$pass = $pass;
		self::$dbname = $dbname;
		
		self::$conn = mysqli_connect($host, $user, $pass, $dbname);
		if (mysqli_connect_errno()) {
			$errmsg .= ("Failed to connect to MySQL: " . mysqli_connect_error());
		} else if (!mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)) {
			$errmsg .= ("Failed to set mysqli report");
		} else if (!mysqli_autocommit(self::$conn, TRUE)) {
			$errmsg .= ("Failed to set mysqli autocommit");
		} else if (!mysqli_set_charset(self::$conn,"utf8")) {
			$errmsg .= ("Failed to set charset");
		}
		$status = $errmsg ? false : true;
		return array("status"=>$status, "errmsg"=>$errmsg);
	}
	
	public static function close() {
		mysqli_close(self::$conn);
	}
	
	
	public static function result($res,$row=0,$col=0){ 
		$numrows = mysqli_num_rows($res); 
		if ($numrows && $row <= ($numrows-1) && $row >=0){
			mysqli_data_seek($res,$row);
			$resrow = (is_numeric($col)) ? mysqli_fetch_row($res) : mysqli_fetch_assoc($res);
			if (isset($resrow[$col])){
				return $resrow[$col];
			}
		}
		return false;
	}
	
	public static function esc($str) {
		return mysqli_real_escape_string(self::$conn,$str);
	}
	
	public static function errno() {
		$errno = mysqli_errno(self::$conn);
		return $errno;
	}
	
	public static function error($sql) {
		$err = mysqli_error(self::$conn);
		if ($err) {
			return "{$sql}, Error: {$err}";
		} else {
			return "";
		}
	}
	
	public static function beginTransaction() {
		$result = mysqli_begin_transaction(self::$conn);
		
		if (!$result) {
			//throw new mysqli_sql_exception("Unknown reason: begintransaction failure");
		}
	}
	
	public static function rollback() {
		$result = mysqli_rollback(self::$conn);
		if (!$result) {
			//throw new mysqli_sql_exception("Unknown reason: rollback failure");
		}
	}
	
	public static function commit() {
		$result = mysqli_commit(self::$conn);
		if (!$result) {
			//throw new mysqli_sql_exception("Unknown reason: commit failure");
		}
	}
	
	public static function affectedRows() {
		
		$r = mysqli_affected_rows(self::$conn);
		
		return $r;
	}
	
	public static function query($sql) {
		
		try {
			$r = mysqli_query(self::$conn,$sql);
		} catch(mysqli_sql_exception $e) {
			$errno = self::errno();
			
			if ($errno == 2006 or $errno == 2013 ) {
				$conn_result = self::connect(self::$host, self::$user, self::$pass, self::$dbname);

				if (!$conn_result['status']) {
					throw new mysqli_sql_exception($e->getMessage()." (Caught Mysqli Errno: {$errno})");
				} else {
					$r = mysqli_query(self::$conn,$sql);
				}
			} else {
				throw new mysqli_sql_exception($e->getMessage()." (Caught Mysqli Errno: {$errno})");
			}
		}
		
		return $r;
	}
	
	public static function multiQuery($sql) {
		
		$r = mysqli_multi_query(self::$conn,$sql);
		
		do { 
			mysqli_use_result(self::$conn);
		}while( mysqli_more_results(self::$conn) && mysqli_next_result(self::$conn) );
		
		return $r;
	}
	
	public static function insertID() {
		$insert_id = mysqli_insert_id(self::$conn);

		return $insert_id;
	}
}
