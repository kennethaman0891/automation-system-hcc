<?php
/**
 * Additive schema for manual-only WhatsApp send lock.
 * Adds origin/approved_by/approved_at/confirm_nonce and statuses held/draft/blocked_auto.
 * Safe to re-run.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../config/database.php';
$db = getDB();

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

echo "DB=" . DB_NAME . "\n";

// Expand status enum
$row = $db->query("SHOW COLUMNS FROM whatsapp_outbound_queue LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
$type = (string)($row['Type'] ?? '');
echo "status_type_before=$type\n";
$needed = ['held', 'draft', 'blocked_auto'];
$missing = [];
foreach ($needed as $s) {
    if (stripos($type, "'{$s}'") === false) $missing[] = $s;
}
if ($missing) {
    // Keep existing values + new ones
    $db->exec("ALTER TABLE whatsapp_outbound_queue
        MODIFY COLUMN `status` ENUM(
            'queued','processing','sent','delivered','read','failed','cancelled',
            'held','draft','blocked_auto','revoke_pending','revoked','revoke_failed'
        ) NOT NULL DEFAULT 'queued'");
    echo "status_enum_expanded\n";
} else {
    echo "status_enum_ok\n";
}

$cols = [
    'origin' => "ALTER TABLE whatsapp_outbound_queue ADD COLUMN `origin` VARCHAR(40) NULL DEFAULT NULL AFTER `dispatch_method`",
    'approved_by' => "ALTER TABLE whatsapp_outbound_queue ADD COLUMN `approved_by` INT UNSIGNED NULL DEFAULT NULL AFTER `origin`",
    'approved_at' => "ALTER TABLE whatsapp_outbound_queue ADD COLUMN `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_by`",
    'confirm_nonce' => "ALTER TABLE whatsapp_outbound_queue ADD COLUMN `confirm_nonce` VARCHAR(64) NULL DEFAULT NULL AFTER `approved_at`",
];
foreach ($cols as $name => $sql) {
    if (colExists($db, 'whatsapp_outbound_queue', $name)) {
        echo "col_$name exists\n";
    } else {
        $db->exec($sql);
        echo "col_$name added\n";
    }
}

// Index for dequeue filter
try {
    $db->exec("CREATE INDEX idx_wa_out_manual ON whatsapp_outbound_queue (status, origin, approved_by, scheduled_at)");
    echo "index_added\n";
} catch (Throwable $e) {
    echo "index_skip: " . $e->getMessage() . "\n";
}

echo "DONE\n";
