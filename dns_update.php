<?php
require_once("/classdns.php");
$config = parse_ini_file("config");
$zones = new zone_records($config["cpanel_user"], $config["cpanel_password"], $config["cpanel_authdomain"], $config["cpanel_dnsdomain"]);

//$newip = file_get_contents($config["checkip_url"]); //use curl instead; this has a short timeout
$curl = curl_init($config["checkip_url"]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10); //10 second timeout
$newip = curl_exec($curl);
if (!$newip) { var_dump($curl); throw new Exception("Bad cURL", 0); }
curl_close($curl);

preg_match('/Current IP Address: ([\[\]:.[0-9a-fA-F]+)</', $newip, $m); //works for http://checkip.dyndns.org/
$newip = $m[1];

foreach ($zones->DNSrecords as $value) {
        //remember the trailing . on target_domain in config!
        if ($value->name==$config["target_domain"]) {
                if ($value->target!=$newip) {
                        echo "Change to $newip";
                        $value->target=$newip; //this works because the object's __set() method then calls updaterecords() on its parent zone_records object
                }
        }
}
?>
