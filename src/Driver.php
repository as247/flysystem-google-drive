<?php


namespace As247\Flysystem\GoogleDrive;

use As247\Flysystem\DriveSupport\Cache\NullCache;
use As247\Flysystem\DriveSupport\Cache\PathObjectCache;
use As247\Flysystem\DriveSupport\Contracts\Cache\CacheInterface;
use As247\Flysystem\DriveSupport\Contracts\Cache\PathCacheInterface;
use As247\Flysystem\DriveSupport\Exception\FileNotFoundException;
use As247\Flysystem\DriveSupport\Exception\InvalidStreamProvided;
use As247\Flysystem\DriveSupport\Exception\InvalidVisibilityProvided;
use As247\Flysystem\DriveSupport\Exception\UnableToCopyFile;
use As247\Flysystem\DriveSupport\Exception\UnableToCreateDirectory;
use As247\Flysystem\DriveSupport\Exception\UnableToDeleteDirectory;
use As247\Flysystem\DriveSupport\Exception\UnableToDeleteFile;
use As247\Flysystem\DriveSupport\Exception\UnableToMoveFile;
use As247\Flysystem\DriveSupport\Exception\UnableToReadFile;
use As247\Flysystem\DriveSupport\Exception\UnableToRetrieveMetadata;
use As247\Flysystem\DriveSupport\Exception\UnableToSetVisibility;
use As247\Flysystem\DriveSupport\Exception\UnableToWriteFile;
use As247\Flysystem\DriveSupport\Service\GoogleDrive;
use As247\Flysystem\DriveSupport\Service\Logger;
use As247\Flysystem\DriveSupport\Support\FileAttributes;
use Closure;
use Exception;
use Generator;
use GuzzleHttp\Psr7;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\FilesystemException;
use As247\Flysystem\DriveSupport\Support\Path;
use As247\Flysystem\DriveSupport\Contracts\Driver as DriverContract;

class Driver implements DriverContract
{
	/**
	 * MIME tyoe of directory
	 *
	 * @var string
	 */
	const DIRMIME = 'application/vnd.google-apps.folder';


	/**
	 * Google_Service_Drive instance
	 */
	protected $service;

	protected $logger;
	protected $root;//Root id
	/**
	 * @var PathObjectCache|PathCacheInterface
	 */
	protected $cache;
	protected $maxFolderLevel = 128;

	public function __construct(Google_Service_Drive $service, $options)
	{
		if (is_string($options)) {
			$options = ['root' => $options];
		}
		$this->service = new GoogleDrive($service, $options);
		$this->setRoot($options);
		$this->logger = new Logger();
	}

	public function isTeamDrive(){
		return $this->service->isTeamDrive();
	}

	public function getCache()
	{
		return $this->cache;
	}
	public function disableCaching(){
		$this->cache=new NullCache();
		$this->initializeCacheRoot();
	}

	function setRoot($options)
	{
		$root = $options['root'];
		$this->root = $root;
		if(!isset($options['cache'])){
			$options['cache']=new PathObjectCache();
		}
		if($options['cache'] instanceof Closure){
			$options['cache']=$options['cache']();
		}
		if($options['cache']===false || $options['cache']==='null'){
			$options['cache']=new NullCache();
		}
		if(!$options['cache'] instanceof CacheInterface){
			$options['cache']=new PathObjectCache();
		}
		$this->cache = $options['cache'];
		$this->initializeCacheRoot();
	}
	public function initializeCacheRoot(){
		$dRoot = new Google_Service_Drive_DriveFile();
		$dRoot->setId($this->root);
		$dRoot->setMimeType(static::DIRMIME);
		$this->cache->forever('/', $dRoot);
	}

	/**
	 * @param string|array $path create directory structure
	 * @return string folder id
	 */
	protected function ensureDirectory($path)
	{
		$path = Path::clean($path);
		if ($this->isFile($path)) {
			throw UnableToCreateDirectory::atLocation($path, "File already exists");
		}
		if (isset($this->maxFolderLevel)) {
			$nestedFolderLevel = count(explode('/', $path)) - 1;
			if ($nestedFolderLevel > $this->maxFolderLevel) {// -1 for /
				throw UnableToCreateDirectory::atLocation($path, "Maximum nesting folder exceeded");
			}
		}
		$this->logger->log("Ensure directory $path");
		list($parent, $paths, $currentPaths) = $this->detectPath($path);

		if (count($paths) != 0) {
			while (null !== ($name = array_shift($paths))) {
				$currentPaths[] = $name;
				$currentPathString = join('/', $currentPaths);
				if ($this->isFile($currentPaths)) {
					throw  UnableToCreateDirectory::atLocation($currentPathString, "File already exists");
				}

				$created = $this->service->dirCreate($name, $parent);
				$this->cache->put($currentPaths, $created);
				$this->cache->completed($currentPaths);
				$parent = $created->getId();
			}
		}
		return $parent;
	}


	/**
	 * @inheritDoc
	 */
	public function write(string $path, string $contents, Config $config = null): void
	{
		$this->upload($path, $contents, $config);
	}

	/**
	 * @inheritDoc
	 */
	public function writeStream(string $path, $contents, Config $config): void
	{
		$this->upload($path, $contents, $config);
	}

	/**
	 * Delete file only
	 * @param $path
	 * @return void
	 */
	public function delete(string $path): void
	{
		if ($this->isDirectory($path)) {
			throw UnableToDeleteFile::atLocation($path, "$path is directory");
		}
		$file = $this->find($path);
		if (!$file) {//already deleted
			throw FileNotFoundException::create($path);
		}
		if ($file->getId() === $this->root) {
			throw UnableToDeleteDirectory::atLocation($path, "Root directory cannot be deleted");
		}
		$this->service->filesDelete($file);
		$this->cache->put($path, false);
	}

	/**
	 * @inheritDoc
	 */
	public function deleteDirectory(string $path): void
	{
		if ($this->isFile($path)) {
			throw UnableToDeleteDirectory::atLocation($path, "$path is file");
		}
		$file = $this->find($path);
		if (!$file) {//already deleted
			throw FileNotFoundException::create($path);
		}
		if ($file->getId() === $this->root) {
			throw UnableToDeleteDirectory::atLocation($path, "Root directory cannot be deleted");
		}
		$this->service->filesDelete($file);
		$this->cache->rename($path, false);
	}

	/**
	 * @inheritDoc
	 */
	public function visibility(string $path): FileAttributes
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function mimeType(string $path): FileAttributes
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function lastModified(string $path): FileAttributes
	{
		return $this->getMetadata($path);
	}

	/**
	 * @inheritDoc
	 */
	public function fileSize(string $path): FileAttributes
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

		list(, $paths) = $this->detectPath($path);

		if (count($paths) >= 2) {
			//remaining 2 segments /A/B/C/file.txt
			//C not exists mean file.txt also not exists
			return false;
		}
		if ($this->cache->has($path)) {
			return $this->cache->get($path);
		}
		return false;

	}

	public function createDirectory(string $path, Config $config): void
	{
		$this->ensureDirectory(Path::clean($path));
		$result = $this->getMetadata($path);
		if ($visibility = $config->get('visibility')) {
			$this->setVisibility($path, $visibility);
			$result['visibility'] = $visibility;
		}
	}

	public function copy(string $fromPath, string $toPath, Config $config = null): void
	{
		$fromPath = Path::clean($fromPath);
		$toPath = Path::clean($toPath);
		$from = $this->find($fromPath);
		if (!$from) {
			throw UnableToCopyFile::fromLocationTo($fromPath, $toPath, "$fromPath not exists");
		}
		if ($this->isDirectory($fromPath)) {
			throw UnableToCopyFile::fromLocationTo($fromPath, $toPath, "$fromPath is directory");
		}


		if ($this->isDirectory($toPath)) {
			throw UnableToCopyFile::fromLocationTo($fromPath, $toPath, "$toPath is directory");
		}
		if ($this->has($toPath)) {
			$this->delete($toPath);
		}
		$paths = $this->parsePath($toPath);
		$fileName = array_pop($paths);
		$dirName = $paths;
		$parents = [$this->ensureDirectory($dirName)];
		$file = new Google_Service_Drive_DriveFile();
		$file->setName($fileName);
		$file->setParents($parents);
		$newFile = $this->service->filesCopy($from->id, $file);
		$this->cache->put($toPath, $newFile);
	}

	public function move(string $fromPath, string $toPath, Config $config = null): void
	{
		$fromPath = Path::clean($fromPath);
		$toPath = Path::clean($toPath);
		if ($fromPath === $toPath) {
			return;
		}
		$from = $this->find($fromPath);
		if (!$from) {
			throw UnableToMoveFile::fromLocationTo($fromPath, $toPath, "$fromPath not found");
		}
		$oldParent = $from->getParents()[0];
		$newParentId = null;
		if ($this->isFile($from)) {//we moving file
			if ($this->has($toPath)) {
				if ($this->isDirectory($toPath)) {//Destination path is directory
					throw UnableToMoveFile::fromLocationTo($fromPath, $toPath, "Destination path exists as a directory, cannot overwrite");
				} else {
					$this->delete($toPath);
				}
			}
		} else {//we moving directory
			if ($this->has($toPath)) {
				if ($this->isFile($toPath)) {//Destination path is file
					throw UnableToMoveFile::fromLocationTo($fromPath, $toPath, "Destination path exists as a file, cannot overwrite");
				} else {
					$this->deleteDirectory($toPath);//overwrite, remove it first
				}
			}
		}
		$paths = $this->parsePath($toPath);
		$fileName = array_pop($paths);
		$dirName = $paths;
		$newParentId = $this->ensureDirectory($dirName);
		$file = new Google_Service_Drive_DriveFile();
		$file->setName($fileName);
		if ($newParentId !== $oldParent) {
			$opts['addParents'] = $newParentId;
			$opts['removeParents'] = $oldParent;
		}
		$this->service->filesUpdate($from->getId(), $file);
		$this->cache->rename($fromPath, $toPath);
	}


	/**
	 * Upload|Update item
	 *
	 * @param string $path
	 * @param string|resource $contents
	 * @param Config $config
	 * @return FileAttributes
	 * @throws FilesystemException
	 */
	protected function upload($path, $contents, Config $config = null)
	{
		$contents = Psr7\stream_for($contents);
		if (!$contents || !$contents->getSize()) {
			throw new InvalidStreamProvided("Resource is empty");
		}
		$contents->rewind();
		if ($this->isDirectory($path)) {
			throw UnableToWriteFile::atLocation($path, "$path is directory");
		}

		$paths = $this->parsePath($path);
		$fileName = array_pop($paths);
		$dirName = $paths;
		//Try to find file before, because if it was removed before, ensure directory will recreate same directory and it may available again
		$parentId = $this->ensureDirectory($dirName);
		if (!$parentId) {
			throw UnableToWriteFile::atLocation($path, "Not able to create parent directory $dirName");
		}
		$file = $this->find($path);
		if (!$file) {
			$file = new Google_Service_Drive_DriveFile();
			$file->setName($fileName);
			$file->setParents([
				$parentId
			]);

		}
		$newMimeType = $config ? $config->get('mimetype', $config->get('mime_type')) : null;
		if ($newMimeType) {
			$file->setMimeType($newMimeType);
		}

		$size5MB = 5 * 1024 * 1024;
		$chunkSize = $config ? $config->get('chunk_size', $size5MB) : $size5MB;
		if ($contents->getSize() <= $size5MB) {
			$obj = $this->service->filesUploadSimple($file, $contents);
		} else {
			$obj = $this->service->filesUploadChunk($file, $contents, $chunkSize);
		}

		if ($obj instanceof Google_Service_Drive_DriveFile) {
			$this->cache->put($path, $obj);//update cache first

			if ($config && $visibility = $config->get('visibility')) {
				$this->setVisibility($path, $visibility);
			}
			return $this->getMetadata($path);
		}

		throw UnableToWriteFile::atLocation($path);
	}

	/**
	 * @param string $directory
	 * @param bool $recursive
	 * @return Generator
	 */
	public function listContents(string $directory, bool $recursive = false): iterable
	{
		if (!$this->isDirectory($directory)) {
			yield from [];
			return;
		}
		$results = $this->fetchDirectory($directory, 1000);
		foreach ($results as $id => $result) {
			yield $id => $result;
			if ($recursive && $result['type'] === 'dir') {
				yield from $this->listContents($result['path'], $recursive);
			}
		}
	}

	protected function fetchDirectory($directory, $pageSize = 1000)
	{
		if ($this->cache->completed($directory)) {
			foreach ($this->cache->query($directory) as $path => $file) {
				if ($file instanceof Google_Service_Drive_DriveFile) {
					yield $file->getId() => $this->service->normalizeFileInfo($file, $path);
				}
			}
			return null;
		}

		list($itemId) = $this->detectPath($directory);
		$pageSize = min($pageSize, 1000);//limit range of page size
		$pageSize = max($pageSize, 1);//
		$parameters = [
			'pageSize' => $pageSize,
			'q' => sprintf('trashed = false and "%s" in parents', $itemId)
		];
		$pageToken = NULL;
		do {
			try {
				if ($pageToken) {
					$parameters['pageToken'] = $pageToken;
				}
				$fileObjs = $this->service->filesListFiles($parameters);
				if ($fileObjs instanceof Google_Service_Drive_FileList) {
					foreach ($fileObjs as $obj) {
						$id = $obj->getId();
						$result = $this->service->normalizeFileInfo($obj, $directory . '/' . $obj->getName());
						yield $id => $result;
						$this->cache->put($result['path'], $obj);
					}
					$pageToken = $fileObjs->getNextPageToken();
				} else {
					$pageToken = NULL;
				}
			} catch (Exception $e) {
				$pageToken = NULL;
			}
		} while ($pageToken);

		$this->cache->complete($directory);
	}

	/**
	 * Publish specified path item
	 *
	 * @param string $path
	 *            itemId path
	 *
	 */
	protected function publish($path)
	{
		if (!$file = $this->find($path)) {
			throw UnableToSetVisibility::atLocation($path, 'File not found');
		}
		$this->service->publish($file);
	}

	/**
	 * Un-publish specified path item
	 *
	 * @param string $path
	 *            itemId path
	 *
	 *
	 */
	protected function unPublish($path)
	{
		if (!$file = $this->find($path)) {
			throw UnableToSetVisibility::atLocation($path, 'File not found');
		}
		$this->service->unPublish($file);
	}

	/**
	 * @param string $path
	 * @param mixed $visibility
	 */
	public function setVisibility(string $path, $visibility): void
	{
		if ($visibility === AdapterInterface::VISIBILITY_PUBLIC) {
			$this->publish($path);
		} elseif ($visibility === AdapterInterface::VISIBILITY_PRIVATE) {
			$this->unPublish($path);
		} else {
			throw InvalidVisibilityProvided::withVisibility($visibility, join(' or ', [AdapterInterface::VISIBILITY_PUBLIC, AdapterInterface::VISIBILITY_PRIVATE]));
		}
	}

	public function read(string $path): string
	{
		if ($readStream = $this->readStream($path)) {
			return (string)stream_get_contents($readStream);
		}
		return '';
	}

	public function readStream(string $path)
	{
		$file = $this->find($path);
		if (!$this->isFile($path)) {
			throw UnableToReadFile::fromLocation($path, "File not found");
		}
		return $this->service->filesRead($file);
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

	protected function parsePath($path)
	{
		$paths = Path::clean($path, 'array');
		$directory = [];
		$file = [];
		$level = 0;
		foreach ($paths as $path) {
			if ($level++ > $this->maxFolderLevel) {
				$file[] = $path;
			} else {
				$directory[] = $path;
			}
		}
		if (!$file) {
			$file[] = array_pop($directory);
		}
		$file = join('/', $file);
		$directory[] = $file;
		return $directory;
	}

	/**
	 * Travel through the path tree then return folder id, remaining path, current path
	 * eg: /path/to/the/file/text.txt
	 *    - if we have directory /path/to then it return [path_to_id, ['the','file','text.txt'], ['path','to']
	 *  - if we have /path/to/the/file/text.txt then it return [id_of_path_to_the_file, ['text.txt'], ['path','to','the','file'] ]
	 * @param $path
	 * @return array
	 */
	protected function detectPath($path)
	{
		$paths = $this->parsePath($path);
		$this->logger->log("Path finding: " . json_encode($paths));
		$currentPaths = [];

		$parent = $this->cache->get('/');

		while (null !== ($name = array_shift($paths))) {
			$currentPaths[] = $name;
			if ($this->cache->has($currentPaths)) {
				$foundDir = $this->cache->get($currentPaths);
				if ($foundDir && $this->isDirectory($foundDir)) {
					$parent = $foundDir;
					continue;
				} else {
					//echo 'break at...'.implode($currentPaths);
					array_pop($currentPaths);
					array_unshift($paths, $name);

					break;
				}
			}
			list($files, $isFull) = $this->service->filesFindByName($name, $parent);
			if ($isFull) {
				$parentPaths = $currentPaths;
				array_pop($parentPaths);
				$this->cache->complete($parentPaths);
			}
			$foundDir = false;
			//Set current path as not exists, it will be updated again when we got matched file
			$this->cache->put($currentPaths, false);
			if ($files->count()) {
				$currentPathsTmp = $currentPaths;
				foreach ($files as $file) {
					if ($file instanceof Google_Service_Drive_DriveFile) {
						array_pop($currentPathsTmp);
						array_push($currentPathsTmp, $file->getName());
						$this->cache->put($currentPathsTmp, $file);
						if ($this->isDirectory($file) && $file->getName() === $name) {
							$foundDir = $file;
						}
					}
				}
			}

			if (!$foundDir) {
				array_pop($currentPaths);
				array_unshift($paths, $name);
				break;
			}
			$parent = $foundDir;
		}
		$parent = $parent->getId();
		$this->logger->log("Found: " . $parent . '(' . json_encode($currentPaths) . ") " . json_encode($paths));
		return [$parent, $paths, $currentPaths];
	}


	/**
	 * Check if given path exists
	 * @param $path
	 * @return bool
	 */
	public function has($path)
	{
		try {
			$this->getMetadata($path);
			return true;
		}catch (FileNotFoundException $e){
			return false;
		}
	}

	public function isDirectory($path)
	{
		try {
			$meta = $this->getMetadata($path);
			return $meta->isDir();
		}catch (FileNotFoundException $e){
			return false;
		}

	}

	public function isFile($path)
	{
		try {
			$meta = $this->getMetadata($path);
			return $meta->isFile();
		}catch (FileNotFoundException $e){
			return false;
		}
	}
	public function fileExists(string $path):bool {
		return $this->isFile($path);
	}


	/**
	 * @param $path
	 * @return FileAttributes
	 */
	public function getMetadata($path):FileAttributes
	{
		if ($obj = $this->find($path)) {
			if ($path instanceof Google_Service_Drive_DriveFile) {
				$path = null;
			}
			if ($obj instanceof Google_Service_Drive_DriveFile) {
				$attributes = $this->service->normalizeFileInfo($obj, $path);

				return FileAttributes::fromArray($attributes);
			}
			throw UnableToRetrieveMetadata::create($path, 'metadata');
		}
		//File not found just return null
		throw FileNotFoundException::create($path);
	}


}
