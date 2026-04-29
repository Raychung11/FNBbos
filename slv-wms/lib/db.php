<?php
// SLV WMS — lib/db.php
// Purpose: PDO singleton. All DB access goes through db().
// Roles allowed: n/a (infrastructure)
// Last updated: 2026-04-29

declare(strict_types=1);

/**
 * Get the shared PDO connection. Lazy-connects on first call.
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $CONFIG;
    $c = $CONFIG['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int)($c['port'] ?? 3306),
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
              . "sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('[SLV WMS] DB connect failed: ' . $e->getMessage());
        header('Content-Type: text/plain; charset=utf-8');
        echo "SLV WMS: database unavailable. Check config/app.php and storage/logs/php-error.log.";
        exit;
    }

    return $pdo;
}

/**
 * Run a callable inside a DB transaction. Commits on success, rolls back on
 * exception. Supports nesting via savepoints.
 *
 * @template T
 * @param callable():T $fn
 * @return T
 */
function db_tx(callable $fn)
{
    $pdo = db();
    $owner = !$pdo->inTransaction();

    if ($owner) {
        $pdo->beginTransaction();
    } else {
        $pdo->exec('SAVEPOINT slvwms_sp');
    }

    try {
        $result = $fn($pdo);
        if ($owner) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT slvwms_sp');
        }
        return $result;
    } catch (Throwable $e) {
        if ($owner) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } else {
            $pdo->exec('ROLLBACK TO SAVEPOINT slvwms_sp');
        }
        throw $e;
    }
}
