<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 04-Oct-18
 * Time: 10:46 PM
 */

namespace As247\Flysystem\GoogleDrive;

use As247\Flysystem\DriveSupport\Exception\InvalidStreamProvided;
use As247\Flysystem\DriveSupport\Exception\UnableToCopyFile;
use As247\Flysystem\DriveSupport\Exception\UnableToCreateDirectory;
use As247\Flysystem\DriveSupport\Exception\UnableToDeleteDirectory;
use As247\Flysystem\DriveSupport\Exception\UnableToDeleteFile;
use As247\Flysystem\DriveSupport\Exception\UnableToMoveFile;
use As247\Flysystem\DriveSupport\Exception\UnableToReadFile;
use As247\Flysystem\DriveSupport\Exception\UnableToWriteFile;
use Google_Service_Drive;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
class GoogleDriveAdapter extends AbstractAdapter
{
    protected $driver;


    public function __construct(Google_Service_Drive $service, $options = [])
    {
        $this->driver = new Driver($service,$options);
    }
    public function getDriver(){
    	return $this->driver;
	}


	/**
	 * @inheritDoc
	 */
	public function write($path, $contents, Config $config=null)
	{
		try {
			$this->driver->write($path, $contents, $config);
			return $this->driver->getMetadata($path);
		}catch (UnableToWriteFile $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function writeStream($path, $resource, Config $config)
	{
		try {
			$this->driver->writeStream($path, $resource, $config);
			return $this->driver->getMetadata($path);
		}catch (UnableToWriteFile $e){
			return false;
		}catch (InvalidStreamProvided $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function update($path, $contents, Config $config)
	{
		return $this->write($path,$contents,$config);
	}

	/**
	 * @inheritDoc
	 */
	public function updateStream($path, $resource, Config $config)
	{
		return $this->writeStream($path,$resource,$config);
	}

	/**
	 * @inheritDoc
	 */
	public function rename($path, $newpath)
	{
		try {
			$this->driver->move($path, $newpath);
			return true;
		}catch (UnableToMoveFile $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function copy($path, $newpath)
	{
		try {
			$this->driver->copy($path, $newpath);
			return true;
		}catch (UnableToCopyFile $exception){
			echo $exception->getMessage();
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function delete($path)
	{
		if(!$this->has($path)){
			return false;
		}
		try {
			$this->driver->delete($path);
			return true;
		}catch (UnableToDeleteFile $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDir($dirname)
	{
		if(!$this->has($dirname)){
			return false;
		}
		try {
			$this->driver->deleteDirectory($dirname);
			return true;
		}catch (UnableToDeleteDirectory $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function createDir($dirname, Config $config)
	{
		try {
			$this->driver->createDirectory($dirname, $config);
			return $this->driver->getMetadata($dirname);
		}catch (UnableToCreateDirectory $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function setVisibility($path, $visibility)
	{
		$this->driver->setVisibility($path,$visibility);
		return $this->driver->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function has($path)
	{
		return (bool)$this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function read($path)
	{
		return ['contents'=>$this->driver->read($path)];
	}

	/**
	 * @inheritDoc
	 */
	public function readStream($path)
	{
		try {
			return ['stream'=>$this->driver->readStream($path)];
		}catch (UnableToReadFile $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function listContents($directory = '', $recursive = false)
	{
		return array_values(iterator_to_array($this->driver->listContents($directory,$recursive),false));
	}

	/**
	 * @inheritDoc
	 */
	public function getMetadata($path)
	{
		$meta=$this->driver->getMetadata($path);
		return $meta?$meta->toArrayV1():false;
	}

	/**
	 * @inheritDoc
	 */
	public function getSize($path)
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function getMimetype($path)
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function getTimestamp($path)
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function getVisibility($path)
	{
		return $this->getMetadata($path);
	}
}
