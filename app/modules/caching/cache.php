<?php

require_once __DIR__.'/filefactory.php';
use Caching_FileFactory as FF;

Class Caching{
	static $cache_path;
	
	static function init(){
		if(Flight::has('caching.path')){
			self::$cache_path = Flight::get('caching.path');
		}else{
			self::$cache_path = "./_cache";
			Flight::set('caching.path', "./_cache");
		}
	}
	
	
	static function has_cached_version($url, $kind){
		$filename = FF::URL_to_CACHEFILE($url, $kind);
		return file_exists($filename);
	}
	
	static function get_cached_version($url, $kind){
		$filename    = FF::URL_to_CACHEFILE($url, $kind);
		$fingerprint = FF::fingerprint_from_file($filename, $kind, $url);
		return $fingerprint;
	}
	
	static function set_cached_version($url, $kind, array $page){
		$cache_path = FF::URL_to_CACHEPATH($url, $kind);
		$cache_file = FF::URL_to_CACHEFILE($url, $kind);
		$return = false;
		
		try{
			//create & check cache dir
			$dirOK = FF::makeCleanDir($cache_path);
			
			//get contents
			//write to file
			if($dirOK){
			$content  = $page['content'];
			$content .= Flight::perfLog('cached', $kind);
				//create cache & measure size
				$bytes = file_put_contents($cache_file, $content);
				chmod($cache_file, 0777);
				if($bytes === 0){throw new Exception('0bytes written');}
				
				$return = $bytes !== FALSE;
			}
			
			//error handling
			if(!$return){throw new Exception('error on write');}

		}catch (Exception $e){
			echo 'Caught exception: ',  $e->getMessage(), "\n";
		}
		
		
		return $return;
	}
	
	
	static function easy($url, $kind, $echo = true){
		if(self::has_cached_version($url, $kind)){
			$cache = self::get_cached_version($url, $kind);

			//checks if client has page cached
			if(Helpers::client_has_local_copy($cache['etag'])){
				header('Etag: '.$cache['etag']);
				header('custom: '.$cache['etag']);
				Flight::halt(304);
			}
			
			//otherwise, deliver content
			if($cache['content']){
				
				//send headers
				header('etag: '.$cache['etag']);
				//Flight::etag($cache['etag']);
				
				//send content
				$res  = $cache['content'];
				//end

				if($echo){
					echo($res);
					ob_end_flush();
				}
				return $res;
			}
		}
		return false;
	}
	static function extraEasy($content, $kind){
		$page = FF::fingerprint($content, $kind);
		Flight::setCached($page, true, $kind);
		Flight::arrive(false);
	}
	
}
