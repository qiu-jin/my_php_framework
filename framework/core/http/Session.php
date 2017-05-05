<?php
namespace framework\core\http;

use framework\core\Config;

class Session
{
    private static $init;
    private static $session;
    
    //run this method in last line when load class
    public static function init()
    {
        if (self::$init) return;
        self::$init = true;
        $config = Config::get('session');
        if ($config) {
            isset($config['id']) && session_id($config['id']);
            isset($config['name']) && session_name($config['name']);
            isset($config['save_path']) && session_save_path($config['save_path']);
            isset($config['cache_expire']) && session_cache_expire($config['cache_expire']);
            isset($config['cache_limiter']) && session_cache_limiter($config['cache_limiter']);
            if (isset($config['cookie_params'])) {
                session_set_cookie_params(...$config['cookie_params']);
            }
            if (isset($config['save_handler'])) {
                session_set_save_handler(new $config['save_handler'][0]($config['save_handler'][1]));
            }
        }
        session_start();
    }
    
    public static function id()
    {
        return session_id();
    }
    
    public static function regen()
    {
        return session_regenerate_id();
    }
    
    public static function get($name = null, $default = null)
    {
        if ($name === null) {
            return $_SEESION;
        }
        return isset($_SEESION[$name]) ? $_SEESION[$name] : $default;
    }
    
    public static function set($name, $value)
    {
        $_SEESION[$name] = $value;
    }
    
    public static function delete($name)
    {
        isset($_SEESION[$name]) && unset($_SEESION[$name]);
    }
    
    public static function clear()
    {
        $_SEESION = [];
    }
    
    public static function destroy()
    {
        $_SEESION = [];
        session_unset();
        session_destroy();
    }

    public static function free()
    {

    }
}
Session::init();
