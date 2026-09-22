<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_files_per_message' => env('MESSAGE_ATTACHMENTS_MAX_FILES', 10),

    'max_file_size_kb' => env('MESSAGE_ATTACHMENTS_MAX_FILE_SIZE_KB', 25600), // 25 MB

    /*
    |--------------------------------------------------------------------------
    | Allowed file types
    |--------------------------------------------------------------------------
    |
    | Keyed by extension so the upload form/validation errors can speak in
    | terms users recognize. Validated server-side against the file's real
    | detected MIME type (Laravel's `mimetypes:` rule), never the client-
    | supplied extension -- a renamed .exe claiming to be a .jpg is rejected.
    | HEIC has no single canonical MIME across browsers/OSes, so both common
    | variants are accepted.
    |
    */

    'allowed_mimes' => [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'csv' => ['text/csv', 'text/plain'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'fig' => ['application/octet-stream'],
        'psd' => ['image/vnd.adobe.photoshop', 'application/octet-stream'],
        'ai' => ['application/postscript', 'application/pdf', 'application/octet-stream'],
        'sketch' => ['application/octet-stream', 'application/zip'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image extensions
    |--------------------------------------------------------------------------
    |
    | Which of the extensions above render inline (grid + lightbox) rather
    | than as a file chip, and are eligible for a thumbnail job. HEIC is
    | intentionally excluded -- browsers can't render it inline, and GD/
    | Imagick can't reliably decode it either, so it's stored and offered as
    | a plain downloadable file instead of failing the thumbnail job.
    |
    */

    'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    */

    'thumbnail_max_dimension' => 400,

];
