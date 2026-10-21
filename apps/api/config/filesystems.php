<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Off: `true` makes the framework register `GET` and `PUT storage/{path}` with none of the
            // application's middleware, over a folder that holds the profile pictures. Answered only
            // for a signed URL, and nothing here signs one — every file the application hands out
            // goes through its own controller, with its own checks.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // The CA the endpoint's certificate is checked against, for one signed by a private CA;
            // the system's otherwise. Never off: every document crosses this connection.
            'http' => ['verify' => env('AWS_CA_CERT') ?: true],
            'throw' => false,
            'report' => false,
        ],

        /*
         * Profile pictures. A local disk, not the encrypted object store and not `public`.
         *
         * Not the object store because an avatar is not a tax document: it carries no counterparty, no
         * amount and no declaration, so wrapping it in a tenant DEK would buy nothing and would tie a
         * face to a subject it does not belong to — a user is one person across every tenant they can
         * act for.
         *
         * Not `public` because that disk is served by the web server straight off a symlink, with no
         * middleware in the path. Every face would then sit behind a URL that works for anybody who
         * guesses it. These are read through a controller that checks who is asking.
         */
        'avatars' => [
            'driver' => 'local',
            'root' => storage_path('app/private/avatars'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // A physically separate bucket from 's3' — Kern\Documents\Quarantine\
        // QuarantineObjectStore (docs/handoff/022-document-pipeline.md) relies on this being a
        // disk the rest of the application never reads documents from, so an unscanned upload is
        // unreachable by construction, not merely by a status check. Same MinIO credentials/
        // endpoint as 's3' in dev — only the bucket differs.
        'quarantine' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('QUARANTINE_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // The CA the endpoint's certificate is checked against, for one signed by a private CA;
            // the system's otherwise. Never off: every document crosses this connection.
            'http' => ['verify' => env('AWS_CA_CERT') ?: true],
            'throw' => false,
            'report' => false,
        ],

    ],

];
