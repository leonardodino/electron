<?php
 /**
  * Minimalistic Console Class for PHP
  *
  * Console for multiple outputs, supports: 
  *  - javascript's window.console
  *  - CLI logging
  *  - file logging
  *
  * @author Leonardo Dino 
  * @version 0.0.1
  * @license BSD 4-clause 
  * @package kunst\Console
  **/

 /* ==== TODO ==== */
 // implement: separated parser (__construct)
 // implement: polyfill parser (__construct)
 // implement: separated toggle (setter)
 // implement: polyfill toggle (setter)
 // implement: size config (setter)

namespace kunst;
Class Console{
	protected $header;
	protected $untitleds;
	protected $logs = [];
	protected $console_width;
	protected $key_width;
	protected $plaintext_output;
	protected $separated;

	protected $scripttags = [
		"start" => "<script>",
		"polyfill_enabled" => true,
		"polyfill" => [ //https://github.com/paulmillr/console-polyfill
			  "!(function(con) {"
			, "	var prop, method;"
			, "	var empty = {};"
			, "	var dummy = function() {};"
			, "	var properties = 'memory'.split(',');"
			, "	var methods = ('assert,clear,count,debug,dir,dirxml,error,exception,group,' +"
			, "	'groupCollapsed,groupEnd,info,log,markTimeline,profile,profiles,profileEnd,' +"
			, "	'show,table,time,timeEnd,timeline,timelineEnd,timeStamp,trace,warn, table').split(',');"
			, "	while (prop = properties.pop()) con[prop] = con[prop] || empty;"
			, "	while (method = methods.pop()) con[method] = con[method] || dummy;"
			, "})(this.console = this.console || {});"
		],
		"end"   => "</script>"
	];

	/**
	 * preprocess each dimension separately
	 *
	 * looks for numeric value, or unconstrained name/alias
	 * returns numeric for this cases, otherwise returns the input, unprocessed
	 *
	 * used in {@link parse_size} parse_size function
	 *
	 * @access    	private
	 * @subpackage	startup
	 * @param     	string	$dimension	input string for pre-parsing
	 * @return    	mixed	dimension in integer or name
	 */
	private static function prepare_size($dimension){
		if(is_numeric($dimension)){
			$dimension = intval($dimension);
		}elseif(is_string($dimension)){
			switch ($dimension){
				case "unconstrained":
				case "none":
				case "n";
					$dimension = 100000;
				break;
			}
		}
		return $dimension;
	}


	/**
	 * parse console dimensions pair (console_width/[key_width])
	 *
	 * key_width is optional,
	 * each dimension can be a number or a "dimension name"
	 *
	 * used in {@link __construct} constructor method
	 *
	 * @access    	private
	 * @subpackage	startup
	 * @uses      	prepare_size
	 * @param     	string	$input	dimensions pair
	 * @return    	array	with named console_width and key_width
	 */
	private static function parse_size($input){
		$size = explode('/', $input);
		$console_width = $size[0];
		$key_width     = isset($size[1])? $size[1] : null;

		//handle unconstrained & numeric values
		$console_width = static::prepare_size($console_width);
		$key_width     = static::prepare_size($key_width);


		//parse console_width named values
		if(is_string($console_width) && $console_width !== "UNCONSTRAINED"){
			switch ($console_width){
			  case "mini":   $console_width =  60; break;
			  case "small":  $console_width =  80; break;
			  case "medium": $console_width = 110; break;
			  case "large":  $console_width = 140; break;
			  case "full":   $console_width = 180; break;
			  default:       $console_width = 110; break;
			}
		};
		if(!isset($console_width)){
			$console_width = 110;
		};


		//parse key column width named values
		if(is_string($key_width) && $key_width !== "UNCONSTRAINED"){
			switch ($key_width){
			  case "zero":    $key_width =  1; break;
			  case "compact": $key_width = 15; break;
			  case "regular": $key_width = 20; break;
			  case "ample":   $key_width = 30; break;
			  default:        $key_width = 20; break;
			}
		};
		if(!isset($key_width)){
			$key_width = 20;
		};

		return ["console_width"=>$console_width, "key_width"=>$key_width];
	}

	/**
	 * creates a new instance, with supplied options
	 *
	 * all options are optional, 
	 * it defaults to a "browser" enviroment with "medium/default" size
	 *
	 * the first argument can be an alias.
	 * it's only parsed if it's a valid one, otherwise it's treated as a $size string
	 * valid aliases: ["browser", "javascript", "cli", "file"] (canse insensitive)
	 *
	 * @access    	public
	 * @subpackage	startup
	 * @uses      	parse_size
	 * @param     	string	$env 	optional enviroment string
	 * @param     	string	$size	optional dimensions pair
	 * @param     	bool 	$plaintext	optional plaintext toggle
	 */
	public function __construct($env = "browser", $size = "medium/default", $plaintext = false){
		//case-insensitive cheking
		$env  = is_string($env)  ? strtolower($env)  : $env;
		$size = is_string($size) ? strtolower($size) : $size;

		//check for env
		switch ($env) {
			case "browser":
			case "javascript":
			case "cli":
			case "file":
				$env = $env;
			break;
			default:
				$size = $env;
				$plaintext = $size;
				$env = null;
			break;
		}

		//smart guessing
		if($env == "cli"){
			$plaintext = true;
		}elseif($env == "file"){
			$size = "n/ample"; $plaintext = true;
		}

		$size = static::parse_size($size);
		$this->console_width = $size["console_width"];
		$this->key_width     = $size["key_width"];

		$this->untitleds = 0;
		$this->plaintext_output = !!$plaintext;

		//pollyfill merge
		$poly = implode("\n",  $this->scripttags["polyfill"]);
		$this->scripttags["polyfill"] = $poly;

	}

	/**
	 * simple/flexible header formatter
	 *
	 * just centers $str to a designated $width,
	 * with $simbol, indented out of center by $indent,
	 * and surrounds str with $margin
	 *
	 * @access    	private
	 * @subpackage	string_ops
	 * @param     	string	$str 	title to be in the header
	 * @param     	int   	$width 	header width
	 * @param     	string	$symbol character to be used as header background
	 * @param     	int   	$indent 	indent info by this integer
	 * @param     	string	$margin character to be used as header padding
	 * @return    	string	formatted header line
	 */
	private static function format_header($str, $width = null, $symbol = "=", $indent = 0, $margin = " "){
		$indenttext = str_repeat($symbol, $indent);

		$str = trim($str);
		$str = $margin . $str . $margin;
		$len = strlen($str);
		$padN = $width - $len;

		$header = str_pad($str, $width, $symbol);
		return $header;
	}

	/**
	 * simple string formatter (title: value)
	 *
	 * creates a simple line containing the title of the logged value, and the value itself
	 * if it's called without a title, its created by {@link untitled_log} untitled_log function
	 *
	 * @access    	private
	 * @subpackage	string_ops
	 * @uses      	untitled_log
	 * @param     	string	$key 	title, null in case it doesn't have one
	 * @param     	string	$val 	value to be logged
	 * @param     	int   	$console_width	console width
	 * @param     	int   	$key_width	key width
	 * @return    	string	formatted string line
	 */
	private static function format_str($log, $console_width, $key_width, $plaintext, $separate = false, $wordwrap = false, $label = false){
		$type  = $log[0];
		$key   = $log[1];
		$val   = $log[2];

		$_NL  =  $plaintext ? "\n" : '\n'; //newline
		$_NLR =  $plaintext ? "\n" : '\\n'; //newline regex

		$break = $separate ? $_NL.$_NL : $_NL;
		$label = $label ? ' ['.$label.']' : '';

		//TITLE
			//remove newlines
			$key = str_replace($_NLR, '', $key);

			//assembling
			//$title = $key . ':' . $label;
			$title = $key . ': ';
			$title = str_pad($title, $key_width, ' ');

		//VALUE
			//string wordwrapping
			if($wordwrap || $type == '%json' && $plaintext){
				//setting up
				$titlelen = strlen($title);
				$indent = $_NL . str_repeat(' ', $titlelen);
				$valwidth = $console_width - $key_width;

				//replace newlines with idents
				$val = str_replace($_NLR, $indent, $val);
				if($type == '%json'){
					$type = '%s';
				}
				if($wordwrap && strlen($val) > $console_width){
					$val = wordwrap($val, $valwidth, $indent);
				}
			}


		$text  = $title.$type.$break;
		return [$text, $val];
	}

	/**
	 * generates auto-incrementing "untitled_00" names
	 *
	 * @access    	private
	 * @subpackage	string_ops
	 * @return    	string	untitled_00 formated name
	 */
	private function untitled_log(){
		$num = str_pad($this->untitleds,2,'0',STR_PAD_LEFT);
		$name = 'untitled_'. $num;
		$this->untitleds += 1;
		return $name;
	}

	/**
	 * parse each entry for browser consumption (JS)
	 *
	 * returns array ready for javascript's console.log (similar to sprintf)
	 * includes browser specific %o object format
	 *
	 * @access    	private
	 * @subpackage	output_linehelpers
	 * @param     	array	$log 	log array, standart [type, key, value]
	 * @param     	int   	$console_width	console width
	 * @param     	int   	$key_width	key width
	 * @param     	bool 	$separate 	separate values by one blank line
	 * @return    	array	[pattern, val] ready for parsing by sprintf or window.console
	 */
	private static function format_browser($log, $console_width, $key_width, $separate = false){
		$plaintext = false;
		$type  = $log[0];
		$key   = $log[1];
		$val   = $log[2];

		//$log   = [$type, $key, $val]; 
		$wordwrap = ($type == "%s");

		return static::format_str($log, $console_width, $key_width, $plaintext, $separate, $wordwrap);
	}

	/**
	 * parse each entry for cli or file writing (plaintext)
	 *
	 * returns array ready for PHP's sprintf
	 * includes only generic %s string format
	 * (convert non-scalar types to json, prettyprint it and output as string)
	 *
	 * @access    	private
	 * @subpackage	output_linehelpers
	 * @param     	array	$log 	log array, standart [type, key, value]
	 * @param     	int   	$console_width	console width
	 * @param     	int   	$key_width	key width
	 * @param     	bool 	$separate 	separate values by one blank line
	 * @return    	array	[pattern, val] ready for parsing by sprintf or window.console
	 */
	private static function format_plain($log, $console_width, $key_width, $separate = true){
		$plaintext = true;
		$type  = $log[0];
		$key   = $log[1];
		$val   = $log[2];

		$wordwrap = ($type == "%s");
		$label    = ($type == "%o") ? 'json' : null;

		if($type == "%o"){
			$val  = json_encode($val, JSON_PRETTY_PRINT);
			$type = "%json";
		}
		$log = [$type, $key, $val]; 

		return static::format_str($log, $console_width, $key_width, $plaintext, $separate, $wordwrap, $label);
	}

	/**
	 * wraps merged logs array in console.log.apply JS function
	 *
	 * @access    	private
	 * @subpackage	output_linehelpers_helpers
	 * @param     	array	$logs 	logs array, merged [pattern, [values]]
	 * @return    	string	console.log call line
	 */
	private static function create_script($logs){
		$titles = $logs[0]; //titles with pattern for objects/strings
		$values = $logs[1];

		array_unshift($values, $titles);

		$toLog = json_encode($values);

		//escaping
		$toLog = addslashes($toLog);
		//magically unescape newline control char
		$toLog = str_replace('\\\\\\\\n', '\\\\n', $toLog);

		$script = ''
			.'console.log.apply(console,'
				."JSON.parse('" . $toLog . "')"
			.');';

		return $script;
	}

	/**
	 * normalize console->log() mixed inputs
	 *
	 * steps:
	 *  - checks if its an PHP object/array,
	 *  - checks if its an JSON object/array,
	 *  - checks if its an scalar type,
	 *  - returns __null__ string otherwise
	 *
	 * @access    	private
	 * @subpackage	input_helpers
	 * @param     	mixed	$input 	input for log
	 * @return    	array	named values: type and input
	 */
	private static function normalize($input = null){
		//process log input: scalar, native arrays or json
		if(isset($input)){
			if(is_array($input) || is_object($input)){
				//passed php array or object
				$input_type = '%o';
			}else{
				@$parsed_input = json_decode($input, true);
				if(is_array($parsed_input) || is_object($parsed_input)){
					//passed json array or object
					$input = $parsed_input;
					$input_type = '%o';
				}else{
					//passed scalar type
					$input_type = '%s';
				}
			}
		}else{
			//called with null
			$input_type = '%s';
			$input = '_null_';
		}
		return ["type" => $input_type, "input" => $input];
	}


	/**
	 * picks all logs and parse each one individually, according to enviroment
	 *
	 * uses: (output_linehelpers)
	 *  - static::format_plain 
	 *  - static::format_browser 
	 *
	 * used by:
	 *  - console->plaintext()
	 *  - console->script()
	 *  - console->__tostring()
	 *
	 * @access    	public
	 * @subpackage	output_helper
	 * @param     	array	$logs 	array of logs arrays [[kind, key, val], ...]
	 * @param     	int  	$console_width 	desired console width
	 * @param     	int  	$key_width 	desired key width
	 * @param     	bool  	$plaintext 	enviroment is plaintext
	 * @return    	array	[message, args] all logs concatenated, ready for sprintf, or console.log
	 */
	public static function render($logs, $console_width, $key_width, $plaintext, $separate = false){
		$message = '';
		$args    = [];
		foreach ($logs as $log){
			/* moved to str_format
				//escaping
				$kind = $log[0];
				$key  = str_replace('\\n', '', $log[1]);
				$val  = isset($log[2])? $log[2] : null;
				$escaped_log = [$kind, $key, $val];
			*/

			if($plaintext){
				$formatted = static::format_plain($log, $console_width, $key_width, $separate);
			}else{
				$formatted = static::format_browser($log, $console_width, $key_width, $separate);
			}
			$text = $formatted[0];
			$arg  = $formatted[1];

			$message .= $text; 
			array_push($args, $arg);
		}
		return [$message, $args];
	}

	/**
	 * add a log
	 *
	 * @access    	public
	 * @subpackage	instance_input
	 * @param     	string	$key 	optional title for the log
	 * @param     	mixed	$val 	value to log
	 * @return    	Console	returns itself for chaining
	 */
	public function log($key = null, $val = null){
		//detect how it was called
		$kind_n = func_num_args();
		switch($kind_n){
			case 0:  $kind = 'empty';  break;
			case 1:  $kind = 'single'; break;
			case 2:  $kind = 'pair';   break;
			default: $kind = 'error';  break;
		}

		//handle it
		if($kind == 'empty'){
			$type = '%s';
			$key  = '_null_';
			$val  = '_null_';
		}
		if($kind == 'single'){
			$norm = static::normalize($key);

			$type = $norm['type'];
			$key  = $this->untitled_log();
			$val  = $norm['input'];
		}
		if($kind == 'pair'){
			$norm = static::normalize($val);

			$type = $norm['type'];
			$key  = $key;
			$val  = $norm['input'];
		}
		if($kind == 'error'){
			$type = '%s';
			$key  = 'error';
			$val  = 'too_many_args';
		}

		//standart format
		$log = [$type, $key, $val]; 
		//add log
		array_push($this->logs, $log);
		//method chaining
		return $this;
	}

	/**
	 * outputs logs array as json
	 *
	 * @access    	public
	 * @subpackage	instance_output
	 * @param     	bool 	$script	whether it's going to be passed to JS
	 * @return    	string	logs array as json
	 */
	public function json($script = false){
		if($script){
			$logs = json_encode($this->logs);
			$logs = addslashes($logs);
			$logs = "'" . $logs . "'";
		}else{
			$logs = json_encode($this->logs, JSON_PRETTY_PRINT);
		}
		return $logs;
	}

	/**
	 * dump raw logs array, possibly flush it's contents
	 *
	 * @access    	public
	 * @subpackage	instance_output
	 * @param     	bool 	$flush	empty current logs array
	 * @return    	string	raw logs array
	 */
	public function dump($flush = false){
		$logs = $this->logs;
		if($flush){
			$this->logs = [];
		}
		return $logs;
	}

	/**
	 * outputs plaintext formatted logs
	 *
	 * @access    	public
	 * @subpackage	instance_output
	 * @return    	string	multi-line plaintext log
	 */
	public function plaintext(){
		$plaintext = true; //output in plaintext
		$separated = true; //new lines between logs
		$loglines = static::render($this->logs, $this->console_width, $this->key_width, $plaintext, $separated);
		$pattern  = $loglines[0];
		$args     = $loglines[1];

		$text = vsprintf($pattern, $args);

		return $text;
	}

	/**
	 * outputs console.log.apply function populated with log data
	 *
	 * @access    	public
	 * @subpackage	instance_output
	 * @param     	bool 	$inside	whether the call is already inside a script tag 
	 * @return    	string	complete JS console function call
	 */
	public function script($inside = false){
		$plaintext = false; //complete object output
		$separated = true; //new lines between logs
		$tags  = $this->scripttags;
		$start = $inside ? '' : $tags["start"];
		$end   = $inside ? '' : $tags["end"];
		$loglines = static::render($this->logs, $this->console_width, $this->key_width, $plaintext, $separated);
		$logcmds  = static::create_script($loglines);
		//$logcmds  = addslashes($logcmds);

		$script = [
			$start,
				$this->scripttags["polyfill"],
				$logcmds,
			$end
		];
		$script = implode("\n\n", $script);
		return $script;
	}

	/**
	 * outputs console.log.apply function populated with log data, in plaintext format
	 *
	 * @access    	public
	 * @subpackage	instance_output
	 * @param     	bool 	$inside	whether the call is already inside a script tag 
	 * @return    	string	complete JS console function call, plaintext output
	 */
	public function legacy_script($inside = false){
		$plaintext = true; //output in plaintext
		$tags  = $this->scripttags;
		$start = $inside ? '' : $tags["start"];
		$end   = $inside ? '' : $tags["end"];
		$loglines = static::render($this->logs, $this->console_width, $this->key_width, $plaintext);
		$logcmds  = static::create_script($loglines);

		$script = [
			$start,
				$this->scripttags["polyfill"],
				$logcmds,
			$end
		];
		$script = implode("\n\n", $script);
		return $script;
	}


	/**
	 * outputs console->plaintext() or console->script() depending on configured enviroment
	 *
	 * @access    	public
	 * @subpackage	instance_magic
	 * @param     	bool 	$inside	whether the echo call is already inside a script tag 
	 * @return    	string	complete JS console function call
	 */
	public function __toString(){
		//TODO format strings
		if($this->plaintext_output){
			$logs = self::plaintext();
		}else{
			$logs = self::script();
		}
		return $logs;
	}
}