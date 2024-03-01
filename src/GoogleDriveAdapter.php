<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 04-Oct-18
 * Time: 10:46 PM
 */

namespace As247\Flysystem\GoogleDrive;

use As247\CloudStorages\Storage\GoogleDrive;
use As247\CloudStorages\Support\StorageToAdapterV1;
use Google_Service_Drive;
use League\Flysystem\Adapter\AbstractAdapter;

class GoogleDriveAdapter extends AbstractAdapter
{
	use StorageToAdapterV1;

    public function __construct(Google_Service_Drive $service, $options = [])
    {
		if(!is_array($options)){
			$options=['root'=>$options];
		}
        $this->storage = new GoogleDrive($service,$options);
        $this->throwException=$options['debug']??'';
    }
}
