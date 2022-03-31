<?php

namespace Tauri\M3u8Downloader;

use Psr\Log\LoggerInterface;

class Debugger
{
    /**
     * @var string
     */
    private $out = 'textarea';

    /**
     * @var int
     */
    private $level = 0;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Debugger constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        if ( ! empty($options['logger'])) {
            $this->logger = $options['logger'];
        }
        if (isset($options['level'])) {
            $this->level = $options['level'];
        }
    }

    /** @noinspection PhpComposerExtensionStubsInspection */
    private function stringify($what)
    {
        if ( ! is_string($what)) {
            if (function_exists('json_encode')) {
                $what = json_encode($what, JSON_UNESCAPED_UNICODE);
                if ($what === false) {
                    if (function_exists('iconv')) {
                        $what = iconv('UTF-8', 'UTF-8//IGNORE', $what);
                        $what = json_encode($what, JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        }
        if ( ! is_string($what)) {
            $what = "!!!stringify error!!!";
        }
        return $what;
    }

    public function show($what, $type)
    {
        if ($type > $this->level) {
            return;
        }
        $what = $this->stringify($what);
        if ($this->out === 'textarea') {
            echo "<textarea>" . htmlspecialchars($what) . "</textarea><br>\n";
        } elseif ($this->out === 'text') {
            echo $what . "<br>\n";
        }
    }

    public function error($what)
    {
        if ($this->logger) {
            $this->logger->error($this->stringify($what));
        }
        $this->show($what, 16);
    }

    public function warn($what)
    {
        if ($this->logger) {
            $this->logger->warning($this->stringify($what));
        }
        $this->show($what, 8);
    }

    public function notice($what)
    {
        if ($this->logger) {
            $this->logger->notice($this->stringify($what));
        }
        $this->show($what, 4);
    }

    public function info($what)
    {
        if ($this->logger) {
            $this->logger->info($this->stringify($what));
        }
        $this->show($what, 2);
    }

    public function debug($what)
    {
        if ($this->logger) {
            $this->logger->debug($this->stringify($what));
        }
        $this->show($what, 1);
    }

}