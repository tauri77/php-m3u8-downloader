<?php


namespace Tauri\M3u8Downloader;


use Chrisyue\PhpM3u8\Data\Value\Byterange;

class XMap extends Segment
{

    /**
     * Set range attribute
     *
     * @param $offset
     * @param $length
     */
    public function setRange($offset, $length)
    {
        $this->listObj['EXT-X-BYTERANGE'] = new Byterange($length, $offset);
    }

    /**
     * Update offset and length property from byte range attribute
     */
    protected function parseRange()
    {
        if ( ! empty($this->listObj["BYTERANGE"])) {
            $range = ! empty($this->listObj["BYTERANGE"]) ? $this->listObj["BYTERANGE"] : false;
            $this->offset = method_exists($range, 'getOffset') ? $range->getOffset() : null;
            $this->length = method_exists($range, 'getLength') ? $range->getLength() : null;
        }
    }

    /**
     * Get the key for uri
     *
     * @return string
     */
    protected function getPropUri()
    {
        return 'URI';
    }

}