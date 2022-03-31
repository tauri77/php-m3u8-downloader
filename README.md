# PHP m3u8 Downloader

PHP class for download/record m3u8 files. It can also decrypt and join the fragments.

## Usage

Basic usage:
```
$options = [
   'saveTo'       => './download_files/folder',
   'decrypt'      => true,
   'joinSegments' => false,
];

$downloader = new Tauri\M3u8Downloader\Downloader(
    'http://site.com/list.m3u8',
    $options
);

$downloader->onProgress( function ( $p ) {
    echo '[' . date('i:s') . '] Approx Progress: ' . round( 100 * $p, 2) . '%<br />';
} );

$downloader->download();
//Or live rec:
//$downloader->downloadLive($minutesToRec);

```

You can check the [example file](https://github.com/tauri77/php-m3u8-downloader/blob/main/example/index.php) for a more complete usage.

## Full options
```
$options = [
   'saveTo'          => './download_files/folder',
   'filename'        => 'master.m3u8',
   'decrypt'         => true,
   'joinSegments'    => false,
   'logger'          => $psr3Logger,
   'userAgent'       => 'Mozilla...',
   'cookies'         => [ 'name' => 'value' , 'name2' => 'value2' ],
   'timeout'         => 0,
   'connect_timeout' => 8
];
```

## Dependencies

This project uses: 
- [chrisyue/php-m3u8](https://github.com/chrisyue/php-m3u8)
- [guzzlehttp/guzzle](https://github.com/guzzle/guzzle)
