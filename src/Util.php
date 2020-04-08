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
	/**
	 * Cleanup path, return path as absolute
	 * @param $path
	 * @param string $return
	 * @return array|string
	 */
    public static function cleanPath($path,$return='string'){
        if(!is_array($path)){
        	$path=str_replace('\\','/',$path);
            $path = explode('/',$path);
        }
        $path=array_filter($path,function($v){
        	if(strlen($v)===0 || $v=='.' || $v=='..' || $v=='/'){
        		return false;
			}
        	return true;
		});
        if($return=='string'){
        	$path='/'.join('/',$path);
		}else{
        	array_unshift($path,'/');
		}
        return $path;
    }
	/**
	 * Read file chunk
	 *
	 * @param resource $handle
	 * @param int $chunkSize
	 *
	 * @return string
	 */
	public static function readFileChunk($handle, $chunkSize)
	{
		$byteCount = 0;
		$giantChunk = '';
		while (! feof($handle)) {
			// fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
			$chunk = fread($handle, 8192);
			$byteCount += strlen($chunk);
			$giantChunk .= $chunk;
			if ($byteCount >= $chunkSize) {
				return $giantChunk;
			}
		}
		return $giantChunk;
	}

	/**
	 * Return bytes from php.ini value
	 *
	 * @param string $iniName
	 * @param string $val
	 * @return number
	 */
	public static function getIniBytes($iniName = '', $val = '')
	{
		if ($iniName !== '') {
			$val = ini_get($iniName);
			if ($val === false) {
				return 0;
			}
		}
		$val = trim($val, "bB \t\n\r\0\x0B");
		$last = strtolower($val[strlen($val) - 1]);
		$val = (int)$val;
		switch ($last) {
			case 't':
				$val *= 1024;
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}
		return $val;
	}
}
