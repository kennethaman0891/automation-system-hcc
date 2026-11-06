<?php
/** Fail if any chat id is outside the fixture allowlist. */
function e2eAssertFixtureChat(PDO $db, int $chatId, array $allowedWa): void {
    $st = $db->prepare('SELECT wa_chat_id, name FROM whatsapp_chats WHERE id = ?');
    $st->execute([$chatId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("chat $chatId missing");
    $wa = strtolower((string)$row['wa_chat_id']);
    $ok = array_map('strtolower', $allowedWa);
    if (!in_array($wa, $ok, true)) {
        throw new RuntimeException("REFUSED non-fixture chat #{$chatId} {$row['name']} {$row['wa_chat_id']}");
    }
}
