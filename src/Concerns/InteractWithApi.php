<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 11-Oct-18
 * Time: 8:24 PM
 */

namespace As247\Flysystem\GoogleDrive\Concerns;

use As247\Flysystem\GoogleDrive\Cache;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Service_Drive_Permission;
use Google_Http_MediaFileUpload;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use As247\Flysystem\GoogleDrive\Util as GdUtil;

/**
 * Trait InteractWithApi
 * @package As247\Flysystem\GoogleDrive\Concerns
 * @property Cache $cache
 */
trait InteractWithApi
{
    use ServiceHelper;

    /**
     * @param $path
     * @return false|Google_Service_Drive_DriveFile
     */
    protected function findFile($path)
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

    protected function dirCreate($name, $parentId)
    {
        $file = new Google_Service_Drive_DriveFile();
        $file->setName($name);
        $file->setParents([
            $parentId
        ]);
        $file->setMimeType(self::DIRMIME);

        $obj = $this->filesCreate($file);

        return ($obj instanceof Google_Service_Drive_DriveFile) ? $obj : false;
    }

    protected function cp($fromPath, $toPath)
    {
        if (!$this->isFile($fromPath)) {
            return false;
        }
        $from = $this->findFile($fromPath);
        if ($this->isDirectory($toPath)) {
            $newParentId = $this->findFile($toPath)->getId();
            $fileName = $from->getName();
            $fullFilePath=rtrim($toPath,'\/').'/'.$fileName;
        } else {
            $newParentId = $this->ensureDirectory(dirname($toPath));
            $fileName = basename($toPath);
            $fullFilePath=$toPath;
        }
        if ($newParentId) {
            $file = new Google_Service_Drive_DriveFile();
            $file->setName($fileName);
            $file->setParents([
                $newParentId
            ]);
            $newFile = $this->filesCopy($from->id, $file);
            $this->cache->update($fullFilePath,$newFile);
            return $fullFilePath;
        }
        return false;
    }

    protected function mv($fromPath, $toPath)
    {
        $from=$this->findFile($fromPath);
        if(!$from){
            return false;
        }
        if($from->getParents()){
            list($oldParent)=$from->getParents();
        }else{
            list($oldParent)=$this->detectPath(dirname($fromPath));
        }

        if ($this->isDirectory($toPath)) {//Destination path is a directory move our file inside
            $newParentId = $this->findFile($toPath)->getId();
            $fileName = $from->getName();
            $fullFilePath=rtrim($toPath,'\/').'/'.$fileName;
        } else {//Destination file not exists, create parent directory then rename
            $newParentId = $this->ensureDirectory(dirname($toPath));
            $fileName = basename($toPath);
            $fullFilePath=$toPath;
        }
        if($this->has($fullFilePath)){//Destination path exists skip
            return false;
        }
        if($fullFilePath===$fromPath){//same path
            return false;
        }
        $file = new Google_Service_Drive_DriveFile();
        $file->setName($fileName);
        $opts = [
            'fields' => $this->fetchfieldsGet
        ];
        if ($newParentId !== $oldParent) {
            $opts['addParents'] = $newParentId;
            $opts['removeParents'] = $oldParent;
        }
        $updatedFile = $this->filesUpdate($from->getId(), $file, $opts);

        if ($updatedFile) {
            $this->cache->rename($fromPath,$fullFilePath);
            return $fullFilePath;
        }
        return false;
    }

    protected function rm($path)
    {
        if (!$this->isFile($path)) {
            return false;
        }
        $file = $this->findFile($path);
        $this->filesDelete($file);
        $this->cache->delete($path);
        return true;
    }

    protected function rmdir($path)
    {
        if (!$this->isDirectory($path)) {
            return false;
        }
        $file = $this->findFile($path);
        if($file->getId()===$this->root){
            return false;
        }
        $this->filesDelete($file);
        $this->cache->deleteDir($path);
        return true;
    }

    protected function mkdir($path)
    {
        if ($this->isFile($path)) {
            return false;
        }
        list($parent, $paths, $currentPaths) = $this->detectPath($path);
        if (count($paths) != 0) {
            while (null!==($name = array_shift($paths))) {
                $currentPaths[] = $name;
                if($this->has($currentPaths)){
                    return false;
                }
                if ($created = $this->dirCreate($name, $parent)) {
                    $this->cache->update($currentPaths,$created);
                    $this->cache->setComplete($currentPaths);
                    $parent = $created->getId();
                } else {
                    return false;
                }
            }
        }
        return $parent;
    }

    protected function detectPath($path)
    {
        $path=GdUtil::cleanPath($path);
        $paths = array_filter(explode('/', $path),function($value){
            return strlen($value)>0;
        });
        array_unshift($paths,'/');
        //var_dump($paths);
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
        return [$parent, $paths, $currentPaths];
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
        $fileName = basename($path);
        $dirName = Util::dirname($path);
        //Try to find file before, because if it was removed before, ensure directory will recreate same directory and it may available again

        $parentId = $this->ensureDirectory($dirName);
        if (!$parentId) {
            return false;
        }
        $mode = 'update';
        $mime = $config->get('mimetype');
        $file = new Google_Service_Drive_DriveFile();
        $srcFile = $this->findFile($path);
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
                return false;
            }
        }
        // set chunk size (max: 100MB)
        $chunkSizeBytes = 100 * 1024 * 1024;
        if ($isResource) {
            $memory = $this->getIniBytes('memory_limit');
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

        if (!$mime) {
            $mime = Util::guessMimeType($fileName, $isResource ? '' : $contents);
        }
        $file->setMimeType($mime);
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
                $chunk = $this->readFileChunk($handle, $chunkSizeBytes);
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
                'fields' => $this->fetchfieldsGet
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
                if ($this->setVisibility($path, $visibility)) {
                    $result['visibility'] = $visibility;
                }
            }
            $this->cache->update($path,$obj);

            return $result;
        }

        return false;
    }

    protected function fetchDirectory($directory, $recursive = true, $maxResults = 0)
    {
        if(!$this->isDirectory($directory)){
            return [];
        }
        $results = $this->fetchDirectoryCache($directory,$maxResults);
        if ($recursive) {
            foreach ($results as $result) {
                if ($result['type'] === 'dir') {
                    $results = array_merge($results, $this->fetchDirectory($result['path'], true, $maxResults));
                }
            }
        }

        return $results;
    }

    protected function fetchDirectoryCache($directory,$maxResults=1000)
    {
        $results=[];
        if ($this->cache->isComplete($directory)) {
            foreach ($this->cache->listContents($directory) as $path => $file) {
                if(!$file){
                    continue;
                }
                $results[$file->getId()] = $this->normalizeFileInfo($file, $path);
            }
            return $results;
        }
        /*else{
            echo 'non cached: '.$directory.PHP_EOL;
        }*/

        list($itemId) = $this->detectPath($directory);

        $maxResults = min($maxResults, 1000);
        $results = [];
        $parameters = [
            'pageSize' => $maxResults ?: 1000,
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
                        $results[$id] = $result;
                        $this->cache->update($result['path'],$obj);
                    }
                    $pageToken = $fileObjs->getNextPageToken();
                } else {
                    $pageToken = NULL;
                }
            } catch (\Exception $e) {
                $pageToken = NULL;
            }
        } while ($pageToken && $maxResults === 0);

        if ($maxResults === 0) {
            $this->cache->setComplete($directory);
        }
        return $results;

    }

    /**
     * Get the object permissions presented as a visibility.
     *
     * @param string $path
     *            itemId path
     *
     * @return string
     */
    protected function getRawVisibility($path)
    {
        $file = $this->findFile($path);
        if (!$file) {
            return false;
        }
        $permissions = $file->getPermissions();
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;
        foreach ($permissions as $permission) {
            if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;
                break;
            }
        }
        return $visibility;
    }

    /**
     * Get download url
     *
     * @param string $path
     *
     * @return string|false
     */
    protected function getDownloadUrl($path)
    {
        if (!$this->isFile($path)) {
            return false;
        }
        $file = $this->findFile($path);
        if (!$file) {
            return false;
        }
        if (strpos($file->mimeType, 'application/vnd.google-apps') !== 0) {
            $url = 'https://www.googleapis.com/drive/v3/files/' . $file->getId() . '?alt=media';
            //$url.='&acknowledgeAbuse=true';
            return $url;
        } else {
            $mimeMap = $this->options['appsExportMap'];
            if (isset($mimeMap[$file->getMimeType()])) {
                $mime = $mimeMap[$file->getMimeType()];
            } else {
                $mime = $mimeMap['default'];
            }
            $mime = rawurlencode($mime);

            return 'https://www.googleapis.com/drive/v3/files/' . $file->getId() . '/export?mimeType=' . $mime;
        }
    }
    protected function readStreamNative($path)
    {
        $redirect = [];
        if (func_num_args() > 1) {
            $redirect = func_get_arg(1);
        }
        $access_token = '';
        if (! $redirect) {
            $redirect = [
                'cnt' => 0,
                'url' => '',
                'token' => '',
                'cookies' => []
            ];
            if ($dlurl = $this->getDownloadUrl($path)) {
                $client = $this->service->getClient();
                if ($client->isUsingApplicationDefaultCredentials()) {
                    $token = $client->fetchAccessTokenWithAssertion();
                } else {
                    $token = $client->getAccessToken();
                }
                if (is_array($token)) {
                    if (empty($token['access_token']) && !empty($token['refresh_token'])) {
                        $token = $client->fetchAccessTokenWithRefreshToken();
                    }
                    $access_token = $token['access_token'];
                } else {
                    if ($token = $client->getAccessToken()) {
                        $access_token = $token['access_token'];
                    }
                }
                $redirect = [
                    'cnt' => 0,
                    'url' => '',
                    'token' => $access_token,
                    'cookies' => []
                ];
            }
        } else {
            if ($redirect['cnt'] > 5) {
                return false;
            }
            $dlurl = $redirect['url'];
            $redirect['url'] = '';
            $access_token = $redirect['token'];
        }

        if ($dlurl) {
            $url = parse_url($dlurl);
            $cookies = [];
            if ($redirect['cookies']) {
                foreach ($redirect['cookies'] as $d => $c) {
                    if (strpos($url['host'], $d) !== false) {
                        $cookies[] = $c;
                    }
                }
            }
            if ($access_token) {
                $query = isset($url['query']) ? '?' . $url['query'] : '';
                $stream = stream_socket_client('ssl://' . $url['host'] . ':443');
                stream_set_timeout($stream, 300);
                fputs($stream, "GET {$url['path']}{$query} HTTP/1.1\r\n");
                fputs($stream, "Host: {$url['host']}\r\n");
                fputs($stream, "Authorization: Bearer {$access_token}\r\n");
                fputs($stream, "Connection: Close\r\n");
                if ($cookies) {
                    fputs($stream, "Cookie: " . join('; ', $cookies) . "\r\n");
                }
                fputs($stream, "\r\n");
                while (($res = trim(fgets($stream))) !== '') {
                    // find redirect
                    if (preg_match('/^Location: (.+)$/', $res, $m)) {
                        $redirect['url'] = $m[1];
                    }
                    // fetch cookie
                    if (strpos($res, 'Set-Cookie:') === 0) {
                        $domain = $url['host'];
                        if (preg_match('/^Set-Cookie:(.+)(?:domain=\s*([^ ;]+))?/i', $res, $c1)) {
                            if (! empty($c1[2])) {
                                $domain = trim($c1[2]);
                            }
                            if (preg_match('/([^ ]+=[^;]+)/', $c1[1], $c2)) {
                                $redirect['cookies'][$domain] = $c2[1];
                            }
                        }
                    }
                }
                if ($redirect['url']) {
                    $redirect['cnt'] ++;
                    fclose($stream);
                    return $this->readStreamNative($path, $redirect);
                }
                return compact('stream');
            }
        }
        return false;
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
        if ($file = $this->findFile($path)) {
            if ($this->getRawVisibility($path) === AdapterInterface::VISIBILITY_PUBLIC) {
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
            } catch (\Exception $e) {
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
        if ($file = $this->findFile($path)) {
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
            } catch (\Exception $e) {
                return false;
            }
        }
        return false;
    }
}