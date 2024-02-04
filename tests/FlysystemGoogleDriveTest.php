<?php


namespace As247\Flysystem\GoogleDrive\Tests;

use As247\Flysystem\GoogleDrive\GoogleDriveAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;

class FlysystemGoogleDriveTest extends FilesystemAdapterTestCase
{

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $client = new \Google_Client();
        $client->setClientId($_ENV['googleClientId']);
        $client->setClientSecret($_ENV['googleClientSecret']);
        $client->fetchAccessTokenWithRefreshToken($_ENV['googleRefreshToken']);
        $service = new \Google_Service_Drive($client);
        $options=[
            'root'=>$_ENV['googleFolderId'],
            'prefix'=>$_ENV['googlePrefix'],
            'teamDrive'=>!empty($_ENV['googleIsTeamDrive']) && $_ENV['googleIsTeamDrive']!=='false',
            //'cache'=>__DIR__.'/cache.sqlite',
            //'debug'=>true,
            //'useTrash'=>false,
            //'log'=>true,
        ];
        $adapter=new GoogleDriveAdapter($service, $options);
        return $adapter;
    }
}
