<?php
/**
 * Create isolated hcc_wa_test DB and seed fixture chats only.
 * READS schema from production via SHOW CREATE TABLE (no data copy of messages).
 * Never writes into production.
 */
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = __FILE__;
$_SERVER['HTTP_HOST'] = 'localhost';

$root = dirname(__DIR__, 2);
require $root . '/config/database.php';

// Connect WITHOUT db name override — we need prod connection only to SHOW CREATE,
// then switch. Force production name explicitly for the schema read.
$prodName = 'hcc_admin';
$testName = 'hcc_wa_test';

function pdo_to(string $dbName): PDO {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $sock = defined('DB_SOCKET') ? DB_SOCKET : '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
    $dsns = [];
    if ($sock && file_exists($sock)) {
        $dsns[] = "mysql:unix_socket={$sock};dbname={$dbName};charset=utf8mb4";
    }
    $dsns[] = "mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset=utf8mb4";
    $last = '';
    foreach ($dsns as $dsn) {
        try { return new PDO($dsn, DB_USER, DB_PASS, $options); }
        catch (Throwable $e) { $last = $e->getMessage(); }
    }
    // server-level (no db) for CREATE DATABASE
    $dsns2 = [];
    if ($sock && file_exists($sock)) $dsns2[] = "mysql:unix_socket={$sock};charset=utf8mb4";
    $dsns2[] = "mysql:host=127.0.0.1;port=3306;charset=utf8mb4";
    foreach ($dsns2 as $dsn) {
        try { return new PDO($dsn, DB_USER, DB_PASS, $options); }
        catch (Throwable $e) { $last = $e->getMessage(); }
    }
    throw new RuntimeException('PDO connect failed: ' . $last);
}

$server = pdo_to(''); // may fail if dbname required — retry
try {
    $server = pdo_to($prodName);
} catch (Throwable $e) {
    fwrite(STDERR, "prod connect failed\n");
    throw $e;
}

try {
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$testName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // App DB user may lack CREATE DATABASE; root must create hcc_wa_test once.
    fwrite(STDERR, "NOTE: CREATE DATABASE skipped/failed ({$e->getMessage()}). Assuming DB exists.\n");
}

$tables = [
    'whatsapp_chats',
    'whatsapp_messages',
    'whatsapp_outbound_queue',
    'whatsapp_sessions',
    'whatsapp_contacts',
    'whatsapp_message_templates',
    'whatsapp_media',
];

$creates = [];
foreach ($tables as $t) {
    $row = $server->query("SHOW CREATE TABLE `{$prodName}`.`{$t}`")->fetch(PDO::FETCH_NUM);
    if (!$row) throw new RuntimeException("missing table $t");
    $creates[$t] = $row[1];
}

$test = pdo_to($testName);
$test->exec("SET FOREIGN_KEY_CHECKS=0");
foreach ($tables as $t) {
    $test->exec("DROP TABLE IF EXISTS `{$t}`");
    // SHOW CREATE may include DB-qualified names — strip
    $sql = $creates[$t];
    $sql = preg_replace('/CREATE TABLE /', 'CREATE TABLE ', $sql, 1);
    $test->exec($sql);
}
$test->exec("SET FOREIGN_KEY_CHECKS=1");

// Seed fixtures ONLY
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/chats.json'), true);
$insChat = $test->prepare("INSERT INTO whatsapp_chats (wa_chat_id, name, chat_type, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())");
// Discover required columns
$cols = $test->query("SHOW COLUMNS FROM whatsapp_chats")->fetchAll(PDO::FETCH_COLUMN);
foreach ($fixtures as $key => $f) {
    $fields = ['wa_chat_id' => $f['wa_chat_id'], 'name' => $f['name']];
    if (in_array('chat_type', $cols, true)) $fields['chat_type'] = $f['chat_type'] ?? 'individual';
    if (in_array('created_at', $cols, true)) $fields['created_at'] = date('Y-m-d H:i:s');
    if (in_array('updated_at', $cols, true)) $fields['updated_at'] = date('Y-m-d H:i:s');
    $cList = implode(',', array_map(fn($c) => "`$c`", array_keys($fields)));
    $ph = implode(',', array_fill(0, count($fields), '?'));
    $test->prepare("INSERT INTO whatsapp_chats ($cList) VALUES ($ph)")->execute(array_values($fields));
}

// Session row
try {
    $test->exec("INSERT INTO whatsapp_sessions (session_name, is_connected, phone_number, updated_at) VALUES ('default', 0, NULL, NOW())");
} catch (Throwable $e) {
    // column variance — best effort
    $scols = $test->query("SHOW COLUMNS FROM whatsapp_sessions")->fetchAll(PDO::FETCH_COLUMN);
    $fields = ['session_name' => 'default'];
    if (in_array('is_connected', $scols, true)) $fields['is_connected'] = 0;
    if (in_array('updated_at', $scols, true)) $fields['updated_at'] = date('Y-m-d H:i:s');
    $cList = implode(',', array_map(fn($c) => "`$c`", array_keys($fields)));
    $ph = implode(',', array_fill(0, count($fields), '?'));
    $test->prepare("INSERT INTO whatsapp_sessions ($cList) VALUES ($ph)")->execute(array_values($fields));
}

// Starter templates if empty
try {
    $n = (int)$test->query("SELECT COUNT(*) FROM whatsapp_message_templates")->fetchColumn();
    if ($n === 0) {
        $sqlFile = $root . '/data/migrations/create_whatsapp_message_templates.sql';
        // table already exists; seed via migrate script starters if present
        $migrate = $root . '/admin/migrate_whatsapp_templates.php';
        if (is_readable($migrate)) {
            // Will run under WA_TEST_MODE later
        }
        $test->prepare("INSERT INTO whatsapp_message_templates (slug,title,category,scope,body,placeholders,is_starter,created_by,updated_by) VALUES (?,?,?,?,?,?,1,1,1)")
            ->execute(['e2e_hello','E2E Hello','custom','both','Hello {parent_name} — {student_name}','["parent_name","student_name"]']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "template seed skipped: {$e->getMessage()}\n");
}

$ids = $test->query("SELECT id, name, wa_chat_id FROM whatsapp_chats ORDER BY id")->fetchAll();
file_put_contents(__DIR__ . '/results/fixture_chats.json', json_encode($ids, JSON_PRETTY_PRINT));
echo "OK test DB {$testName} ready with " . count($ids) . " fixture chats\n";
foreach ($ids as $r) echo "  #{$r['id']} {$r['name']} {$r['wa_chat_id']}\n";


// Apply manual-send lock schema on test DB
$lockMig = $root . '/admin/migrate_whatsapp_manual_send_lock.php';
if (is_file($lockMig)) {
    // Re-run column ensures against test connection
    $cols = $test->query('SHOW COLUMNS FROM whatsapp_outbound_queue')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['origin','approved_by','approved_at','confirm_nonce'] as $col) {
        if (!in_array($col, $cols, true)) {
            if ($col === 'origin') $test->exec("ALTER TABLE whatsapp_outbound_queue ADD COLUMN origin VARCHAR(40) NULL");
            if ($col === 'approved_by') $test->exec("ALTER TABLE whatsapp_outbound_queue ADD COLUMN approved_by INT UNSIGNED NULL");
            if ($col === 'approved_at') $test->exec("ALTER TABLE whatsapp_outbound_queue ADD COLUMN approved_at TIMESTAMP NULL");
            if ($col === 'confirm_nonce') $test->exec("ALTER TABLE whatsapp_outbound_queue ADD COLUMN confirm_nonce VARCHAR(64) NULL");
        }
    }
    $type = (string)$test->query("SHOW COLUMNS FROM whatsapp_outbound_queue LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'];
    if (stripos($type, "'held'") === false) {
        $test->exec("ALTER TABLE whatsapp_outbound_queue MODIFY COLUMN status ENUM('queued','processing','sent','delivered','read','failed','cancelled','held','draft','blocked_auto','revoke_pending','revoked','revoke_failed') NOT NULL DEFAULT 'queued'");
    }
    echo "manual_send_lock schema applied on test DB\n";

// AI review drafts table
$airSql = file_get_contents($root . '/data/migrations/create_admission_ai_review_drafts.sql');
if ($airSql) {
    try { $test->exec($airSql); echo "admission_ai_review_drafts ready on test DB\n"; }
    catch (Throwable $e) { echo "admission_ai_review_drafts note: ".$e->getMessage()."\n"; }
}

}

// Provenance columns for AI review drafts
try {
    $cols = $test->query("SHOW COLUMNS FROM admission_ai_review_drafts")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        "analysis_engine" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_engine VARCHAR(20) NULL DEFAULT 'local'",
        "analysis_provider" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_provider VARCHAR(40) NULL",
        "analysis_model" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_model VARCHAR(120) NULL",
        "analysis_endpoint_host" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_endpoint_host VARCHAR(255) NULL",
    ];
    foreach ($add as $name => $sql) {
        if (!in_array($name, $cols, true)) { $test->exec($sql); }
    }
    echo "admission_ai_review provenance cols ready\n";
} catch (Throwable $e) { echo "provenance note: ".$e->getMessage()."\n"; }

// Empty settings table for AI engine prefs (API keys never stored here)
try {
    $test->exec("CREATE TABLE IF NOT EXISTS settings (
      id int(11) NOT NULL AUTO_INCREMENT,
      setting_key varchar(100) NOT NULL,
      setting_value text DEFAULT NULL,
      setting_type enum('text','textarea','image','number','boolean') DEFAULT 'text',
      updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (id),
      UNIQUE KEY setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "settings table ready (empty)\n";
} catch (Throwable $e) { echo "settings note: ".$e->getMessage()."\n"; }
