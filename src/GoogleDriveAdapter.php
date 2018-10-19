<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 04-Oct-18
 * Time: 10:46 PM
 */

namespace As247\Flysystem\GoogleDrive;

use As247\Flysystem\GoogleDrive\Concerns\Adapter;
use As247\Flysystem\GoogleDrive\Concerns\Helpers;
use As247\Flysystem\GoogleDrive\Concerns\InteractWithApi;
use As247\Flysystem\GoogleDrive\Concerns\Read;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Service_Drive_Permission;
use Google_Http_MediaFileUpload;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
class GoogleDriveAdapter extends AbstractAdapter
{

    use InteractWithApi,Adapter,Read,Helpers;
    /**
     * Fetch fields setting for get
     *
     * @var string
     */
    const FETCHFIELDS_GET = 'id,name,mimeType,modifiedTime,parents,permissions,size,webContentLink,webViewLink';

    /**
     * Fetch fields setting for list
     *
     * @var string
     */
    const FETCHFIELDS_LIST = 'files(FETCHFIELDS_GET),nextPageToken';

    /**
     * MIME tyoe of directory
     *
     * @var string
     */
    const DIRMIME = 'application/vnd.google-apps.folder';

    /**
     * Default options
     *
     * @var array
     */
    protected static $defaultOptions = [
        'spaces' => 'drive',
        'useHasDir' => false,
        'additionalFetchField' => '',
        'publishPermission' => [
            'type' => 'anyone',
            'role' => 'reader',
            'withLink' => true
        ],
        'appsExportMap' => [
            'application/vnd.google-apps.document' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.google-apps.spreadsheet' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.google-apps.drawing' => 'application/pdf',
            'application/vnd.google-apps.presentation' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.google-apps.script' => 'application/vnd.google-apps.script+json',
            'default' => 'application/pdf'
        ],
        // Default parameters for each command
        // see https://developers.google.com/drive/v3/reference/files
        // ex. 'defaultParams' => ['files.list' => ['includeTeamDriveItems' => true]]
        'defaultParams' => [],
        // Team Drive Id
        'teamDriveId' => null,
        // Corpora value for files.list with the Team Drive
        'corpora' => 'teamDrive'
    ];
    protected $root;
    protected $cache;
    protected $options;
    protected $spaces;
    protected $publishPermission;
    protected $fetchfieldsGet;
    protected $fetchfieldsList;
    protected $additionalFields;

    public function __construct(Google_Service_Drive $service, $root = null, $options = [])
    {
        $this->service = $service;
        $this->setPathPrefix($root);

        $this->options = array_replace_recursive(static::$defaultOptions, $options);

        $this->publishPermission = $this->options['publishPermission'];
        $this->spaces = $this->options['spaces'];
        $this->fetchfieldsGet = self::FETCHFIELDS_GET;
        if ($this->options['additionalFetchField']) {
            $this->fetchfieldsGet .= ',' . $this->options['additionalFetchField'];
            $this->additionalFields = explode(',', $this->options['additionalFetchField']);
        }
        $this->fetchfieldsList = str_replace('FETCHFIELDS_GET', $this->fetchfieldsGet, self::FETCHFIELDS_LIST);
        if (isset($this->options['defaultParams']) && is_array($this->options['defaultParams'])) {
            $this->defaultParams = $this->options['defaultParams'];
        }

        if ($this->options['teamDriveId']) {
            $this->setTeamDriveId($this->options['teamDriveId'], $this->options['corpora']);
        }
        $this->cache=new Cache($this->root);
    }
    function setPathPrefix($prefix)
    {
        if(!$prefix){
            $prefix='root';
        }
        $this->pathPrefix=$prefix;
        $this->root=$prefix;
    }
    function getPathPrefix()
    {
        return $this->pathPrefix;
    }










}