<?php
namespace PHPMailer\PHPMailer;

/**
 * PHPMailer RFC821 SMTP Implementation
 */
class SMTP {
    const VERSION = '6.9.1';
    const LE = "\r\n";
    const DEFAULT_PORT = 25;

    protected $smtp_conn;
    protected $error = [];
    protected $last_reply = '';
    public $Timeout = 30;

    public function connect($host, $port = 587, $timeout = 30, $options = []) {
        $this->error = [];
        if ($this->connected()) {
            $this->error = ['error' => 'Already connected to a server'];
            return false;
        }

        if (empty($port)) {
            $port = self::DEFAULT_PORT;
        }

        $errno = 0;
        $errstr = '';
        
        $targetHost = $host;
        if ($port == 465 && strpos($host, 'ssl://') === false) {
            $targetHost = 'ssl://' . $host;
        }

        $socket_context = stream_context_create($options);
        $this->smtp_conn = @stream_socket_client(
            $targetHost . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $socket_context
        );

        if (!is_resource($this->smtp_conn)) {
            $this->error = [
                'error' => "Failed to connect to SMTP server {$host}:{$port} - {$errstr} ({$errno})",
                'errno' => $errno,
                'errstr' => $errstr
            ];
            return false;
        }

        stream_set_timeout($this->smtp_conn, $timeout, 0);
        $announce = $this->get_lines();
        
        if (substr($announce, 0, 3) !== '220') {
            $this->error = ['error' => 'SMTP Server invalid banner: ' . $announce];
            $this->close();
            return false;
        }

        return true;
    }

    public function sendCommand($command, $expectedCode) {
        if (!is_resource($this->smtp_conn)) {
            $this->error = ['error' => 'No active SMTP connection'];
            return false;
        }

        fwrite($this->smtp_conn, $command . self::LE);
        $reply = $this->get_lines();
        $code = substr($reply, 0, 3);

        if ($code !== (string)$expectedCode) {
            $this->error = [
                'error' => "SMTP Command '{$command}' failed with code {$code}: {$reply}",
                'code' => $code,
                'reply' => $reply
            ];
            return false;
        }

        return $reply;
    }

    public function startTLS() {
        // Send EHLO before STARTTLS per RFC 3207
        $ehlo = $this->sendCommand('EHLO ' . gethostname(), 250);
        if ($ehlo === false) {
            $this->sendCommand('HELO ' . gethostname(), 250);
        }

        $reply = $this->sendCommand('STARTTLS', 220);
        if ($reply === false) {
            return false;
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        if (!@stream_socket_enable_crypto($this->smtp_conn, true, $cryptoMethod)) {
            $this->error = ['error' => 'Failed to enable TLS encryption on SMTP socket.'];
            return false;
        }

        // Send EHLO again after TLS encryption is established
        $ehlo2 = $this->sendCommand('EHLO ' . gethostname(), 250);
        if ($ehlo2 === false) {
            $this->sendCommand('HELO ' . gethostname(), 250);
        }

        return true;
    }

    public function authenticate($user, $pass) {
        // Send AUTH LOGIN
        $authResp = $this->sendCommand('AUTH LOGIN', 334);
        if ($authResp === false) {
            return false;
        }

        // Send Username
        $userResp = $this->sendCommand(base64_encode($user), 334);
        if ($userResp === false) {
            return false;
        }

        // Send Password
        $passResp = $this->sendCommand(base64_encode($pass), 235);
        if ($passResp === false) {
            return false;
        }

        return true;
    }

    public function connected() {
        if (is_resource($this->smtp_conn)) {
            $sock_status = stream_get_meta_data($this->smtp_conn);
            return !$sock_status['timed_out'];
        }
        return false;
    }

    public function close() {
        $this->error = [];
        if (is_resource($this->smtp_conn)) {
            @fwrite($this->smtp_conn, 'QUIT' . self::LE);
            fclose($this->smtp_conn);
            $this->smtp_conn = null;
        }
    }

    public function getError() {
        return $this->error;
    }

    protected function get_lines() {
        if (!is_resource($this->smtp_conn)) {
            return '';
        }
        $data = '';
        $endtime = time() + $this->Timeout;
        while (is_resource($this->smtp_conn) && !feof($this->smtp_conn)) {
            $str = @fgets($this->smtp_conn, 515);
            $data .= $str;
            if ((isset($str[3]) && $str[3] === ' ') || time() > $endtime) {
                break;
            }
        }
        $this->last_reply = trim($data);
        return $this->last_reply;
    }
}
