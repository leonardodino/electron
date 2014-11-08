<?php
Class Caching_FileFactory{
    
    //STRING HELPERS (TODO: pass to Helpers module)
    static function add_trailing_character($string, $char){
        if(substr($string, -1) !== $char){
            $string = $string . $char;
        }
        return $string;
    }
    static function add_trailing_slash($url){return self::add_trailing_character($url,  '/');}
    static function add_trailing_dot($path) {return self::add_trailing_character($path, '.');}
    
    
    
    //DIRECTORY HELPERS:
    static function dirmtime($dir){
        $most_recent_time = 0;
        $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir), 
                        RecursiveIteratorIterator::SELF_FIRST
                    );
        
        foreach($iterator as $file) {
            if($file->isDir()){
                $dir = self::add_trailing_slash($file);
                $dir = self::add_trailing_dot($dir);
                $mod_date = filemtime($dir);
                if($mod_date > $most_recent_time){
                    $most_recent_time = $mod_date;
                }
            }
        }
        return $most_recent_time;
    }
    
    static function makeCleanDir($dir){
        if(file_exists($dir)){
            if(!is_dir($dir)){unlink($dir);}
        }else if($dir !== NULL){
            mkdir($dir, 0777, true);
        }else{
            $dir = false;
            $err = new Exception('could not make dir');
            
            Flight::error($err);
        }
        
        
        return $dir;
    }
    
    
    
    //URL->FILESYSTEM resolving
    static function URL_to_CACHEPATH($url, $kind){
        $url = strtok($url,'?'); //sanitize query
        $url = self::add_trailing_slash($url);
        $url = ltrim($url, '/'); //remove beggining slash
        
        $cache_folder = Flight::get('caching.path');
        $cache_folder = self::add_trailing_slash($cache_folder);
        
        return $cache_folder.$url;
    }
    
    static function URL_to_CACHEFILE($url, $kind){
        $cache_path = self::URL_to_CACHEPATH($url, $kind);
        return $cache_path.'_res.'.$kind;
    }
    
    static function URL_to_PATH($url, $kind){
        $url= strtok($url,'?'); //sanitize query
        $url = self::add_trailing_slash($url);
        $url = ltrim($url, '/'); //remove beggining slash
        
        $content_folder = Flight::get('caching.path');
        $content_folder = self::add_trailing_slash($cache_folder);
        
        return $content_folder.$url;
    }
    
    
    //FINGERPRINTS standards
    static function fingerprint($content, $last_modified = NULL,  $url = NULL, $kind = "html"){
        $url           = $url ?: $_SERVER["REQUEST_URI"];
        $last_modified = $last_modified ?: time();
        
        $etag          = sprintf('"%s-%s"', $last_modified, md5($content));
        
        $fingerprint = [
            "modified" => $last_modified,
            "url"      => $url,
            "kind"     => $kind,
            "content"  => $content,
            "etag"     => $etag
        ];
        
        return $fingerprint;
    }
    
    static function fingerprint_from_file($file, $url = NULL, $kind = 'html'){
        try{
            if(file_exists($file)){
                $last_modified = filemtime($file);
                $content       = file_get_contents($file);
                
                $fingerprint = self::fingerprint($content, $last_modified, $url, $kind);
            }else{
                throw new Exception('cachefile_does_not_exists');
                $fingerprint = false;
            }
        }catch (Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), '\n';
        }
        return $fingerprint;
    }
    
    
}