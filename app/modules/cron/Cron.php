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

//paths
$content_dir  = './content';
$cache_dir    = './app/_cache';
$state_fname  = '.state.json';
$state_file   = $cache_dir.'/'.$state_fname;
$dploy_file   = './.rev';

//state
$isStale = false;

//options
$force   = false;
$dryrun  = false;

if(!$isCLI) echo '<pre>';
		
echo 'starting chache linting!'.PHP_EOL;


//arg parsing
if($isCLI){
	$defs   = ['f'=>false, 'force'=>false, 'd'=>false, 'dryrun'=>false];
	$args   = getopt ("fd", ["force", "dryrun"]);
	$args   = array_merge($defs, $args);
	
	$force  = !!($args["f"] || $args["force"]);
	$dryrun = !!($args["d"] || $args["dryrun"]);
}else{
	$args   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
	$args   = strtolower($args); //case-insensitive check;
	
	$force  = !(strpos($args, 'force' ) === false);
	$dryrun = !(strpos($args, 'dryrun') === false);
}

echo 'options:'.PHP_EOL;
echo '    force:  '.$force.PHP_EOL;
echo '    dryrun: '.$dryrun.PHP_EOL;


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
	$dploy_version = file_get_contents($dploy_file);
	return $dploy_version;
}



function get_cached_state(){
	$json_state    = file_get_contents($state_file);
	$cached_state  = json_decode($json_state, true);
	return $cached_state;
}
function get_current_state(){
	$time    = time();
	$content = dirmtime($content_dir);
	$dploy   = get_dploy_version();
	return ['start'=> $time, 'content'=> $content, 'dploy'=> $dploy];
}
function set_cache_state($state){
	$stateJSON = json_encode($state);
	file_put_contents($state_file, $stateJSON);
	touch($state_file);
}

function delete_cache(){
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
	$hasStateJson  = exists($state_file);
	$stale         = false;
	
	#setup
	if($hasStateJson){
		$json_state    = file_get_contents($state_file);
		$cached_state  = json_decode($json_state, true);
		$current_state = get_current_state();
	}
	
	#step 1 - detect stale
	if($hasStateJson){
		if($cached_state->content < $current_state->content){
			$stale = true;
			$message += "modified [content]".PHP_EOL;
		}else if($cached_state->dploy !== $current_state->dploy){
			$stale = true;
			$message += "modified [dploy]".PHP_EOL;
		}else{
			$message = "not modified".PHP_EOL;
			$message = "cache is up-to-date".PHP_EOL;
		}
	}else{
		$stale = true;
		$message += "has no state json file".PHP_EOL;
	}
	echo $message;
	
	#step 2 - discard stale
	#step 3 - set new state
	if($stale && !$dryrun){
		delete_cache();
		set_cache_state($current_state)		
	}
	
}

if(!$isCLI) echo '<pre>';
chdir($startFolder);