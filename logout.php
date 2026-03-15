<?php
session_start();
require_once 'includes/config.php';

// Clear persistent login
if (function_exists('clearPersistentLogin')) {
    $user_id = $_SESSION['user_id'] ?? null;
    clearPersistentLogin($user_id);
}

// Destroy user session
if (isset($_SESSION['user_id'])) {
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page
    header("Location: index.php");
    exit();
}

// Destroy admin session
if (isset($_SESSION['admin_id'])) {
    $_SESSION = array();
    session_destroy();
    header("Location: admin.php");
    exit();
}

// If no session exists, redirect to login
header("Location: index.php");
exit();
?>