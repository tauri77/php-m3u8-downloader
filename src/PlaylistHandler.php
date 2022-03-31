<?php

namespace Tauri\M3u8Downloader;

use Chrisyue\PhpM3u8\Stream\TextStream;
use Exception;
use GuzzleHttp\Client;

class PlaylistHandler
{
    use FileReverse;

    /**
     * @var Debugger
     */
    private $debugger;

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @var array
     */
    private $cachedKeys = [];

    /**
     * @var bool
     */
    private $decrypt;

    /**
     * @var array
     */
    private $decryptProps = [];

    /**
     * @var SegmentsResolver
     */
    private $segmentsResolver;

    /**
     * @var string
     */
    private $url;

    /**
     * @var string
     */
    private $folder;
    /**
     * @var string
     */
    private $filename;

    /**
     * @var \ArrayObject
     */
    private $m3u8;

    /**
     * @var string
     */
    private $save;

    /**
     * @var null|bool
     */
    private $folderCreated = null;

    /**
     * PlaylistHandler constructor.
     *
     * @param Client $httpClient
     * @param string $playlistUri
     * @param string $parentUri
     * @param string $saveAs
     * @param array $options
     */
    public function __construct($httpClient, $playlistUri, $parentUri, $saveAs, $options = [])
    {
        $this->httpClient = $httpClient;
        $this->url = HelperPath::getNotRelative($playlistUri, $parentUri);
        $this->decrypt = isset($options['decrypt']) ? $options['decrypt'] : false;
        $this->debugger = isset($options['debugger']) ? $options['debugger'] : (new Debugger());

        $this->segmentsResolver = new SegmentsResolver($httpClient, $this->debugger);

        if (is_dir($saveAs)) {
            $saveAs = $saveAs . DIRECTORY_SEPARATOR . basename(parse_url($playlistUri, PHP_URL_PATH));
        }

        $this->save = $saveAs;

        $levelPathInfo = pathinfo($saveAs);
        $this->folder = $levelPathInfo['dirname'];
        $this->filename = $levelPathInfo['basename'];

        $this->loadM3u8();
        $this->loadM3u8();
    }

    /**
     * Get the content of m3u8 file
     */
    private function loadM3u8()
    {
        if (preg_match('@https?://@i', $this->url)) { //remote list?
            $m3u8Content = httpGet($this->url, $this->httpClient);
        } else {
            $m3u8Content = file_get_contents($this->url); //local file
        }
        $this->debugger->debug($this->url);
        $this->debugger->debug($m3u8Content);

        $parser = new M3u8Parser();
        $this->m3u8 = $parser->parse(new TextStream($m3u8Content));
    }

    /**
     * @return \ArrayObject
     */
    public function getParsed()
    {
        return $this->m3u8;
    }

    /**
     * @return string
     */
    public function getSavePath()
    {
        return $this->save;
    }

    /**
     * @param $parentFile
     *
     * @return string
     */
    public function getRelativeUri($parentFile)
    {
        $this->debugger->notice("{$parentFile} <-> {$this->save}");
        return HelperPath::relativeUrl(
            realpath($parentFile),
            realpath($this->save)
        );
    }

    /**
     * Make new request to the m3u8 and reload (for live stream)
     */
    public function reloadM3u8()
    {
        $this->loadM3u8();
    }

    /**
     * Download or move a playlist
     *
     * @return bool|string    false on error, otherwise the playlist path.
     * @throws Exception
     */
    public function processSegments( )
    {
        $this->checkFolder();

        $this->setFileExist($this->save);

        $sequenceNro = isset($this->m3u8['EXT-X-MEDIA-SEQUENCE']) ? (int)$this->m3u8['EXT-X-MEDIA-SEQUENCE'] : 0;
        if ( ! empty($this->m3u8['mediaSegments'])) {
            foreach ($this->m3u8['mediaSegments'] as $s => $segment) {
                $segmentHandle = new Segment($segment, $this->url, $this->save, $sequenceNro);

                if ( ! empty($segment["EXT-X-KEY"])) {
                    $keyFile = $segmentHandle->dest_file . '.key';
                    $xKey = $segment["EXT-X-KEY"][0];
                    $xKey["URI"] = HelperPath::getNotRelative($xKey["URI"], $this->url);
                    if ( ! isset($this->cachedKeys[$xKey["URI"]])) {
                        $keyContent = httpGet($xKey["URI"], $this->httpClient);
                        if (false === $keyContent) {
                            return false;
                        }
                        if ($this->decrypt) {
                            $this->cachedKeys[$xKey["URI"]] = $keyContent;
                        } else {
                            file_put_contents($keyFile, $keyContent);
                            $this->cachedKeys[$xKey["URI"]] = HelperPath::relativeUrl(
                                realpath($this->save),
                                realpath($keyFile)
                            );
                        }
                    }

                    if ($this->decrypt) {
                        $this->decryptProps['key'] = $this->cachedKeys[$xKey["URI"]];
                        $this->decryptProps['iv'] = isset($xKey["IV"]) ? hex2bin(str_replace('0x', '',
                            $xKey["IV"])) : null;
                        //if decrypting remove key tag
                        unset($segment["EXT-X-KEY"]);
                    } else {
                        $xKey["URI"] = $this->cachedKeys[$xKey["URI"]];
                    }
                }

                if ( ! empty($segment["EXT-X-MAP"])) {
                    $xMapHandle = new XMap($segment["EXT-X-MAP"], $this->url, $this->save, $sequenceNro - 0.5);
                    $xMapHandle->setDecrypt($this->decryptProps);
                    $this->segmentsResolver->addSegment($xMapHandle);
                }

                $segmentHandle->setDecrypt($this->decryptProps);
                $this->segmentsResolver->addSegment($segmentHandle);

                $sequenceNro++;
            }
        }

        return true;
    }

    /**
     * Enqueue the request, or re-enqueue for retry (or resolve the move)
     *
     * @throws Exception
     */
    public function reQueue() {
        $this->segmentsResolver->reQueue();
    }

    /**
     * Check if exist the folder or create this
     *
     * @throws Exception
     */
    public function checkFolder()
    {
        if ($this->folderCreated === null) {
            $this->folderCreated = true;
            $this->setFolderExist($this->folder);
            if ( ! is_writeable($this->folder)) {
                throw new Exception('Unable to write folder.');
            }
        }
    }

    /**
     * Call this on error
     */
    public function removeAll()
    {
        $this->segmentsResolver->removeAll();
        $this->storageReverse();
    }

    /**
     * Call "wait" without retry
     */
    public function resolveAllQueued()
    {
        $this->segmentsResolver->resolveQueued();
    }

    /**
     * Wait end transfers and save the list
     *
     * @param bool $join
     * @param bool $endList Add EXT-X-ENDLIST
     *
     * @return bool
     * @throws Exception
     */
    public function close($join = false, $endList = true)
    {
        $this->checkFolder();

        $ret = $this->segmentsResolver->endResolve();

        if ($ret && $join) {
            $ret = $this->segmentsResolver->joinSegments();
        }

        if (isset($this->m3u8['mediaSegments'])) {
            foreach ($this->m3u8['mediaSegments'] as $s => $segment) {
                unset($this->m3u8['mediaSegments'][$s]);
            }

            $this->m3u8['mediaSegments'] = $this->segmentsResolver->getSegmentsObject();
            //add end for not live

            if ( $endList && !isset( $this->m3u8['EXT-X-ENDLIST'] ) ) {
                $this->m3u8['EXT-X-ENDLIST'] = true;
            }

        }

        //try dump anyway on soft error (no check $ret)
        dumpToFile($this->m3u8, $this->save, Downloader::$modeFile);

        return $ret;
    }

    /**
     * Set progress callback
     *
     * @param $fn
     */
    public function onProgress($fn)
    {
        $this->segmentsResolver->onProgress($fn);
    }

    /**
     * Get the download/copy progress
     *
     * @return float|int
     */
    public function progress()
    {
        return $this->segmentsResolver->progress();
    }

    /**
     * Get sum duration
     *
     * @param $checkSegment
     *
     * @return bool
     */
    public function getDuration()
    {
        return $this->segmentsResolver->getDuration();
    }

}