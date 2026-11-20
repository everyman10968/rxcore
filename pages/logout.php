<?php
/**
 * RxCore — Logout Action
 */

require_once dirname(__DIR__) . '/config/app.php';

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
rx_clear_auth_cookie();
@session_destroy();

header('Location: ' . url('pages/login.php'));
exit;
