<?php
/* deceased
Flight::map('cachedRoute', function($pattern, callable $callback, array $options = []){
	$kind = $options["kind"] ?: "html";
	$url  = $options["url"]  ?: $_SERVER["REQUEST_URI"];
	
	Flight::route($pattern, $callback);
});
*/

Flight::before('route', function(&$params, &$output){
	Flight::getCached();
});


Flight::map('getCached', function($kind = 'html', $url = NULL){
	$url = $url ?: $_SERVER["REQUEST_URI"];
	$output = FALSE;
	
	$cached = Caching::easy($url, $kind);
	if($cached){
		Flight::arrive();
	};
});

Flight::map('setCached', function(array $page, $echo = true, $kind = 'html', $url = NULL){
	$url = $url ?: $_SERVER["REQUEST_URI"];
	$ok = Caching::set_cached_version($url, $kind, $page);
	$res = false;
	if($ok){
		$res = $page['content'] . Flight::perfLog('fresh');
	}
	if($echo){echo $res;}
});

