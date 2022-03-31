<?php

namespace Tauri\M3u8Downloader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;

class SegmentsResolver
{
    use FileReverse;

    /**
     * @var Debugger
     */
    private $debugger;

    /**
     * Segments to resolve
     * @var int
     */
    private $segmentPending = 0;

    /**
     * Segment resolved
     * @var int
     */
    private $segmentReady = 0;

    /**
     * All segments
     * @var Segment[]
     */
    private $segments = [];

    /**
     * @var  Client
     */
    private $httpClient;
    /**
     * @var null|callable
     */
    private $progressFn = null;
    /**
     * @var array
     */
    private $cache = [];

    /**
     * @var null|Promise
     */
    private $promise = null;

    /**
     * SegmentsResolver constructor.
     *
     * @param Client $client
     * @param Debugger $debugger
     */
    public function __construct($client, $debugger)
    {
        $this->httpClient = $client;
        $this->debugger = $debugger;
    }

    /**
     * Set progress listener
     *
     * @param callable $fn
     */
    public function onProgress($fn)
    {
        $this->progressFn = $fn;
    }

    /**
     * Get the array of segments objects to use on php-m3u8
     *
     * @return array
     */
    public function getSegmentsObject()
    {
        usort($this->segments, function ($a, $b) {
            return $a->sequence > $b->sequence;
        });

        $ret = [];
        $nextXMap = null;
        foreach ($this->segments as $k => $segment) {
            if ($segment instanceof XMap) {
                $nextXMap = $segment->listObj;
                continue;
            }
            if ($nextXMap !== null) {
                $segment->listObj["EXT-X-MAP"] = $nextXMap;
                $nextXMap = null;
            }
            $ret[] = $segment->listObj;
        }

        return $ret;
    }

    /**
     * Add segment to queue
     *
     * @param Segment $segment
     * @param bool $checkSequenceExist
     */
    public function addSegment($segment, $checkSequenceExist = true)
    {
        if ($checkSequenceExist && $this->segmentSequenceExist($segment)) {
            return;
        }
        $segment->setPending();
        $this->segments[] = $segment;
        $this->segmentPending += 1;
    }

    /**
     * Check if segment already added
     *
     * @param $checkSegment
     *
     * @return bool
     */
    public function segmentSequenceExist($checkSegment)
    {
        foreach ($this->segments as $segment) {
            if ($segment->sequence === $checkSegment->sequence) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get sum duration
     *
     * @return float
     */
    public function getDuration()
    {
        return array_reduce(
            $this->segments,
            function ($acum, $segment){
                return $acum + max( 0, $segment->getDuration());
                },
            0
        );
    }

    /**
     * Continue/Start resolve if promise is inactive
     *
     * @throws \Exception
     */
    public function reQueue()
    {
        if ($this->promise === null || $this->promise->getState() !== Promise::PENDING) {
            $this->resolve(1, false);
        }
    }

    /**
     * Resolve all segments
     *
     * @param int $try
     * @param bool $wait
     *
     * @return bool
     * @throws \Exception
     */
    public function resolve($try = 1, $wait = true)
    {
        $this->resolveLocalAndCached();
        $this->resolveRemotes();

        if ($wait) {
            $this->wait();
            if ($this->segmentPending !== 0) {
                if ($try > 5) {
                    return false;
                }
                return $this->resolve($try + 1, true);
            }
        }

        return true;
    }

    /**
     * Resolve local and cached segments
     *
     * @throws \Exception
     */
    private function resolveLocalAndCached()
    {
        foreach ($this->segments as $index => $segment) {
            if ($segment->isPending()) {
                if ($this->inCache($segment)) {
                    $this->setAsReady($index);
                }
                //Check if is local file, then copy
                if ($segment->isExistLocalFile()) {
                    if ($this->copyFile($segment)) {
                        $this->pushCache($segment);
                        $this->setAsReady($index);
                    }
                }
            }
        }
    }

    /**
     * Check if a segment already downloaded
     *
     * @param Segment $segment
     *
     * @return bool
     */
    private function inCache($segment)
    {
        $key = md5($segment->url . '-' . $segment->offset . '-' . $segment->length);
        if (isset($this->cache[$key]) && $this->cache[$key] === $segment->dest_file) {
            return true;
        }
        return false;
    }

    /**
     * Set a segment as ready and call progress callback
     *
     * @param int $index
     */
    private function setAsReady($index)
    {
        $this->segmentReady += 1;
        $this->segmentPending -= 1;
        $this->segments[$index]->setReady();
        if (is_callable($this->progressFn)) {
            call_user_func($this->progressFn, $this->progress());
        }
    }

    /**
     * Get the percent of segments already downloaded [0-1]
     *
     * @return float|int
     */
    public function progress()
    {
        if (count($this->segments) === 0) {
            return 1;
        }
        return $this->segmentReady / count($this->segments);
    }

    /**
     * Copy and decrypt a file or part of file
     *
     * @param Segment $segment
     *
     * @return bool
     * @throws \Exception
     */
    private function copyFile($segment)
    {
        return $segment->saveDataToFile($segment->localSourceData());
    }

    /**
     * Register segment as downloded
     *
     * @param Segment $segment
     */
    private function pushCache($segment)
    {
        $key = md5($segment->url . '-' . $segment->offset . '-' . $segment->length);
        $this->cache[$key] = $segment->dest_file;
    }

    /**
     * Resolve remote segments
     */
    private function resolveRemotes()
    {
        $this->debugger->debug(date("i:s") . ": call resolveRemotes");
        $pool = new Pool($this->httpClient, $this->requests(), [
            'concurrency' => 5,
            'fulfilled' => function (Response $response, $index) {
                $this->setAsReady($index);
                $segment = $this->segments[$index];
                $data = (string)$response->getBody();

                $segment->saveDataToFile($segment->decryptData($data));

                chmod($segment->dest_file, Downloader::$modeFile);
            },
            'rejected' => function (ConnectException $reason, $index) {
                $this->segments[$index]->setPending();
                $this->debugger->warn("Error on get " . $this->segments[$index]->url);
                $this->debugger->warn($reason->getMessage());
                // this is delivered each failed request
                if (is_callable($this->progressFn)) {
                    call_user_func($this->progressFn, $this->progress());
                }
            },
        ]);

        // Initiate the transfers and create a promise
        $this->promise = $pool->promise();
    }

    /**
     * Generator for parallel requests
     *
     * @return \Generator
     */
    private function requests()
    {
        foreach ($this->segments as $index => $segment) {
            if ($segment->isPending()) {
                $args = [];
                $args['stream'] = true;
                $this->debugger->debug(date("i:s") . ": iterate to " . $segment->url);
                if ($segment->offset !== null) {
                    $args['headers'] = [];
                    $args['headers']['Range'] = 'bytes=' . $segment->offset . '-' . ($segment->length + $segment->offset - 1);
                    $this->debugger->debug($args);
                }
                $segment->setProcessing();
                yield $index => function () use ($segment, $args) {
                    return $this->httpClient->getAsync($segment->url, $args);
                };
            }
        }
    }

    private function wait()
    {
        // Force the pool of requests to complete.
        if ($this->promise !== null) {
            $this->promise->wait();
        }
    }

    /**
     * Wait all requests
     */
    public function resolveQueued()
    {
        $this->wait();
    }

    /**
     * Wait and retry all requests
     *
     * @return bool returns false if not all segments were downloaded
     *
     * @throws \Exception
     */
    public function endResolve()
    {
        if ($this->promise !== null && $this->promise->getState() === Promise::PENDING) {
            $this->wait();
        }
        $this->resolve(1, true);

        return $this->segmentPending === 0;
    }

    /**
     * Remove all downloaded segments
     */
    public function removeAll()
    {
        $segments = array_reverse($this->segments);
        foreach ($segments as $segment) {
            $segment->storageReverse();
        }
        //Maybe no folder empty the fist iteration.. rerun
        foreach ($segments as $segment) {
            $segment->storageReverse();
        }
        $this->storageReverse();
    }

    /**
     * Join all segments on a file
     *
     * @return bool
     * @throws \Exception
     */
    public function joinSegments()
    {
        usort($this->segments, function ($a, $b) {
            return $a->sequence > $b->sequence;
        });

        if (empty($this->segments)) {
            //No segments on m3u8..
            return true;
        }

        $maybe_unique_file = $this->segments[0]->dest_file;
        foreach ($this->segments as $segment) {
            if ($segment->dest_file != $maybe_unique_file) {
                $maybe_unique_file = false;
                break;
            }
        }
        if ($maybe_unique_file !== false) {
            //Only one file.. maybe fmp4
            return true;
        }

        $levelPathInfo = pathinfo($this->segments[0]->dest_file);
        $mergedFile = $levelPathInfo['dirname'] . '/_merged.' . $levelPathInfo['extension'];

        $this->setFileExist($mergedFile);

        $uri = HelperPath::relativeUrl(
            realpath($this->segments[0]->levelFile),
            realpath($mergedFile)
        );

        //Check exists all segments
        foreach ($this->segments as $segment) {
            if ( ! file_exists($segment->dest_file)) {
                @unlink($mergedFile);
                throw new \Exception("Segment not found: " . $segment->dest_file);
            }
        }

        //Copy segments
        $f = fopen($mergedFile, 'r+');
        if (false === $f) {
            @unlink($mergedFile);
            throw new \Exception("Unable to open merged file");
        }
        foreach ($this->segments as $segment) {
            $ret = fwrite($f, $segment->getData());
            if (false === $ret) {
                unlink($mergedFile);
                throw new \Exception("Unable to write merged file");
            }
        }
        fclose($f);

        //update m3u8 and remove segment file
        $offset = 0;
        foreach ($this->segments as $segment) {
            $length = $segment->length !== null ? $segment->length : filesize($segment->dest_file);
            //Change on m3u8 to range...
            $segment->setUri($uri);
            $segment->setRange($offset > 0 ? $offset : null, $length);
            $offset += $length;
            unlink($segment->dest_file);
        }

        return true;
    }

}