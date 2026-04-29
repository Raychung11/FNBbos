<?php
/**
 * Public-facing demo request handler. Persists to the demo_requests table and
 * redirects back to the landing page with a flash. Honeypot field rejects bots
 * silently. CSRF is enforced.
 */

require __DIR__ . '/../src/bootstrap.php';

use FNBBOS\Csrf;
use FNBBOS\AuditLog;
use FNBBOS\Engine\Notifier;

if (requestMethod() !== 'POST') {
    redirect('index.php#demo');
}

Csrf::requireValid();

// Honeypot: bots tend to fill every input. If "website" is non-empty, pretend success.
if (trim((string)input('website', '')) !== '') {
    redirect('index.php?demo=ok#demo');
}

$fullName    = trim((string)input('full_name'));
$companyName = trim((string)input('company_name'));
$email       = trim((string)input('email'));
$phone       = trim((string)input('phone'));
$outletCount = asInt(input('outlet_count'));
$platforms   = trim((string)input('platforms'));
$message     = trim((string)input('message'));

if ($fullName === '' || $companyName === '' || $email === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect('index.php?demo=err#demo');
}
if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);

try {
    $stmt = db()->prepare('
        INSERT INTO demo_requests
            (full_name, company_name, email, phone, outlet_count, platforms, message,
             status, ip_address, user_agent, created_at)
        VALUES (?,?,?,?,?,?,?, "new", ?,?,?)');
    $stmt->execute([
        $fullName, $companyName, $email, $phone ?: null,
        $outletCount ?: null, $platforms ?: null, $message ?: null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        nowDb(),
    ]);
    $reqId = (int)db()->lastInsertId();

    AuditLog::record('demo_request.create', 'demo_request', $reqId, [
        'company' => $companyName, 'email' => $email,
    ]);

    $alerts = (array)config('notifications.operator_alerts', []);
    Notifier::dispatch(
        'demo.request',
        'New demo request',
        sprintf("%s from %s (%s) requested a demo.\nOutlets: %s\nPlatforms: %s\nMessage: %s",
            $fullName, $companyName, $email,
            $outletCount ?: 'n/a', $platforms ?: 'n/a',
            $message ?: '(none)'),
        [
            'entity'      => 'demo_request',
            'entity_id'   => $reqId,
            'channels'    => $alerts['channels']    ?? ['in_app'],
            'email_to'    => $alerts['email_to']    ?? null,
            'whatsapp_to' => $alerts['whatsapp_to'] ?? null,
        ]
    );

    redirect('index.php?demo=ok#demo');
} catch (Throwable $e) {
    error_log('demo-request failed: ' . $e->getMessage());
    redirect('index.php?demo=err#demo');
}
