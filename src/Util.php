<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 19-Oct-18
 * Time: 3:03 PM
 */

namespace As247\Flysystem\GoogleDrive;


class Util
{
    public static function cleanPath($path){
        if(is_array($path)){
            $path = implode('/',$path);
        }
        if(!is_string($path)){
            $path='/';
        }
        $path = trim($path);
        if ($path==='' || $path === '.') {
            return '/';
        }
        $path=str_replace('\\','/',$path);
        $path='/'.ltrim($path,'\/');

        return $path;
    }
}