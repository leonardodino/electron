<?php
$start = microtime(true);
//MINIMAL FILE:
// add slash to URI
// transliterate URI
require_once './modules/helpers/transliterate.helper.php';

$uri = $_SERVER["REQUEST_URI"];
$uri = strtok($uri,'?');
$transliteratedURI = _transliterate(urldecode($uri));



if(substr($uri, -1) !== "/" && substr($uri, -5) !== ".json" ){
	$to_url = sprintf(
		'%s://%s%s/',
		isset($_SERVER['HTTPS']) ? 'https' : 'http',
		$_SERVER['HTTP_HOST'],
		$transliteratedURI
	);
	header_remove();
	header("HTTP/1.1 301 Moved Permanently");
	header("Location: ".$to_url);
	die();
};
if ($uri != $transliteratedURI){
	$to_url = sprintf(
		'%s://%s%s',
		isset($_SERVER['HTTPS']) ? 'https' : 'http',
		$_SERVER['HTTP_HOST'],
		$transliteratedURI
	);
	header_remove();
	header("HTTP/1.1 301 Moved Permanently");
	header("Location: ".$to_url);
	die();
}
