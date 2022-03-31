<?php

namespace Tauri\M3u8Downloader;

use Chrisyue\PhpM3u8\Config;
use Chrisyue\PhpM3u8\Definition\TagDefinitions;
use Chrisyue\PhpM3u8\Line\Lines;
use Chrisyue\PhpM3u8\Parser\DataBuilder;
use Chrisyue\PhpM3u8\Parser\Parser;
use Chrisyue\PhpM3u8\Stream\StreamInterface;

class M3u8Parser
{
    /**
     * @var Parser
     */
    private $parser;

    /**
     * @param StreamInterface $stream
     *
     * @return \ArrayObject
     */
    public function parse(StreamInterface $stream)
    {
        if (null === $this->parser) {
            $rootPath = realpath(__DIR__);
            $tagDefinitions = new TagDefinitions(require $rootPath . '/php-m3u8-resources/tags.php');

            $this->parser = new Parser(
                $tagDefinitions,
                new Config(require $rootPath . '/php-m3u8-resources/tagValueParsers.php'),
                new DataBuilder()
            );
        }

        return $this->parser->parse(new Lines($stream));
    }
}
