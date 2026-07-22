<?php
/**
 * SMTP Mail Configuration Options
 * Loads environment parameters and exports the SMTP transport configuration array.
 */

require_once __DIR__ . '/app.php';

return [
    'host'       => defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com',
    'port'       => defined('SMTP_PORT') ? SMTP_PORT : 587,
    'secure'     => defined('SMTP_SECURE') ? SMTP_SECURE : 'tls',
    'username'   => defined('SMTP_USER') ? SMTP_USER : '',
    'password'   => defined('SMTP_PASS') ? SMTP_PASS : '',
    'from'       => defined('MAIL_FROM') ? MAIL_FROM : '',
    'from_name'  => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'CampusRecruit Support',
];
