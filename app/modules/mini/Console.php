<?php
Class Console{
	protected $header;
	protected $untitleds;
	protected $logs = [];
	protected $console_width;
	protected $key_width;
	protected $plaintext;
	protected $scripttags = [
		"start" => "<script>",
		//https://github.com/paulmillr/console-polyfill
		"polyfill" => "". 
		    . "	(function(con) {"
			. "		var prop, method;"
			. "		var empty = {};"
			. "		var dummy = function() {};"
			. "		var properties = 'memory'.split(',');"
			. "		var methods = ('assert,clear,count,debug,dir,dirxml,error,exception,group,' +"
			. "		'groupCollapsed,groupEnd,info,log,markTimeline,profile,profiles,profileEnd,' +"
			. "		'show,table,time,timeEnd,timeline,timelineEnd,timeStamp,trace,warn, table').split(',');"
			. "		while (prop = properties.pop()) con[prop] = con[prop] || empty;"
			. "		while (method = methods.pop()) con[method] = con[method] || dummy;"
			. "	})(this.console = this.console || {});"
		"end"   => "</script>"
	];
	
	//startup
		//defaults & argument parsing
		private static function prepare_size($dimension){
			if(is_string($dimension)){
				switch ($dimension){
					case "unconstrained":
					case "none":
					case "n";
						$dimension == "UNCONSTRAINED";
					break;
				}
			}elseif(is_numeric($dimension)){
				$dimension = intval($dimension);
			}
			return $dimension;
		}
		
		private static function parse_size($input){
			$size = explode('/', $input);
			$console_width = $size[0];
			$key_width     = isset($size[1])? $size[1] : null;
			
			//handle unconstrained & numeric values
			$console_width = static::prepare_size($console_width);
			$console_width = static::prepare_size($console_width);
			
			
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
		
		//contructor method
		public function __construct($size = "medium/default", $plaintext = false){
			//case-insensitive cheking
			$size = is_string($size) ? strtolower($size) : $size;
			
			//smart guessing
			if($size == "CLI" || $size == "plaintext"){
				$size = "medium/default"; $plaintext == true;
			}elseif($size == "file"){
				$size = "N/ample"; $plaintext == true;
			}
			
			$size = static::parse_size($size);
			$console_width = $size['console'];
			$key_width     = $size['key'];
			
			$this->console_width = $console_width;
			$this->$key_width    = $key_width;
			
			$this->untitleds = 0;
			$this->plaintext = !!$plaintext;
		}
		
	//string formatters
		private static function format_header($str, $width = null, $symbol = "=", $indent = 0, $margin = " "){
			$indenttext = str_repeat($symbol, $indent);
			
			$str = $margin . $str . $margin;
			$len = strlen($str);
			$padN = $width - $len;
			
			$header = str_pad($str, $width, $symbol);
			return $header;
		}
		
		private static function format_subheader($str, $width){
			return "\n" . static::format_header($str, $width, $symbol = "-", 1);
		}
		
		private static function format_str($key, $val, $console_width, $key_width){
			$title = $key . ':';
			$title = str_pad($title, $key_width);
			$text = $title.$val;
			if(strlen($text) > $console_width){
				$titlelen = strlen($title);
				$break = '\n' . str_repeat(' ', $titlelen);
				$text = wordwrap($text, $console_width, $break, true);
			}
			return $text;
		}
		
		//master formatter (PUBLIC)
		public static function render($logs, $console_width, $plaintext){
			$rendered = [];
			foreach ($logs as $log){
				$kind = $log[0];
				$key  = $log[1];
				$val  = isset($log[2])? $log[2] : null;
				
				if($kind == "header"){
					$message = static::format_header($key, $console_width);
				}
				elseif($kind == "title"){
					$message = static::format_subheader($key, $console_width);	
				}
				elseif($kind == "string"){
					$message = static::format_str($key, $val, $console_width);	
				}
				elseif($kind == "json"){
					$message = $key;
				}
				
				$log = [$kind, $message];
				array_push($rendered, $log);
			}
			return $rendered;
		}
	
	
	//private log manipulators
		private function add_scalar($key, $val = null){
			if(!isset($val)){
				$val = $key;
				$key = 'untitled'. $this->untitleds;
				$this->untitleds += 1;
			}
			$val = strval($val);
			$log = ['string', $key, $val];
			array_push($this->logs, $log);
		}
		
		private function add_json($name, $value = null){
			$parsed_name = json_decode($name, true);
			if(is_array($parsed_name) || is_object($parsed_name)){
				//passed only json
				$value = $parsed_name;
			}elseif(is_array($name) || is_object($name){
				//passed only an array or object
				$value = $name;
			}
			elseif(is_scalar($name)){
				//this object has a title as well. print it before the object 
				$name = strval($name);
				$title = ['title', $name];
				array_push($this->logs, $title);
			}else{
				$value = $name;
			}
			$log = ['json', $value];
			array_push($this->logs, $log);
		}
		
	
	//public methods
	public function log($key = null, $val = null){
		if(!isset($val)){
			if(is_scalar($val)){$this->add_scalar($key, $val);}
			else               {$this->add_json($key, $val);}
		}
	}
	
	public function json(){
		$logs = json_encode($this->logs, JSON_PRETTY_PRINT);
		return $logs;
	}
	
	public function dump($clear = false){
		$logs = $this->logs;
		if($clear){
			$this->logs = [];
		}
		return $logs;
	}
	
	
	private static function create_script($logs){
		$func = function($carry, $log){
			$nl_JS = '\n';
			$nl_Tx = "\n";
			$NL    = $nl_JS + $nl_Tx;
			$NL   .= ($log['type'] == 'title') ? $NL : ''; //add a new line before title
			
			return $carry .$NL. $log["message"];
		};
		$lines = array_reduce($logs, $func, "");
		$script = ''.
			'console.log('.
			'JSON.parse(' . json_encode($lines)
			
		return 
	}
	
	public function script($inside = false){
		$start = $inside ? '' : $tags["start"];
		$end   = $inside ? '' : $tags["end"];
		$tags = $this->scripttags;
		$loglines = static::render($this->logs, $this->console_width);
		$logcmds  = create_script($loglines);
		
		$script = [
			$start
				$tags["pollyfill"],
				$logs,
			$end
		]
		$script .= $this->polyfill;
		
	}
	
	//apply string formatting
	
	
	
	public function __toString(){
		//TODO format strings
		if($this->plaintext){
			$logs = json_encode($this->logs, JSON_PRETTY_PRINT);
		}else{
			$logs = static::render($this->logs, $this->console_width);
		}
		
		return $logs;
	}
}