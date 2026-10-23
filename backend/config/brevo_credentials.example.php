<?php

// Copy to brevo_credentials.php and fill in the two empty values locally.
// Empty placeholders intentionally prevent delivery until configured.
return [
    'api_key' => '', // Your regular Brevo API key, not an SMTP key.
    'sender_email' => '', // Your verified sender email address.
    'sender_name' => 'Leo Mejillano Salon & Beauty',
    'api_url' => 'https://api.brevo.com/v3/smtp/email',
];
