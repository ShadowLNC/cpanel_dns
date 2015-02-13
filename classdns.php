<?php
//Code by Scott Stevens
//Last Edited 21 December 2012
//This code is in the public domain

//While not required (there can be no requirements in the
//public domain) I ask that you please include this
//information in any derivative works, along with a summary
//of the modifications made.


class zone_records {
	private $auth = "";
	private $connection = "";
	private $basequery = "";
	private $zonedomain = "";
	private $DNSrecords = array();
	
	public function __construct($user, $pass, $location, $domain) {
		$this->auth = "Authorization: Basic ".base64_encode($user.":".$pass);
		$this->connection = $location;
		$this->basequery = "https://".$location.":2083/json-api/cpanel?";
		$this->zonedomain = $domain;
		$this->updaterecords(); //get new records
	}
	
	public function __get($var) {
		return ($var=="DNSrecords" ? $this->DNSrecords : NULL);
	}
	
	public function doquery($function, $params, $headers=array()) {
		//query the cpanel server and do the query's bidding
		$curl = curl_init();
		$init_params = array( "cpanel_jsonapi_module" => "ZoneEdit", "cpanel_jsonapi_func" => $function, "cpanel_jsonapi_version" => "2", "domain" => $this->zonedomain );
		$query = $this->basequery.http_build_query($init_params)."&".http_build_query($params);
		$headers[] = $this->auth;
		$options = array(	CURLOPT_URL				=> $query,
							CURLOPT_SSL_VERIFYPEER 	=> 0,		//Allow self-signed cert :P
							CURLOPT_SSL_VERIFYHOST 	=> 0,		//Allow cert hostname mismatch
							CURLOPT_HEADER			=> 0,		//Output: Header not included
							CURLOPT_RETURNTRANSFER	=> 1,		//Output: Contents included
							CURLOPT_HTTPHEADER		=> $headers	//Auth
							);
		curl_setopt_array($curl, $options);
		$result = curl_exec($curl);
		
		if ($result === false) { throw new Exception("cURL Execution Error ".curl_error($curl)." in $query", 0); } //error handling for failure
		curl_close($curl);
		return json_decode($result, true);
	}
	
	public function updaterecords() {
		//A RECORDS
		$records = $this->doquery("fetchzone", array(), array());
		if ($records["cpanelresult"]["data"][0]["status"]!=1 || !array_key_exists("record", $records["cpanelresult"]["data"][0])) {
			throw new Exception("Bad zonefile result ".var_export($records, true), 404); }
		$records = $records["cpanelresult"]["data"][0]["record"];
		//now start sorting records
		
		$newrecords = array();
		foreach ($records as $i) {
			if ($i["type"]=="A") {
				//MUST MATCH: address==record, class==IN, **type==A, line==Line
				if ($i["address"]!=$i["record"] || $i["Line"]!=$i["line"] || $i["class"]!="IN" || array_key_exists($i["line"], $newrecords)) {
					throw new Exception("Bad A record ".var_export($i, true), 404); }
			$newrecords[$i["line"]] = new DNSrecord("A", $i["name"], $i["record"], $i["line"], $i["ttl"], $this);
			}
			if ($i["type"]=="CNAME") {
				//MUST MATCH: cname==record, class==IN, **type==CNAME, line==Line
				if ($i["cname"]!=$i["record"] || $i["Line"]!=$i["line"] || $i["class"]!="IN" || array_key_exists($i["line"], $newrecords)) {
					throw new Exception("Bad CNAME record ".var_export($i, true), 404); }
			$newrecords[$i["line"]] = new DNSrecord("CNAME", $i["name"], $i["record"], $i["line"], $i["ttl"], $this);
			}
		}
		
		$this->DNSrecords = array();
		foreach ($newrecords as $key => $value) { $this->DNSrecords[$key] = $value; }
	}
	
	public function addrecord($type, $name, $target, $ttl) {
		if ($type!="A" && $type!="CNAME") {
			throw new Exception("Invalid type '$type'", 0); }
		if (!preg_match("/^([a-zA-Z0-9\-]+\.)*".preg_quote($this->zonedomain)."\.$/", $name)) {
			throw new Exception("Invalid name '$name'", 0); }
		if (!is_numeric($ttl)) {
			throw new Exception("Invalid TTL '$ttl'", 0); }
		if (($type=="A" && !preg_match(DNSrecord::$valid_ip, $target)) || ($type=="CNAME" && !preg_match(DNSrecord::$valid_domain, $target))) {
			throw new Exception("Invalid target '$target'", 0); }
		
		$targetname = ($type=="A" ? "address" : ($type=="CNAME" ? "cname" : "txtdata"));
		$params = array(	"type"		=> $type,
							"name"		=> $name,
							$targetname	=> $target,
							"ttl"		=> $ttl,
							"class"		=> DNSrecord::$class);
		$result = $this->doquery("add_zone_record", $params, array());
		$this->updaterecords();
		return $result; //todo: check status?
	}
	
	public function deleterecord($line) {
		if (!array_key_exists($line, $this->DNSrecords)) { throw new Exception("Record does not exist", 0); }
		$result = $this->doquery("remove_zone_record", array("line" => $line), array());
		$this->updaterecords();
		return $result; //todo: check status?
	}
}

/********************************************************************************/

class DNSrecord {
	public static $class = "IN";
	public static $valid_ip = "/^(((((1[0-9])|[1-9])?[0-9])|(2(([0-4][0-9])|(5[0-5]))))\.){3}((((1[0-9])|[1-9])?[0-9])|(2(([0-4][0-9])|(5[0-5]))))$/";
	//"((((1[0-9])|[1-9])?[0-9])|(2(([0-4][0-9])|(5[0-5]))))"
	public static $valid_domain = "/^([a-zA-Z0-9\-]+\.){2,}[a-zA-Z0-9\-]{2,}$/";
	private $type = "";
	private $name = "";
	private $target = "";
	private $line = 0;
	private $ttl = 0;
	public $zone_records = NULL;
	
	public function __construct($type, $name, $target, $line, $ttl, $zone_records) {
		$this->type = $type;
		$this->name = $name;
		$this->target = $target;
		$this->line = $line;
		$this->ttl = $ttl;
		$this->zone_records = $zone_records;
	}
	
	public function __get($var) {
		/************ This function allows the properties to be fetched (but not the zone records object) *************/
		$temp_array = array(	"type"		=> $this->type,
								"name"		=> $this->name,
								"target"	=> $this->target,
								"line"		=> $this->line,
								"ttl"		=> $this->ttl);
		return (array_key_exists($var, $temp_array) ? $temp_array[$var] : NULL);
	}
	
	public function __set($var, $val) {
		if ($var=="target") {
			if ($this->type=="A" && preg_match(self::$valid_ip, $val)===0) { throw new Exception("Invalid IP record '$val', ".self::$valid_ip, 0); }
			if ($this->type=="CNAME" && !preg_match(self::$valid_domain, $val)) { throw new Exception("Invalid subdomain record '$val'", 0); }
			$this->target = $val;
			$this->update();
		} else if ($var=="ttl") {
			if (!is_numeric($val)) { throw new Exception("Invalid TTL '$val'", 0); }
			$this->ttl = $val;
			$this->update();
		} else { throw new Exception("Tried to edit inaccessible property '$var'", 403); }
	}
	
	private function update() {
		//curl call to update line
		$targetname = ($this->type=="A" ? "address" : ($this->type=="CNAME" ? "cname" : "txtdata"));
		$params = array(	"line"			=> $this->line,
							"type"			=> $this->type,
							$targetname		=> $this->target,
							"ttl"			=> $this->ttl,
							"class"			=> self::$class);
		$result = $this->zone_records->doquery("edit_zone_record", $params, array());
		$this->zone_records->updaterecords();
		//todo: return result or check status?
	}
}
?>