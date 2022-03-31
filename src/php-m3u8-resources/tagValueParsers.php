<?php

declare(strict_types=1);

use Chrisyue\PhpM3u8\Config;
use Chrisyue\PhpM3u8\Data\Transformer\Iso8601Transformer;
use Chrisyue\PhpM3u8\Data\Value\Byterange;
use Chrisyue\PhpM3u8\Data\Value\Tag\Inf;
use Chrisyue\PhpM3u8\Parser\AttributeListParser;

$attributeListParser = new AttributeListParser(
    new Config(require __DIR__ . '/attributeValueParsers.php'),
    new Tauri\M3u8Downloader\CustomAttributeStringToArray()
);

return [
    'int' => 'intval',
    'bool' => null,
    'enum' => null,
    'attribute-list' => [$attributeListParser, 'parse'],
    // special types
    'inf' => [Inf::class, 'fromString'],
    'byterange' => [Byterange::class, 'fromString'],
    'datetime' => [Iso8601Transformer::class, 'fromString'],
];
