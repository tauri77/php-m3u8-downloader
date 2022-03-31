<?php

namespace Tauri;

class Flusher
{

	private $flushing = false;
    private $last_time_flush = 0;
    private $last_size_flush = 0;

	function send( $str )
    {
		if ( ! $this->flushing ) {
			return;
		}
		$this->flush_text( $str );
	}

	public function sendPercent( $progress )
    {
		$percent = round( $progress * 100, 2 );
		$str = '<script> setProgress('.$percent.') </script>';
		$this->flush_text($str);
	}

	public function setForFlush( $content_type = 'text/plain' )
    {
		if ( $this->flushing ) {
			return;
		}
		$this->flushing        = true;
		$this->last_time_flush = time();
		$this->last_size_flush = 0;

		if ( ! ( php_sapi_name() === 'cli' || defined( 'STDIN' ) ) ) {
			//tasks done, send to browser
			header( "HTTP/1.1 200 OK" );
			header( "Content-Type: " . $content_type );
			header( "Cache-Control: no-store, no-cache, must-revalidate" );
			header( "Cache-Control: post-check=0, pre-check=0", false );
			header( "Pragma: no-cache" );
			header( "Content-Encoding: none" ); //Invalid encoding 54 vs 540
			echo str_pad( "", 54 * 1024, " " );
		}

		?><!DOCTYPE html>
<html>
<head>
	<title>Progress</title>
	<style>
		#progress {
			height: 30px;
			width:500px;
			border:1px solid #ccc;
			overflow:hidden;
		}
		#progress-bar{
			height: 30px;
			background-color:#ddd;
		}
		#progress-info {
			width: 500px;
			text-align: center;
			position: relative;
			top: -30px;
			line-height: 30px;
		}
	</style>
	<script>
		function setProgress(percent) {
			document.getElementById("progress-bar").style.width = percent + "%";
			document.getElementById("progress-info").innerHTML = percent + "%";

			if (percent>=100) {
				document.getElementById("progress-info").innerHTML = 'Process completed';
			}
		}
	</script>
</head>
<body>
<div id="progress">
	<div id="progress-bar">&nbsp;</div>
</div>
<div id="progress-info"></div><?php
		if ( function_exists( "ob_implicit_flush" ) ) {
			ob_implicit_flush( true );
		}

		if ( function_exists( "ob_end_flush" ) ) {
			while ( @ob_end_flush() ) {
				;
			}
		}

	}

	private function flush_text( $text = ' ', $pad = false, $timed_pad = true )
    {
		echo $text;
		if ( empty( $this->flushing ) ) {
			return;
		}
		$this->last_size_flush += strlen( $text );
		if ( $pad ) {
			echo str_pad( "", 54 * 1024, " " );
		}

		if ( $timed_pad ) {
			$buffer_size = 54 * 1024 * 4;//4096;
			if ( $this->last_size_flush > $buffer_size ) {
				$this->last_time_flush = time();
				$this->last_size_flush = 0;
			} else {
				if ( time() - $this->last_time_flush > 10 ) {
					$this->last_time_flush = time();
					echo str_pad( "", $buffer_size - $this->last_size_flush, " " );
					$this->last_size_flush = 0;
				}
			}
		}
		flush();
		@ob_flush();
	}
}
