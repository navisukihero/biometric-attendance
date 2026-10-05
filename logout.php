<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Use the Logout button inside the system to end your session safely.');
    redirect(!empty($_SESSION['user_id']) ? 'app.php?page=dashboard' : 'index.php');
}
verify_csrf();

if (!empty($_SESSION['user_id'])) {
    log_activity($pdo, (int) $_SESSION['user_id'], 'Logged out');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
redirect('index.php');
