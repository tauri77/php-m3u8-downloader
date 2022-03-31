<?php


namespace Tauri\M3u8Downloader;

use Chrisyue\PhpM3u8\Data\Transformer\AttributeStringToArray;

/**
 * Class CustomAttributeStringToArray
 *
 * Support whitespace after comma
 *
 * @package Tauri\M3u8Downloader
 */
class CustomAttributeStringToArray extends AttributeStringToArray
{
    public function __invoke($string)
    {
        if ( ! \is_string($string)) {
            throw new \InvalidArgumentException(sprintf('$string can only be string, got %s', \gettype($string)));
        }

        preg_match_all('/(?<=^|,|, )[A-Z0-9-]+=("?).+?\1(?=,|$)/', $string, $matches);

        $attrs = [];
        foreach ($matches[0] as $attr) {
            [$key, $value] = explode('=', $attr, 2);
            $attrs[$key] = $value;
        }

        return $attrs;
    }
}
