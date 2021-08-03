# Flysystem ELEMENTS

[![Tests](https://github.com/biigle/flysystem-elements/actions/workflows/php.yml/badge.svg)](https://github.com/biigle/flysystem-elements/actions/workflows/php.yml)

Flysystem adapter for the ELEMENTS media asset management system (read-only).

## Installation

```bash
composer require biigle/flysystem-elements
```
## Usage

```php
use Biigle\Flysystem\Elements\ElementsAdapter;
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://elements.example.com',
    'headers' => [
        'Authorization' => 'Bearer my-elements-api-token',
    ],
]);
$adapter = new ElementsAdapter($client);

$data = $adapter->getMetadata('.projects/path/to/file.jpg');
var_dump($data);
// [
//     'type' => 'file',
//     'dirname' => '.projects/path/to',
//     'path' => '.projects/path/to/file.jpg',
//     'timestamp' => 1627980183,
//     'mimetype' => 'image/jpeg',
//     'size' => 123456,
// ];
```

Available methods are:

- has
- read
- readStream
- listContents
- getMetadata
- getSize
- getMimetype
- getTimestamp

All other (non-reading) methods have no effect.
