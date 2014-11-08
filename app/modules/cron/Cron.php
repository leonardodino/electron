#!/usr/bin/php
<?php
//check php version	
if(phpversion() < 5.4){die('kunst-cms services require PHP 5.4 or higher.'.PHP_EOL.'You are currently running PHP '.phpversion().PHP_EOL.'Please Update.'.PHP_EOL);}


date_default_timezone_set('America/Sao_Paulo');
print 'starting chache linting!'.PHP_EOL;





//libs
function add_trailing_character($string, $char){
	if(substr($string, -1) !== $char){
		$string = $string . $char;
	}
	return $string;
}

function add_trailing_slash($url){return add_trailing_character($url,  '/');}
function add_trailing_dot($path) {return add_trailing_character($path, '.');}


function dirmtime($dir){
	$most_recent_time = 0;
	$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($dir), 
					RecursiveIteratorIterator::SELF_FIRST
				);

	foreach($iterator as $file) {
		if($file->isDir()){
			$dir = add_trailing_slash($file);
			$dir = add_trailing_dot($dir);
			$mod_date = filemtime($dir);
			if($mod_date > $most_recent_time){
				$most_recent_time = $mod_date;
			}
		}
	}
	return $most_recent_time;

}