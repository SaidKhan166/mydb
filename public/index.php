<?php
require_once '../config/database.php';
require_once '../includes/Auth.php';
require_once '../includes/functions.php';

$auth = new Auth($conn);

if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
} else {
    header('Location: login.php');
    exit;
}

?>