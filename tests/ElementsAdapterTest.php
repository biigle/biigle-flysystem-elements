<?php

use Biigle\Flysystem\Elements\ElementsAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\TestCase;


class ElementsAdapterTest extends TestCase
{
    public function testGetMetadataRequest()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $container = [];
        $history = Middleware::history($container);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $adapter->lastModified('my/path');
        $adapter->lastModified('my/path');

        // Use cache for same paths.
        $this->assertCount(1, $container);
        $request = $container[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $uri = $request->getUri();
        $this->assertEquals('api/2/media/files', $uri->getPath());
        $this->assertStringContainsString('path=my%2Fpath', $uri->getQuery());
    }

    public function testGetMetadataFile()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path.jpg"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->assertEquals(123, $adapter->lastModified('my/path.jpg')->lastModified());
        $this->assertEquals(456, $adapter->fileSize('my/path.jpg')->fileSize());
        $this->assertEquals('image/jpeg', $adapter->mimeType('my/path.jpg')->mimeType());
    }

    public function testGetMetadataDir()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"is_dir":true,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->expectException(UnableToRetrieveMetadata::class);
        $adapter->lastModified('my/path');
    }

    public function testFileExists()
    {
        $mock = new MockHandler([
            new Response(200, [], '[]'),
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->assertFalse($adapter->fileExists('file-one'));
        $this->assertTrue($adapter->fileExists('file-two'));
    }

    public function testDirectoryExists()
    {
        $mock = new MockHandler([
            new Response(200, [], '[]'),
            new Response(200, [], '[{"is_dir":true,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->assertFalse($adapter->directoryExists('directory-one'));
        $this->assertTrue($adapter->directoryExists('file-two'));
    }

    public function testReadRequest()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":false,"mtime":123,"size":456,"path":"my/path"}]'),
            new Response(200, [], 'hello world'),
        ]);
        $container = [];
        $history = Middleware::history($container);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $adapter->read('my/path');

        $this->assertCount(2, $container);
        $request = $container[1]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('api/2/media/files/321/download', $request->getUri()->getPath());
    }

    public function testReadFile()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":false,"mtime":123,"size":456,"path":"my/path"}]'),
            new Response(200, [], 'hello world'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->assertEquals('hello world', $adapter->read('my/path'));
    }

    public function testReadDir()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":true,"mtime":123,"size":456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->expectException(UnableToReadFile::class);
        $data = $adapter->read('my/path');
    }

    public function testReadStreamFile()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":false,"mtime":123,"size":456,"path":"my/path"}]'),
            new Response(200, [], 'hello world'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $data = $adapter->readStream('my/path');

        $this->assertEquals('hello world', stream_get_contents($data));
    }

    public function testReadStreamDir()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":true,"mtime":123,"size":456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->expectException(UnableToReadFile::class);
        $data = $adapter->readStream('my/path');
    }

    public function testListContentsRequest()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":true,"mtime":123,"size":456,"path":"my"}]'),
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $container = [];
        $history = Middleware::history($container);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $contents = $adapter->listContents('my/path', false);
        // Unroll the iterator, otherwise no requests would be sent.
        iterator_to_array($contents);

        $this->assertCount(2, $container);
        $request = $container[0]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $uri = $request->getUri();
        $this->assertEquals('api/2/media/files', $uri->getPath());
        $this->assertStringContainsString('path=my', $uri->getQuery());

        $request = $container[1]['request'];
        $this->assertEquals('GET', $request->getMethod());
        $uri = $request->getUri();
        $this->assertEquals('api/2/media/files', $uri->getPath());
        $this->assertStringContainsString('parent=321', $uri->getQuery());
    }

    public function testListContents()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":true,"mtime":123,"size":456,"path":"my"}]'),
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $contents = $adapter->listContents('my', false);
        $contents = iterator_to_array($contents);
        $this->assertCount(1, $contents);

        $attributes = $contents[0];
        $this->assertEquals('my/path', $attributes->path());
        $this->assertEquals(123, $attributes->lastModified());
        $this->assertEquals(456, $attributes->fileSize());
        $this->assertEquals(null, $attributes->mimeType());

        // Use cached directory contents.
        $contents = $adapter->listContents('my', false);
        $contents = iterator_to_array($contents);
        $this->assertCount(1, $contents);
    }

    public function testMetadataMethods()
    {
        $methods = [
            'visibility',
            'fileSize',
            'mimeType',
            'lastModified'
        ];

        $mock = new MockHandler([
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        foreach ($methods as $method) {
            $attributes = $adapter->$method('my/path');

            $this->assertEquals('my/path', $attributes->path());
            $this->assertEquals(123, $attributes->lastModified());
            $this->assertEquals(456, $attributes->fileSize());
            $this->assertEquals(null, $attributes->mimeType());
        }
    }
}
