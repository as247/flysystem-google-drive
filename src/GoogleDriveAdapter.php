<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 04-Oct-18
 * Time: 10:46 PM
 */

namespace As247\Flysystem\GoogleDrive;

use Google_Service_Drive;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use As247\Flysystem\GoogleDrive\Exceptions\GoogleDriveException;
class GoogleDriveAdapter extends AbstractAdapter
{
    protected $driver;


    public function __construct(Google_Service_Drive $service, $root = null, $options = [])
    {
        $this->driver = new Driver($service,$root,$options);
    }
    public function getDriver(){
    	return $this->driver;
	}


	/**
	 * @inheritDoc
	 */
	public function write($path, $contents, Config $config)
	{
		try {
			$this->driver->write($path, $contents, $config);
			return $this->driver->getMetadata($path);
		}catch (GoogleDriveException $e){
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
		}catch (GoogleDriveException $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function update($path, $contents, Config $config)
	{
		try {
			$this->driver->write($path, $contents, $config);
			return $this->driver->getMetadata($path);
		}catch (GoogleDriveException $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function updateStream($path, $resource, Config $config)
	{
		try {
			$this->driver->writeStream($path, $resource, $config);
			return $this->driver->getMetadata($path);
		}catch (GoogleDriveException $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function rename($path, $newpath)
	{
		try {
			$this->driver->move($path, $newpath);
			return true;
		}catch (GoogleDriveException $e){
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
		}catch (GoogleDriveException $exception){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function delete($path)
	{
		try {
			$this->driver->delete($path);
			return true;
		}catch (GoogleDriveException $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDir($dirname)
	{
		try {
			$this->driver->deleteDirectory($dirname);
			return true;
		}catch (GoogleDriveException $e){
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
		}catch (GoogleDriveException $e){
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
		return $this->driver->exists($path);
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
		}catch (GoogleDriveException $e){
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function listContents($directory = '', $recursive = false)
	{
		return array_values(iterator_to_array($this->driver->listContents($directory,$recursive)));
	}

	/**
	 * @inheritDoc
	 */
	public function getMetadata($path)
	{
		return $this->driver->getMetadata($path);
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
		return ['visibility'=>$this->driver->getVisibility($path),'path'=>$path];
	}
}
