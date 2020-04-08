<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 18-Oct-18
 * Time: 10:50 AM
 */

namespace As247\Flysystem\GoogleDrive;

use Google_Service_Drive_DriveFile;
use As247\Flysystem\GoogleDrive\Util;
class Cache
{
    const DIRMIME = 'application/vnd.google-apps.folder';


    protected $root;
    protected $files=[];
    protected $fullyCached=[];
    public function __construct($root)
    {
        $this->root=$root;
    }
    public function delete($path){
        $this->update($path,false);
    }
    public function clear($path){
        unset($this->files[Util::cleanPath($path)]);
    }
    public function deleteDir($path){
        $this->rename($path,false);
    }
    public function rename($from,$to){
        $from=Util::cleanPath($from);
        $remove=$to===false;
        $to=Util::cleanPath($to);
        foreach ($this->files as $key=>$file){
            if($remove) {
                if(strpos($key,$from)===0){
                    $this->files[$key]=false;
                }
            }else{
                $newKey = $this->str_replace_path($from, $to, $key);
                if ($newKey !== $key) {
                    $this->files[$newKey] = $file;
                    $this->files[$key] = false;
                }
            }
        }
        foreach ($this->fullyCached as $key=>$value){
            if($remove){
                if(strpos($key,$from)===0){
                    unset($this->fullyCached[$key]);
                }
            }else {
                $newKey = $this->str_replace_path($from, $to, $key);
                if ($newKey !== $key) {
                    $this->fullyCached[$newKey] = $value;
                    unset($this->fullyCached[$key]);
                }
            }
        }
    }
    public function setComplete($path){
        $this->fullyCached[Util::cleanPath($path)]=true;
    }
    public function isComplete($path){
        return !empty($this->fullyCached[Util::cleanPath($path)]);
    }
    public function set($path,$file){
        $path=Util::cleanPath($path);
        if(!isset($this->files[$path])){
            $this->files[$path]=$this->convertToDriveFile($file);
        }
        return $this;
    }
    public function update($path,$file){
        $path=Util::cleanPath($path);
        $this->files[$path]=$this->convertToDriveFile($file);
    }

	/**
	 * Check if we have path in cache
	 * @param $path
	 * @return bool
	 */
    public function has($path){
        $path=Util::cleanPath($path);
        if($this->isComplete(dirname($path))){//Parent directory fully indexed so we should have child
            return true;
        }
        return array_key_exists($path,$this->files);
    }

    /**
     * @param $path
     * @return Google_Service_Drive_DriveFile|false
     */
    public function get($path){
        $path=Util::cleanPath($path);
        return isset($this->files[$path])?$this->files[$path]:false;
    }
    public function getId($path){
        $path=Util::cleanPath($path);
        if($file=$this->get($path)){
            return $file->getId();
        }
        return false;
    }

    /**
     * @param $directory
     * @return Google_Service_Drive_DriveFile[]
     */
    public function listContents($directory){
        $directory=Util::cleanPath($directory);
        $results=[];
        foreach ($this->files as $path => $file) {
            if(!$file){
                continue;
            }
            if (strpos($path, $directory) === 0 && $path!==$directory) {
                $results[$path] = $file;
            }
        }
        return $results;
    }
    public function convertToDriveFile($file){
        if($file && !$file instanceof Google_Service_Drive_DriveFile){
            $dFile=new Google_Service_Drive_DriveFile();
            $dFile->setId($file);
            if($file===$this->root){
                $dFile->setMimeType(static::DIRMIME);
            }
            return $dFile;
        }
        return $file;
    }
    public function showDebug(){
        $debug=[];
        foreach ($this->files as $path=>$file){
            if(!$file){
                $debug[$path]='Not exists';
            }else {
                $debug[$path] = $file->getMimeType() === static::DIRMIME ? 'dir' : 'file';
            }
        }
        echo json_encode($debug,JSON_PRETTY_PRINT);
    }
    protected function str_replace_path($search,$replace,$subject){
        $pos = strpos($subject, $search);
        if ($pos === 0) {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }
        return $subject;

    }
}
