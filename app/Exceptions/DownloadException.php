<?php

namespace App\Exceptions;

use Exception;

/**
 * yt-dlp could not download a video (private video, removed, blocked...).
 */
class DownloadException extends Exception {}
