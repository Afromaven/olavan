<?php
/**
 * Olavan Logout Script
 * Location: C:/xampp/htdocs/olavan/logout.php
 */

session_start();
session_destroy();
header("Location: index.php");
exit;
?>