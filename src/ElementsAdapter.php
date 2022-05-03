<?php

namespace Biigle\Flysystem\Elements;

use Exception;
use GuzzleHttp\Client;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

class ElementsAdapter implements FilesystemAdapter
{
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

    protected PathPrefixer $prefixer;

    /**
     * Constructor
     *
     * @param Client $client
     * @param string    $prefix
     */
    public function __construct(Client $client, $prefix = '')
    {
        $this->client = $client;
        $this->cache = [];
        $this->contentCache = [];
        $this->prefixer = new PathPrefixer($prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function fileExists(string $path): bool
    {
        $prefixPath = $this->prefixer->prefixPath($path);

        try {
            $file = $this->getMediaFile($prefixPath);
        } catch (Exception $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }

        return !is_null($file) && $file['is_dir'] === false;
    }

    /**
     * {@inheritdoc}
     */
    public function directoryExists(string $path): bool
    {
        $prefixPath = $this->prefixer->prefixPath($path);

        try {
            $file = $this->getMediaFile($prefixPath);
        } catch (Exception $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }

        return !is_null($file) && $file['is_dir'] === true;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $prefixPath = $this->prefixer->prefixPath($path);

        try {
            $response = $this->getMediaFileDownload($prefixPath);
        } catch (Exception $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        return $response->getBody()->getContents();
    }

    /**
    * {@inheritdoc}
    */
    public function readStream(string $path)
    {
        $prefixPath = $this->prefixer->prefixPath($path);

        try {
            $response = $this->getMediaFileDownload($prefixPath);
        } catch (Exception $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        return $response->getBody()->detach();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): void
    {
        throw UnableToDeleteFile::atLocation($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw new FilesystemException('Not supported.');
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->getFileMetadata($path, 'visibility');
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getFileMetadata($path, 'mimeType');
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getFileMetadata($path, 'lastModified');
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getFileMetadata($path, 'fileSize');
    }

    /**
     * {@inheritdoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefixPath = $this->prefixer->prefixPath($path);
        $files = $this->getMediaFiles($prefixPath);

        foreach ($files as $file) {
            yield $this->parseStorageAttributes($file);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
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
            $prefixPath = $this->prefixer->prefixPath($path);

            $response = $this->client->get('api/2/media/files', ['query' => [
                'path' => $prefixPath,
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
            if ($path !== '') {
                $parent = $this->getMediaFile($path);

                if (!$parent) {
                    return [];
                }

                $response = $this->client->get('api/2/media/files', ['query' => [
                    'parent' => $parent['id'],
                ]]);

                $content = json_decode($response->getBody()->getContents(), true);
            } else {
                $response = $this->client->get('api/2/media/roots');
                $content = json_decode($response->getBody()->getContents(), true);

                if (!is_null($content)) {
                    $content = $this->processFileRoots($content);
                }
            }

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
        $file = $this->getMediaFile($path);
        if ($file['is_dir']) {
            throw new Exception("Not a file.");
        }

        $id = $file['id'];
        $response = $this->client->get("api/2/media/files/{$id}/download");

        return $response;
    }

    protected function getFileMetadata(string $path, string $type): FileAttributes
    {
        $data = $this->getMetadata($path, $type);

        if ($data instanceof FileAttributes) {
            return $data;
        }

        throw UnableToRetrieveMetadata::$type($path, 'No file.');
    }

    protected function getMetadata(string $path, string $type): FileAttributes|DirectoryAttributes
    {
        $prefixPath = $this->prefixer->prefixPath($path);
        $file = $this->getMediaFile($prefixPath);

        try {
            return $this->parseStorageAttributes($file);
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::$type($path, $e->getMessage(), $e);
        }
    }

    protected function parseStorageAttributes(array $attributes): FileAttributes|DirectoryAttributes
    {
        if ($attributes['is_dir']) {
            return new DirectoryAttributes(
                $attributes['path'],
                'public',
                $attributes['mtime'] ?? null
            );
        }

        $detector = new ExtensionMimeTypeDetector();
        $mimeType = $detector->detectMimeTypeFromPath($attributes['path']);

        return new FileAttributes(
            $attributes['path'],
            (int) $attributes['size'],
            'public',
            $attributes['mtime'],
            $mimeType
        );
    }

    protected function processFileRoots(array $roots): array
    {
        return array_map(function ($root) {
            return [
                'is_dir' => true,
                'path' => $root['path'],
            ];
        }, $roots);
    }
}
