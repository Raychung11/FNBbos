<?php
// SLV WMS — lib/csv.php
// Purpose: CSV reader/writer + chunked import runner.
//          Each importer is a small file under lib/import/<type>.php that
//          exposes:
//            csv_import_<type>_required_headers(): array<string>
//            csv_import_<type>_process_row(array $row, int $row_no, array $opts): array{ok:bool, error?:string}
// Roles allowed: n/a (callable from any super_admin/warehouse_manager handler)
// Last updated: 2026-04-29

declare(strict_types=1);

const CSV_IMPORT_CHUNK = 500;

/** Map of importer type -> human label. Source of truth for the upload UI. */
function csv_import_types(): array
{
    return [
        'products'       => 'Products / SKUs (with primary barcode)',
        'bins'           => 'Bins (warehouse_code,zone_code,rack_code,bin_code,...)',
        'opening_stock'  => 'Opening stock (warehouse_code,sku_code,bin_code,qty,unit_cost)',
    ];
}

/**
 * Read a CSV file and yield each row as a header-keyed assoc array.
 *
 * @param string $path absolute path
 * @return Generator<int, array<string,string>>
 */
function csv_read_assoc(string $path): Generator
{
    $h = fopen($path, 'rb');
    if ($h === false) {
        throw new RuntimeException("Cannot open CSV: $path");
    }
    try {
        // BOM strip
        $first = fgets($h);
        if ($first === false) return;
        if (substr($first, 0, 3) === "\xEF\xBB\xBF") {
            $first = substr($first, 3);
        }
        // re-parse the first line as the header row
        $headers = str_getcsv(rtrim($first, "\r\n"));
        $headers = array_map(fn($s) => strtolower(trim((string)$s)), $headers);

        $rowNo = 1;
        while (($cells = fgetcsv($h)) !== false) {
            $rowNo++;
            if ($cells === [null] || (count($cells) === 1 && trim((string)$cells[0]) === '')) {
                continue; // skip blank lines
            }
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
            }
            yield $rowNo => $row;
        }
    } finally {
        fclose($h);
    }
}

/** Count rows in a CSV (excluding the header row). Cheap streaming pass. */
function csv_count_rows(string $path): int
{
    $h = fopen($path, 'rb');
    if (!$h) return 0;
    $n = -1; // discount header
    while (!feof($h)) {
        $line = fgets($h);
        if ($line === false) break;
        if (trim((string)$line) === '') continue;
        $n++;
    }
    fclose($h);
    return max(0, $n);
}

/**
 * Validate that every required header is present in the CSV's first row.
 * Returns null on success, error message otherwise.
 */
function csv_validate_headers(string $path, array $required): ?string
{
    $h = fopen($path, 'rb');
    if (!$h) return 'Cannot open uploaded file.';
    $first = fgets($h);
    fclose($h);
    if ($first === false) return 'CSV is empty.';
    if (substr($first, 0, 3) === "\xEF\xBB\xBF") {
        $first = substr($first, 3);
    }
    $headers = array_map(fn($s) => strtolower(trim((string)$s)), str_getcsv(rtrim($first, "\r\n")));
    $missing = array_diff($required, $headers);
    if ($missing) {
        return 'Missing required column(s): ' . implode(', ', $missing);
    }
    return null;
}

/**
 * Append an error row to the job's error CSV. Lazily creates the file.
 */
function csv_record_error(int $job_id, int $row_no, array $row, string $error): void
{
    static $cache = [];
    if (!isset($cache[$job_id])) {
        $dir = dirname(__DIR__) . '/storage/imports';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $path = $dir . '/job-' . $job_id . '-errors.csv';
        $isNew = !is_file($path);
        $h = fopen($path, 'ab');
        if ($h && $isNew) {
            // Write a header row matching the source CSV plus a trailing _error column.
            fputcsv($h, array_merge(['_row_no'], array_keys($row), ['_error']));
        }
        $cache[$job_id] = $h;
        // Persist path on the job record once.
        db()->prepare('UPDATE import_jobs SET error_csv_path = ? WHERE id = ?')
            ->execute([$path, $job_id]);
    }
    if ($cache[$job_id]) {
        fputcsv($cache[$job_id], array_merge([$row_no], array_values($row), [$error]));
    }
}

/**
 * Run the next chunk of an import job. Each row is processed in its own
 * transaction (managed by the importer via db_tx) so a single bad row does
 * not roll back the whole chunk.
 *
 * Concurrency note: this is a single-operator admin action invoked from
 * the import UI. We don't lock the job row across the chunk — the UI calls
 * run.php sequentially, and the worst case (a second runner racing the
 * first) is a few duplicated rows reported as "already exists" errors,
 * which is harmless.
 *
 * @return array updated job row
 */
function csv_run_chunk(int $job_id): array
{
    $sel = db()->prepare(
        'SELECT * FROM import_jobs WHERE id = ? AND company_id = ?'
    );
    $sel->execute([$job_id, company_id()]);
    $job = $sel->fetch();
    if (!$job) {
        throw new RuntimeException("Import job $job_id not found.");
    }
    if (in_array($job['status'], ['COMPLETED', 'FAILED'], true)) {
        return $job;
    }

    $type = (string)$job['type'];
    $importerFile = __DIR__ . '/import/' . $type . '.php';
    if (!is_file($importerFile)) {
        db()->prepare(
            "UPDATE import_jobs SET status='FAILED', finished_at = NOW() WHERE id = ?"
        )->execute([$job_id]);
        throw new RuntimeException("No importer registered for type '$type'.");
    }
    require_once $importerFile;
    $processFn = "csv_import_{$type}_process_row";
    if (!function_exists($processFn)) {
        db()->prepare(
            "UPDATE import_jobs SET status='FAILED', finished_at = NOW() WHERE id = ?"
        )->execute([$job_id]);
        throw new RuntimeException("Importer for '$type' is malformed.");
    }

    // Mark RUNNING on the first chunk.
    if ($job['status'] === 'PENDING') {
        db()->prepare("UPDATE import_jobs SET status='RUNNING' WHERE id = ?")->execute([$job_id]);
        $job['status'] = 'RUNNING';
    }

    $opts = $job['options_json'] ? (json_decode((string)$job['options_json'], true) ?: []) : [];
    // Make job + uploader visible to row handlers so they can stamp
    // source_ref_id / user_id (e.g. opening-stock layers reference back
    // to the import_jobs row that created them).
    $opts['job_id']  = (int)$job['id'];
    $opts['user_id'] = (int)$job['created_by'];

    $processedBefore = (int)$job['processed_rows'];
    $successDelta    = 0;
    $errorDelta      = 0;
    $rowsThisChunk   = 0;
    $startRow        = $processedBefore + 2; // skip header (1) and already-processed rows

    foreach (csv_read_assoc($job['file_path']) as $rowNo => $data) {
        if ($rowNo < $startRow) continue;
        $rowsThisChunk++;

        try {
            $r = $processFn($data, $rowNo, $opts);
            if (!is_array($r) || !array_key_exists('ok', $r)) {
                throw new RuntimeException('Importer returned malformed result.');
            }
            if ($r['ok']) {
                $successDelta++;
            } else {
                $errorDelta++;
                csv_record_error($job_id, $rowNo, $data, (string)($r['error'] ?? 'Unknown error'));
            }
        } catch (Throwable $e) {
            // Make sure the importer didn't leave a tx open on our connection.
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $errorDelta++;
            csv_record_error($job_id, $rowNo, $data, $e->getMessage());
        }

        if ($rowsThisChunk >= CSV_IMPORT_CHUNK) break;
    }

    $newProcessed = $processedBefore + $rowsThisChunk;
    $newSuccess   = (int)$job['success_rows']   + $successDelta;
    $newErrors    = (int)$job['error_rows']     + $errorDelta;
    $isDone       = $newProcessed >= (int)$job['total_rows'];

    db()->prepare(
        'UPDATE import_jobs
            SET processed_rows = ?, success_rows = ?, error_rows = ?,
                status = ?, finished_at = ?
          WHERE id = ?'
    )->execute([
        $newProcessed, $newSuccess, $newErrors,
        $isDone ? 'COMPLETED' : 'RUNNING',
        $isDone ? date('Y-m-d H:i:s') : null,
        $job_id,
    ]);

    $job['processed_rows'] = $newProcessed;
    $job['success_rows']   = $newSuccess;
    $job['error_rows']     = $newErrors;
    $job['status']         = $isDone ? 'COMPLETED' : 'RUNNING';
    return $job;
}
