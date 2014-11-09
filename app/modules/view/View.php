<?php
	//provisory method
	//TODO implement staceyUP's dynTemplate
	Class View{
		static function render($file, $data){
			Flight::render($file, $data);
		}
		static function fetch($file, $data){
			ob_start();
			self::render($file, $data);
			return ob_get_clean();
		}
	}