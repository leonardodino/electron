<?php
	Class View{
		static function fetch($file, $data){
			ob_start();
			Flight::render($file, $data);
			return ob_get_clean();
		}
	}