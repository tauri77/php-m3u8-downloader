<?php


namespace Tauri\M3u8Downloader;


class SubListFilter
{

    /**
     * @var array
     */
    private $items = [];

    /**
     * @var string all|better|list
     */
    private $mode = 'all';

    /**
     * The media to download, ex: levels, audios, etc
     *
     * @param $masterPlaylist
     *
     * @return array
     */
    public function itemsAvailable($masterPlaylist)
    {
        $this->items = [];
        $extra_tags = ['EXT-X-STREAM-INF', 'EXT-X-MEDIA', 'EXT-X-I-FRAME-STREAM-INF'];
        foreach ($extra_tags as $tag_name) {
            if ( ! empty($masterPlaylist[$tag_name])) {
                foreach ($masterPlaylist[$tag_name] as $l => $tag) {
                    $key = $this->getItemKey($tag_name, $tag);
                    $this->items[$key] = $tag;
                }
            }
        }

        return $this->items;
    }

    /**
     * Get keys for list items (can use to select levels)
     *
     * @param $type
     * @param $level
     *
     * @return string
     */
    private function getItemKey($type, $level)
    {
        $key = $type;
        if ($type === 'EXT-X-STREAM-INF') {
            if (isset($level['BANDWIDTH'])) {
                $key .= '-bandwidth:' . $level['BANDWIDTH'];
            }
            if (isset($level['RESOLUTION']) && method_exists($level['RESOLUTION'], 'getHeight')) {
                $key .= '-height:' . $level['RESOLUTION']->getHeight();
            }
        }
        if (in_array($type, ['EXT-X-MEDIA', 'EXT-X-I-FRAME-STREAM-INF'])) {
            if (isset($level['TYPE'])) {
                $key .= '-TYPE:' . $level['TYPE'];
            }
            if (isset($level['NAME'])) {
                $key .= '-NAME:' . $level['NAME'];
            }
            if (isset($level['CHANNELS'])) {
                $key .= '-CHANNELS:' . $level['CHANNELS'];
            }
            if (isset($level['URI'])) {
                $key .= '-URI:' . $level['URI'];
            }
            if (isset($level['RESOLUTION']) && method_exists($level['RESOLUTION'], 'getHeight')) {
                $key .= '-height:' . $level['RESOLUTION']->getHeight();
            }
        }

        return $key;
    }

    /**
     * Set items keys
     *
     * @param $items
     */
    public function setSubList($items)
    {
        $this->mode = 'list';
        $this->items = $items;
    }

    /**
     * Set sublist select mode
     *
     * @param string $mode
     */
    public function setSubListMode($mode)
    {
        $this->mode = $mode;
    }

    /**
     * Remove sublist that not will be remove
     *
     * @param \ArrayObject $masterPlaylist
     *
     * @return int the list count to be downloaded
     */
    public function filterSelected($masterPlaylist)
    {
        $items = [];
        //remove not used streams
        if ($this->mode === 'better') {
            //Only high bandwidth
            $max_bandwidth = 0;
            $max_bandwidth_key = '';
            if ( ! empty($masterPlaylist['EXT-X-STREAM-INF'])) {
                foreach ($masterPlaylist['EXT-X-STREAM-INF'] as $x => $media) {
                    if ($media['BANDWIDTH'] > $max_bandwidth) {
                        $max_bandwidth = $media['BANDWIDTH'];
                        $max_bandwidth_key = $this->getItemKey('EXT-X-STREAM-INF', $media);
                    }
                }
            }
            if ( ! empty($max_bandwidth_key)) {
                $items = [$max_bandwidth_key];
            }
        }
        if ($this->mode === 'list') {
            $items = $this->items;
        }
        $count = 0;
        $tags = ['EXT-X-STREAM-INF', "EXT-X-MEDIA", "EXT-X-I-FRAME-STREAM-INF"];
        foreach ($tags as $tag) {
            if ( ! empty($masterPlaylist[$tag])) {
                foreach ($masterPlaylist[$tag] as $x => $media) {
                    if ($this->mode !== 'all' && ! in_array($this->getItemKey($tag, $media), $items)) {
                        unset($masterPlaylist[$tag][$x]);
                        continue;
                    } else {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

}