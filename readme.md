# Flysystem Adapter for Google Drive

[![Author](https://img.shields.io/badge/author-as247-orange)](http://as247.vui360.com/)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

## Installation

```bash
composer require as247/flysystem-google-drive:^3.0
```

## Usage
#### Follow [Google Docs](https://developers.google.com/drive/v3/web/enable-sdk) to obtain your `ClientId, ClientSecret & refreshToken`

#### In addition, you can also check these easy-to-follow tutorial by [@ivanvermeyen](https://github.com/ivanvermeyen/laravel-google-drive-demo)

- [Getting your Client ID and Secret](https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/1-getting-your-dlient-id-and-secret.md)
- [Getting your Refresh Token](https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/2-getting-your-refresh-token.md)

```php
$client = new \Google_Client();
$client->setClientId('[app client id].apps.googleusercontent.com');
$client->setClientSecret('[app client secret]');
$client->fetchAccessTokenWithRefreshToken('[your refresh token]');

$service = new \Google_Service_Drive($client);

$options=[
    'root'=>'[Root folder id]',
    'teamDrive'=>'[Team drive id]'//If your root folder inside team drive
    'prefix'=>'[Path prefix]',//Path prefix inside root folder
];

$adapter = new \As247\Flysystem\GoogleDrive\GoogleDriveAdapter($service, $options);

$filesystem = new \League\Flysystem\Filesystem($adapter);

```
