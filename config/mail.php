<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => 10,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Cap For Document Notifications
    |--------------------------------------------------------------------------
    |
    | The mailbox is limited to 100 messages per 24 hours and is shared with
    | password-reset and registration emails. Document notifications may only
    | use messages while the total sent today stays within this cap.
    |
    */

    'daily_cap_for_documents' => (int) env('MAIL_DOC_DAILY_CAP', 60),

    /*
    |--------------------------------------------------------------------------
    | Document Notification Test Recipient
    |--------------------------------------------------------------------------
    |
    | When set to a valid address, document notifications are sent ONLY to this
    | address (never to real users) and the document is not marked as notified.
    | Leave empty to send for real. An invalid value sends nothing at all.
    |
    */

    'doc_test_recipient' => env('MAIL_DOC_TEST_RECIPIENT'),

    /*
    |--------------------------------------------------------------------------
    | Credential Expiry Reminders
    |--------------------------------------------------------------------------
    |
    | เพดานอีเมลเตือนวันหมดอายุใบอนุญาตต่อวัน (นับรวมโควตา 100 ฉบับของโฮสต์)
    | และที่อยู่ทดสอบ: ถ้าตั้งไว้ จะส่งทุกฉบับไปที่นี่ที่เดียวและไม่บันทึกว่าเตือนแล้ว
    |
    */

    'daily_cap_for_reminders' => (int) env('MAIL_REMINDER_DAILY_CAP', 40),

    'reminder_test_recipient' => env('MAIL_REMINDER_TEST_RECIPIENT'),

];
