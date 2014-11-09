<?php
$__defaults = null;
$__env      = null;
Class Env{
	protected $name;
	protected $variables = array();

	public function __construct($variables = []){
		$this->variables = $variables;
	}

	public function __get($key){
		return $this->variables[$key];
	}

	public function __set($key, $value){
		$this->variables[$key] = $value;
	}
	public function import($variables = []){
		if(!is_array($variables)) return false;
		
		$alenght = count($variables);
		if($alenght){
			foreach ($variables as $k => $v) {
				$this->variables[$k] = $v;
			}	
		}
	}
	public function export(){
		Flight::set($this->variables);
	}
	
	public function __toString(){
		return var_export($this->variables, true);
	}
}
require_once __DIR__.'/defaults.php';
$ENV = new Env($__defaults);
@include_once './env.php';
$ENV->import($__env);
