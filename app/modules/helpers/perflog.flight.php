<?
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
	if(Flight::get('perfLogs')){
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

/**
 * Flight arrival
 *
 * final additions to HTML Flights
 * possibly ends the current flight (breaking the chain)
 *
 * @param		bool 	$halt		breaks the chain
 */
Flight::map('arrive', function($halt = false){
	echo Flight::perfLog('access');
	echo Flight::perfLog('elapsed');
	if($halt){
		Flight::halt();
	}
});