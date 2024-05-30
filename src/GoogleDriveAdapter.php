<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 04-Oct-18
 * Time: 10:46 PM
 */

namespace As247\Flysystem\GoogleDrive;
use As247\CloudStorages\Storage\GoogleDrive;

use As247\CloudStorages\Support\StorageToAdapter;
use Google_Service_Drive;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;

class GoogleDriveAdapter implements FilesystemAdapter
{
	use StorageToAdapter;
    protected $prefixer;
    public function __construct(Google_Service_Drive $service, $options = [])
    {
		if(!is_array($options)){
			$options=['root'=>$options];
		}
        $this->storage = new GoogleDrive($service,$options);
        $this->prefixer = new PathPrefixer($options['google_drive_adapter_prefix']??'', DIRECTORY_SEPARATOR);
    }
}
