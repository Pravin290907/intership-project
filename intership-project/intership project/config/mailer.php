<?php
/**
 * Mailer Helper Module
 * Encapsulates PHPMailer transport using config/mail.php options.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Support both vendor/autoload.php and direct file inclusions
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
}

function sendResetPasswordMail($recipientEmail, $recipientName, $resetLink) {
    // Load config options from config/mail.php
    $mailConfig = require __DIR__ . '/mail.php';

    $smtpHost   = $mailConfig['host'] ?? 'smtp.gmail.com';
    $smtpPort   = $mailConfig['port'] ?? 587;
    $smtpSecure = $mailConfig['secure'] ?? 'tls';
    $smtpUser   = $mailConfig['username'] ?? '';
    $smtpPass   = $mailConfig['password'] ?? '';
    $mailFrom   = !empty($mailConfig['from']) ? $mailConfig['from'] : ($smtpUser ?: 'no-reply@campusrecruit.edu');
    $fromName   = $mailConfig['from_name'] ?? 'CampusRecruit Support';

    // 1. Verify SMTP Credentials
    if (empty($smtpUser) || empty($smtpPass)) {
        return [
            'success' => false,
            'type'    => 'smtp_unconfigured',
            'message' => 'Email could not be sent: Please update SMTP_USER and SMTP_PASS in .env or config/mail.php'
        ];
    }

    $mail = new PHPMailer(true);

    try {
        // Enforce Strict SMTP Transport
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;
        $mail->SMTPSecure = $smtpSecure;

        $mail->setFrom($mailFrom, $fromName);
        $mail->addAddress($recipientEmail, $recipientName);

        $mail->isHTML(true);
        $mail->Subject = 'Reset Your Password - CampusRecruit';
        $mail->Body    = getResetEmailTemplate($recipientName, $resetLink);
        $mail->AltBody = "Hello {$recipientName},\n\nYou requested to reset your password for your CampusRecruit account.\n\nPlease click or copy the following link into your browser to reset your password:\n{$resetLink}\n\nThis reset link is valid for 30 minutes.\n\nIf you did not request a password reset, please ignore this email.";

        $sent = $mail->send();

        if ($sent) {
            return [
                'success' => true,
                'type'    => 'mail_sent',
                'message' => 'Email sent successfully. Please check your inbox (or spam folder).'
            ];
        } else {
            error_log("PHPMailer error output: " . $mail->ErrorInfo);
            return [
                'success' => false,
                'type'    => 'mail_failed',
                'message' => 'Email transport error: ' . $mail->ErrorInfo
            ];
        }

    } catch (Exception $e) {
        $errorMsg = $e->errorMessage();
        error_log("PHPMailer Exception: " . $errorMsg);

        if (strpos(strtolower($errorMsg), 'auth') !== false || strpos(strtolower($errorMsg), '535') !== false) {
            return [
                'success' => false,
                'type'    => 'smtp_auth_failed',
                'message' => 'SMTP Authentication Failed: Incorrect Gmail address or App Password. (If using Gmail, please ensure 2-Factor Authentication is ON and a 16-character App Password is generated under Google Security).'
            ];
        }

        return [
            'success' => false,
            'type'    => 'mail_failed',
            'message' => 'SMTP Error: ' . $errorMsg
        ];
    } catch (\Throwable $e) {
        error_log("Throwable in mailer: " . $e->getMessage());
        return [
            'success' => false,
            'type'    => 'mail_failed',
            'message' => 'SMTP Exception: ' . $e->getMessage()
        ];
    }
}

function getResetEmailTemplate($userName, $resetLink) {
    $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Your Password</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0F172A; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #F8FAFC;">
  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;">
    <tr>
      <td align="center" style="padding: 40px 15px;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #1E293B; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.1); overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
          
          <!-- Header -->
          <tr>
            <td align="center" style="padding: 32px 24px 16px 24px; background: linear-gradient(135deg, #1E293B 0%, #0F172A 100%); border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
              <div style="font-size: 24px; font-weight: 800; color: #2563EB; letter-spacing: -0.5px;">
                🎓 Campus<span style="color: #06B6D4;">Recruit</span>
              </div>
            </td>
          </tr>

          <!-- Body Content -->
          <tr>
            <td style="padding: 32px 32px 24px 32px; color: #E2E8F0;">
              <h2 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #FFFFFF; text-align: center;">Password Reset Request</h2>
              <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6; color: #94A3B8;">
                Hello <strong style="color: #FFFFFF;">{$safeName}</strong>,
              </p>
              <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #94A3B8;">
                We received a request to reset your password for your Campus Recruitment System account. Click the button below to set a new password:
              </p>

              <!-- CTA Button -->
              <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 28px;">
                <tr>
                  <td align="center">
                    <a href="{$safeLink}" target="_blank" style="display: inline-block; padding: 14px 32px; background-color: #2563EB; color: #FFFFFF; font-size: 15px; font-weight: 700; text-decoration: none; border-radius: 8px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);">Reset Password</a>
                  </td>
                </tr>
              </table>

              <p style="margin: 0 0 12px 0; font-size: 13px; line-height: 1.5; color: #64748B;">
                Or copy and paste this URL into your browser:
              </p>
              <div style="padding: 12px; background-color: #0F172A; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.05); font-size: 12px; word-break: break-all; color: #38BDF8; font-family: monospace;">
                {$safeLink}
              </div>

              <div style="margin-top: 24px; padding: 12px; background-color: rgba(234, 179, 8, 0.1); border-left: 4px solid #EAB308; border-radius: 4px; font-size: 13px; color: #FDE047;">
                ⏱️ This link is valid for <strong>30 minutes</strong>. If you did not request this reset, you can safely ignore this email.
              </div>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td align="center" style="padding: 20px 24px; background-color: #0F172A; border-top: 1px solid rgba(255, 255, 255, 0.05); font-size: 12px; color: #64748B;">
              © Campus Recruitment System • Secure Automated Notification
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}
