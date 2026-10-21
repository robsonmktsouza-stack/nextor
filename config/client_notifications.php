<?php
return [
    // Explicit production opt-in. Test and local installs never send by default.
    'email_enabled' => (bool) env('LUMERON_CLIENT_EMAIL_ENABLED', false),
];