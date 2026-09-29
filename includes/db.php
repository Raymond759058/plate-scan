<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Shared PDO connection (created lazily, once per request). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, (int) DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepared statements
        ]);
    } catch (PDOException $e) {
        // Log privately. The message can contain the DB user name, so never re-throw it.
        error_log('DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database unavailable');
    }
    return $pdo;
}
