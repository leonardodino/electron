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

/**
* Performance Logs
*
* gets timing, dates and states, and output it formatted.
* useful for printing html comments to cached files and responses.
*
* Available log $kinds:
*  - 'elapsed':	request-response timer
*  - 'render':	template render timer
*  - 'cached':	date cached
*  - 'fresh':	states fresh file (static return)
*  - 'dynamic':	states dynamic file (static return)
*
* @param		string	$kind		one of the methods: ['elapsed', 'render', 'cached', 'access', 'm_date', 'fresh', 'dynamic']
* @param		mixed	$time		relevant past time info (only for 'render' OR 'm_date')
* @param		string	$res_type	response formatting (as of now, only html)
* @param		bool		$echo		automatically echo the line
* @return	string	formated log line
*/

Flight::map('perfLog', function($kind, $echo = false, $res_type = 'html', $time = 0){
	//if(Flight::get('perfLogs')){
	if(true){
		//delcaring
		$title = $kind;
		$value = NULL;
		$line  = NULL;
		
		//processing
		if($kind == 'elapsed'){
			$value = (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"])*1000 . "ms";
			
		}else if($kind == 'render'){
			$value = (microtime(true) - $time) * 1000 ."ms";
			
		}else if($kind == 'cached' || $kind == 'access'){
			$time = time();
			$tz = explode(":", date("P", $time));
			$value = date("F jS, Y, h:iA", $time)." [GMT".intval($tz[0])."]";
			
		}else if($kind == 'm_date' && $time > 0){
			$tz = explode(":", date("P", $time));
			$value = date("F jS, Y, h:iA", $time)." [GMT".intval($tz[0])."]";
			
		}else if($kind == 'fresh'){
			$value = 'just parsed!';
			
		}else if($kind == 'dynamic'){
			$value = 'always parsed!';
		}
		
		//output
		if(strtolower($res_type) == 'html' && $value){
			$line = Helpers::htmlComment($title, $value);
		}
		
		//exit
		if($line){
			if($echo){echo($line);}
			return $line;
		}
	}
	return false;
});

Flight::map('arrive', function($halt = true){
	echo Flight::perfLog('access');
	echo Flight::perfLog('elapsed');
	if($halt){
		Flight::halt();
	}
});