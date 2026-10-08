<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Where Chat's uploads live: any private disk, normally the s3 driver
    | pointed at S3 or Cloudflare R2 (docs/chat.md). Defaults to the same
    | private disk as message attachments and avatars. Files are only ever
    | handed out through an authorized controller, as short-lived signed URLs
    | on s3. The allowed types and image extensions are message attachments'
    | (config/message_attachments.php).
    |
    */

    'attachments_disk' => env('CHAT_ATTACHMENTS_DISK', env('FILESYSTEM_PRIVATE_DISK', 'local')),

    'max_files_per_message' => env('CHAT_ATTACHMENTS_MAX_FILES', 10),

    'max_file_size_kb' => env('CHAT_ATTACHMENTS_MAX_FILE_SIZE_KB', 25600), // 25 MB

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    'max_body_length' => 10000,

    // Messages per history page (scrolling up loads the next page).
    'page_size' => 50,

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    |
    | The emoji a message can be reacted with -- the composer's set in
    | EmojiPicker.jsx (COMPOSER_EMOJI), which must match.
    |
    */

    'reaction_emoji' => [
        '👍', '❤️', '😂', '🎉', '👀', '🙏', '✅', '🔥',
        '😀', '😄', '😊', '🙂', '😉', '😍', '🤔', '😅',
        '😬', '😮', '😢', '😎', '🥳', '🤩', '🙌', '👏',
        '👋', '🤝', '💪', '👌', '✌️', '🤞', '👎', '💡',
        '⭐', '✨', '💯', '⚡', '🚀', '📌', '📎', '📅',
        '⏰', '✏️', '💬', '❗', '❓', '⚠️', '☕', '🍕',
    ],

];
