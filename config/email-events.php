<?php

return [
    'url' => env('MAIL_EVENTS_URL', '.hooks/email-events'),

    'token' => env('MAIL_EVENTS_AUTH_TOKEN'),
    'token_parameter' => env('MAIL_EVENTS_AUTH_TOKEN_PARAM', 'auth'),

    'basic_username' => env('MAIL_EVENTS_AUTH_USERNAME'),
    'basic_password' => env('MAIL_EVENTS_AUTH_PASSWORD'),

    'signature_key' => env('MAIL_EVENTS_SIGNATURE_KEY', env('MAILGUN_SECRET')),

    /*
     * What to do with a webhook payload that no adapter can turn into a valid
     * event: "log" a warning, "throw" an exception, or silently "ignore" it.
     */
    'on_invalid' => env('MAIL_EVENTS_ON_INVALID', 'log'),

    'authorizers' => [
        'token' => \STS\EmailEvents\Auth\TokenAuth::class,
        'basic' => \STS\EmailEvents\Auth\BasicHttpAuth::class,
        'signature' => \STS\EmailEvents\Providers\Mailgun\SignatureAuth::class,
        'user-agent' => \STS\EmailEvents\Auth\UserAgentAuth::class
    ],

    'providers' => [
        'sendgrid' => [
            'adapter' => \STS\EmailEvents\Providers\SendGrid\Adapter::class,
            'auth' => env('MAIL_EVENTS_SENDGRID_AUTH', 'token')
        ],
        'postmark' => [
            'adapter' => \STS\EmailEvents\Providers\Postmark\Adapter::class,
            'auth' => env('MAIL_EVENTS_POSTMARK_AUTH', 'token')
        ],
        'mailgun' => [
            'adapter' => \STS\EmailEvents\Providers\Mailgun\Adapter::class,
            'auth' => env('MAIL_EVENTS_MAILGUN_AUTH', 'token')
        ]
    ]
];