<?php
/* ── Shared header notification bell ─────────────────────────────────
   Single source of truth for the topbar bell used across admin pages.
   Behaviour: assets/js/admin.js (section 5). Styles: assets/css/admin.css.
   Mark-as-read: admin/api_notifications_read.php.
   Uses $notifData if the page already loaded it; otherwise loads it (fail-soft).
   ────────────────────────────────────────────────────────────── */
if (!isset($notifData) || !is_array($notifData)) {
    try {
        $notifData = rcGetRecentNotifications(8);
    } catch (Throwable $e) {
        $notifData = ['unread' => 0, 'items' => []];
    }
}
$__nbUnread = (int)($notifData['unread'] ?? 0);
$__nbItems = is_array($notifData['items'] ?? null) ? $notifData['items'] : [];
$__nbIcons = [
    'promotion' => 'fa-graduation-cap',
    'unit_test' => 'fa-pen-to-square',
    'followup'  => 'fa-phone-volume',
    'admission' => 'fa-user-plus',
    'whatsapp'  => 'fa-comment-dots',
];
?>
<div class="notif-container" id="notifContainer">
    <button type="button" class="notif-trigger<?= $__nbUnread > 0 ? ' has-unread' : '' ?>" id="notifTrigger"
            title="Recent activity notifications" aria-label="Notifications<?= $__nbUnread > 0 ? ' (' . $__nbUnread . ' unread)' : '' ?>"
            aria-haspopup="true" aria-expanded="false" aria-controls="notifDropdown">
        <i class="fas fa-bell" aria-hidden="true"></i>
        <?php if ($__nbUnread > 0): ?>
            <span class="notif-badge" id="notifBadge"><?= $__nbUnread > 99 ? '99+' : $__nbUnread ?></span>
        <?php endif; ?>
    </button>
    <div class="notif-dropdown" id="notifDropdown" role="menu" aria-label="Recent system activity">
        <div class="notif-dropdown-header">
            <span><i class="fas fa-bell" aria-hidden="true"></i> Recent System Activity</span>
            <span class="notif-count"><?= count($__nbItems) ?> event<?= count($__nbItems) === 1 ? '' : 's' ?></span>
        </div>
        <div class="notif-dropdown-list">
            <?php if (empty($__nbItems)): ?>
                <div class="notif-empty">
                    <i class="fas fa-bell-slash" aria-hidden="true"></i>
                    No recent notifications.
                </div>
            <?php else: ?>
                <?php foreach ($__nbItems as $nt):
                    $nbType = (string)($nt['type'] ?? '');
                    $nbIcon = $__nbIcons[$nbType] ?? 'fa-circle-info';
                    $nbTime = !empty($nt['created_at']) ? strtotime((string)$nt['created_at']) : false; ?>
                    <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item<?= empty($nt['is_read']) ? ' is-unread' : '' ?>" role="menuitem">
                        <span class="notif-item-icon" aria-hidden="true"><i class="fas <?= $nbIcon ?>"></i></span>
                        <span class="notif-item-body">
                            <span class="notif-item-title"><?= sanitize($nt['title'] ?? '') ?></span>
                            <span class="notif-item-desc"><?= sanitize($nt['message'] ?? '') ?></span>
                            <?php if ($nbTime): ?>
                                <span class="notif-item-time"><i class="far fa-clock" aria-hidden="true"></i> <?= date('M d, Y • h:i A', $nbTime) ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
