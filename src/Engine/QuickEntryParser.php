<?php
declare(strict_types=1);

namespace FNBBOS\Engine;

/**
 * Free-text quick-entry parser. Takes a natural-language sentence and
 * extracts structured fields for a claim, a sales order, or a bank
 * transaction. Returns whatever it could confidently extract; the caller
 * shows a preview so the user confirms/edits before commit.
 *
 * Examples:
 *   Claim: "petrol RM 45 KL-01 shell yesterday cash"
 *   Sales: "grabfood KL-01 GF-000123 45.00 today"
 *   Bank : "credit RM 500 today MAIN-01 grabfood ref SETT-001"
 *
 * Nothing here is destructive — the parser only reads company lookups
 * (outlets, platforms, claim_types) to resolve tokens to codes/slugs.
 */
final class QuickEntryParser
{
    public static function parseClaim(string $text, int $companyId): array
    {
        $ctx = self::buildContext($companyId);
        $tokens = self::tokenise($text);

        $amount   = self::extractAmount($text);
        $date     = self::extractDate($text);
        $outlet   = self::extractCode($tokens, $ctx['outlets']);
        $supplier = self::extractSupplier($text);
        $type     = self::extractClaimType($text, $ctx['claim_types']);
        $payment  = self::extractPayment($text);

        return [
            'target'          => 'claim',
            'claim_type'      => $type,
            'amount'          => $amount,
            'outlet_code'     => $outlet,
            'supplier'        => $supplier,
            'claim_date'      => $date ?: date('Y-m-d'),
            'payment_method'  => $payment,
            'description'     => trim($text),
        ];
    }

    public static function parseSales(string $text, int $companyId): array
    {
        $ctx = self::buildContext($companyId);
        $tokens = self::tokenise($text);

        return [
            'target'      => 'sales',
            'platform_code' => self::extractCode($tokens, $ctx['platforms']),
            'outlet_code'   => self::extractCode($tokens, $ctx['outlets']),
            'order_id'      => self::extractRef($tokens),
            'order_date'    => self::extractDate($text) ?: date('Y-m-d'),
            'gross_sales'   => self::extractAmount($text),
        ];
    }

    public static function parseBank(string $text, int $companyId): array
    {
        $ctx = self::buildContext($companyId);
        $tokens = self::tokenise($text);
        $lower = strtolower($text);
        $direction = 'credit';
        if (str_contains($lower, 'debit') || str_contains($lower, 'charge') || str_contains($lower, 'fee')) {
            $direction = 'debit';
        }

        return [
            'target'         => 'bank',
            'value_date'     => self::extractDate($text) ?: date('Y-m-d'),
            'amount'         => self::extractAmount($text),
            'direction'      => $direction,
            'reference'      => self::extractRef($tokens, ['ref', 'reference']),
            'platform_code'  => self::extractCode($tokens, $ctx['platforms']),
            'outlet_code'    => self::extractCode($tokens, $ctx['outlets']),
        ];
    }

    // ---------- extractors ----------

    private static function extractAmount(string $text): ?float
    {
        if (preg_match_all('/(?:RM\s*)?(\d{1,7}(?:[.,]\d{1,2})?)/i', $text, $m)) {
            $best = null;
            foreach ($m[1] as $raw) {
                $v = (float)str_replace(',', '.', $raw);
                if ($v > 0 && ($best === null || $v > $best)) $best = $v;
            }
            return $best;
        }
        return null;
    }

    private static function extractDate(string $text): ?string
    {
        $lower = strtolower($text);
        if (preg_match('/\b(today|now)\b/', $lower))     return date('Y-m-d');
        if (str_contains($lower, 'yesterday'))           return date('Y-m-d', strtotime('-1 day'));
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) return $m[1];
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $text, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            if ($y < 100) $y += 2000;
            if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        // "15 jan", "15 january", "jan 15"
        $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec';
        if (preg_match('/\b(\d{1,2})\s+(' . $months . ')[a-z]*\b/i', $text, $m)
         || preg_match('/\b(' . $months . ')[a-z]*\s+(\d{1,2})\b/i', $text, $m)) {
            $ts = strtotime(implode(' ', array_slice($m, 1)) . ' ' . date('Y'));
            if ($ts !== false) return date('Y-m-d', $ts);
        }
        return null;
    }

    private static function extractCode(array $tokens, array $codes): ?string
    {
        if (!$codes) return null;
        $lookup = array_flip(array_map('strtolower', $codes));
        foreach ($tokens as $t) {
            if (isset($lookup[strtolower($t)])) return $codes[$lookup[strtolower($t)]];
        }
        return null;
    }

    private static function extractSupplier(string $text): ?string
    {
        if (preg_match('/@\s*([A-Za-z0-9][A-Za-z0-9 &\'\-\.]{1,60})/', $text, $m)) return trim($m[1]);
        if (preg_match('/\b(?:at|from|supplier)\s+([A-Z][A-Za-z0-9 &\'\-\.]{2,60})/', $text, $m)) return trim($m[1]);
        return null;
    }

    private static function extractClaimType(string $text, array $types): ?string
    {
        $lower = strtolower($text);
        // Common phrase → slug shortcuts.
        $shortcuts = [
            'petrol'      => 'petrol',
            'fuel'        => 'petrol',
            'gas'         => 'petrol',
            'meal'        => 'staff_meal',
            'lunch'       => 'staff_meal',
            'dinner'      => 'staff_meal',
            'breakfast'   => 'staff_meal',
            'transport'   => 'transport',
            'grab'        => 'transport',
            'taxi'        => 'transport',
            'supplier'    => 'supplier_purchase',
            'purchase'    => 'supplier_purchase',
            'petty'       => 'petty_cash',
            'marketing'   => 'marketing',
            'advert'      => 'marketing',
            'delivery'    => 'delivery',
            'repair'      => 'maintenance',
            'maintenance' => 'maintenance',
            'fix'         => 'maintenance',
            'voucher'     => 'voucher_redemption',
        ];
        foreach ($shortcuts as $kw => $slug) {
            if (str_contains($lower, $kw)) return $slug;
        }
        // Fall back to direct slug match.
        foreach ($types as $slug) {
            if (str_contains($lower, str_replace('_', ' ', $slug))) return $slug;
        }
        return null;
    }

    private static function extractPayment(string $text): ?string
    {
        $lower = strtolower($text);
        foreach (['cash','card','bank_transfer','bank transfer','ewallet','e-wallet','tng','grabpay','boost','duitnow'] as $kw) {
            if (str_contains($lower, $kw)) {
                return match ($kw) {
                    'bank transfer' => 'bank_transfer',
                    'e-wallet' => 'ewallet',
                    'tng','grabpay','boost','duitnow' => 'ewallet',
                    default => $kw,
                };
            }
        }
        return null;
    }

    /** Grabs an alphanumeric-with-dashes token that looks like a ref / order id. */
    private static function extractRef(array $tokens, array $hintKeywords = []): ?string
    {
        // First honour explicit hints: "ref XYZ" or "reference XYZ".
        $lowerTokens = array_map('strtolower', $tokens);
        foreach ($hintKeywords as $kw) {
            $i = array_search(strtolower($kw), $lowerTokens, true);
            if ($i !== false && isset($tokens[$i + 1])) return $tokens[$i + 1];
        }
        foreach ($tokens as $t) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9\-\/]{3,40}$/', $t) && preg_match('/[0-9]/', $t) && preg_match('/[A-Za-z]/', $t)) {
                return $t;
            }
        }
        return null;
    }

    private static function tokenise(string $text): array
    {
        // Split on whitespace and common punctuation, keep alphanumeric tokens.
        $parts = preg_split('/[\s,;:]+/', trim($text)) ?: [];
        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    private static function buildContext(int $companyId): array
    {
        $out = ['outlets' => [], 'platforms' => [], 'claim_types' => []];
        $o = \db()->prepare('SELECT code FROM outlets WHERE company_id = ?');
        $o->execute([$companyId]);
        $out['outlets'] = array_column($o->fetchAll(), 'code');

        $p = \db()->prepare('SELECT code FROM platforms WHERE company_id = ?');
        $p->execute([$companyId]);
        $out['platforms'] = array_column($p->fetchAll(), 'code');

        $ct = \db()->query('SELECT slug FROM claim_types');
        $out['claim_types'] = $ct ? array_column($ct->fetchAll(), 'slug') : [];
        return $out;
    }
}
