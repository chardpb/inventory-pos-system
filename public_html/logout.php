<?php
session_start();

// Unset all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear Remember Me Cookie
if (isset($_COOKIE['remember_token'])) {
    unset($_COOKIE['remember_token']);
    setcookie('remember_token', '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Redirect straight to login.php (NOT index.php)
header("Location: login.php");
exit();
?>