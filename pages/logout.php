<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = [];
session_unset();
session_destroy();

// Redirect to login
header("Location: ../pages/login.php");
exit;
