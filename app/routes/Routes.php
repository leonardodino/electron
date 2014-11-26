<?php
//include route files
//foreach(glob('./routes/*.route.php') as $route){include_once $route;}
//use Caching_FileFactory as FF;

Flight::route('/', function(){
	$content = View::fetch('test.html', ['page'=>['title'=> 'Hellow World', 'text'=> 'lorem ipsum!']]);
	Caching::extraEasy($content);
});

Flight::route('/child', function(){
	$content = View::fetch('test.html', ['page'=>['title'=> 'Hellow children World', 'text'=> 'ipsum ipsum!']]);
	Caching::extraEasy($content);
});

Flight::route('/cachin', function(){
	View::render('test.html', ['page'=>['title'=> 'Hellow Cache', 'text'=> 'caching ipsum!']]);
	Flight::arrive();
});
