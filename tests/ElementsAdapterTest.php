<?php

use Biigle\Flysystem\Elements\ElementsAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Config;
use OpenStack\Common\Error\BadResponseError;
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

        $adapter->getMetadata('my/path');
        $adapter->getMetadata('my/path');

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

        $data = $adapter->getMetadata('my/path.jpg');

        $expect = [
            'type' => 'file',
            'dirname' => 'my',
            'path' => 'my/path.jpg',
            'timestamp' => 123,
            'mimetype' => 'image/jpeg',
            'size' => 456,
        ];
        $this->assertEquals($expect, $data);
    }

    public function testGetMetadataDir()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"is_dir":true,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $data = $adapter->getMetadata('my/path');

        $expect = [
            'type' => 'dir',
            'dirname' => 'my',
            'path' => 'my/path',
            'timestamp' => 123,
            'mimetype' => 'application/octet-stream',
            'size' => 456,
        ];
        $this->assertEquals($expect, $data);
    }

    public function testHas()
    {
        $mock = new MockHandler([
            new Response(200, [], '[]'),
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $this->assertFalse($adapter->has('file-one'));
        $this->assertTrue($adapter->has('file-two'));
    }

    public function testReadRequest()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"bundle":321,"is_dir":false,"mtime":123,"size":456,"path":"my/path"}]'),
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
        $this->assertEquals('api/media/download/321', $request->getUri()->getPath());
    }

    public function testReadFile()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"bundle":321,"is_dir":false,"mtime":123,"size":456,"path":"my/path"}]'),
            new Response(200, [], 'hello world'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $data = $adapter->read('my/path');

        $this->assertEquals($data, [
            'type' => 'file',
            'dirname' => 'my',
            'path' => 'my/path',
            'timestamp' =>  123,
            'mimetype' => 'application/octet-stream',
            'size' => 456,
            'contents' => 'hello world'
        ]);
    }

    public function testReadDir()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":true,"mtime":123,"size":456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $data = $adapter->read('my/path');

        $this->assertEquals($data, [
            'type' => 'dir',
            'dirname' => 'my',
            'path' => 'my/path',
            'timestamp' =>  123,
            'mimetype' => 'application/octet-stream',
            'size' => 456,
            'contents' => ''
        ]);
    }

    public function testReadStreamFile()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"bundle":321,"is_dir":false,"mtime":123,"size":456,"path":"my/path"}]'),
            new Response(200, [], 'hello world'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $data = $adapter->readStream('my/path');

        $this->assertEquals('hello world', stream_get_contents($data['stream']));
    }

    public function testReadStreamDir()
    {
        $mock = new MockHandler([
            new Response(200, [], '[{"id":321,"is_dir":true,"mtime":123,"size":456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        $data = $adapter->readStream('my/path');

        $this->assertEquals(null, $data['stream']);
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

        $adapter->listContents('my/path');

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

        $contents = $adapter->listContents('my');
        $this->assertCount(1, $contents);

        $expect = [
            'type' => 'file',
            'dirname' => 'my',
            'path' => 'my/path',
            'timestamp' => 123,
            'mimetype' => 'application/octet-stream',
            'size' => 456,
        ];
        $this->assertEquals($expect, $contents[0]);

        // Use cached directory contents.
        $contents = $adapter->listContents('my');
        $this->assertCount(1, $contents);
    }

    public function testMetadataMethods()
    {
        $methods = [
            'getMetadata',
            'getSize',
            'getMimetype',
            'getTimestamp'
        ];

        $mock = new MockHandler([
            new Response(200, [], '[{"is_dir":false,"mtime":123,"size": 456,"path":"my/path"}]'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $adapter = new ElementsAdapter($client);

        foreach ($methods as $method) {
            $metadata = $adapter->$method('my/path');

            $this->assertEquals($metadata, [
                'type' => 'file',
                'dirname' => 'my',
                'path' => 'my/path',
                'timestamp' =>  123,
                'mimetype' => 'application/octet-stream',
                'size' => 456,
            ]);
        }
    }
}
