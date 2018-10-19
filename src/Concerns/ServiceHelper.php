<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 12-Oct-18
 * Time: 11:26 AM
 */

namespace As247\Flysystem\GoogleDrive\Concerns;

use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Psr\Http\Message\RequestInterface;

trait ServiceHelper
{
    /**
     * Google_Service_Drive instance
     *
     * @var Google_Service_Drive
     */
    protected $service;

    protected $defaultParams;
    protected $query=[];
    protected $logQuery=false;
    /**
     * Gets the service (Google_Service_Drive)
     *
     * @return object  Google_Service_Drive
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * @param $name
     * @param $parent
     * @return Google_Service_Drive_FileList|Google_Service_Drive_DriveFile[]
     */
    protected function filesFindByName($name,$parent){
        if($parent instanceof Google_Service_Drive_DriveFile){
            $parent=$parent->getId();
        }
        $client=$this->service->getClient();
        $client->setUseBatch(true);
        $batch = $this->service->createBatch();
        $q='trashed = false and "%s" in parents and name = "%s"';
        $args = [
            'pageSize' => 50,
            'q' =>sprintf($q,$parent,$name,static::DIRMIME),
        ];
        $filesMatchedName=$this->filesListFiles($args);
        $q='trashed = false and "%s" in parents';
        $args = [
            'pageSize' => 100,
            'q' =>sprintf($q,$parent,$name,static::DIRMIME),
        ];
        $otherFiles=$this->filesListFiles($args);
        $batch->add($filesMatchedName,'matched');
        $batch->add($otherFiles,'others');
        $results = $batch->execute();
        $files=[];
        $isFullResult=true;
        foreach ($results as $key=> $result){
            if($result instanceof Google_Service_Drive_FileList){
                foreach ($result as $file){
                    if(!isset($files[$file->id])) {
                        $files[$file->id] = $file;
                    }
                }
                if($key==='response-others'&&$result->nextPageToken){
                    $isFullResult=false;
                }
            }
        }

        $client->setUseBatch(false);
        $this->logQuery('files.list.batch',['find for '.$name.' in '.$parent]);
        $list=new Google_Service_Drive_FileList();
        $list->setFiles($files);
        //return $results['response-matched'];
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
        $optParams=$this->mergeParams('files.list',['fields' => $this->fetchfieldsList],$optParams);
        return $this->service->files->listFiles($optParams);
    }
    protected function filesGet($fileId, $optParams = array()){
        if($fileId instanceof Google_Service_Drive_DriveFile){
            $fileId=$fileId->getId();
        }
        $this->logQuery('files.get',func_get_args());
        $optParams=$this->mergeParams('files.get',['fields' => $this->fetchfieldsGet],$optParams);
        return $this->service->files->get($fileId,$optParams);
    }

    /**
     * @param Google_Service_Drive_DriveFile $postBody
     * @param array $optParams
     * @return Google_Service_Drive_DriveFile | RequestInterface
     */
    protected function filesCreate(Google_Service_Drive_DriveFile $postBody, $optParams = array()){
        $this->logQuery('files.create',func_get_args());
        $optParams=$this->mergeParams('files.create',['fields' => $this->fetchfieldsGet],$optParams);
        return $this->service->files->create($postBody,$optParams);
    }
    protected function filesUpdate($fileId, Google_Service_Drive_DriveFile $postBody, $optParams = array()){
        if($fileId instanceof Google_Service_Drive_DriveFile){
            $fileId=$fileId->getId();
        }
        $this->logQuery('files.update',func_get_args());
        $optParams=$this->mergeParams('files.update',['fields' => $this->fetchfieldsGet],$optParams);
        return $this->service->files->update($fileId,$postBody,$optParams);
    }
    protected function filesCopy($fileId, Google_Service_Drive_DriveFile $postBody, $optParams = array()){
        if($fileId instanceof Google_Service_Drive_DriveFile){
            $fileId=$fileId->getId();
        }
        $this->logQuery('files.copy',func_get_args());
        $optParams=$this->mergeParams('files.copy',['fields' => $this->fetchfieldsGet],$optParams);
        return $this->service->files->copy($fileId,$postBody,$optParams);
    }
    protected function filesDelete($fileId, $optParams = array()){
        if($fileId instanceof Google_Service_Drive_DriveFile){
            $fileId=$fileId->getId();
        }
        $this->logQuery('files.delete',func_get_args());
        $optParams=$this->mergeParams('files.delete',$optParams);
        return $this->service->files->delete($fileId,$optParams);
    }
    protected function mergeParams($cmd,...$params){
        $default=$this->getDefaultParams($cmd);
        return array_merge($default,...$params);
    }
    protected function getDefaultParams($cmd){
        if(isset($this->defaultParams[$cmd]) && is_array($this->defaultParams[$cmd])){
            return $this->defaultParams[$cmd];
        }
        return [];
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
    public function setTeamDriveId($teamDriveId, $corpora = 'teamDrive')
    {
        $this->enableTeamDriveSupport();
        $this->defaultParams = array_merge_recursive($this->defaultParams, [
            'files.list' => [
                'corpora' => $corpora,
                'includeTeamDriveItems' => true,
                'teamDriveId' => $teamDriveId
            ]
        ]);

        $this->setPathPrefix($teamDriveId);
    }
    protected function logQuery($cmd,$query){
        if(!$this->logQuery){
            return ;
        }
        $id=md5(json_encode($query));
        if(!isset($this->query['total'])){
            $this->query['total']=0;
        }
        $this->query['total']++;
        if(isset($this->query['counts'][$cmd][$id])){
            $this->query['counts'][$cmd][$id]++;
        }else{
            $this->query['counts'][$cmd][$id]=1;
        }
        $this->query['queries'][$cmd][]=$query;
    }
    public function enableQueryLog(){
        $this->logQuery=true;
    }
    public function showQueryLog($query='queries'){
        if(!$query){
            $show=$this->query;
        }else{
            $show=isset($this->query[$query])?$this->query[$query]:null;
        }
        print_r($show);
    }
}