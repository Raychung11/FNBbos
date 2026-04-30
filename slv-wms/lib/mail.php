<?php
// SLV WMS — lib/mail.php
// Purpose: minimal outbound-mail wrapper. Reads from + from-name from
//          app_settings (Settings → SMTP page) and sends via PHP's native
//          mail() function. Every attempt is logged to
//          storage/logs/outbound-mail.log so the operator can recover the
//          contents (e.g. a password-reset URL) if delivery fails — useful
//          before PHPMailer is dropped into vendor_local/ in Phase 12.
// Last updated: 2026-04-30

declare(strict_types=1);

/**
 * Send a plain-text email. Returns true if PHP's mail() handed it to
 * sendmail, false otherwise. Either way the body is written to the
 * outbound-mail log.
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $fromEmail = trim((string)setting('smtp.from_email', ''));
    $fromName  = trim((string)setting('smtp.from_name',  'SLV WMS'));

    if ($fromEmail === '') {
        // Best-effort default that won't pretend to come from a domain we
        // don't own. Operators should configure smtp.from_email in Settings.
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $fromEmail = 'noreply@' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);
    }

    $headers = [
        'From: ' . sprintf('"%s" <%s>', mb_encode_mimeheader($fromName), $fromEmail),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: SLV WMS',
    ];

    // Attempt delivery. mail() returns false on most shared hosts that
    // require explicit SMTP auth; we still want to log the body so the
    // operator can grab a reset URL by hand.
    $ok = false;
    try {
        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
    } catch (Throwable $e) {
        $ok = false;
    }

    _outbound_mail_log($to, $subject, $body, $ok);
    return $ok;
}

function _outbound_mail_log(string $to, string $subject, string $body, bool $ok): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = sprintf(
        "[%s] to=%s subject=%s sent_via_mail()=%s\n%s\n%s\n",
        date('c'), $to, $subject, $ok ? 'yes' : 'NO',
        $body,
        str_repeat('-', 60)
    );
    @file_put_contents($dir . '/outbound-mail.log', $line, FILE_APPEND | LOCK_EX);
}
