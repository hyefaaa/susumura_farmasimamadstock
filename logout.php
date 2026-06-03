<?php
// Mulakan session supaya boleh destroy
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Padamkan semua data sesi
$_SESSION = [];
session_unset();

// Padamkan cookie session dari browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect ke halaman login
header("Location: index.php");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
exit;
?>