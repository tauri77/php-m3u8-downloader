<?php

namespace Tauri\M3u8Downloader;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;


class Downloader
{
    use FileReverse;

    /**
     * @var int
     */
    public static $modeFile = 0644;

    /**
     * @var int
     */
    public static $modeFolder = 0777;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var Debugger
     */
    private $debugger;

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @var string Url of the main m3u8
     */
    private $url;

    /**
     * @var string Folder where save
     */
    private $saveTo;

    /**
     * @var bool Decrypt segments?
     */
    private $decrypt;

    /**
     * @var bool Join segments?
     */
    private $join;

    /**
     * @var \ArrayObject
     */
    private $m3u8;

    /**
     * @var callable
     */
    private $progressFn = null;

    /**
     * @var PlaylistHandler[]
     */
    private $playlistHandlers;

    /**
     * @var SubListFilter
     */
    private $subListFilter;

    /**
     * @var float|null time when init download
     */
    private $initialTime = null;

    /**
     * @var float|null
     */
    private $endRecTime = null;

    /**
     * Downloader constructor.
     *
     * Options array with settings:
     *   saveTo: Path where saving.
     *   cookies: Requests cookies.
     *   userAgent: Requests UA.
     *
     * @param string $url The m3u8 url
     * @param array $options Options
     */
    public function __construct($url, $options = [])
    {
        $this->url = $url;

        $this->debugger = new Debugger(
            [
                'logger' => ! empty($options['logger']) ? $options['logger'] : false
            ]
        );

        $this->filename = ! empty($options['filename']) ? $options['filename'] : 'master.m3u8';
        $this->saveTo = ! empty($options['saveTo']) ? $options['saveTo'] : './';
        $this->decrypt = ! empty($options['decrypt']) ? $options['decrypt'] : false;
        $this->join = ! empty($options['joinSegments']) ? $options['joinSegments'] : false;

        //HTTP Client setup
        $args = [
            'base_uri' => $this->url,
            'debug' => false,
            'timeout' => isset($options['timeout']) ? $options['timeout'] : 0,
            'verify' => false,
            'connect_timeout' => isset($options['connect_timeout']) ? $options['connect_timeout'] : 8,
            'version' => '1.1',
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:78.0) Gecko/20100101 Firefox/78.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
                'Accept-Language' => 'en-US;q=0.5,en;q=0.3',
                'Upgrade-Insecure-Requests' => '1',
                'TE' => 'Trailers',
                'pragma' => 'no-cache',
                'cache-control' => 'no-cache',
            ]
        ];

        if ( ! empty($options['userAgent'])) {
            $args['headers']['User-Agent'] = $options['userAgent'];
        }

        $jar = new CookieJar();//SessionCookieJar or CookieJar();
        if ( ! empty($options['cookies'])) {
            foreach ($options['cookies'] as $name => $cookie) {
                if (is_object($cookie)) {
                    $jar->setCookie($cookie);
                } elseif (is_array($cookie)) {
                    if ( ! isset($cookie['Domain'])) {
                        $cookie['Domain'] = parse_url($this->url, PHP_URL_HOST);
                    }
                    $jar->setCookie(new SetCookie($cookie));
                } else {
                    $cook = [
                        'Name' => $name,
                        'Value' => $cookie,
                        'Domain' => parse_url($this->url, PHP_URL_HOST)
                    ];
                    $jar->setCookie(new SetCookie($cook));
                }
            }
        }
        $args['cookies'] = $jar;

        $this->httpClient = new Client($args);

        // filter levels
        $this->subListFilter = new SubListFilter();

        $this->m3u8 = $this->getPlaylistHandler($this->url)->getParsed();
    }

    /**
     * Create the handler for a playlist uri, or return if exists
     *
     * @param $playlistUri
     * @param bool $reload
     *
     * @return PlaylistHandler
     */
    public function getPlaylistHandler($playlistUri, $reload = false)
    {
        if (isset($this->playlistHandlers[$playlistUri])) {
            if ($reload) {
                $this->playlistHandlers[$playlistUri]->reloadM3u8();
            }
            return $this->playlistHandlers[$playlistUri];
        }
        if ($playlistUri === $this->url) {
            $this->setFolderExist($this->saveTo);
            $saveAs = $this->saveFilePath();
            $parentUri = false;
        } else {
            $saveAs = $this->getPlaylistDirectory($playlistUri);
            $parentUri = $this->url;
        }
        // Segments downloader
        $handler = new PlaylistHandler(
            $this->httpClient,
            $playlistUri,
            $parentUri,
            $saveAs,
            [
                'decrypt' => $this->decrypt,
                'debugger' => $this->debugger
            ]
        );
        $this->playlistHandlers[$playlistUri] = $handler;
        $handler->onProgress([$this, 'segmentsDownloadProgress']);
        return $handler;
    }

    /**
     * Get the path to save main m3u8
     *
     * @return string
     */
    public function saveFilePath()
    {
        return $this->saveTo . '/' . $this->filename;
    }

    /**
     * Get the directory for level file from main url
     *
     * @param string $uri
     *
     * @return string
     */
    private function getPlaylistDirectory($uri)
    {
        $levelFilename = basename(parse_url($uri, PHP_URL_PATH));
        $mainFilename = basename(parse_url($this->url, PHP_URL_PATH));
        $levelFolder = $this->saveTo;
        $this->setFolderExist($levelFolder);

        $depth = 0;
        $folder = HelperPath::childDirectory($uri, $this->url, $depth);
        while ( ! empty($folder) && ! in_array($folder, [$mainFilename, $levelFilename])) {
            $levelFolder = $levelFolder . DIRECTORY_SEPARATOR . substr($folder, 0, 222);
            $this->setFolderExist($levelFolder);
            $depth++;
            $folder = HelperPath::childDirectory($uri, $this->url, $depth);
        }

        return $levelFolder;
    }


    /**
     * Download/Move a m3u8 file
     *
     * @throws Exception
     */
    public function download()
    {
        $this->debugger->debug("Start download: " . $this->url );
        $this->execute_pass('load', false);
        $this->execute_pass('enqueue', false);
        $this->execute_pass('resolve', false);
        $this->execute_pass('close', false);
        $this->debugger->debug("End download");
    }

    /**
     * Download/Move a m3u8 file
     *
     * @param string $action load|enqueue|resolve|close
     * @param bool $reload Reload m3u8 (for live)
     *
     * @throws Exception
     */
    private function execute_pass($action = 'load', $reload = false)
    {
        $saveAs = $this->getPlaylistHandler($this->url)->getSavePath();

        $this->setFileExist($saveAs);

        if ( ! empty($this->m3u8['EXT-X-STREAM-INF'])) {
            //remove not selected level, audios, etc..
            if ( $action === 'load' ) {
                $this->subListFilter->filterSelected($this->m3u8);
            }

            //Download
            $listingTags = ['EXT-X-STREAM-INF', "EXT-X-MEDIA", "EXT-X-I-FRAME-STREAM-INF"];
            foreach ($listingTags as $tag) {
                if ( ! empty($this->m3u8[$tag])) {
                    foreach ($this->m3u8[$tag] as $x => $media) {
                        $prop_uri = ($tag === 'EXT-X-STREAM-INF') ? 'uri' : 'URI';
                        if ( ! isset($media[$prop_uri])) {
                            $this->debugger->debug("Not $prop_uri for $tag. Skip..");
                            $this->debugger->debug($media);
                            continue;
                        }
                        $uri = HelperPath::getNotRelative($media[$prop_uri], $this->url);

                        try {
                            $this->getPlaylistHandler($uri, $reload)->processSegments();
                            // wait to all request ready
                            if ($action === 'close') {
                                $segmentsReady = $this->getPlaylistHandler($uri)->close($this->join);
                                if ( ! $segmentsReady) {
                                    //the resource result on error, remove this
                                    $this->debugger->warn("No resolve $tag list: $uri");
                                    if ( ! $segmentsReady) {
                                        //remove all if any segment or join fail?
                                        throw new Exception("Some error on $tag list ($uri). Segment error?");
                                    }
                                } else {
                                    $this->debugger->notice(
                                        "Relative for $uri: '" .
                                        $this->getPlaylistHandler($uri)->getRelativeUri($saveAs) . "'"
                                    );
                                    $media[$prop_uri] = $this->getPlaylistHandler($uri)->getRelativeUri($saveAs);
                                }
                            } elseif ($action === 'enqueue') {
                                $this->getPlaylistHandler($uri)->reQueue();
                            } elseif ($action === 'resolve') {
                                $this->getPlaylistHandler($uri)->resolveAllQueued();
                            }
                        } catch (Exception $e) {
                            $this->debugger->warn($e->getMessage());
                            $this->debugger->warn("No resolve $tag list: $uri");
                            $this->getPlaylistHandler($uri)->removeAll();
                            unset($this->m3u8[$tag][$x]);
                            if ($tag === 'EXT-X-STREAM-INF' && empty($this->m3u8[$tag])) {
                                $this->storageReverse();
                                throw $e;
                            }
                        }
                    }
                }
            }
        }

        try {
            if ( ! empty($this->m3u8['mediaSegments'])) {
                $this->getPlaylistHandler($this->url, $reload)->processSegments();
            }

            if ($action === 'close') {
                // wait to all request ready
                $segmentsReady = $this->getPlaylistHandler($this->url)->close($this->join);
                if ( ! $segmentsReady) {
                    throw new Exception(
                        "Some error on the process playlist. Maybe some segment error."
                    );
                }
            } elseif ($action === 'enqueue') {
                $this->getPlaylistHandler($this->url)->reQueue();
            } elseif ($action === 'resolve') {
                $this->getPlaylistHandler($this->url)->resolveAllQueued();
            }
        } catch (Exception $e) {
            $this->debugger->error($e->getMessage());
            $this->getPlaylistHandler($this->url)->removeAll();
            $this->storageReverse();
            throw $e;
        }

        $this->sendProgress();
    }

    /**
     * Send process to flusher
     */
    private function sendProgress()
    {
        if (is_callable($this->progressFn)) {
            $p = 0;
            foreach ($this->playlistHandlers as $resolver) {
                $p += $resolver->progress();
            }
            $p = $p / count($this->playlistHandlers);

            if (
                $this->endRecTime !== null &&
                $this->endRecTime > time() &&
                $this->endRecTime !== $this->initialTime
            ) {
                $timeProgress = ( time() - $this->initialTime ) / ( $this->endRecTime - $this->initialTime );
                $p = $p * $timeProgress;
            }

            $p = min(1, $p);

            call_user_func($this->progressFn, $p);
        }
    }

    /**
     * Get max lists duration
     *
     * @return float
     */
    public function getDuration()
    {
        return array_reduce(
            $this->playlistHandlers,
            function ($max, $handler) {
                return max($max, $handler->getDuration());
            },
            0
        );
    }

    /**
     * Download for a amount of minutes refreshing m3u8
     *
     * @param int $minutes
     *
     * @throws Exception
     */
    public function downloadLive($minutes = 5)
    {
        $this->debugger->debug("Start live download: " . $this->url );

        $interval = 10;

        if ( ! empty($this->m3u8['mediaSegments'])) {
            $interval = $this->m3u8['mediaSegments'][0]['EXTINF']->getDuration() ?
                $this->m3u8['mediaSegments'][0]['EXTINF']->getDuration() : 10;
        }

        $this->initialTime = time();
        $this->endRecTime  = $this->initialTime + 60 * $minutes;

        $this->execute_pass('load', false); //Only fist time
        $this->execute_pass('enqueue', false); //Enqueue segments
        $this->execute_pass('resolve', false); //Wait and write the segments

        $nextTime = time() + $interval;
        while (time() < $this->endRecTime) {
            $this->sendProgress();
            sleep(1);
            if ($nextTime < time()) {
                $nextTime = time() + $interval;
                $this->execute_pass('enqueue', true);
                $this->execute_pass('resolve', false);
            } else {
                $this->execute_pass('enqueue', false); //Enqueue or requeue segments for retry
                $this->execute_pass('resolve', false);
            }
        }

        // wait to all request ready
        $this->execute_pass('close', false);

        $this->debugger->debug("End live download");
    }

    /**
     * Get list of sub list (levels, audios, etc)
     * @return array
     */
    public function getSubList()
    {
        return $this->subListFilter->itemsAvailable($this->getPlaylistHandler($this->url)->getParsed());
    }

    /**
     * Set levels to download
     *
     * @param $levels
     */
    public function setSubList($levels)
    {
        $this->subListFilter->setSubList($levels);
    }

    /**
     * Set sublist select mode
     *
     * @param string $mode
     */
    public function setSubListMode($mode)
    {
        $this->subListFilter->setSubListMode($mode);
    }

    /**
     * Set progress listener
     *
     * @param $fn
     */
    public function onProgress($fn)
    {
        $this->progressFn = $fn;
    }

    /**
     * Callback for segments download progress
     *
     * @param $progress
     * @noinspection PhpUnused
     */
    public function segmentsDownloadProgress($progress)
    {
        $this->sendProgress();
    }

}