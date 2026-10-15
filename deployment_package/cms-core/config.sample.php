<?php
/**
 * Green AVIC CMS — Configuration Template
 * 
 * Instructions:
 * 1. Copy or rename this file to config.php in this same directory.
 * 2. Fill in your MariaDB database credentials created in Hostinger hPanel.
 * 3. Replace the SESSION_SECRET with a random 64-character hex key.
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);

// ----------------------------------------------------------
// Database Connection Settings (from Hostinger Databases)
// ----------------------------------------------------------
define('DB_HOST', 'localhost');                  // Usually 'localhost' on Hostinger
define('DB_NAME', 'u123456789_greenavic');       // Your Hostinger database name
define('DB_USER', 'u123456789_admin');           // Your Hostinger database username
define('DB_PASS', 'YOUR_DATABASE_PASSWORD_HERE'); // Your Hostinger database user password
define('DB_PORT', 3306);

// ----------------------------------------------------------
// Security & Session Settings
// ----------------------------------------------------------
// Replace with a secure random string (e.g. bin2hex(random_bytes(32)))
define('SESSION_SECRET', 'REPLACE_WITH_A_RANDOM_SECURE_64_CHAR_HEX_KEY');
define('SESSION_LIFETIME', 86400); // 24 hours in seconds

// ----------------------------------------------------------
// Subdomain URLs & File Paths
// ----------------------------------------------------------
define('NEWSLETTER_URL', 'https://newsletter.greenavic.org');
define('ADMIN_URL',      'https://admin.greenavic.org');
define('MAIN_SITE_URL',  'https://greenavic.org');

// Relative paths on server (Sibling folders inside public_html)
define('NEWSLETTER_DIR', dirname(__DIR__) . '/newsletter');
define('ADMIN_DIR',      dirname(__DIR__) . '/admin');
define('UPLOADS_DIR',    NEWSLETTER_DIR . '/uploads');
define('UPLOADS_URL',    NEWSLETTER_URL . '/uploads');

// ----------------------------------------------------------
// Hostinger SMTP Mailer Configuration
// ----------------------------------------------------------
define('SMTP_HOST',       'smtp.hostinger.com');          // Hostinger SMTP host
define('SMTP_PORT',       465);                           // 465 (SSL) or 587 (TLS)
define('SMTP_SECURE',     'ssl');                         // 'ssl' or 'tls'
define('SMTP_USER',       'newsletter@greenavic.org');     // Your Hostinger email account
define('SMTP_PASS',       'YOUR_EMAIL_ACCOUNT_PASSWORD'); // Your Hostinger email password
define('SMTP_FROM_EMAIL', 'newsletter@greenavic.org');     // Sender email address
define('SMTP_FROM_NAME',  'Green AVIC');                  // Sender display name

