<?php

return [
    'frontend_url' => rtrim(
        env('TRUSTFIX_FRONTEND_URL', 'https://trustfix.lakehousesoftware.com'),
        '/'
    ),

    'support_email' => env(
        'TRUSTFIX_SUPPORT_EMAIL',
        't.tyler@trustfixai.com'
    ),

    'verification_expire_minutes' => (int) env(
        'TRUSTFIX_VERIFICATION_EXPIRE_MINUTES',
        60
    ),
];
