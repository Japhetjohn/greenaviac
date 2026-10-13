<?php
// Green AVIC CMS Configuration
// This file is protected from web access by .htaccess
defined('GREENAVIC_APP') or (http_response_code(403) && exit('Direct access forbidden'));

return [
    'admin_email' => getenv('ADMIN_EMAIL') ?: 'admin@greenavic.org',
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: 'pbkdf2:sha256:100000$6abcd0e4b1389973e6ea22dddc9e6438$f458034d4b1234ef055c22d37a667e407c8faee597ef511fae8f135698b5ce7b',
    'session_secret' => getenv('SESSION_SECRET') ?: '1fc37160935cffb2ef4d118bee73ca959b16d256255c69b2a841e965f0884320',
    'session_duration' => 86400, // 24 hours
];
