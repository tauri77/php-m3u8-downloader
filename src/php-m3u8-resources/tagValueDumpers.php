<?php

declare(strict_types=1);

use Chrisyue\PhpM3u8\Config;
use Chrisyue\PhpM3u8\Data\Transformer\Iso8601Transformer;
use Chrisyue\PhpM3u8\Dumper\AttributeListDumper;

$attributeListDumper = new AttributeListDumper(
    new Config(require __DIR__ . '/attributeValueDumpers.php')
);

return [
    'int' => 'strval',
    'bool' => null,
    'enum' => null,
    'attribute-list' => [$attributeListDumper, 'dump'],
    // special types
    'inf' => 'strval', // Chrisyue\PhpM3u8\Value\Inf is __toString able
    'byterange' => 'strval', // Chrisyue\PhpM3u8\Value\Byterange is __toString able
    'datetime' => [Iso8601Transformer::class, 'toString'],
];
