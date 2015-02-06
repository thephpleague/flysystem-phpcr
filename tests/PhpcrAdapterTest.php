<?php

namespace League\Flysystem\Phpcr\Tests;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\DriverManager;
use Jackalope\RepositoryFactoryDoctrineDBAL;
use League\Flysystem\Phpcr\PhpcrAdapter;
use League\Flysystem\Config;
use PHPCR\SessionInterface;
use PHPCR\SimpleCredentials;

class PhpcrAdapterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Connection
     */
    private static $connection;

    /**
     * @var PhpcrAdapter
     */
    private $adapter;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var string Path to the test root.
     */
    private $root;

    public static function setUpBeforeClass()
    {
        static::$connection = DriverManager::getConnection(array(
            'driver' => 'pdo_sqlite',
            'path'   => sys_get_temp_dir().DIRECTORY_SEPARATOR.'flysystem-test.db',
        ));
        $options = array('disable_fks' => static::$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform);
        $repositorySchema = new \Jackalope\Transport\DoctrineDBAL\RepositorySchema($options, static::$connection);
        $repositorySchema->reset();
    }

    public function setUp()
    {
        $this->root = '/flysystem_tests';

        $factory = new RepositoryFactoryDoctrineDBAL();
        $repository = $factory->getRepository(array(
            'jackalope.doctrine_dbal_connection' => static::$connection,
        ));
        $this->session = $repository->login(new SimpleCredentials('test', 'test'));
        if ($this->session->nodeExists($this->root)) {
            $this->session->removeItem($this->root);
        }
        $this->adapter = new PhpcrAdapter($this->session, $this->root);
    }

    /**
     * @expectedException \LogicException
     */
    public function testConstructor()
    {
        $this->session->getRootNode()->addNode('/flysystem_tests_broken', 'nt:unstructured');
        new PhpcrAdapter($this->session, '/flysystem_tests_broken');
    }

    public function testHasWithDir()
    {
        $this->adapter->createDir('0', new Config());
        $this->assertTrue($this->adapter->has('0'));
        $this->adapter->deleteDir('0');
    }

    public function testHasWithFile()
    {
        $this->adapter->write('file.txt', 'content', new Config());
        $this->assertTrue($this->adapter->has('file.txt'));
        $this->adapter->delete('file.txt');
    }

    public function testReadNotFound()
    {
        $this->assertFalse($this->adapter->read('file.txt'));
    }

    public function testReadFolderNotFound()
    {
        $this->assertFalse($this->adapter->read('folder/file.txt'));
    }

    public function testReadStream()
    {
        $this->adapter->write('file.txt', 'contents', new Config());
        $result = $this->adapter->readStream('file.txt');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('stream', $result);
        $this->assertInternalType('resource', $result['stream']);
        fclose($result['stream']);
        $this->adapter->delete('file.txt');
    }

    public function testReadStreamNotFound()
    {
        $this->assertFalse($this->adapter->readStream('file.txt'));
    }

    public function testUpdate()
    {
        $this->adapter->update('file.txt', 'content', new Config());
        $this->assertTrue($this->adapter->has('file.txt'));
        $this->adapter->delete('file.txt');
    }

    public function testWriteStream()
    {
        $temp = tmpfile();
        fwrite($temp, 'dummy');
        rewind($temp);
        $this->adapter->writeStream('dir/file.txt', $temp, new Config(['visibility' => 'public']));
        //fclose($temp);
        $this->assertTrue($this->adapter->has('dir/file.txt'));
        $result = $this->adapter->read('dir/file.txt');
        $this->assertEquals('dummy', $result['contents']);
        $this->adapter->deleteDir('dir');
    }

    public function testUpdateStream()
    {
        $this->adapter->write('file.txt', 'initial', new Config());
        $temp = tmpfile();
        fwrite($temp, 'dummy');
        $this->adapter->updateStream('file.txt', $temp, new Config());
//        fclose($temp);
        $this->assertTrue($this->adapter->has('file.txt'));
        $this->adapter->delete('file.txt');
    }

    public function testCreateZeroDir()
    {
        $this->adapter->createDir('0', new Config());
        $this->assertTrue($this->session->nodeExists($this->root.'/0'));
        $this->adapter->deleteDir('0');
    }

    public function testRename()
    {
        $this->adapter->write('file.ext', 'content', new Config(['visibility' => 'public']));
        $this->assertTrue($this->adapter->rename('file.ext', 'new.ext'));
        $this->assertTrue($this->adapter->has('new.ext'));
        $this->assertFalse($this->adapter->has('file.ext'));
        $this->adapter->delete('file.ext');
        $this->adapter->delete('new.ext');
    }

    public function testRenameNotExists()
    {
        $this->assertFalse($this->adapter->rename('file.ext', 'new.ext'));
    }

    public function testCopy()
    {
        $this->adapter->write('file.ext', 'content', new Config(['visibility' => 'public']));
        $this->assertTrue($this->adapter->copy('file.ext', 'new.ext'));
        $this->assertTrue($this->adapter->has('new.ext'));
        $this->assertTrue($this->adapter->has('file.ext'));
        $this->adapter->delete('file.ext');
        $this->adapter->delete('new.ext');
    }

    public function testCopyNotExists()
    {
        $this->assertFalse($this->adapter->copy('file.ext', 'new.ext'));
    }

    public function testListContents()
    {
        $this->adapter->write('dirname/file.txt', 'contents', new Config());
        $this->adapter->write('dirname/subfolder/file.txt', 'contents', new Config());
        $contents = $this->adapter->listContents('dirname', false);
        $this->assertCount(2, $contents);
        $this->assertArrayHasKey('type', $contents[0]);
        $this->assertEquals('file', $contents[0]['type']);
        $this->assertArrayHasKey('type', $contents[1]);
        $this->assertEquals('folder', $contents[1]['type']);
    }

    public function testListContentsRecursive()
    {
        $this->adapter->write('dirname/file.txt', 'contents', new Config());
        $this->adapter->write('dirname/subfolder/file.txt', 'contents', new Config());
        $contents = $this->adapter->listContents('dirname', true);
        $this->assertCount(3, $contents);
        $this->assertArrayHasKey('type', $contents[0]);
        $this->assertEquals('file', $contents[0]['type']);
        $this->assertArrayHasKey('type', $contents[1]);
        $this->assertEquals('folder', $contents[1]['type']);
        $this->assertArrayHasKey('type', $contents[2]);
        $this->assertEquals('file', $contents[2]['type']);
        $this->assertEquals('dirname/subfolder/file.txt', $contents[2]['path']);
    }

    public function testListingNonexistingDirectory()
    {
        $result = $this->adapter->listContents('nonexisting/directory');
        $this->assertEquals([], $result);
    }

    public function testGetMetadataFolder()
    {
        $this->adapter->createDir('test', new Config());
        $result = $this->adapter->getMetadata('test');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('folder', $result['type']);
    }

    public function testGetMetadataNotExisting()
    {
        $this->assertFalse($this->adapter->getMetadata('dummy.txt'));
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

    public function testGetEncoding()
    {
        $this->adapter->write('text.txt', 'contents', new Config(array('encoding' => 'utf-8')));
        $result = $this->adapter->getMimetype('text.txt');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('encoding', $result);
        $this->assertEquals('utf-8', $result['encoding']);
    }

    public function testDeleteDir()
    {
        $this->adapter->write('nested/dir/path.txt', 'contents', new Config());
        $this->assertTrue($this->session->nodeExists($this->root.'/nested/dir/path.txt'));
        $this->assertTrue($this->adapter->deleteDir('nested'));
        $this->assertFalse($this->adapter->has('nested/dir/path.txt'));
        $this->assertFalse($this->session->nodeExists($this->root.'/nested'));
    }

    public function testDeleteDirNotFound()
    {
        $this->assertFalse($this->adapter->deleteDir('nested'));
    }

    public function testDeleteDirIsFile()
    {
        $this->adapter->write('dir/path.txt', 'contents', new Config());
        $this->assertTrue($this->session->nodeExists($this->root.'/dir/path.txt'));
        $this->assertFalse($this->adapter->deleteDir('dir/path.txt'));
    }

    /**
     * @expectedException \LogicException
     */
    public function testVisibilityPublic()
    {
        $this->adapter->write('path.txt', 'contents', new Config());
        $this->adapter->setVisibility('path.txt', 'public');
    }

    /**
     * @expectedException \LogicException
     */
    public function testVisibilityPrivate()
    {
        $this->adapter->write('path.txt', 'contents', new Config());
        $this->adapter->setVisibility('path.txt', 'private');
    }

    public function testApplyPathPrefix()
    {
        $this->adapter->setPathPrefix('');
        $this->assertEquals('', $this->adapter->applyPathPrefix(''));
    }

    public function testApplyPathPrefixAbsolute()
    {
        $this->adapter->setPathPrefix($this->root . '/');
        $this->assertEquals($this->root, $this->adapter->applyPathPrefix('/'));
    }
}
