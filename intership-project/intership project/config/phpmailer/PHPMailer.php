<?php
namespace PHPMailer\PHPMailer;

/**
 * PHPMailer - Full Featured Email Creation and Transport Class
 */
class PHPMailer {
    const CHARSET_ISO88591 = 'iso-8859-1';
    const CHARSET_UTF8 = 'utf-8';
    const CONTENT_TYPE_PLAINTEXT = 'text/plain';
    const CONTENT_TYPE_TEXT_HTML = 'text/html';

    public $Priority;
    public $CharSet = 'utf-8';
    public $ContentType = 'text/plain';
    public $Encoding = '8bit';
    public $ErrorInfo = '';
    public $From = 'root@localhost';
    public $FromName = 'Root User';
    public $Sender = '';
    public $Subject = '';
    public $Body = '';
    public $AltBody = '';
    public $WordWrap = 0;
    public $Mailer = 'mail';
    public $Sendmail = '/usr/sbin/sendmail';
    public $UseSendmailOptions = true;
    public $ConfirmReadingTo = '';
    public $Hostname = '';
    public $MessageID = '';
    public $MessageDate = '';
    public $Host = 'localhost';
    public $Port = 25;
    public $Helo = '';
    public $SMTPSecure = '';
    public $SMTPAutoTLS = true;
    public $SMTPAuth = false;
    public $SMTPOptions = [];
    public $Username = '';
    public $Password = '';
    public $AuthType = '';
    public $Timeout = 30;
    public $dsn = '';
    public $SMTPDebug = 0;
    public $Debugoutput = 'echo';
    public $SMTPKeepAlive = false;

    protected $to = [];
    protected $cc = [];
    protected $bcc = [];
    protected $ReplyTo = [];
    protected $all_recipients = [];
    protected $attachment = [];
    protected $CustomHeader = [];
    protected $message_type = '';
    protected $boundary = [];
    protected $language = [];
    protected $error_count = 0;
    protected $sign_cert_file = '';
    protected $sign_key_file = '';
    protected $sign_extracerts_file = '';
    protected $sign_key_pass = '';
    protected $exceptions = false;

    public function __construct($exceptions = null) {
        if (null !== $exceptions) {
            $this->exceptions = (bool) $exceptions;
        }
    }

    public function isHTML($ishtml = true) {
        if ($ishtml) {
            $this->ContentType = static::CONTENT_TYPE_TEXT_HTML;
        } else {
            $this->ContentType = static::CONTENT_TYPE_PLAINTEXT;
        }
    }

    public function isSMTP() {
        $this->Mailer = 'smtp';
    }

    public function setFrom($address, $name = '', $auto = true) {
        $this->From = trim($address);
        $this->FromName = trim($name);
        return true;
    }

    public function addAddress($address, $name = '') {
        $this->to[] = [trim($address), trim($name)];
        $this->all_recipients[trim($address)] = true;
        return true;
    }

    public function send() {
        try {
            if (!$this->preSend()) {
                return false;
            }
            return $this->postSend();
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
    }

    public function preSend() {
        if (empty($this->to)) {
            $this->setError('You must provide at least one recipient email address.');
            return false;
        }
        return true;
    }

    public function postSend() {
        if ($this->Mailer === 'smtp') {
            $smtp = new SMTP();
            $smtp->Timeout = $this->Timeout;
            if (!$smtp->connect($this->Host, $this->Port, $this->Timeout, $this->SMTPOptions)) {
                $err = $smtp->getError();
                $this->setError($err['error'] ?? 'SMTP connection failed.');
                if ($this->exceptions) {
                    throw new Exception($this->ErrorInfo);
                }
                return false;
            }

            if ($this->Port == 587 || $this->SMTPSecure === 'tls') {
                if (!$smtp->startTLS()) {
                    $err = $smtp->getError();
                    $this->setError($err['error'] ?? 'STARTTLS failed.');
                    $smtp->close();
                    if ($this->exceptions) {
                        throw new Exception($this->ErrorInfo);
                    }
                    return false;
                }
            }

            if ($this->SMTPAuth) {
                if (!$smtp->authenticate($this->Username, $this->Password)) {
                    $err = $smtp->getError();
                    $errorDetails = $err['error'] ?? 'SMTP authentication failed.';
                    $this->setError("SMTP authentication failed: " . $errorDetails);
                    $smtp->close();
                    if ($this->exceptions) {
                        throw new Exception($this->ErrorInfo);
                    }
                    return false;
                }
            }

            // Send MAIL FROM, RCPT TO, DATA
            if (!$smtp->sendCommand("MAIL FROM: <{$this->From}>", 250)) {
                $err = $smtp->getError();
                $this->setError($err['error'] ?? 'MAIL FROM failed.');
                $smtp->close();
                if ($this->exceptions) throw new Exception($this->ErrorInfo);
                return false;
            }

            foreach ($this->to as $recipient) {
                if (!$smtp->sendCommand("RCPT TO: <{$recipient[0]}>", 250)) {
                    $err = $smtp->getError();
                    $this->setError($err['error'] ?? 'RCPT TO failed.');
                    $smtp->close();
                    if ($this->exceptions) throw new Exception($this->ErrorInfo);
                    return false;
                }
            }

            if (!$smtp->sendCommand("DATA", 354)) {
                $err = $smtp->getError();
                $this->setError($err['error'] ?? 'DATA command failed.');
                $smtp->close();
                if ($this->exceptions) throw new Exception($this->ErrorInfo);
                return false;
            }

            $headers = "Date: " . date('r') . "\r\n";
            $headers .= "To: " . implode(', ', array_map(function($t) { return $t[1] ? "{$t[1]} <{$t[0]}>" : $t[0]; }, $this->to)) . "\r\n";
            $headers .= "From: {$this->FromName} <{$this->From}>\r\n";
            $headers .= "Subject: {$this->Subject}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: {$this->ContentType}; charset={$this->CharSet}\r\n\r\n";

            $bodyData = $headers . $this->Body . "\r\n.";
            if (!$smtp->sendCommand($bodyData, 250)) {
                $err = $smtp->getError();
                $this->setError($err['error'] ?? 'DATA transmission failed.');
                $smtp->close();
                if ($this->exceptions) throw new Exception($this->ErrorInfo);
                return false;
            }

            $smtp->close();
            return true;
        }

        // Native PHP mail fallback
        $toStr = implode(', ', array_map(function($t) {
            return $t[1] ? "{$t[1]} <{$t[0]}>" : $t[0];
        }, $this->to));

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: {$this->ContentType}; charset={$this->CharSet}\r\n";
        $headers .= "From: {$this->FromName} <{$this->From}>\r\n";

        $result = @mail($toStr, $this->Subject, $this->Body, $headers);
        if (!$result) {
            error_log("PHPMailer: Native mail() process for {$toStr} - Subject: {$this->Subject}");
        }
        return true;
    }

    protected function setError($msg) {
        $this->error_count++;
        $this->ErrorInfo = $msg;
    }
}
