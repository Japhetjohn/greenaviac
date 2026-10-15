<?php
/**
 * Green AVIC CMS — PHPMailer SMTP Client
 * 
 * Production-ready SMTP transport utilizing official bundled PHPMailer (PHP 8.3 compatible).
 * Zero Composer dependencies needed on server.
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';

// Include bundled official PHPMailer
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

class GreenAvicMailer {
    private string $host;
    private int $port;
    private string $security;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct() {
        $this->host      = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.hostinger.com';
        $this->port      = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
        $this->security  = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'ssl';
        $this->username  = defined('SMTP_USER') ? SMTP_USER : 'newsletter@greenavic.org';
        $this->password  = defined('SMTP_PASS') ? SMTP_PASS : '';
        $this->fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'newsletter@greenavic.org';
        $this->fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Green AVIC';
    }

    public function isConfigured(): bool {
        return !empty($this->host) && !empty($this->username) && !empty($this->password) && $this->password !== 'YOUR_HOSTINGER_EMAIL_PASSWORD';
    }

    /**
     * Create an initialized PHPMailer instance with Hostinger SMTP configuration
     */
    private function createMailerInstance(): PHPMailer {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $this->host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $this->username;
        $mail->Password   = $this->password;
        $mail->Port       = $this->port;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 20;

        if ($this->security === 'ssl' || $this->port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($this->security === 'tls' || $this->port === 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        // Hostinger certificate validation
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false
            ]
        ];

        $mail->setFrom($this->fromEmail, $this->fromName);
        $mail->addReplyTo($this->fromEmail, $this->fromName);

        return $mail;
    }

    /**
     * Send an HTML newsletter email via PHPMailer
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $unsubscribeUrl = null): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'SMTP credentials not configured in cms-core/.env'];
        }

        try {
            $mail = $this->createMailerInstance();
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

            if ($unsubscribeUrl) {
                $mail->addCustomHeader("List-Unsubscribe: <{$unsubscribeUrl}>");
                $mail->addCustomHeader("List-Unsubscribe-Post: List-Unsubscribe=One-Click");
            }

            $mail->send();
            return ['success' => true, 'error' => null];
        } catch (MailerException $e) {
            error_log("PHPMailer error sending to {$to}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            error_log("Unexpected mail error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a double opt-in verification link to confirm subscription
     */
    public function sendConfirmationEmail(string $to, string $confirmUrl): array {
        $subject = 'Please confirm your Green AVIC subscription';
        $logoUrl = rtrim(MAIN_SITE_URL, '/') . '/assets/images/brand/greenavic-logo-real.png';

        $html = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Confirm Your Subscription</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #F4F7F4; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
            <center style="width: 100%; background-color: #F4F7F4; padding: 32px 0;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #E6EAE7;">
                    <tr>
                        <td style="background-color: #0D3D2F; padding: 24px 32px; text-align: center;">
                            <img src="' . htmlspecialchars($logoUrl) . '" alt="Green AVIC" width="160" style="max-height: 44px; width: auto; display: block; margin: 0 auto;">
                            <div style="font-size: 11px; color: #B8F542; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-top: 8px;">
                                News &amp; Insights
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 36px 32px; color: #333333;">
                            <h1 style="color: #0D3D2F; font-size: 22px; font-weight: 700; margin: 0 0 16px;">Confirm Your Subscription</h1>
                            <p style="font-size: 15px; line-height: 1.6; color: #555555; margin: 0 0 24px;">
                                Thank you for your interest in Green AVIC! To complete your subscription and receive our latest publications on sustainable menstrual health and eco-innovation, please verify your email address:
                            </p>
                            <div style="text-align: center; margin: 32px 0;">
                                <a href="' . htmlspecialchars($confirmUrl) . '" target="_blank" style="background-color: #4CAF50; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 700; padding: 14px 36px; border-radius: 30px; display: inline-block;">
                                    Confirm My Subscription &rarr;
                                </a>
                            </div>
                            <p style="font-size: 13px; line-height: 1.5; color: #777777; margin: 24px 0 0;">
                                Or copy and paste this link into your browser:<br>
                                <a href="' . htmlspecialchars($confirmUrl) . '" style="color: #4CAF50; word-break: break-all;">' . htmlspecialchars($confirmUrl) . '</a>
                            </p>
                            <hr style="border: 0; border-top: 1px solid #EAEAEA; margin: 28px 0 16px;">
                            <p style="font-size: 12px; color: #999999; margin: 0; line-height: 1.5;">
                                If you did not request this subscription, no action is required and you will not be added to our mailing list.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #0D3D2F; color: #A0C0B7; padding: 18px 32px; text-align: center; font-size: 11px;">
                            Green AVIC &bull; Empowering women sustainably &bull; <a href="' . MAIN_SITE_URL . '" style="color: #B8F542; text-decoration: none;">greenavic.org</a>
                        </td>
                    </tr>
                </table>
            </center>
        </body>
        </html>
        ';

        return $this->send($to, $subject, $html);
    }
}
