<?php

namespace As247\Flysystem\GoogleDrive\Tests;


use As247\Flysystem\GoogleDrive\GoogleDriveAdapter;
use PHPUnit\Framework\TestCase;
use League\Flysystem\Config;
class GoogleDriveAdapterTest extends TestCase
{
    /**
     * @var GoogleDriveAdapter
     */
    protected $adapter;
    function setUp()
    {
        $client = new \Google_Client();
        $client->setClientId($_ENV['googleClientId']);
        $client->setClientSecret($_ENV['googleClientSecret']);
        $client->refreshToken($_ENV['googleRefreshToken']);
        $service = new \Google_Service_Drive($client);
        $options=[];
        if(isset($_ENV['teamDriveId'])) {
            $options['teamDriveId'] = $_ENV['teamDriveId'];
        }
        $this->adapter = new GoogleDriveAdapter($service, $_ENV['folderId'], $options);
    }
    public function teardown()
    {
        foreach ($this->adapter->listContents() as $content){
            if($content['type']=='dir'){
                $this->adapter->deleteDir($content['path']);
            }else{
                $this->adapter->delete($content['path']);
            }
        }
    }
    public function testHasRootDir(){
        $this->assertTrue($this->adapter->has('.'));
        $this->assertTrue($this->adapter->has('/'));
        $this->assertTrue($this->adapter->has(''));
    }
    public function testRootDirDeletion(){
        $this->assertFalse($this->adapter->delete('.'));
        $this->assertFalse($this->adapter->delete('//'));
        $this->assertFalse($this->adapter->delete(''));
        $this->assertFalse($this->adapter->delete('..'));
    }

    public function testHasWithDir()
    {
        $this->adapter->createDir('0', new Config());
        $this->assertTrue($this->adapter->has('0'));
        $this->adapter->deleteDir('0');
    }
    public function testHasWithFile()
    {
        $adapter = $this->adapter;
        $adapter->write('file.txt', 'content', new Config());
        $this->assertTrue($adapter->has('file.txt'));
        $adapter->delete('file.txt');
    }
    public function testReadStream()
    {
        $adapter = $this->adapter;
        $adapter->write('file.txt', 'contents', new Config());
        $result = $adapter->readStream('file.txt');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('stream', $result);
        $this->assertInternalType('resource', $result['stream']);
        fclose($result['stream']);
        $adapter->delete('file.txt');
    }
    public function testWriteStream()
    {
        $adapter = $this->adapter;
        $temp = tmpfile();
        fwrite($temp, 'dummy');
        rewind($temp);
        $adapter->writeStream('dir/file.txt', $temp, new Config(['visibility' => 'public']));
        $this->assertTrue($adapter->has('dir/file.txt'));
        $result = $adapter->read('dir/file.txt');
        $this->assertEquals('dummy', $result['contents']);
        $adapter->deleteDir('dir');
    }
    public function testListingNonexistingDirectory()
    {
        $result = $this->adapter->listContents('nonexisting/directory');
        $this->assertEquals([], $result);
    }
    public function testUpdateStream()
    {
        $adapter = $this->adapter;
        $adapter->write('file.txt', 'initial', new Config());
        $temp = tmpfile();
        fwrite($temp, 'dummy');
        $adapter->updateStream('file.txt', $temp, new Config());
        @fclose($temp);
        $this->assertTrue($adapter->has('file.txt'));
        $this->assertEquals('dummy',$adapter->read('file.txt')['contents']);
        $adapter->delete('file.txt');
    }
    public function testCreateZeroDir()
    {
        $this->adapter->createDir('0', new Config());
        $this->assertTrue($this->adapter->isDirectory('0'));
        $this->adapter->deleteDir('0');
    }
    public function testCreateDirRecurse(){
        $this->adapter->createDir('a/b/c', new Config());
        $this->assertTrue($this->adapter->isDirectory('a/b/c'));
        $this->adapter->deleteDir('a');
    }
    public function testCopy()
    {
        $adapter = $this->adapter;
        $adapter->write('file.ext', 'content', new Config(['visibility' => 'public']));
        $this->assertTrue($adapter->copy('file.ext', 'new.ext'));
        $this->assertTrue($adapter->has('new.ext'));
        $adapter->delete('file.ext');
        $adapter->delete('new.ext');
    }
    public function testCopyNested(){
        $adapter = $this->adapter;
        $adapter->write('file.ext', 'content', new Config(['visibility' => 'public']));
        $this->assertTrue($adapter->copy('file.ext', '/a/b/new.ext'));
        $this->assertTrue($adapter->has('/a/b/new.ext'));
    }
    public function testFailingStreamCalls()
    {
        $this->assertFalse($this->adapter->writeStream('false', tmpfile(), new Config()));
        $this->assertFalse($this->adapter->writeStream('fail.close', tmpfile(), new Config()));
    }
    public function testNullPrefix()
    {
        $this->adapter->setPathPrefix('');
        $expected='';
        if($this->adapter instanceof GoogleDriveAdapter){
            $expected='root';
        }
        $this->assertEquals($expected, $this->adapter->getPathPrefix());
    }
    public function testRenameToNonExistsingDirectory()
    {
        $this->adapter->write('file.txt', 'contents', new Config());
        $dirname = uniqid();
        $this->assertFalse($this->adapter->isDirectory($dirname));
        $this->assertTrue($this->adapter->rename('file.txt', $dirname . '/file.txt'));
    }
    public function testListContents()
    {
        $this->adapter->write('dirname/file.txt', 'contents', new Config());
        $contents = $this->adapter->listContents('dirname', false);
        $this->assertCount(1, $contents);
        $this->assertArrayHasKey('type', $contents[0]);
    }
    public function testListContentsRecursive()
    {
        $this->adapter->write('dirname/file.txt', 'contents', new Config());
        $this->adapter->write('dirname/other.txt', 'contents', new Config());
        $contents = $this->adapter->listContents('', true);
        $this->assertCount(3, $contents);
    }
    public function testGetSize()
    {
        $this->adapter->write('dummy.txt', '1234', new Config());
        $result = $this->adapter->getSize('dummy.txt');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertEquals(4, $result['size']);
    }
    public function testGetTimestamp()
    {
        $this->adapter->write('dummy.txt', '1234', new Config());
        $result = $this->adapter->getTimestamp('dummy.txt');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertInternalType('int', $result['timestamp']);
    }
    public function testGetMimetype()
    {
        $this->adapter->write('text.txt', 'contents', new Config());
        $result = $this->adapter->getMimetype('text.txt');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('mimetype', $result);
        $this->assertEquals('text/plain', $result['mimetype']);
    }
    public function testCreateDirFail()
    {
        $this->adapter->write('fail.plz','contents',new Config());
        $this->assertFalse($this->adapter->createDir('fail.plz', new Config()));
    }
    public function testCreateDirDefaultVisibility()
    {
        $this->adapter->createDir('test-dir', new Config());
        $output = $this->adapter->getVisibility('test-dir');
        $this->assertInternalType('array', $output);
        $this->assertArrayHasKey('visibility', $output);
        $this->assertEquals('private', $output['visibility']);
    }
    public function testVisibilityPublish()
    {
        $this->adapter->createDir('test-dir', new Config());
        $this->adapter->setVisibility('test-dir','public');
        $output = $this->adapter->getVisibility('test-dir');
        $this->assertInternalType('array', $output);
        $this->assertArrayHasKey('visibility', $output);
        $this->assertEquals('public', $output['visibility']);
        $this->adapter->setVisibility('test-dir','private');
        $output = $this->adapter->getVisibility('test-dir');
        $this->assertInternalType('array', $output);
        $this->assertArrayHasKey('visibility', $output);
        $this->assertEquals('private', $output['visibility']);
    }
    public function testDeleteDir()
    {
        $this->adapter->write('nested/dir/path.txt', 'contents', new Config());
        $this->assertTrue($this->adapter->isDirectory('nested/dir'));
        $this->adapter->deleteDir('nested');
        $this->assertFalse($this->adapter->has('nested/dir/path.txt'));
        $this->assertFalse($this->adapter->isDirectory('nested/dir'));
    }

    public function testMimetypeFallbackOnExtension()
    {
        $this->adapter->write('test.xlsx', '', new Config);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->adapter->getMimetype('test.xlsx')['mimetype']);
    }
    public function testDeleteFileShouldReturnTrue(){
        $this->adapter->write('delete.txt','something',new Config());
        $this->assertTrue($this->adapter->delete('delete.txt'));
    }
    public function testDeleteMissingFileShouldReturnFalse(){
        $this->assertFalse($this->adapter->delete('missing.txt'));
    }
}