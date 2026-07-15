<?php
/**
 * Shared PDO connection + config loader.
 * MySQL can be slow to accept connections on first boot, so we retry briefly.
 */

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME') ?: 'hunted';
    $user = getenv('DB_USER') ?: 'hunted';
    $pass = getenv('DB_PASS') ?: 'huntedpass';
    $dsn  = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    $lastErr = null;
    for ($i = 0; $i < 30; $i++) {
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            ensure_schema($pdo);
            return $pdo;
        } catch (PDOException $e) {
            $lastErr = $e;
            sleep(1); // wait for the db container to come up
        }
    }

    http_response_code(503);
    exit('Database unavailable: ' . htmlspecialchars($lastErr ? $lastErr->getMessage() : 'unknown'));
}

/**
 * Apply small additive schema changes that postdate the initial db/init.sql.
 * init.sql only runs on a fresh data volume, so existing databases need their
 * new columns added here. Runs once per request; safe to call repeatedly.
 */
function ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $have = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participants'
               AND COLUMN_NAME IN ('disqualified', 'dq_reason', 'litter')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('disqualified', $have, true)) {
            $pdo->exec('ALTER TABLE participants ADD COLUMN disqualified TINYINT NOT NULL DEFAULT 0');
        }
        if (!in_array('dq_reason', $have, true)) {
            $pdo->exec('ALTER TABLE participants ADD COLUMN dq_reason VARCHAR(255) NULL');
        }
        if (!in_array('litter', $have, true)) {
            $pdo->exec('ALTER TABLE participants ADD COLUMN litter TINYINT NOT NULL DEFAULT 0');
        }
        // Discretionary points ledger. Created here for existing databases;
        // seed it from current discretionary balances so no points are lost.
        $hasAwards = (bool)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_awards'"
        )->fetchColumn();
        if (!$hasAwards) {
            $pdo->exec(
                'CREATE TABLE point_awards (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    participant_id INT NOT NULL,
                    points         INT NOT NULL,
                    reason         VARCHAR(255) NOT NULL DEFAULT \'\',
                    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
                    INDEX idx_award_participant (participant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $pdo->exec(
                "INSERT INTO point_awards (participant_id, points, reason)
                 SELECT id, discretionary, 'Existing balance' FROM participants WHERE discretionary <> 0"
            );
        }
    } catch (PDOException $e) {
        // Best-effort: if the DB user lacks ALTER, the disqualify feature is
        // unavailable but the rest of the app keeps working.
    }
}
