<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sensitive Setting Keys
    |--------------------------------------------------------------------------
    |
    | Settings listed here will be encrypted when stored in the database
    | and decrypted when retrieved. Use "group.key" notation.
    |
    */

    'sensitive_keys' => [
        'mail.password',
        'storage.spaces_secret',
        'turnstile.secret_key',
    ],

];
