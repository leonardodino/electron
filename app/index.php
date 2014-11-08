<?php
    if(phpversion() < 5.4) {
    
      die('<h3>kunst-cms requires PHP 5.4 or higher.<br>You are currently running PHP '.phpversion().'.</h3><p>Please Update.</p>');
    
    } else {
        //BASE
        date_default_timezone_set('America/Sao_Paulo');
        
        //require_once './modules/mini/Slash.php';
        
//         require_once './modules/error/php_error.php';
//         \php_error\reportErrors();
        
        require_once './modules/flight/Flight.php';
        //CONFIG
        include_once './config.php';
        
        //BINDINGS
        require_once './modules/view/View.php';
        require_once './modules/helpers/Helpers.php';
        require_once './modules/init/Init.php';
        
        //CACHE BINDINGS
        require_once './modules/caching/Caching.php';
        
        //APP logic
        include_once './routes/Routes.php';
        #include_once './controllers/Controllers.php';
        Flight::start();
        
    }
?>
