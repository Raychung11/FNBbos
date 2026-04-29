<?php
// SLV WMS — lib/docnum.php
// Purpose: Atomically allocate the next document number for a doc_type
//          (INV, DO, SO, GRN, PO, TRF, ADJ, CNT, ...).
//          Format read from document_sequences. NEVER hard-code formats.
// Roles allowed: n/a (called by transactional flows)
// Last updated: 2026-04-29

declare(strict_types=1);

/**
 * Allocate and return the next document number for ($company_id, $doc_type).
 *
 * Atomicity: SELECT ... FOR UPDATE inside a transaction. Caller may already be
 * inside a tx; db_tx() handles savepoint nesting.
 *
 * Format placeholders supported:
 *   {prefix}          — literal (left intact in template)
 *   {warehouse_code}  — pass via $context['warehouse_code']
 *   {YY}              — 2-digit year
 *   {YYYY}            — 4-digit year
 *   {MM}              — 2-digit month
 *   {seq:N}           — zero-padded sequence number, width N (1–8)
 *
 * @param string $doc_type  e.g. 'INV', 'GRN'
 * @param array  $context   optional — currently only 'warehouse_code'
 * @return string  formatted document number
 */
function next_doc_no(string $doc_type, array $context = []): string
{
    $company_id = company_id();
    $year = (int)date('Y');

    return db_tx(function (PDO $pdo) use ($company_id, $doc_type, $context, $year) {
        $sel = $pdo->prepare(
            'SELECT id, format_template, current_seq, reset_yearly, last_reset_year
               FROM document_sequences
              WHERE company_id = ? AND doc_type = ?
              FOR UPDATE'
        );
        $sel->execute([$company_id, $doc_type]);
        $row = $sel->fetch();

        if (!$row) {
            throw new RuntimeException(
                "Document sequence not configured for doc_type='{$doc_type}'."
              . " Add a row to document_sequences."
            );
        }

        $seq = (int)$row['current_seq'];
        $reset_year = (int)$row['last_reset_year'];

        if ((int)$row['reset_yearly'] === 1 && $reset_year !== $year) {
            $seq = 0;
            $reset_year = $year;
        }
        $seq += 1;

        $upd = $pdo->prepare(
            'UPDATE document_sequences
                SET current_seq = ?, last_reset_year = ?
              WHERE id = ?'
        );
        $upd->execute([$seq, $reset_year, $row['id']]);

        return docnum_render($row['format_template'], $seq, $context);
    });
}

/**
 * Render a format_template using the supplied seq + context.
 * Pure function — exposed for tests and previews.
 */
function docnum_render(string $template, int $seq, array $context = []): string
{
    $now  = time();
    $YY   = date('y', $now);
    $YYYY = date('Y', $now);
    $MM   = date('m', $now);

    $out = strtr($template, [
        '{YY}'             => $YY,
        '{YYYY}'           => $YYYY,
        '{MM}'             => $MM,
        '{warehouse_code}' => (string)($context['warehouse_code'] ?? ''),
        '{prefix}'         => (string)($context['prefix'] ?? ''),
    ]);

    $out = preg_replace_callback(
        '/\{seq:(\d{1,2})\}/',
        function ($m) use ($seq) {
            $w = max(1, min(12, (int)$m[1]));
            return str_pad((string)$seq, $w, '0', STR_PAD_LEFT);
        },
        $out
    );

    return $out ?? '';
}
