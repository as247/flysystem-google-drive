<?php
/**
 * Created by PhpStorm.
 * User: alt
 * Date: 11-Oct-18
 * Time: 10:05 PM
 */

namespace As247\Flysystem\GoogleDrive\Concerns;

use Google_Service_Drive_DriveFile;
trait Helpers
{
    /**
     * @param string $path create directory structure
     * @return bool|string folder id
     *
     */
    protected function ensureDirectory($path){
        return $this->mkdir($path);
    }
    public function applyPathPrefix($path){
        return $this->detectPath($path);
    }
    /**
     * Read file chunk
     *
     * @param resource $handle
     * @param int $chunkSize
     *
     * @return string
     */
    protected function readFileChunk($handle, $chunkSize)
    {
        $byteCount = 0;
        $giantChunk = '';
        while (! feof($handle)) {
            // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
            $chunk = fread($handle, 8192);
            $byteCount += strlen($chunk);
            $giantChunk .= $chunk;
            if ($byteCount >= $chunkSize) {
                return $giantChunk;
            }
        }
        return $giantChunk;
    }
    /**
     * Return bytes from php.ini value
     *
     * @param string $iniName
     * @param string $val
     * @return number
     */
    protected function getIniBytes($iniName = '', $val = '')
    {
        if ($iniName !== '') {
            $val = ini_get($iniName);
            if ($val === false) {
                return 0;
            }
        }
        $val = trim($val, "bB \t\n\r\0\x0B");
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int)$val;
        switch ($last) {
            case 't':
                $val *= 1024;
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
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

    function line($line=''){
        echo $line.PHP_EOL;

    }
}