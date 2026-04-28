<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Notification dispatcher. Persists every alert to the notifications table and
 * optionally sends WhatsApp via Evolution API and email via mail(). When the
 * Evolution API or mail transport is disabled, alerts are still recorded so the
 * UI can show them in-app.
 *
 * Channels:
 *   - in_app   (always)
 *   - whatsapp (via Evolution API)
 *   - email
 */
final class Notifier
{
    public const EVENT_CRITICAL_CLAIM      = 'claim.critical';
    public const EVENT_CLAIM_APPROVED      = 'claim.approved';
    public const EVENT_CLAIM_REJECTED      = 'claim.rejected';
    public const EVENT_SETTLEMENT_MISMATCH = 'settlement.mismatch';
    public const EVENT_MISSING_SETTLEMENT  = 'settlement.missing';
    public const EVENT_BUDGET_EXCEEDED     = 'budget.exceeded';
    public const EVENT_ABNORMAL_PURCHASE   = 'purchase.abnormal';

    public static function dispatch(string $event, string $title, string $message, array $opts = []): void
    {
        $userId    = $opts['user_id']    ?? null;
        $companyId = $opts['company_id'] ?? null;
        $entity    = $opts['entity']     ?? null;
        $entityId  = $opts['entity_id']  ?? null;
        $channels  = $opts['channels']   ?? ['in_app'];

        try {
            $stmt = \db()->prepare('
                INSERT INTO notifications
                  (company_id, user_id, event, title, message, channels, entity_type, entity_id, read_at, created_at)
                VALUES (?,?,?,?,?,?,?,?,NULL,?)');
            $stmt->execute([
                $companyId, $userId, $event, $title, $message,
                implode(',', $channels), $entity, $entityId, \nowDb(),
            ]);
        } catch (\Throwable $e) {
            error_log('Notifier persist failed: ' . $e->getMessage());
        }

        if (in_array('whatsapp', $channels, true)) self::sendWhatsApp($opts['whatsapp_to'] ?? null, $title, $message);
        if (in_array('email',    $channels, true)) self::sendEmail($opts['email_to'] ?? null, $title, $message);
    }

    private static function sendWhatsApp(?string $to, string $title, string $message): void
    {
        $cfg = \config('notifications.evolution_api');
        if (empty($cfg['enabled']) || !$to) return;

        $url = rtrim((string)$cfg['base_url'], '/') . '/message/sendText/' . urlencode((string)$cfg['instance']);
        $payload = json_encode([
            'number'  => $to,
            'text'    => "*{$title}*\n{$message}",
            'options' => ['delay' => 800],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: ' . (string)$cfg['api_key'],
            ],
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('WhatsApp dispatch failed: ' . curl_error($ch));
        }
        curl_close($ch);
    }

    private static function sendEmail(?string $to, string $title, string $message): void
    {
        $cfg = \config('notifications.email');
        if (empty($cfg['enabled']) || !$to) return;
        $headers = 'From: ' . (string)$cfg['from'] . "\r\nContent-Type: text/plain; charset=utf-8\r\n";
        @mail($to, $title, $message, $headers);
    }
}
