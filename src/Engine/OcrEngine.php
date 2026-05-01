<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * OCR receipt reader. Pluggable provider: defaults to OCR.space (free tier
 * with an API key). When OCR is disabled or the provider is unreachable,
 * extract() returns null and the rest of the system treats the claim as
 * "no OCR data available" — manual verification only.
 *
 * Returned shape:
 *   [
 *     'merchant'    => string|null,
 *     'receipt_no'  => string|null,
 *     'date'        => string|null  (YYYY-MM-DD),
 *     'total'       => float|null,
 *     'sst'         => float|null,
 *     'payment'     => string|null,
 *     'items'       => string[]      (best-effort line items),
 *     'raw_text'    => string,
 *   ]
 */
final class OcrEngine
{
    public static function extract(string $filePath): ?array
    {
        $cfg = (array)\config('ocr', []);
        if (empty($cfg['enabled'])) return null;
        if (!is_readable($filePath)) return null;

        $provider = (string)($cfg['provider'] ?? 'noop');
        if ($provider === 'ocr_space') {
            $rawText = self::callOcrSpace($filePath, $cfg);
            if ($rawText === null) return null;
            return self::mine($rawText);
        }
        return null;
    }

    /**
     * Pull structured fields out of free-form receipt text. Rule-based; works
     * well for typical Malaysian F&B receipts (RM amounts, SST line, dates).
     */
    public static function mine(string $rawText): array
    {
        $text   = trim($rawText);
        $upper  = strtoupper($text);

        // --- Total amount: prefer the line that looks most like a grand total.
        $total = null;
        $totalCandidates = [];
        if (preg_match_all('/(?:GRAND\s*TOTAL|TOTAL\s*AMOUNT|TOTAL\s*DUE|TOTAL|AMOUNT\s*DUE)\b[^0-9\-]*(\d{1,5}(?:[.,]\d{2}))/i', $text, $m)) {
            foreach ($m[1] as $v) $totalCandidates[] = (float)str_replace(',', '.', $v);
        }
        if ($totalCandidates) {
            $total = max($totalCandidates);
        } elseif (preg_match_all('/RM\s*(\d{1,5}(?:[.,]\d{2}))/i', $text, $m)) {
            foreach ($m[1] as $v) $totalCandidates[] = (float)str_replace(',', '.', $v);
            if ($totalCandidates) $total = max($totalCandidates);
        }

        // --- SST / tax line.
        $sst = null;
        if (preg_match('/(?:SST|GST|SERVICE\s*TAX|TAX)\b[^0-9\-]*(\d{1,5}(?:[.,]\d{2}))/i', $text, $m)) {
            $sst = (float)str_replace(',', '.', $m[1]);
        }

        // --- Date: try a few common formats.
        $date = null;
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $text, $m)) {
            $date = $m[1];
        } elseif (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $text, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            if ($y < 100) $y += 2000;
            if (checkdate($mo, $d, $y)) $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        // --- Receipt number.
        $receiptNo = null;
        if (preg_match('/(?:RECEIPT|RCPT|INVOICE|INV|BILL|REF)\s*(?:NO|#|:)?\s*[:#]?\s*([A-Z0-9\-\/]{3,30})/i', $text, $m)) {
            $receiptNo = $m[1];
        }

        // --- Payment method (best effort).
        $payment = null;
        foreach (['CASH','VISA','MASTERCARD','MASTER CARD','AMEX','DEBIT','TOUCH','TNG','BOOST','GRABPAY','SHOPEEPAY','MAYBANK','CIMB','PUBLIC','DUITNOW','EWALLET','QR'] as $kw) {
            if (str_contains($upper, $kw)) { $payment = $kw; break; }
        }

        // --- Merchant: typically near the top. Take the first non-empty line
        // that has alphabetic characters and isn't a date/number.
        $merchant = null;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $clean = trim($line);
            if ($clean === '') continue;
            if (preg_match('/^[\d\W]+$/', $clean)) continue;
            $merchant = mb_substr($clean, 0, 120);
            break;
        }

        // --- Item lines: anything that looks like "<text> <amount>" before TOTAL.
        $items = [];
        $beforeTotal = preg_split('/(GRAND\s*TOTAL|TOTAL)/i', $text, 2)[0] ?? $text;
        foreach (preg_split('/\r\n|\r|\n/', $beforeTotal) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9\s\-\(\)\/&]{1,40})\s+(\d{1,4}(?:[.,]\d{2}))$/', trim($line), $m)) {
                $items[] = trim($m[1]) . ' ' . str_replace(',', '.', $m[2]);
                if (count($items) >= 30) break;
            }
        }

        return [
            'merchant'   => $merchant,
            'receipt_no' => $receiptNo,
            'date'       => $date,
            'total'      => $total,
            'sst'        => $sst,
            'payment'    => $payment,
            'items'      => $items,
            'raw_text'   => mb_substr($text, 0, 8000),
        ];
    }

    /**
     * POST the file to OCR.space and return the extracted text on success,
     * or null on any failure (network, API quota, parse error). Errors are
     * logged but never thrown — OCR is a best-effort enrichment.
     */
    private static function callOcrSpace(string $filePath, array $cfg): ?string
    {
        $apiKey = (string)($cfg['api_key'] ?? '');
        if ($apiKey === '') return null;

        $ch = curl_init('https://api.ocr.space/parse/image');
        $post = [
            'apikey'                  => $apiKey,
            'language'                => (string)($cfg['language'] ?? 'eng'),
            'OCREngine'               => (string)($cfg['engine'] ?? 2),
            'isTable'                 => 'true',
            'scale'                   => 'true',
            'detectOrientation'       => 'true',
            'file'                    => new \CURLFile($filePath),
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            error_log('OCR.space request failed: HTTP ' . (int)$code);
            return null;
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json) || !empty($json['IsErroredOnProcessing'])) {
            error_log('OCR.space parse error: ' . substr((string)$resp, 0, 500));
            return null;
        }
        $parts = [];
        foreach (($json['ParsedResults'] ?? []) as $res) {
            if (!empty($res['ParsedText'])) $parts[] = (string)$res['ParsedText'];
        }
        return $parts ? implode("\n", $parts) : null;
    }
}
