<?php

namespace Biigle\Flysystem\Elements;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Mzur\GuessMIME\GuessMIME;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;

class ElementsAdapter extends AbstractAdapter
{
    use StreamedCopyTrait;
    use NotSupportingVisibilityTrait;

    /**
     * @var Client
     */
    protected $client;

    /**
     * File information cache.
     *
     * @var array
     */
    protected $cache;

    /**
     * Directory content cache.
     *
     * @var array
     */
    protected $contentCache;

    /**
     * Constructor
     *
     * @param Client $client
     * @param string    $prefix
     */
    public function __construct(Client $client, $prefix = null)
    {
        $this->setPathPrefix($prefix);
        $this->client = $client;
        $this->cache = [];
        $this->contentCache = [];
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config, $size = 0)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        //
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $file = $this->getMediaFile($path);

        return !is_null($file);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $data = $this->getMetadata($path);

        if ($data['type'] === 'file') {
            $response = $this->getMediaFileDownload($path);

            $data['contents'] = $response->getBody()->getContents();
        } else {
            $data['contents'] = null;
        }

        return $data;
    }

    /**
    * {@inheritdoc}
    */
    public function readStream($path)
    {
        $data = $this->getMetadata($path);

        if ($data['type'] === 'file') {
            $response = $this->getMediaFileDownload($path);

            $data['stream'] = StreamWrapper::getResource($response->getBody());
        } else {
            $data['stream'] = null;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $files = $this->getMediaFiles($directory);

        return array_map(function ($file) {
            return $this->parseFileMetadata($file);
        }, $files);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $file = $this->getMediaFile($path);

        return $this->parseFileMetadata($file);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the media file information.
     *
     * @param string $path
     *
     * @return array|null
     */
    protected function getMediaFile($path)
    {
        if (!array_key_exists($path, $this->cache)) {
            $response = $this->client->get('api/2/media/files', ['query' => [
                'path' => $this->applyPathPrefix($path),
                'limit' => 1,
            ]]);

            $content = json_decode($response->getBody()->getContents(), true);

            $this->cache[$path] = $content[0] ?? null;
        }

        return $this->cache[$path];
    }

    /**
     * Get the media file information for all files of a given parent.
     *
     * @param string $path Path to the parent (directory)
     *
     * @return array
     */
    protected function getMediaFiles($path)
    {
        if (array_key_exists($path, $this->contentCache)) {
            $content = array_map(function ($filePath) {
                return $this->cache[$filePath];
            }, $this->contentCache[$path]);
        } else {
            $parent = $this->getMediaFile($path);

            $response = $this->client->get('api/2/media/files', ['query' => [
                'parent' => $parent['id'],
            ]]);

            $content = json_decode($response->getBody()->getContents(), true);
            $this->contentCache[$path] = [];

            foreach ($content as $file) {
                $this->contentCache[$path][] = $file['path'];
                $this->cache[$file['path']] = $file;
            }
        }

        return $content;
    }

    /**
     * Get the content of a media file.
     *
     * @param string $path
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function getMediaFileDownload($path)
    {
        $id = $this->getMediaFile($path)['bundle'];
        $response = $this->client->get("api/media/download/{$id}");

        return $response;
    }

    /**
     * Parse the raw media file information to a metadata array.
     *
     * @param array $file
     *
     * @return array
     */
    protected function parseFileMetadata(array $file)
    {
        $gm = new GuessMIME;

        return [
            'type' => $file['is_dir'] ? 'dir' : 'file',
            'dirname' => Util::dirname($file['path']),
            'path' => $file['path'],
            'timestamp' => $file['mtime'],
            'mimetype' => $gm->guess($file['path']),
            'size' => $file['size'],
        ];
    }
}
