<?php
	//Helpers::route_slashes();
	Flight::set('request.url', Helpers::cleanUrl());
	
	if(substr($uri, -5) == ".json" ){
		header('Content-type: application/json; charset=utf-8');
		Flight::set('request.kind', 'json');
	}else{
		Flight::set('request.kind', 'html');
	}
	