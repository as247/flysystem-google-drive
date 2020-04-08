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
			return $this->driver->upload($path, $contents, $config);
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
			return $this->driver->upload($path, $resource, $config);
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
			return $this->driver->upload($path, $contents, $config);
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
			return $this->driver->upload($path, $resource, $config);
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
			return $this->driver->move($path, $newpath);
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
			return $this->driver->copy($path, $newpath);
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
			return $this->driver->delete($path);
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
			return $this->driver->delete($dirname, true);
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
			return $this->driver->createDirectory($dirname, $config);
		}catch (GoogleDriveException $e){
			echo $e->getMessage();
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function setVisibility($path, $visibility)
	{
		return $this->driver->setVisibility($path,$visibility);
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
		return $this->driver->read($path);
	}

	/**
	 * @inheritDoc
	 */
	public function readStream($path)
	{
		try {
			return $this->driver->readStream($path);
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
