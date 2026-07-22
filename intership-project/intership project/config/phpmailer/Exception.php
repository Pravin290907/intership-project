<?php
namespace PHPMailer\PHPMailer;

/**
 * PHPMailer Exception Class
 */
class Exception extends \Exception {
    /**
     * Prettify error message.
     *
     * @return string
     */
    public function errorMessage() {
        return '<strong>' . htmlspecialchars($this->getMessage(), ENT_QUOTES, 'UTF-8') . "</strong><br />\n";
    }
}
