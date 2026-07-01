<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Mailbox
    |--------------------------------------------------------------------------
    */
    'default' => env('DMARC_IMAP_MAILBOX', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Mailboxes
    |--------------------------------------------------------------------------
    */
    'mailboxes' => [
        'default' => [
            'dmarc_email' => env('DMARC_EMAIL', 'dmarc@localhost'),
            'host' => env('DMARC_IMAP_HOST', 'imap.example.com'),
            'port' => env('DMARC_IMAP_PORT', 993),
            'encryption' => env('DMARC_IMAP_ENCRYPTION', 'ssl'),
            'username' => env('DMARC_IMAP_USERNAME'),
            'password' => env('DMARC_IMAP_PASSWORD'),
            'validate_cert' => env('DMARC_IMAP_VALIDATE_CERT', true),
            'timeout' => env('DMARC_IMAP_TIMEOUT', 30),
            'debug' => env('DMARC_IMAP_DEBUG', false),
        ],
        
        // You can define multiple mailboxes if needed
        // 'secondary' => [ ... ],
    ],
    'emails' => [
        'default_rua_localpart' => 'dmarc',
        'default_ruf_localpart' => 'dmarc',
    ],    
    
    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    | These are the default values that will be used if not set in the database
    */
    'defaults' => [
        'policy' => 'none',
        'subdomain_policy' => null,
        'adkim' => 'relaxed',
        'aspf' => 'relaxed',
        'reporting' => ['all'],
        'percentage' => 100,
        'report_interval' => 86400,
        't' => 'n',
    ],    
];