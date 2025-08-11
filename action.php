<?php
function page() {
	$pageURL = 'http';
	if (isset($_SERVER["HTTPS"])) {
		if ($_SERVER["HTTPS"] == "on") {
			$pageURL .= "s";
		}
	}
	$pageURL .= "://";
	if ($_SERVER["SERVER_PORT"] != "80") {
		$pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	}
	else {
		$pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	}
	return $pageURL;
}

$extra = "";
$page = page();
if (substr_count($page, "?") > 0) {
	$explode = explode("?", $page);
	$extra = "?" . $explode[1];
}

$server = "showdown";
$url = "http://play.pokemonshowdown.com/~~" . $server . "/action.php" . $extra;

//get encoded variables
$postvars = "";
if (isset($_GET['post'])) {
	$postvars = urldecode($_GET['post']);
}
$postvarsarray = explode("|", $postvars);


$postarray = Array();
for ($i = 0; $i < substr_count($postvars, "|"); $i++) {
	$part = $postvarsarray[$i];
	$postarray[$part] = $_POST[$part];
}

//structure it
$fields_string = "";
foreach($postarray as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
rtrim($fields_string,'& ');

//open connection
$ch = curl_init();

//set the url, number of POST vars, POST data
curl_setopt($ch,CURLOPT_URL, $url);
curl_setopt($ch,CURLOPT_POST, count($postarray));
curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);

//execute post
$result = curl_exec($ch);


//close connection
curl_close($ch);
