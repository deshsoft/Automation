<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'http' => [
        'force_ipv4' => (bool) env('HTTP_FORCE_IPV4', false),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v24.0'),
        'config_id' => env('FACEBOOK_CONFIG_ID'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'youtube' => [
        'privacy' => env('YOUTUBE_PRIVACY', 'public'),
        'category_id' => env('YOUTUBE_CATEGORY_ID', '25'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

    /*
     * Video downloader (yt-dlp). Leave the paths empty to use storage/app/bin
     * (filled by "php artisan downloads:install") or the system PATH.
     */
    'downloader' => [
        'binary' => env('YTDLP_PATH'),
        'ffmpeg' => env('FFMPEG_PATH'),
        'deno' => env('DENO_PATH'),
        'cookies' => env('YTDLP_COOKIES'),
        'max_filesize_mb' => (int) env('DOWNLOAD_MAX_MB', 2048),
        'keep_days' => (int) env('DOWNLOAD_KEEP_DAYS', 3),
    ],

];
