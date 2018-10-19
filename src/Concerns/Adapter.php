<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 11-Oct-18
 * Time: 8:24 PM
 */

namespace As247\Flysystem\GoogleDrive\Concerns;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;

trait Adapter
{
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path,$contents,$config);
    }

    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    public function rename($path, $newpath)
    {
        return (bool)$this->mv($path,$newpath);
    }

    public function copy($path, $newpath)
    {
        return (bool)$this->cp($path,$newpath);
    }

    public function delete($path)
    {
        return (bool)$this->rm($path);
    }

    public function deleteDir($dirname)
    {
        return $this->rmdir($dirname);
    }

    public function createDir($dirname, Config $config)
    {
        $id = $this->ensureDirectory($dirname);
        if($id) {
            return $this->normalizeFileInfo($this->findFile($dirname), $dirname);
        }
        return false;
    }

    public function setVisibility($path, $visibility)
    {
        $result = ($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? $this->publish($path) : $this->unPublish($path);
        if ($result) {
            return compact('path', 'visibility');
        }
        return false;
    }
}