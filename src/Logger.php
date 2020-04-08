<?php


namespace As247\Flysystem\GoogleDrive;


class Logger
{
	protected $queries=[];
	function debug($message){
		//echo $message.PHP_EOL;
	}
	function query($cmd,$query){
		$id=md5(json_encode($query));
		if(!isset($this->queries['total'])){
			$this->queries['total']=0;
		}
		$this->queries['total']++;
		if(isset($this->queries['counts'][$cmd][$id])){
			$this->queries['counts'][$cmd][$id]++;
		}else{
			$this->queries['counts'][$cmd][$id]=1;
		}
		$this->queries['queries'][$cmd][]=$query;
		return $this;
	}
}
