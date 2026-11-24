<?php
require_once __DIR__ . '/includes/functions.php';
$target = !empty($_SESSION['admin_id']) ? (BASE_URL . 'admin/dashboard.php') : (BASE_URL . 'admin/login.php');
header('Location: ' . $target);
exit;
