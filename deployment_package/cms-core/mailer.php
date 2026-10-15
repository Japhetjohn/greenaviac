<?php
/**
 * Green AVIC CMS — Native Secure SMTP Mailer
 * 
 * Lightweight, zero-dependency SMTP client for Hostinger PHP 8.3.
 * Supports SSL (Port 465) and STARTTLS (Port 587) with RFC-compliant headers.
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';

class GreenAvicMailer {
    private string $host;
    private int $port;
    private string $security; // 'ssl', 'tls', or 'none'
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private int $timeout;

    public function __construct() {
        $this->host      = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.hostinger.com';
        $this->port      = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
        $this->security  = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'ssl';
        $this->username  = defined('SMTP_USER') ? SMTP_USER : '';
        $this->password  = defined('SMTP_PASS') ? SMTP_PASS : '';
        $this->fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (defined('SMTP_USER') ? SMTP_USER : 'newsletter@greenavic.org');
        $this->fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Green AVIC';
        $this->timeout   = 15;
    }

    public function isConfigured(): bool {
        return !empty($this->host) && !empty($this->username) && !empty($this->password);
    }

    /**
     * Send an HTML email via SMTP
     * 
     * @param string $to Email address
     * @param string $subject Subject line
     * @param string $htmlBody HTML content
     * @param string|null $unsubscribeUrl One-click unsubscribe URL
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $unsubscribeUrl = null): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'SMTP is not configured in cms-core/.env'];
        }

        $hostPrefix = ($this->security === 'ssl') ? 'ssl://' : '';
        $connectionAddress = $hostPrefix . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ]
        ]);

        $socket = @stream_socket_client(
            $connectionAddress,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            return ['success' => false, 'error' => "Failed to connect to SMTP server: {$errstr} ({$errno})"];
        }

        stream_set_timeout($socket, $this->timeout);

        // Read server banner
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 220)) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP greeting failed: {$response}"];
        }

        // Send EHLO
        $clientHost = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'greenavic.org';
        $this->sendCommand($socket, "EHLO {$clientHost}");
        $response = $this->readResponse($socket);

        // Handle STARTTLS for Port 587
        if ($this->security === 'tls') {
            $this->sendCommand($socket, "STARTTLS");
            $response = $this->readResponse($socket);
            if (!$this->isOkResponse($response, 220)) {
                fclose($socket);
                return ['success' => false, 'error' => "STARTTLS failed: {$response}"];
            }

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'error' => "TLS handshake failed"];
            }

            // Re-send EHLO after TLS negotiation
            $this->sendCommand($socket, "EHLO {$clientHost}");
            $this->readResponse($socket);
        }

        // Authenticate (AUTH LOGIN)
        $this->sendCommand($socket, "AUTH LOGIN");
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 334)) {
            fclose($socket);
            return ['success' => false, 'error' => "AUTH LOGIN rejected: {$response}"];
        }

        $this->sendCommand($socket, base64_encode($this->username));
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 334)) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP Username rejected: {$response}"];
        }

        $this->sendCommand($socket, base64_encode($this->password));
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 235)) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP Password authentication failed: {$response}"];
        }

        // MAIL FROM
        $this->sendCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 250)) {
            fclose($socket);
            return ['success' => false, 'error' => "MAIL FROM rejected: {$response}"];
        }

        // RCPT TO
        $this->sendCommand($socket, "RCPT TO:<{$to}>");
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 250)) {
            fclose($socket);
            return ['success' => false, 'error' => "Recipient {$to} rejected: {$response}"];
        }

        // DATA
        $this->sendCommand($socket, "DATA");
        $response = $this->readResponse($socket);
        if (!$this->isOkResponse($response, 354)) {
            fclose($socket);
            return ['success' => false, 'error' => "DATA rejected: {$response}"];
        }

        // Build Email Headers & Body
        $messageId = sprintf('<%s.%s@%s>', bin2hex(random_bytes(16)), time(), $clientHost);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';

        $headers = [
            "Date: " . date('r'),
            "To: <{$to}>",
            "From: {$encodedFromName} <{$this->fromEmail}>",
            "Reply-To: {$encodedFromName} <{$this->fromEmail}>",
            "Subject: {$encodedSubject}",
            "Message-ID: {$messageId}",
            "X-Mailer: Green AVIC CMS Mailer",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64"
        ];

        if ($unsubscribeUrl) {
            $headers[] = "List-Unsubscribe: <{$unsubscribeUrl}>";
            $headers[] = "List-Unsubscribe-Post: List-Unsubscribe=One-Click";
        }

        $emailData = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";
        
        $this->sendCommand($socket, $emailData);
        $response = $this->readResponse($socket);

        $this->sendCommand($socket, "QUIT");
        fclose($socket);

        if ($this->isOkResponse($response, 250)) {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => "SMTP Delivery Error: {$response}"];
    }

    private function sendCommand($socket, string $command): void {
        fwrite($socket, $command . "\r\n");
    }

    private function readResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // Response line format: '250-...' means multi-line, '250 ...' means last line
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    }

    private function isOkResponse(string $response, int $expectedCode): bool {
        return (int)substr($response, 0, 3) === $expectedCode;
    }
}
