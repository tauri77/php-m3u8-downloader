<?php

namespace Tauri\M3u8Downloader;

use Chrisyue\PhpM3u8\Facade\DumperFacade;
use Chrisyue\PhpM3u8\Stream\TextStream;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Get the content of url
 *
 * @param string $url
 * @param Client $httpClient
 * @param int $max_retry
 *
 * @return bool|string
 */
function httpGet($url, $httpClient, $max_retry = 5)
{
    if (startsWith($url, 'data:text/plain;base64,')) {
        return base64_decode(substr($url, strlen('data:text/plain;base64,')));
    }
    $try = 0;
    while ($try < $max_retry) {
        try {
            $response = $httpClient->request('GET', $url);

            return (string)$response->getBody();
        } catch (GuzzleException $e) {
            (new Debugger())->warn($e->getMessage());
        }
        $try++;
    }
    return false;
}

/**
 * Save object to m3u8
 *
 * @param $parsed
 * @param $toPath
 * @param $mode
 */
function dumpToFile($parsed, $toPath, $mode = 0644)
{
    $content = new TextStream();
    $dumper = new DumperFacade();
    $dumper->dump($parsed, $content);
    file_put_contents($toPath, $content);
    chmod($toPath, $mode);
}

function startsWith($haystack, $needle)
{
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}