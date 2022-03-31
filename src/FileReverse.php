<?php


namespace Tauri\M3u8Downloader;


trait FileReverse
{
    private $createdFolders = [];
    private $createdFiles = [];

    /**
     * Create a directory if not exist
     *
     * @param $dir
     */
    public function setFolderExist($dir)
    {
        if ( ! file_exists($dir)) {
            $original = umask(0);
            mkdir($dir, Downloader::$modeFolder);
            umask($original);
            $this->createdFolders[] = $dir;
        }
    }

    /**
     * Create a file if not exist
     *
     * @param $file
     */
    public function setFileExist($file)
    {
        if ( ! file_exists($file)) {
            touch($file);
            chmod($file, Downloader::$modeFile);
            $this->createdFiles[] = $file;
        }
    }

    /**
     * Remove all created files/folders
     */
    public function storageReverse()
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        foreach ($this->createdFolders as $dir) {
            @rmdir($dir);
        }
    }
}