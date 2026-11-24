<?php
// Usage: HCC_ASSISTANT_INPUT='{"message":"..."}' php tests/assistant_chat_cli.php
session_id('hcctest' . getmypid());
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'Test Admin';
$_SESSION['admin_role'] = 'admin';
$_SESSION['csrf_token'] = 'tok';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/hcc/admin/api_assistant_chat.php';
$_SERVER['HTTP_X_CSRF_TOKEN'] = getenv('TEST_CSRF') !== false ? getenv('TEST_CSRF') : 'tok';
require __DIR__ . '/../admin/api_assistant_chat.php';
