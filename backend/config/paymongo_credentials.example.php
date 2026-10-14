<?php

// Copy this file to paymongo_credentials.php and fill in your PayMongo keys locally.
// paymongo_credentials.php is excluded from Git. Do not paste keys into chat.
// Use the test keys (sk_test_...) until the integration is verified.
return [
    'secret_key' => '', // Secret key from PayMongo Dashboard > Developers > API Keys.
    'public_key' => '', // Public key (pk_test_...), same page.
    'webhook_secret' => '', // whsk_... returned when the webhook is registered.
];
