<?php

require 'Flusher.php';

require '../vendor/autoload.php';


if (isset($_POST) && isset($_POST['action'])) {

    // Second view: Select the levels to download and settings (decrypt, join, live, etc..)
    if ($_POST['action'] === 'analyze') {
        // Instance downloader
        $downloader = new Tauri\M3u8Downloader\Downloader($_POST['url']);
        // Get the levels to list for select
        $options = $downloader->getSubList();

        startDocument();

        //Create settings form
        ?>
        <form action="" method="POST">
            <label for="display_url">URL:</label> <span><?php
                echo htmlentities($_POST['url']); ?></span>
            <input type="hidden" name="url" id="url"
                   value="<?php
                   echo htmlspecialchars($_POST['url'], ENT_COMPAT); ?>"/>
            <br>
            <br>
            <input type="hidden" name="action" value="download">
            <?php
            foreach ($options as $option_key => $option) {
                echo "<label>";
                echo "<input type='checkbox' name='subList[]' value='" . $option_key . "'>";
                echo $option_key;
                echo "</label><br>";
            }
            ?>
            <br>
            <label>
                <input type='checkbox' name='decrypt' value='1'>
                Decrypt (if encrypted)
            </label><br>
            <br>
            <label>
                <input type='checkbox' name='join' value='1'>
                Join segments
            </label><br>
            <br>
            <label>
                <input type='checkbox' id="isLive" name='isLive' value='1'>
                Is Live
            </label><br>
            <div id="minutesContent" style="display:none">
                <label for="minutes">Minutes to record:</label>
                <input type="number" name="minutes" id="minutes" value="5">
            </div>
            <br>
            <br>
            <input type="submit" value="Download">
        </form>
        <script type="text/javascript">
            document.getElementById("isLive").addEventListener('change', function (event) {
                document.getElementById("minutesContent").style.display = event.currentTarget.checked ? 'block' : 'none';
            })
        </script>
        <?php

        endDocument();
        die();
    }

    // Last view: Execute downloader
    if ($_POST['action'] === 'download') {

        // Change limits
        ini_set('memory_limit', '1024M');
        set_time_limit(60 * 60 * 6);
        ignore_user_abort(true);

        // For send progress
        $flusher = new Tauri\Flusher();
        $flusher->setForFlush('text/html');

        $downloader = new Tauri\M3u8Downloader\Downloader(
            $_POST['url'],
            [
                'saveTo' => './download_files/testing' . rand(0, 10000),
                'decrypt' => ! empty($_POST['decrypt']),
                'joinSegments' => ! empty($_POST['join']),
            ]
        );

        // Set the levels to download
        if ( ! empty($_POST['subList'])) {
            $downloader->setSubList($_POST['subList']);
        }

        // Show progress
        $downloader->onProgress(function ($p) use ($flusher) {
            $flusher->sendPercent($p);
        });

        // Initialize download
        if ( ! empty($_POST['isLive'])) {
            $downloader->downloadLive((int)$_POST['minutes']);
        } else {
            $downloader->download();
        }

        // Get the link to show
        $result = $downloader->saveFilePath();
        $link = \Tauri\M3u8Downloader\HelperPath::relativeUrl(realpath(__FILE__), realpath($result));

        echo "<a href=\"" . htmlspecialchars($link, ENT_COMPAT) .
            "\">Downloaded m3u8 (" . round($downloader->getDuration()) . " secs)</a>";

        die();
    }
}

// Fist view
startDocument();
?>
    <form action="" method="POST">
        <label for="url">URL:</label>
        <input type="text" name="url" id="url"/>
        <input type="hidden" name="action" value="analyze">
        <input type="submit" value="Find levels">
    </form>
<?php
endDocument();


/******************
 *  HTML DOC PARTS
 *****************/
function startDocument() {
?><!DOCTYPE html>
    <html lang="EN">
    <head>
        <title>M3u8 Downloader Example</title>
    </head>
    <body>
    <?php
}

function endDocument()
{
    echo '	</body>
</html>';
}