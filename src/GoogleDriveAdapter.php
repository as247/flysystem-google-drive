<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 04-Oct-18
 * Time: 10:46 PM
 */

namespace As247\Flysystem\GoogleDrive;
use As247\Flysystem\DriveSupport\Support\DriverForAdapter;
use Google_Service_Drive;
use League\Flysystem\Adapter\AbstractAdapter;

class GoogleDriveAdapter extends AbstractAdapter
{
	use DriverForAdapter;

    public function __construct(Google_Service_Drive $service, $options = [])
    {
        $this->driver = new Driver($service,$options);
    }




}
