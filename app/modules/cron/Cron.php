#!/usr/bin/php
<?php
//check php version	
if(phpversion() < 5.4){die('kunst-cms services require PHP 5.4 or higher.'.PHP_EOL.'You are currently running PHP '.phpversion().PHP_EOL.'Please Update.'.PHP_EOL);}
date_default_timezone_set('America/Sao_Paulo');

$startFolder = getcwd();
chdir(__DIR__);
chdir('../../../'); //change to the root cms folder

//enviroment
$isCLI   = (php_sapi_name() == 'cli');
$env     = ($isCLI ? 'cli': 'web');

//paths
$content_dir  = './content';
$cache_dir    = './app/_cache';

$state_fname  = '.state.json';
$state_file   = $cache_dir.'/'.$state_fname;

$log_fname    = '.cache.log';
$log_file     = $cache_dir.'/'.$log_fname;

$dploy_file   = './.rev';
$dploy_file   = file_exists($dploy_file) ? $dploy_file : './.local_rev'; //fallback

//options
$force   = false;
$dryrun  = false;

if(!$isCLI) echo '<pre>';
echo PHP_EOL;
echo 'starting chache linting!'.PHP_EOL;


//arg parsing
if($isCLI){
	$defs   = ['f'=>true, 'force'=>true, 'd'=>true, 'dryrun'=>true];
	$args   = getopt ("fd", ["force", "dryrun"]);
	
	$force  = isset($args["f"]) || isset($args["force"]);
	$dryrun = isset($args["d"]) || isset($args["dryrun"]);
}else{
	$args   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
	$args   = strtolower($args); //case-insensitive check;
	
	$force  = !(strpos($args, 'force' ) === false);
	$dryrun = !(strpos($args, 'dryrun') === false);
}

function boolstr($bool){
	return ($bool) ? 'true' : 'false';
}

echo 'options:'.PHP_EOL;
echo '    force:  '.boolstr($force).PHP_EOL;
echo '    dryrun: '.boolstr($dryrun).PHP_EOL;
echo 'enviroment: '.$env.PHP_EOL;
echo '    dploy:  '.$dploy_file.PHP_EOL;






//libs
function tailFile($file, $lines = 1){
	//return last n lines in file, as array, usefull for truncating.
	return array_slice(file($file), -$lines);
}

function human_json($json){
	if(!is_string($json)){
		$json = json_encode($json);
	}
	$human = str_replace( [',', ':', '"', '{', '}'], [', ', ': ', ''], $json);
	return $human;
}

function add_trailing_character($string, $char){
	if(substr($string, -1) !== $char){
		$string = $string . $char;
	}
	return $string;
}

function print_state_file(){
	$dploy_version = file_get_contents($dploy_file);
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

function get_dploy_version(){
	global $dploy_file;
	
	$dploy_version = file_get_contents($dploy_file);
	return $dploy_version;
}



function get_cached_state(){
	global $state_file;
	
	$json_state    = file_get_contents($state_file);
	$cached_state  = json_decode($json_state, true);
	return $cached_state;
}
function get_current_state(){
	global $content_dir;
	
	$time    = time();
	$content = dirmtime($content_dir);
	$dploy   = get_dploy_version();
	return ['start'=> $time, 'content'=> $content, 'dploy'=> $dploy];
}
function set_cache_state($state){
	global $state_file;
	
	$stateJSON = json_encode($state);
	file_put_contents($state_file, $stateJSON);
	touch($state_file);
}
function makeFile($file){
	if(file_exists($file)){
		return true;
	}else{
		return touch($file);
	}
}

function save_log($state, $changes){
	global $log_file, $env;
	if(makeFile($log_file)){	
		$time = $state['start'];
		
		$_tag = '['.implode(', ', $changes).']';
		
		$tz   = explode(":", date("P", $time));
		$_date = date("d/m/Y h:iA", $time)." [GMT".intval($tz[0])."]";
		
		
		$_env   = '~'.$env; 
		$_state = human_json($state);
		$line = [$_date, $_env, $_tag, $_state];
		$line = implode(" \t", $line) . PHP_EOL;
				
		$log   = tailFile($log_file, 99);
		$log[] = $line;
		$log   = implode('', $log);
		
		file_put_contents($log_file, $log);
		return PHP_EOL.$line;
	}else{
		echo PHP_EOL.'not possible to create logfile'.PHP_EOL;
	}
}

function delete_cache(){
	global $cache_dir, $state_fname, $log_fname;
	
	$dir = $cache_dir;
	$di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
	$ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
	foreach ( $ri as $file ){
		$fname = basename($file);
		if($fname !== $state_fname && $fname !== $log_fname){
			$file->isDir() ?  rmdir($file) : unlink($file);
		}
	}
	return true;
}

function main(){
	global $state_file, $force, $dryrun;
	
	$hasStateJson  = file_exists($state_file);
	$stale         = false;
	$message       = PHP_EOL;
	$changes       = [];
	
	#setup
	if($hasStateJson){
		$cached_state  = get_cached_state();
		$current_state = get_current_state();
	}
	
	
	#step 1 - detect stale
	if($hasStateJson){
		if($cached_state['content'] < $current_state['content']){
			$stale     = true;
			$message  .= "modified [content]".PHP_EOL;
			$changes[] = 'content';
		}else if($cached_state['dploy'] !== $current_state['dploy']){
			$stale     = true;
			$message  .= "modified [dploy]".PHP_EOL;
			$changes[] = 'dploy';
		}else{
			$message  .= "not modified".PHP_EOL;
			$message  .= "cache is up-to-date".PHP_EOL;
		}
		if($force){
			$stale     = true;
			$message  .= "forced refresh";
			$changes[] = 'force';
		}
	}else{
		$stale  = true;
		$current_state = get_current_state();
		set_cache_state($current_state);
		
		$message .= "has no state json file".PHP_EOL;
		$message .= "creating json file, ressetting the cache, and exiting".PHP_EOL;
	}
	echo $message.PHP_EOL;
	
	
	#step 2 - discard stale
	#step 3 - set new state
	if($stale && !$dryrun){
		echo PHP_EOL;
		echo 'deletting'.PHP_EOL;
		delete_cache();
		$current_state = get_current_state();
		set_cache_state($current_state);
		$log = save_log($current_state, $changes);
		echo 'cache resetted!'.PHP_EOL;	
		echo $log;
	}
	
}
main();

if(!$isCLI) echo '<pre>';
chdir($startFolder);