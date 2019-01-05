<?php
/**
 * Utility Class to provide cache functions
 *
 * User: fabian
 * Date: 31.12.18
 * Time: 02:24
 */

namespace Lara\utilities;


class CacheUtility
{
    /**
     * @param $key
     * @param \Closure $closure
     * @return mixed
     */
    static function remember($key, \Closure $closure)
    {
        $viewmode = \Session::get('view_mode', 'light');
        $user = \Auth::hasUser() ? \Auth::user()->id:'';
        
        return \Cache::rememberForever($key.'-'.$viewmode.'-'.$user, $closure);
    }
    
    static function forget()
    {
        \Artisan::call('cache:clear');
    }
}