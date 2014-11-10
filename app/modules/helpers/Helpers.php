<?php
foreach(glob('./modules/helpers/*.helper.php') as $helper){require_once $helper;}
Class Helpers{
	static function transliterate($str){return _transliterate($str);}
	
	static function htmlComment($title, $value = false, $trim = false){
		$title = htmlspecialchars($title, ENT_COMPAT | ENT_HTML5, 'UTF-8', false);
		$value = htmlspecialchars($value, ENT_COMPAT | ENT_HTML5, 'UTF-8', false);
		return ($trim ? '' : "\n") . '<!-- ' . $title . ($value ? ': '.$value : '') . ' -->';
	}
	static function cleanUrl($url = NULL){
		$url = $url ?: $_SERVER["REQUEST_URI"];
		$url = strtok($url,'?');
		$url = self::transliterate($url);
		
		return $url;
	}
	
	# add trailing slash if required
	static function route_slashes(){
		//var_dump($_SERVER["REQUEST_URI"] ,  Flight::request()->url);
		$url = $_SERVER["REQUEST_URI"];
		$cleanurl = self::cleanUrl($url);
		if(!preg_match('/\/$/', $url) && !preg_match('/[\.\?\&][^\/]+$/', $url)){
			//echo'bbb';
			$host  = $_SERVER['HTTP_HOST'];
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: http://'.$host.$url.'/');
			//die('http://'.$host.'/'.$url.'/');
		}
	}
	
	static function client_has_local_copy($etag){
		if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag ){
			return true;
		}
		return false;
	}
	
	
	
	
};

foreach(glob('./modules/helpers/*.flight.php') as $helper){require_once $helper;}
