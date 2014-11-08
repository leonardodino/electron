<?php
$start = microtime(true);
//MINIMAL FILE:
// add slash to URI
// transliterate URI
require_once './modules/helpers/transliterate.helper.php';

//$start = $_SERVER["REQUEST_TIME_FLOAT"];

$uri = strtok($_SERVER["REQUEST_URI"],'?');
$transliteratedURI = _transliterate(urldecode($uri));
$time = (microtime(true) - $start )*1000 . "ms";
//exit($transliteratedURI);
//echo('2');



if(substr($uri, -1) !== "/"){
	$to_url = sprintf(
		'%s://%s%s/?time=%s',
		isset($_SERVER['HTTPS']) ? 'https' : 'http',
		$_SERVER['HTTP_HOST'],
		$transliteratedURI,
		$time
	);
	header_remove();
	header("HTTP/1.1 301 Moved Permanently");
	header("Location: ".$to_url);
	die();
};
if ($uri != $transliteratedURI){
	$to_url = sprintf(
		'%s://%s%s?time=%s',
		isset($_SERVER['HTTPS']) ? 'https' : 'http',
		$_SERVER['HTTP_HOST'],
		$transliteratedURI,
		$time
	);
	header_remove();
	header("HTTP/1.1 301 Moved Permanently");
	header("Location: ".$to_url);
	die();
}