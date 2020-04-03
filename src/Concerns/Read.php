<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 11-Oct-18
 * Time: 8:43 PM
 */

namespace As247\Flysystem\GoogleDrive\Concerns;

use Google_Service_Drive_DriveFile;
use Google_Service_Drive;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Stream;

/**
 * Trait Read
 * @package As247\Flysystem\GoogleDrive\Concerns
 * @property Google_Service_Drive $service
 */
trait Read
{
    public function isDirectory($path){
        $meta=$this->getMetadata($path);
        return isset($meta['type'])&& $meta['type']==='dir';
    }
    public function isFile($path){
        $meta=$this->getMetadata($path);
        return isset($meta['type'])&& $meta['type']==='file';
    }

    function getSize($path)
    {
        return $this->getMetadata($path);
    }

    public function has($path)
    {
        return (bool)$this->getMetadata($path);
    }

    public function read($path)
    {
        if($readStream=$this->readStream($path)){
            return ['type' => 'file', 'path' => $path, 'contents' => (string)stream_get_contents($readStream['stream'])];
        }
        return false;
    }
    public function readStream($path){
        $file = $this->findFile($path);
        if($file instanceof Google_Service_Drive_DriveFile) {
            if($this->isFile($path)){
                $this->service->getClient()->setUseBatch(true);
                $stream=null;
                if ($response = $this->filesGet($file->getId(),['alt'=>'media'])) {
                    $client=$this->service->getClient()->authorize();
                    try {
                        $response = $client->send($response, ['stream' => true]);
                        if ($response->getBody() instanceof Stream) {
                            $stream = $response->getBody()->detach();
                        }
                    }catch (GuzzleException $e) {
                    }
                }
                $this->service->getClient()->setUseBatch(false);
                if(is_resource($stream)){
                    return compact('stream');
                }
            }

        }
        return false;
    }


    public function listContents($directory = '', $recursive = false)
    {
        return array_values($this->fetchDirectory($directory,$recursive));
    }

	/**
	 * @param $path
	 * @return bool|array
	 */
    public function getMetadata($path)
    {
        if ($obj = $this->findFile($path)) {
            if($path instanceof Google_Service_Drive_DriveFile){
                $path=null;
            }
            if ($obj instanceof Google_Service_Drive_DriveFile) {
                return $this->normalizeFileInfo($obj,$path);
            }
        }
        return false;
    }

    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    public function getVisibility($path){
        return ['path'=>$path,'visibility'=>$this->getRawVisibility($path)];
    }
    public function getUrl($path){
        if($file=$this->findFile($path)){
            return $file->webViewLink;
        }
        return null;
    }
}
