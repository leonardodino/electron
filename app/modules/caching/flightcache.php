<?php


Flight::map('getCached', function($kind, $url = NULL){
	$url = $url ?: $_SERVER["REQUEST_URI"];
	$output = FALSE;
	
	$cached = Caching::easy($url, $kind);
	if($cached){
		Flight::arrive(true);
	};
});

Flight::map('setCached', function(array $page, $echo = true, $kind, $url = NULL){
	$url = $url ?: $_SERVER["REQUEST_URI"];
	$ok = Caching::set_cached_version($url, $kind, $page);
	$res = false;
	if($ok){
		$res = $page['content'] . Flight::perfLog('fresh', $kind);
	}
	if($echo){echo $res;}
});

