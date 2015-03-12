<?php
//include route files
//foreach(glob('./routes/*.route.php') as $route){include_once $route;}
//use Caching_FileFactory as FF;

Flight::route('/', function(){
	$kind = Flight::get('request.kind');
	Flight::getCached($kind);
	
	$content = View::fetch('test.html', ['page'=>['title'=> 'Hellow World', 'text'=> 'lorem ipsum!']]);
	Caching::extraEasy($content, $kind);
});

Flight::route('/child', function(){
	$kind = Flight::get('request.kind');
	Flight::getCached($kind);
	
	$data = ['page'=>['title'=> 'Hellow children World', 'text'=> "ipsum ipsum! $kind"]];
	if($kind === 'json'){
		$content = json_encode($data, JSON_PRETTY_PRINT);
	}else{
		$content = View::fetch('test.html', $data);
	}
	Caching::extraEasy($content, $kind);
});


Flight::route('/cachin', function(){
	View::render('test.html', ['page'=>['title'=> 'Hellow Cache', 'text'=> 'caching ipsum!']]);
	Flight::arrive();
});
