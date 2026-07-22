<?php
/**
 * Master Application Configuration
 * Loads environment variables from .env and defines global constants including BASE_URL and SMTP settings.
 */

// --- Lightweight .env File Parser ---
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, '#') !== 0 && strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// --- Dynamic Base URL Calculation ---
if (!function_exists('computeBaseUrl')) {
    function computeBaseUrl() {
        $envBase = getenv('BASE_URL');
        if (!empty($envBase) && strpos($envBase, 'http') === 0 && strpos($envBase, 'http://localhost/intership-project/') === false) {
            return rtrim($envBase, '/') . '/';
        }

        if (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST'])) {
            return 'http://localhost/intership-project/';
        }

        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = dirname($scriptName);
        if ($scriptDir === '/' || $scriptDir === '\\') {
            $scriptDir = '';
        }

        $subdirs = ['/auth', '/admin', '/student', '/company', '/tpo', '/api', '/config', '/recruiter', '/scratch'];
        foreach ($subdirs as $sub) {
            if (substr($scriptDir, -strlen($sub)) === $sub) {
                $scriptDir = substr($scriptDir, 0, -strlen($sub));
                break;
            }
        }

        return $protocol . $host . rtrim($scriptDir, '/') . '/';
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', computeBaseUrl());
}

// --- SMTP Email Transport Configuration ---
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
if (!defined('MAIL_FROM')) define('MAIL_FROM', getenv('MAIL_FROM') ?: (!empty(SMTP_USER) ? SMTP_USER : 'no-reply@campusrecruit.edu'));
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'CampusRecruit Support');
