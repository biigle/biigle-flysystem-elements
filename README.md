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

$exists = $adapter->fileExists('.projects/path/to/file.jpg');
var_dump($exists);
// bool(true);
```

Supported methods are:

- fileExists
- directoryExists
- read
- readStream
- visibility
- mimeType
- lastModified
- fileSize
- listContents

All other (non-reading) methods throw an exception.
