#!/usr/bin/php
<?php
//check php version	
if(phpversion() < 5.4){die('kunst-cms services require PHP 5.4 or higher.'.PHP_EOL.'You are currently running PHP '.phpversion().PHP_EOL.'Please Update.'.PHP_EOL);}
date_default_timezone_set('America/Sao_Paulo');

$startFolder = getcwd();
chdir(__DIR__);
chdir('../../../'); //change to the root cms folder
echo(getcwd());
//enviroment
$isCLI   = (php_sapi_name() == 'cli');

//paths
$content_dir  = './content';
$cache_dir    = './app/_cache';
$state_fname  = '.state.json';
$state_file   = $cache_dir.'/'.$state_fname;
$dploy_file   = './.rev';
$dploy_file   = file_exists($dploy_file) ? $dploy_file : './.local_rev'; //fallback


//state
$isStale = false;

//options
$force   = false;
$dryrun  = false;

if(!$isCLI) echo '<pre>';
	
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
echo 'enviroment: '.($isCLI ? 'cli': 'web').PHP_EOL;
echo '    dploy:  '.$dploy_file.PHP_EOL;


if($force){$isStale = true;}





//libs
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

function delete_cache(){
	global $cache_dir;
	
	$dir = $cache_dir;
	$di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
	$ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
	foreach ( $ri as $file ){
		if(basename($file) !== $state_fname){
			$file->isDir() ?  rmdir($file) : unlink($file);
		}
	}
	return true;
}

function main(){
	global $state_file, $force, $stale, $dryrun;
	
	$hasStateJson  = file_exists($state_file);
	$stale         = false;
	$message       = PHP_EOL;
	
	#setup
	if($hasStateJson){
		$cached_state  = get_cached_state();
		$current_state = get_current_state();
	}
	
	#step 1 - detect stale
	if($hasStateJson){
		if($cached_state->content < $current_state->content){
			$stale = true;
			$message .= "modified [content]".PHP_EOL;
		}else if($cached_state->dploy !== $current_state->dploy){
			$stale = true;
			$message .= "modified [dploy]".PHP_EOL;
		}else{
			$message .= "not modified".PHP_EOL;
			$message .= "cache is up-to-date".PHP_EOL;
		}
		if($force){
			$stale  = true;
			$message .= "forced refresh";
		}
	}else{
		$stale  = true;
		$current_state = get_current_state();
		set_cache_state($current_state);
		
		$message .= "has no state json file".PHP_EOL;
		$message .= "creating json file, ressetting the cache, and exiting".PHP_EOL;
	}
	echo $message;
	
	#step 2 - discard stale
	#step 3 - set new state
	if($stale && !$dryrun){
		delete_cache();
		$current_state = get_current_state();
		set_cache_state($current_state);
		echo PHP_EOL.'cache resetted';
	}
	
}
main();

if(!$isCLI) echo '<pre>';
chdir($startFolder);