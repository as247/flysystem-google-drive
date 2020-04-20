<?php


namespace As247\Flysystem\GoogleDrive;

use As247\Flysystem\GoogleDrive\Util as GdUtil;
use Exception;
use Google_Http_MediaFileUpload;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Service_Drive_Permission;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use As247\Flysystem\GoogleDrive\Exceptions\GoogleDriveException;
use Psr\Http\Message\RequestInterface;

class Driver implements DriverInterface
{
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
		'defaultParams' => [
			'files.list'=>[
				'corpora'=>'user'// default is user
			],
		],
		// Team Drive Id
		'teamDriveId' => null,
	];
	protected $options;
	protected $spaces;
	protected $publishPermission;
	/**
	 * Fetch fields setting for get
	 *
	 * @var string
	 */
	protected $fetchFieldsGet='id,name,mimeType,modifiedTime,parents,permissions,size,webContentLink,webViewLink';
	protected $fetchFieldsList='files({{fieldsGet}}),nextPageToken';
	protected $additionalFields;
	/**
	 * Google_Service_Drive instance
	 *
	 * @var Google_Service_Drive
	 */
	protected $service;

	protected $defaultParams;

	protected $logger;
	protected $logQuery=true;
	protected $root;//Root id
	/**
	 * @var Cache
	 */
	protected $cache;
	protected $maxFolderLevel=128;
	public function __construct(Google_Service_Drive $service, $root, $options)
	{
		$this->service=$service;
		$this->options = array_replace_recursive(static::$defaultOptions, $options);

		$this->publishPermission = $this->options['publishPermission'];
		$this->spaces = $this->options['spaces'];
		if ($this->options['additionalFetchField']) {
			$this->fetchFieldsGet .= ',' . $this->options['additionalFetchField'];
			$this->additionalFields = explode(',', $this->options['additionalFetchField']);
		}
		$this->fetchFieldsList = str_replace('{{fieldsGet}}', $this->fetchFieldsGet, $this->fetchFieldsList);
		if (isset($this->options['defaultParams']) && is_array($this->options['defaultParams'])) {
			$this->defaultParams = $this->options['defaultParams'];
		}

		$this->setRoot($root);
		if ($this->options['teamDriveId']) {
			$this->setTeamDriveId($this->options['teamDriveId'], $this->options['corpora']);
		}
		$this->logger=new Logger();

	}


	function setRoot($root){
		$this->root=$root;
		$this->cache=new Cache($this->root);
	}

	/**
	 * @param string|array $path create directory structure
	 * @return bool|string folder id
	 * @throws GoogleDriveException
	 */
	protected function ensureDirectory($path){
		$path=GdUtil::cleanPath($path);
		if ($this->isFile($path)) {
			throw new GoogleDriveException('Cannot create directory '.$path.': File exists',100);
		}
		if(isset($this->maxFolderLevel)){
			$nestedFolderLevel=count(explode('/',$path))-1;
			if($nestedFolderLevel>$this->maxFolderLevel){// -1 for /
				throw new GoogleDriveException("Could not create directory ".$path." maximum nesting folder exceeded",102);
			}
		}
		$this->logger->debug("Ensure directory $path");
		list($parent, $paths, $currentPaths) = $this->detectPath($path);

		if (count($paths) != 0) {
			while (null!==($name = array_shift($paths))) {
				$currentPaths[] = $name;
				$currentPathString=join('/',$currentPaths);
				if($this->isFile($currentPaths)){
					throw new GoogleDriveException("Cannot create $currentPathString, file already exists");
				}

				$created = $this->dirCreate($name, $parent);
				$this->cache->update($currentPaths,$created);
				$this->cache->setComplete($currentPaths);
				$parent = $created->getId();
			}
		}
		return $parent;
	}




	/**
	 * @inheritDoc
	 */
	public function write(string $path, string $contents, Config $config): void
	{
		$this->upload($path,$contents,$config);
	}

	/**
	 * @inheritDoc
	 */
	public function writeStream(string $path, $contents, Config $config): void
	{
		$this->upload($path,$contents,$config);
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDirectory(string $path): void
	{
		if ($this->isFile($path)) {
			throw new GoogleDriveException("$path is file");
		}
		$file = $this->find($path);
		if(!$file){
			throw new GoogleDriveException("$path not found");
		}
		if($file->getId()===$this->root){
			throw new GoogleDriveException("Root directory cannot be deleted");
		}
		$this->filesDelete($file);
		$this->cache->delete($path);
	}

	/**
	 * @inheritDoc
	 */
	public function visibility(string $path): array
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function mimeType(string $path): array
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function lastModified(string $path): array
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function fileSize(string $path): array
	{
		return $this->getMetadata($path);
	}


	/**
	 * Find for path
	 * @param $path
	 * @return false|Google_Service_Drive_DriveFile
	 */
	protected function find($path)
	{
		if ($path instanceof Google_Service_Drive_DriveFile) {
			return $path;
		}

		list(,$paths)=$this->detectPath($path);

		if(count($paths)>=2){
			//remaining 2 segments /A/B/C/file.txt
			//C not exists mean file.txt also not exists
			return false;
		}
		if($this->cache->has($path)){
			return $this->cache->get($path);
		}
		return false;

	}

	public function createDirectory(string $path, Config $config):void {
		$this->ensureDirectory(GdUtil::cleanPath($path));
		$result=$this->getMetadata($path);
		if ($visibility = $config->get('visibility')) {
			$this->setVisibility($path, $visibility);
			$result['visibility'] = $visibility;
		}
	}

	public function copy(string $fromPath, string $toPath, Config $config=null):void
	{
		$fromPath=GdUtil::cleanPath($fromPath);
		$toPath=GdUtil::cleanPath($toPath);
		if ($this->isDirectory($fromPath)) {
			throw new GoogleDriveException("$fromPath is directory",101);
		}
		$from = $this->find($fromPath);
		if ($this->isDirectory($toPath)) {
			throw new GoogleDriveException("$toPath is directory",101);
		}
		if($this->exists($toPath)){
			$this->delete($toPath);
		}
		$paths = $this->parsePath($toPath);
		$fileName = array_pop($paths);
		$dirName = $paths;
		$parents = [$this->ensureDirectory($dirName)];
		$file = new Google_Service_Drive_DriveFile();
		$file->setName($fileName);
		$file->setParents($parents);
		$newFile = $this->filesCopy($from->id, $file);
		$this->cache->update($toPath,$newFile);
	}

	public function move(string $fromPath, string $toPath, Config $config=null):void
	{
		$fromPath=GdUtil::cleanPath($fromPath);
		$toPath=GdUtil::cleanPath($toPath);
		if($fromPath===$toPath){
			return ;
		}
		$from=$this->find($fromPath);
		if(!$from){
			throw new GoogleDriveException("$fromPath not found");
		}
		$oldParent=$from->getParents()[0];
		$newParentId=null;
		if($this->isFile($from)){//we moving file
			if($this->exists($toPath)) {
				if ($this->isDirectory($toPath)) {//Destination path is directory
					throw new GoogleDriveException("Destination path exists as a directory, cannot overwrite");
				}else{
					$this->delete($toPath);
				}
			}
		}else{//we moving directory
			if($this->exists($toPath)) {
				if ($this->isFile($toPath)) {//Destination path is file
					throw new GoogleDriveException("Destination path exists as a file, cannot overwrite");
				}else{
					$this->deleteDirectory($toPath);//overwrite, remove it first
				}
			}
		}
		$paths=$this->parsePath($toPath);
		$fileName = array_pop($paths);
		$dirName = $paths;
		$newParentId = $this->ensureDirectory($dirName);
		$file = new Google_Service_Drive_DriveFile();
		$file->setName($fileName);
		$opts = [
			'fields' => $this->fetchFieldsGet
		];
		if ($newParentId !== $oldParent) {
			$opts['addParents'] = $newParentId;
			$opts['removeParents'] = $oldParent;
		}
		$this->filesUpdate($from->getId(), $file, $opts);
		$this->cache->rename($fromPath,$toPath);
	}

	/**
	 * Delete file only, if force is set to true it also delete directory
	 * @param $path
	 * @return void
	 */
	public function delete(string $path):void
	{
		if ($this->isDirectory($path)) {
			throw new GoogleDriveException("$path is directory");
		}
		$file = $this->find($path);
		if(!$file){
			throw new GoogleDriveException("$path not found");
		}
		if($file->getId()===$this->root){
			throw new GoogleDriveException("Root directory cannot be deleted");
		}
		$this->filesDelete($file);
		$this->cache->delete($path);
	}


	/**
	 * Upload|Update item
	 *
	 * @param string $path
	 * @param string|resource $contents
	 * @param Config $config
	 *
	 * @return array|false item info array
	 */
	protected function upload($path, $contents, Config $config)
	{
		$paths=$this->parsePath($path);
		$fileName = array_pop($paths);
		$dirName = $paths;
		//Try to find file before, because if it was removed before, ensure directory will recreate same directory and it may available again
		$parentId = $this->ensureDirectory($dirName);
		if (!$parentId) {
			return false;
		}
		$mode = 'update';
		$mime = $config->get('mimetype');
		$file = new Google_Service_Drive_DriveFile();
		$srcFile = $this->find($path);
		if (!$srcFile) {
			$mode = 'insert';
			$file->setName($fileName);
			$file->setParents([
				$parentId
			]);
		} else {
			if ($srcFile->getMimeType() === static::DIRMIME) {//directtory exists in same path
				return false;
			}
		}

		$fstat= $isResource = false;

		if (is_resource($contents)) {
			@rewind($contents);
			$fstat = @fstat($contents);
			if (!empty($fstat['size'])) {
				$isResource = true;
			}else{//empty resource is not allowed
				throw new GoogleDriveException("Resource is empty");
			}
		}
		// set chunk size (max: 100MB)
		$chunkSizeBytes = 5 * 1024 * 1024;
		if ($isResource) {
			$memory = GdUtil::getIniBytes('memory_limit');
			if ($memory > 0) {
				$chunkSizeBytes = max(262144, min([
					$chunkSizeBytes,
					(intval($memory / 4 / 256) * 256)
				]));
			}
			if (isset($fstat['size']) && $fstat['size'] < $chunkSizeBytes) {
				$isResource = false;
				$contents = stream_get_contents($contents);
			}
		}

		if($mime) {
			$file->setMimeType($mime);
		}
		$obj=null;
		if ($isResource) {
			$client = $this->service->getClient();
			// Call the API with the media upload, defer so it doesn't immediately return.
			$client->setDefer(true);
			if ($mode === 'insert') {
				$request = $this->filesCreate($file);
			} else {
				$request = $this->filesUpdate($srcFile->getId(), $file);
			}

			// Create a media file upload to represent our upload process.
			$media = new Google_Http_MediaFileUpload($client, $request, $mime, null, true, $chunkSizeBytes);
			$media->setFileSize($fstat['size']);
			// Upload the various chunks. $status will be false until the process is
			// complete.
			$status = false;
			$handle = $contents;
			while (!$status && !feof($handle)) {
				// read until you get $chunkSizeBytes from TESTFILE
				// fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
				// An example of a read buffered file is when reading from a URL
				$chunk = GdUtil::readFileChunk($handle, $chunkSizeBytes);
				$status = $media->nextChunk($chunk);
			}
			// The final value of $status will be the data from the API for the object
			// that has been uploaded.
			if ($status != false) {
				$obj = $status;
			}

			$client->setDefer(false);
		} else {
			$params = [
				'data' => $contents,
				'uploadType' => 'media',
				'fields' => $this->fetchFieldsGet
			];
			if ($mode === 'insert') {
				$obj = $this->filesCreate($file, $params);
			} else {
				$obj = $this->filesUpdate($srcFile->getId(), $file, $params);
			}
		}

		if ($obj instanceof Google_Service_Drive_DriveFile) {
			$result = $this->normalizeFileInfo($obj, $path);

			if ($visibility = $config->get('visibility')) {
				$this->setVisibility($path, $visibility);
				$result['visibility'] = $visibility;
			}
			$this->cache->update($path,$obj);

			return $result;
		}

		return false;
	}
	function listContents(string $directory,bool $recursive=true):iterable{
		return $this->fetchDirectory($directory,$recursive);
	}
	protected  function fetchDirectory($directory, $recursive = true, $maxResults = 0)
	{
		if(!$this->isDirectory($directory)){
			yield from [];
			return ;
		}
		$results = $this->fetchDirectoryCache($directory,$maxResults);
		if ($recursive) {
			foreach ($results as $id=>$result) {
				if ($result['type'] === 'dir') {
					yield from $this->fetchDirectory($result['path'], true, $maxResults) ;
					//var_dump($result['path']);

				}
				yield $id=>$result;
			}
		}else{
			yield from $results;
		}

	}

	protected function fetchDirectoryCache($directory,$maxResults=1000, $pageSize=1000)
	{
		if ($this->cache->isComplete($directory)) {
			foreach ($this->cache->listContents($directory) as $path => $file) {
				if(!$file){
					continue;
				}
				yield $file->getId() => $this->normalizeFileInfo($file, $path);

			}
			return null;
		}

		list($itemId) = $this->detectPath($directory);

		$maxResults = min($maxResults, 1000);
		$pageSize=min($pageSize,$maxResults);//allow smaller page size to save memory
		$parameters = [
			'pageSize' => $pageSize,
			'spaces' => $this->spaces,
			'q' => sprintf('trashed = false and "%s" in parents', $itemId)
		];
		$pageToken = NULL;
		do {
			try {
				if ($pageToken) {
					$parameters['pageToken'] = $pageToken;
				}
				$fileObjs = $this->filesListFiles($parameters);
				if ($fileObjs instanceof Google_Service_Drive_FileList) {
					foreach ($fileObjs as $obj) {
						$id = $obj->getId();
						$result = $this->normalizeFileInfo($obj, $directory . '/' . $obj->getName());
						yield $id=>$result;
						$this->cache->update($result['path'],$obj);
					}
					$pageToken = $fileObjs->getNextPageToken();
				} else {
					$pageToken = NULL;
				}
			} catch (Exception $e) {
				$pageToken = NULL;
			}
		} while ($pageToken && $maxResults === 0);

		if ($maxResults === 0) {
			$this->cache->setComplete($directory);
		}


	}
	/**
	 * Publish specified path item
	 *
	 * @param string $path
	 *            itemId path
	 *
	 * @return bool
	 */
	protected function publish($path)
	{
		if ($file = $this->find($path)) {
			if ($this->visibility($path)['visibility'] === AdapterInterface::VISIBILITY_PUBLIC) {
				return true;
			}
			try {
				$permission = new Google_Service_Drive_Permission($this->publishPermission);
				if ($newPermission=$this->service->permissions->create($file->getId(), $permission)) {
					$permissions=$file->getPermissions();
					$permissions=array_merge($permissions,[$newPermission]);
					$file->setPermissions($permissions);
					return true;
				}
			} catch (Exception $e) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Un-publish specified path item
	 *
	 * @param string $path
	 *            itemId path
	 *
	 * @return bool
	 */
	protected function unPublish($path)
	{
		if ($file = $this->find($path)) {
			$permissions = $file->getPermissions();
			try {
				foreach ($permissions as $index=> $permission) {
					if ($permission->type === 'anyone' && $permission->role === 'reader') {
						$this->service->permissions->delete($file->getId(), $permission->getId());
						unset($permissions[$index]);
					}
				}
				$file->setPermissions($permissions);
				return true;
			} catch (Exception $e) {
				return false;
			}
		}
		return false;
	}


	public function setVisibility(string $path, $visibility):void
	{
		($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? $this->publish($path) : $this->unPublish($path);
	}
	public function read(string $path):string
	{
		if($readStream=$this->readStream($path)){
			return (string)stream_get_contents($readStream);
		}
		return '';
	}
	public function readStream(string $path){
		$file = $this->find($path);
		if(!$this->isFile($path)) {
			throw new GoogleDriveException("File not found $file");
		}

		try {
			$this->service->getClient()->setUseBatch(true);
			$stream=null;
			$client=$this->service->getClient()->authorize();
			$response = $client->send($this->filesGet($file->getId(), ['alt' => 'media']), ['stream' => true]);
			if ($response->getBody() instanceof Stream) {
				$stream = $response->getBody()->detach();
			}
			$this->service->getClient()->setUseBatch(false);
			if (is_resource($stream)) {
				return $stream;
			}
		}catch (GuzzleException $e){
			throw new GoogleDriveException("Failed to read file $path",0,$e);
		}

		throw new GoogleDriveException("Failed to read file $path");
	}

	/**
	 * Gets the service (Google_Service_Drive)
	 *
	 * @return object  Google_Service_Drive
	 */
	public function getService()
	{
		return $this->service;
	}
	protected function parsePath($path){
		$paths=GdUtil::cleanPath($path,'array');
		$directory=[];
		$file=[];
		$level=0;
		foreach ($paths as $path){
			if($level++>$this->maxFolderLevel){
				$file[]=$path;
			}else{
				$directory[]=$path;
			}
		}
		if(!$file){
			$file[]=array_pop($directory);
		}
		$file=join('/',$file);
		$directory[]=$file;
		return $directory;
	}

	/**
	 * Travel through the path tree then return folder id, remaining path, current path
	 * eg: /path/to/the/file/text.txt
	 * 	- if we have directory /path/to then it return [path_to_id, ['the','file','text.txt'], ['path','to']
	 *  - if we have /path/to/the/file/text.txt then it return [id_of_path_to_the_file, ['text.txt'], ['path','to','the','file'] ]
	 * @param $path
	 * @return array
	 */
	protected function detectPath($path)
	{
		$paths=$this->parsePath($path);
		$this->logger->debug("Path finding: ".json_encode($paths));
		$currentPaths = [];
		$this->cache->set('/',$this->root);
		$parent=$this->cache->get('/');

		while (null!==($name = array_shift($paths))) {
			$currentPaths[] = $name;
			if($this->cache->has($currentPaths)){
				$foundDir=$this->cache->get($currentPaths);
				if($foundDir && $this->isDirectory($foundDir)){
					$parent=$foundDir;
					continue;
				}else{
					//echo 'break at...'.implode($currentPaths);
					array_pop($currentPaths);
					array_unshift($paths, $name);

					break;
				}
			}
			list($files,$isFull) = $this->filesFindByName($name, $parent);
			if($isFull){
				$parentPaths=$currentPaths;
				array_pop($parentPaths);
				$this->cache->setComplete($parentPaths);
			}
			$foundDir = false;
			//Set current path as not exists, it will be updated again when we got matched file
			$this->cache->update($currentPaths,false);
			if ($files->count()) {
				$currentPathsTmp=$currentPaths;
				foreach ($files as $file) {
					if ($file instanceof Google_Service_Drive_DriveFile) {
						array_pop($currentPathsTmp);
						array_push($currentPathsTmp, $file->getName());
						$this->cache->update($currentPathsTmp,$file);
						if($this->isDirectory($file) && $file->getName()===$name){
							$foundDir=$file;
						}
					}
				}
			}

			if (!$foundDir) {
				array_pop($currentPaths);
				array_unshift($paths, $name);
				break;
			}
			$parent=$foundDir;
		}
		$parent=$parent->getId();
		$this->logger->debug("Found: ".$parent.'('.json_encode($currentPaths).") ".json_encode($paths));
		return [$parent, $paths, $currentPaths];
	}
	/**
	 * Find files by name in given directory
	 * @param $name
	 * @param $parent
	 * @param $mineType
	 * @return Google_Service_Drive_FileList|Google_Service_Drive_DriveFile[]
	 */
	protected function filesFindByName($name,$parent, $mineType=null){
		if($parent instanceof Google_Service_Drive_DriveFile){
			$parent=$parent->getId();
		}
		$this->logger->debug("Find $name{[$mineType]} in $parent ");
		$client=$this->service->getClient();
		$client->setUseBatch(true);
		$batch = $this->service->createBatch();
		$q='trashed = false and "%s" in parents and name = "%s"';
		$args = [
			'pageSize' => 2,
			'q' =>sprintf($q,$parent,$name,static::DIRMIME),
		];
		$filesMatchedName=$this->filesListFiles($args);
		$q='trashed = false and "%s" in parents';
		if($mineType){
			$q.=" and mimeType ".$mineType;
		}
		$args = [
			'pageSize' => 50,
			'q' =>sprintf($q,$parent,$name,static::DIRMIME),
		];
		$otherFiles=$this->filesListFiles($args);
		$batch->add($filesMatchedName,'matched');
		$batch->add($otherFiles,'others');
		$results = $batch->execute();
		$files=[];
		$isFullResult=empty($mineType);//if limited to a mime type so it is not full result

		foreach ($results as $key => $result) {
			if ($result instanceof Google_Service_Drive_FileList) {
				if($key==='response-matched'){
					if(count($result)>1){
						throw new GoogleDriveException("Duplicated file ".$name.' in '.$parent);
					}
				}
				foreach ($result as $file) {
					if (!isset($files[$file->id])) {
						$files[$file->id] = $file;
					}
				}
				if ($key === 'response-others' && $result->nextPageToken) {
					$isFullResult = false;
				}
			}
		}

		$client->setUseBatch(false);
		$this->logQuery('files.list.batch',['find for '.$name.' in '.$parent]);
		$list=new Google_Service_Drive_FileList();
		$list->setFiles($files);
		return [$list,$isFullResult];
	}
	/**
	 * @param array $optParams
	 * @return Google_Service_Drive_FileList | RequestInterface
	 */
	protected function filesListFiles($optParams = array()){
		if(!$this->service->getClient()->shouldDefer()) {
			$this->logQuery('files.list', func_get_args());
		}
		$optParams=$this->getParams('files.list',['fields' => $this->fetchFieldsList],$optParams);
		return $this->service->files->listFiles($optParams);
	}
	protected function filesGet($fileId, $optParams = array()){
		if($fileId instanceof Google_Service_Drive_DriveFile){
			$fileId=$fileId->getId();
		}
		$this->logQuery('files.get',func_get_args());
		$optParams=$this->getParams('files.get',['fields' => $this->fetchFieldsGet],$optParams);
		return $this->service->files->get($fileId,$optParams);
	}

	/**
	 * @param Google_Service_Drive_DriveFile $postBody
	 * @param array $optParams
	 * @return Google_Service_Drive_DriveFile | RequestInterface
	 */
	protected function filesCreate(Google_Service_Drive_DriveFile $postBody, $optParams = array()){
		$this->logQuery('files.create',func_get_args());
		$optParams=$this->getParams('files.create',['fields' => $this->fetchFieldsGet],$optParams);
		return $this->service->files->create($postBody,$optParams);
	}

	/**
	 * Create directory
	 * @param $name
	 * @param $parentId
	 * @return bool|Google_Service_Drive_DriveFile|RequestInterface
	 */
	protected function dirCreate($name, $parentId=''){
		if(empty($parentId)){
			$parentId=$this->root;
		}
		$this->logger->debug("Creating directory $name in $parentId");
		$file = new Google_Service_Drive_DriveFile();
		$file->setName($name);
		$file->setParents([
			$parentId
		]);
		$file->setMimeType(self::DIRMIME);
		return $this->filesCreate($file);
	}
	protected function filesUpdate($fileId, Google_Service_Drive_DriveFile $postBody, $optParams = array()){
		if($fileId instanceof Google_Service_Drive_DriveFile){
			$fileId=$fileId->getId();
		}
		$this->logQuery('files.update',func_get_args());
		$optParams=$this->getParams('files.update',['fields' => $this->fetchFieldsGet],$optParams);
		return $this->service->files->update($fileId,$postBody,$optParams);
	}
	protected function filesCopy($fileId, Google_Service_Drive_DriveFile $postBody, $optParams = array()){
		if($fileId instanceof Google_Service_Drive_DriveFile){
			$fileId=$fileId->getId();
		}
		$this->logQuery('files.copy',func_get_args());
		$optParams=$this->getParams('files.copy',['fields' => $this->fetchFieldsGet],$optParams);
		return $this->service->files->copy($fileId,$postBody,$optParams);
	}
	protected function filesDelete($fileId, $optParams = array()){
		if($fileId instanceof Google_Service_Drive_DriveFile){
			$fileId=$fileId->getId();
		}
		$this->logQuery('files.delete',func_get_args());
		$optParams=$this->getParams('files.delete',$optParams);
		return $this->service->files->delete($fileId,$optParams);
	}
	protected function getParams($cmd, ...$params){
		$default=$this->getDefaultParams($cmd);
		return array_merge($default,...$params);
	}
	protected function getDefaultParams($cmd){
		if(isset($this->defaultParams[$cmd]) && is_array($this->defaultParams[$cmd])){
			return $this->defaultParams[$cmd];
		}
		return [];
	}
	protected function mergeCommandDefaultParams($cmd,$params){
		if(!isset($this->defaultParams[$cmd])){
			$this->defaultParams[$cmd]=[];
		}
		$this->defaultParams[$cmd]=array_merge_recursive($this->defaultParams[$cmd],$params);
		return $this;
	}
	/**
	 * Enables Team Drive support by changing default parameters
	 *
	 * @return void
	 *
	 * @see https://developers.google.com/drive/v3/reference/files
	 * @see \Google_Service_Drive_Resource_Files
	 */
	public function enableTeamDriveSupport()
	{
		$this->defaultParams = array_merge_recursive(
			array_fill_keys([
				'files.copy', 'files.create', 'files.delete',
				'files.trash', 'files.get', 'files.list', 'files.update',
				'files.watch'
			], ['supportsTeamDrives' => true]),
			$this->defaultParams
		);
	}
	/**
	 * Selects Team Drive to operate by changing default parameters
	 *
	 * @return void
	 *
	 * @param   string   $teamDriveId   Team Drive id
	 * @param   string   $corpora       Corpora value for files.list
	 *
	 * @see https://developers.google.com/drive/v3/reference/files
	 * @see https://developers.google.com/drive/v3/reference/files/list
	 * @see \Google_Service_Drive_Resource_Files
	 */
	public function setTeamDriveId($teamDriveId, $corpora = 'drive')
	{
		$this->enableTeamDriveSupport();
		$this->mergeCommandDefaultParams('files.list',[
			'corpora' => $corpora,
			'includeTeamDriveItems' => true,
			'driveId' => $teamDriveId
		]);
		$this->setRoot($teamDriveId);
	}

	/**
	 * Check if given path exists
	 * @param $path
	 * @return bool
	 */
	public function exists($path){
		return (bool)$this->getMetadata($path);
	}
	public function isDirectory($path){
		$meta=$this->getMetadata($path);
		return isset($meta['type'])&& $meta['type']==='dir';
	}
	public function isFile($path){
		$meta=$this->getMetadata($path);
		return isset($meta['type'])&& $meta['type']==='file';
	}
	/**
	 * @inheritDoc
	 */
	public function fileExists(string $path): bool
	{
		return $this->isFile($path);
	}
	/**
	 * @param $path
	 * @return bool|array
	 */
	public function getMetadata($path)
	{
		if ($obj = $this->find($path)) {
			if($path instanceof Google_Service_Drive_DriveFile){
				$path=null;
			}
			if ($obj instanceof Google_Service_Drive_DriveFile) {
				return $this->normalizeFileInfo($obj,$path);
			}
		}
		return false;
	}
	protected function normalizeFileInfo(Google_Service_Drive_DriveFile $object, $path)
	{
		$id = $object->getId();
		$result = [
			'id'=>$id,
			'name' => $object->getName(),
			'path' => is_string($path)?'/'.ltrim($path,'\/'):null,
			'type' => $object->mimeType === self::DIRMIME ? 'dir' : 'file',
			'timestamp'=>strtotime($object->getModifiedTime())
		];
		if ($result['type'] === 'file') {
			$result['mimetype'] = $object->mimeType;
			$result['size'] = (int) $object->getSize();
		}


		$permissions = $object->getPermissions();
		$visibility = AdapterInterface::VISIBILITY_PRIVATE;
		foreach ($permissions as $permission) {
			if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
				$visibility = AdapterInterface::VISIBILITY_PUBLIC;
				break;
			}
		}
		$result['visibility']=$visibility;

		// attach additional fields
		if ($this->additionalFields) {
			foreach($this->additionalFields as $field) {
				if (property_exists($object, $field)) {
					$result[$field] = $object->$field;
				}
			}
		}
		return $result;
	}
	protected function logQuery($cmd,$query){
		if(!$this->logQuery){
			return ;
		}
		$this->logger->query($cmd,$query);
	}
	public function enableQueryLog(){
		$this->logQuery=true;
	}
	public function showQueryLog($which='queries'){
		$this->logger->showQueryLog($which);
	}


}
