<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // ชีตวันหมดอายุใบอนุญาต (อ่านอย่างเดียว ต้องเปิดแบบมีลิงก์ดูได้)
    'credential_sheet' => [
        'id' => env('CREDENTIAL_SHEET_ID'),
        'gid' => env('CREDENTIAL_SHEET_GID', '0'),
    ],

    // ที่เก็บชุดสำรองนอกเครื่อง host (Google Drive) ว่าง = ไม่อัปโหลด
    // สร้างค่าด้วย php artisan backup:drive-auth (scope drive.file เห็นเฉพาะไฟล์ที่ระบบนี้สร้างเอง)
    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folder_name' => env('GOOGLE_DRIVE_FOLDER_NAME', 'U-Tapao ATC Backups'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
