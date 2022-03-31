<?php


namespace Tauri\M3u8Downloader;


class HelperPath
{

    /**
     * Get the relative url for a file from other location
     *
     * Use for set the ts segment relative to a m3u8 file.
     *
     * @param string $root The root path
     * @param string $file The file for find relative url
     *
     * @return string
     */
    public static function relativeUrl($root, $file)
    {
        if (empty($root) || empty($file)) {
            return '';
        }
        $filePath = explode('\\', str_replace('/', '\\', dirname($file)));
        $rootPath = explode('\\', str_replace('/', '\\', dirname($root)));
        $relPath = array();
        $ups = 0;
        for ($i = 0; $i < count($rootPath); $i++) {
            if ($i >= count($filePath)) {
                $ups++;
            } elseif ($filePath[$i] != $rootPath[$i]) {
                $relPath[] = $filePath[$i];
                $ups++;
            }
        }
        $folders = array_merge($relPath, array_slice($filePath, count($rootPath)));

        return str_repeat('../', $ups) .
            implode('/', $folders) .
            (! empty($folders) ? '/' : '') .
            basename(parse_url($file, PHP_URL_PATH));
    }

    /**
     * Get path/url without ref to base. Maybe not absolute path (if base is not absolute)
     *
     * @param $maybeRelative
     * @param $base
     *
     * @return string
     */
    public static function getNotRelative($maybeRelative, $base)
    {
        if ( ! empty($base) && ! startsWith($base, 'https://') && ! startsWith($base, 'http://')) {
            //Local files
            if (startsWith($maybeRelative, '/')) {
                return $maybeRelative;
            }
            return preg_replace('@/([^/]*)$@', '', $base) . '/' . $maybeRelative;
        }
        $parsedUrl = parse_url($base);

        if (startsWith($maybeRelative, 'https://') || startsWith($maybeRelative, 'http://')) {
            return $maybeRelative;
        } elseif (startsWith($maybeRelative, '//')) {
            return $parsedUrl['scheme'] . ':' . $maybeRelative;
        } elseif (startsWith($maybeRelative, '/')) {
            return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $maybeRelative;
        } else {
            $path = $parsedUrl['path'];
            if ( ! endsWith($path, '/')) {
                $path = dirname($path) . '/';
            }

            return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $path . $maybeRelative;
        }
    }

    /**
     * Return foldername relative to base for create a child directory
     *
     * Maybe return the filename of path.
     *
     * @param string $path
     * @param string $base
     * @param int $depth The depth after last directory match
     *
     * @return string
     */
    public static function childDirectory($path, $base, $depth)
    {
        $path = self::getNotRelative($path, $base);
        $path = preg_replace('@https?://[^/]*/@i', '', $path);
        $base = preg_replace('@https?://[^/]*/@i', '', $base);
        $i = 0;
        $pathParts = explode('/', $path);
        $baseParts = explode('/', $base);
        while (isset($baseParts[$i]) && $pathParts[$i] === $baseParts[$i]) {
            $i++;
            if (count($pathParts) <= $i) {
                return '';
            }
        }
        $i += $depth;
        return (count($pathParts) > $i) ? explode('?', $pathParts[$i])[0] : '';
    }

}