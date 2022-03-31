<?php

namespace Tauri\M3u8Downloader;

use Chrisyue\PhpM3u8\Data\Value\Byterange;

/**
 * Class Segment
 *
 * Represent a part to download (segment or xmap)
 *
 * @package M3u8SimpleDownloader
 */
class Segment
{
    use FileReverse;

    /**
     * @var string
     */
    public $url;

    /**
     * @var null|int
     */
    public $offset = null;

    /**
     * @var null|int
     */
    public $length = null;

    /**
     * @var array
     */
    public $decrypt = [];

    /**
     * @var float
     */
    public $sequence = 0;

    /**
     * @var string
     */
    public $status = 'pending';

    /**
     * @var string
     */
    public $dest_file;

    /**
     * @var \ArrayObject
     */
    public $listObj;

    /**
     * @var string
     */
    public $levelFile;

    /**
     * Segment constructor.
     *
     * @param \ArrayObject $segment php-m3u8 segment object
     * @param string $parentUri url for the m3u8
     * @param string $levelFile file for the m3u8
     * @param float $sequence sequence number
     */
    public function __construct($segment, $parentUri, $levelFile, $sequence)
    {
        $this->listObj = $segment;
        $this->levelFile = $levelFile;

        $propUri = $this->getPropUri();

        $segment[$propUri] = HelperPath::getNotRelative($segment[$propUri], $parentUri);
        $this->url = $segment[$propUri];

        $levelPathInfo = pathinfo($levelFile);

        $this->dest_file = $this->getFilePathSave(
            $this->url,
            $parentUri,
            $levelPathInfo['dirname'],
            [
                $levelPathInfo['basename'],
                basename(parse_url($parentUri, PHP_URL_PATH))
            ]
        );

        $this->setFileExist($this->dest_file);
        $this->setUri(HelperPath::relativeUrl(realpath($levelFile), realpath($this->dest_file)));

        $this->parseRange();

        $this->sequence = $sequence;
    }

    /**
     * Get the key for uri
     */
    protected function getPropUri()
    {
        return 'uri';
    }

    /**
     * Get duration
     *
     * @return float
     */
    public function getDuration()
    {
        if ( isset($this->listObj["EXTINF"]) && method_exists($this->listObj["EXTINF"], 'getDuration' )){
            return $this->listObj["EXTINF"]->getDuration();
        }
        return 0;
    }

    /**
     * Set url for use on m3u8
     *
     * @param $relativeUrl
     */
    public function setUri($relativeUrl)
    {
        $this->listObj[$this->getPropUri()] = $relativeUrl;
    }

    /**
     * Update offset and length property from byte range attribute
     */
    protected function parseRange()
    {
        if ( ! empty($this->listObj["EXT-X-BYTERANGE"])) {
            $range = $this->listObj["EXT-X-BYTERANGE"];
            $this->offset = method_exists($range, 'getOffset') ? $range->getOffset() : null;
            $this->length = method_exists($range, 'getLength') ? $range->getLength() : null;
        }
    }

    /**
     * Set decrypt props
     *
     * @param array $decrypt array with "key" an "iv" keys. Or empty array.
     */
    public function setDecrypt($decrypt)
    {
        $this->decrypt = $decrypt;
    }

    /**
     * Set segment as ready
     */
    public function setReady()
    {
        $this->status = 'ready';
    }

    /**
     * Set segment as ready
     */
    public function setProcessing()
    {
        $this->status = 'processing';
    }

    /**
     * Set segment as ready
     */
    public function setPending()
    {
        $this->status = 'pending';
    }

    /**
     * @return bool
     */
    public function isPending()
    {
        return 'pending' === $this->status;
    }

    /**
     * @return bool
     */
    public function isReady()
    {
        return 'ready' === $this->status;
    }

    /**
     * Check if is a local file
     *
     * @return bool
     */
    public function isExistLocalFile()
    {
        return file_exists($this->url) && is_file($this->url);
    }

    /**
     * Data from url
     *
     * @return false|string
     * @throws \Exception
     */
    public function localSourceData()
    {
        return $this->_getData($this->url);
    }

    /**
     * Data from the dest_file
     *
     * @param $file
     *
     * @return false|string
     * @throws \Exception
     */
    private function _getData($file)
    {
        $f = fopen($file, 'r');
        if (false === $f) {
            throw new \Exception("Unable to open segment file (read)");
        }
        $read_bytes = filesize($file);
        if ($this->offset !== null) {
            fseek($f, $this->offset);
            $read_bytes -= $this->offset;
        }
        if ($this->length !== null) {
            $read_bytes = $this->length;
        }
        $data = fread($f, $read_bytes);
        fclose($f);

        return $data;
    }

    /**
     * Decrypt data from the dest_file
     *
     * @return false|string
     * @throws \Exception
     */
    public function decryptFromFile()
    {
        return $this->decryptData($this->getData());
    }

    /**
     * Decrypt only if decrypt is not empty
     *
     * @param $data
     *
     * @return false|string
     */
    public function decryptData($data)
    {
        if (empty($this->decrypt)) {
            return $data;
        }
        if ($this->decrypt['iv'] === null) {
            $this->decrypt['iv'] = hex2bin(
                str_pad(
                    dechex($this->sequence) . '',
                    32,
                    '0',
                    STR_PAD_LEFT
                )
            );
        }

        return self::decrypt($data, $this->decrypt['key'], $this->decrypt['iv']);
    }

    /**
     * Decrypt data
     *
     * All arguments are raw
     *
     * @param string $data
     * @param string $key
     * @param string $iv
     *
     * @return false|string
     */
    private static function decrypt($data, $key, $iv)
    {
        return openssl_decrypt($data, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Data from the dest_file
     *
     * @return false|string
     * @throws \Exception
     */
    public function getData()
    {
        return $this->_getData($this->dest_file);
    }

    /**
     * Save data to the dest file
     *
     * @param $data
     *
     * @return bool
     * @throws \Exception
     */
    public function saveDataToFile($data)
    {
        $fp = fopen($this->dest_file, "r+");
        if (false === $fp) {
            throw new \Exception("Unable to open segment file (write)");
        }
        if ($this->offset !== null) {
            fseek($fp, $this->offset, SEEK_SET);
            $ret = fwrite($fp, $data, $this->length);
        } else {
            $ret = fwrite($fp, $data);
        }
        if (false === $ret) {
            throw new \Exception("Unable to write segment file");
        }
        fclose($fp);

        return true;
    }

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
     * Get where $url will save based on ref_url and root directory
     *
     * The root_folder is the equivalent path for ref_url.
     *
     * @param string $url the url/path downloading/moving
     * @param string $ref_url the url equivalent to root folder
     * @param string $root_folder the root folder
     * @param array $folder_nop list not allowed
     *
     * @return string
     */
    public function getFilePathSave($url, $ref_url, $root_folder, $folder_nop = [])
    {
        $url = HelperPath::getNotRelative($url, $ref_url);
        $file = basename(parse_url($url, PHP_URL_PATH));

        $ret_folder = $root_folder;

        $depth = 0;
        $this->setFolderExist($ret_folder);

        $folder = HelperPath::childDirectory($ref_url, $url, $depth);
        while ( ! empty($folder) && $folder !== $file && ! in_array($folder, $folder_nop)) {
            $ret_folder = $ret_folder . DIRECTORY_SEPARATOR . $folder;
            $this->setFolderExist($ret_folder);
            $depth++;
            $folder = HelperPath::childDirectory($ref_url, $url, $depth);
        }
        $file = $ret_folder . DIRECTORY_SEPARATOR . $file;

        return $file;
    }

}