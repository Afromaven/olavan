<?php
/**
 * Olavan - Complete Admin Dashboard
 * Location: C:/xampp/htdocs/olavan/admin.php
 */

require_once 'db.php';
session_start();

// Prevent form resubmission on refresh
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] != 1) {
    header('Location: index.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

// Get admin details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

// Handle profile image upload
if (isset($_POST['upload_profile_image'])) {
    if ($_FILES['profile_image']['error'] == 0) {
        $target_dir = "uploads/images/";
        $file_extension = strtolower(pathinfo($_FILES["profile_image"]["name"], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed)) {
            $filename = 'admin_' . $admin_id . '_' . time() . '.' . $file_extension;
            $target_file = $target_dir . $filename;
            
            if ($admin['profile_image'] != 'uploads/images/default.jpg' && file_exists($admin['profile_image'])) {
                unlink($admin['profile_image']);
            }
            
            if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $target_file)) {
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$target_file, $admin_id]);
                $_SESSION['success_message'] = "✓ Profile image updated";
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$admin_id]);
                $admin = $stmt->fetch();
            } else {
                $_SESSION['error_message'] = "❌ Upload failed";
            }
        } else {
            $_SESSION['error_message'] = "❌ Invalid file type";
        }
    }
    header("Location: admin.php?section=profile");
    exit;
}

// Get statistics with proper counts
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn();
$total_admins = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();

// Payment statistics
$total_payments = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
$completed_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'completed'")->fetchColumn();
$unpaid_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'unpayed'")->fetchColumn();
$total_revenue = $pdo->query("SELECT SUM(amount_paid) FROM payments WHERE status = 'completed'")->fetchColumn();

// Get unread notifications count
$unread_count = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();

// Get recent notifications
$recent_notifications = $pdo->query("
    SELECT n.*, u.phone_number, u.full_name 
    FROM notifications n 
    JOIN users u ON n.user_id = u.id 
    ORDER BY n.created_at DESC 
    LIMIT 5
")->fetchAll();
// Handle status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Handle search
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$search_results = [];

if (!empty($search_term)) {
    $search_param = "%$search_term%";
    $stmt = $pdo->prepare("
        SELECT u.*, 
        (SELECT COUNT(*) FROM payments WHERE user_id = u.id) as total_payments,
        (SELECT SUM(amount_paid) FROM payments WHERE user_id = u.id) as total_spent
        FROM users u 
        WHERE u.phone_number LIKE ? OR u.full_name LIKE ? OR u.country LIKE ?
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$search_param, $search_param, $search_param]);
    $search_results = $stmt->fetchAll();
}

// Get all users (for regular view)
$users = $pdo->query("
    SELECT u.*, 
    (SELECT COUNT(*) FROM payments WHERE user_id = u.id) as total_payments,
    (SELECT SUM(amount_paid) FROM payments WHERE user_id = u.id) as total_spent
    FROM users u 
    ORDER BY u.created_at DESC
")->fetchAll();

// Get all payments with user details
$payments = $pdo->query("
    SELECT p.*, u.phone_number, u.full_name, u.country
    FROM payments p 
    JOIN users u ON p.user_id = u.id 
    ORDER BY p.created_at DESC
")->fetchAll();

// Get pending payments
$pending_subs = $pdo->query("
    SELECT p.*, u.phone_number, u.full_name, u.country 
    FROM payments p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.status = 'pending' 
    ORDER BY p.created_at ASC
")->fetchAll();

// Get expiring soon (next 7 days)
$expiring_soon = $pdo->query("
    SELECT p.*, u.phone_number, u.full_name, 
    DATEDIFF(p.end_date, CURDATE()) as days_left
    FROM payments p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.status = 'completed' 
    AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY p.end_date ASC
")->fetchAll();

// Get activity logs
$logs = $pdo->query("
    SELECT l.*, u.phone_number, u.full_name, u.profile_image
    FROM logs l 
    JOIN users u ON l.user_id = u.id 
    ORDER BY l.created_at DESC 
    LIMIT 50
")->fetchAll();

// Handle approve payment (one-click)
if (isset($_GET['approve_payment'])) {
    $payment_id = (int)$_GET['approve_payment'];
    
    $stmt = $pdo->prepare("SELECT p.*, u.id as uid FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();
    
    if ($payment) {
        // Update payment status to completed
        $update = $pdo->prepare("UPDATE payments SET status = 'completed' WHERE id = ?");
        $update->execute([$payment_id]);
        
        // Create notification for user
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'payment_approved', ?, ?)");
        $notif->execute([
            $payment['uid'],
            '✅ Payment Approved',
            "Your payment of " . number_format($payment['amount_paid'], 2) . " has been approved."
        ]);
        
        // Log activity
        $log = $pdo->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, 'payment_approved', ?)");
        $log->execute([$payment['uid'], "Payment approved by admin"]);
        
        $_SESSION['success_message'] = "✓ Payment approved successfully";
    }
    header("Location: admin.php?section=pending");
    exit;
}

// Handle reject payment (one-click)
if (isset($_GET['reject_payment'])) {
    $payment_id = (int)$_GET['reject_payment'];
    
    $stmt = $pdo->prepare("SELECT p.*, u.id as uid FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();
    
    if ($payment) {
        // Update payment status to unpayed
        $update = $pdo->prepare("UPDATE payments SET status = 'unpayed' WHERE id = ?");
        $update->execute([$payment_id]);
        
        // Create notification for user
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'payment_rejected', ?, ?)");
        $notif->execute([
            $payment['uid'],
            '❌ Payment Rejected',
            "Your payment has been rejected."
        ]);
        
        // Log activity
        $log = $pdo->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, 'payment_rejected', ?)");
        $log->execute([$payment['uid'], "Payment rejected by admin"]);
        
        $_SESSION['success_message'] = "✗ Payment rejected";
    }
    header("Location: admin.php?section=pending");
    exit;
}

// Handle delete payment
if (isset($_POST['delete_payment'])) {
    $payment_id = $_POST['payment_id'];
    
    // Get proof URL to delete file
    $stmt = $pdo->prepare("SELECT proof_url FROM payments WHERE id = ?");
    $stmt->execute([$payment_id]);
    $proof = $stmt->fetch();
    
    if ($proof && $proof['proof_url'] && file_exists($proof['proof_url'])) {
        unlink($proof['proof_url']);
    }
    
    $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$payment_id]);
    $_SESSION['success_message'] = "✓ Payment deleted";
    
    header("Location: admin.php?section=payments");
    exit;
}
//handler accept or reject user

// Handle user status change (accept/reject)
if (isset($_GET['accept_user'])) {
    $user_id = (int)$_GET['accept_user'];
    
    // Update user status to accepted
    $update = $pdo->prepare("UPDATE users SET status = 'accepted' WHERE id = ?");
    $update->execute([$user_id]);
    
    // Create notification for user
    $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'welcome', ?, ?)");
    $notif->execute([
        $user_id,
        '✅ Account Approved',
        'Your account has been approved by admin. You can now make payments.'
    ]);
    
    // Log activity
    $log = $pdo->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, 'user_accepted', ?)");
    $log->execute([$user_id, "User account approved by admin"]);
    
    $_SESSION['success_message'] = "✓ User accepted successfully";
    header("Location: admin.php?section=users");
    exit;
}

if (isset($_GET['reject_user'])) {
    $user_id = (int)$_GET['reject_user'];
    
    // Update user status to rejected
    $update = $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
    $update->execute([$user_id]);
    
    // Create notification for user
    $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'payment_rejected', ?, ?)");
    $notif->execute([
        $user_id,
        '❌ Account Rejected',
        'Your account has been rejected. Please contact support.'
    ]);
    
    // Log activity
    $log = $pdo->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, 'user_rejected', ?)");
    $log->execute([$user_id, "User account rejected by admin"]);
    
    $_SESSION['success_message'] = "✗ User rejected";
    header("Location: admin.php?section=users");
    exit;
}


// Handle delete user
if (isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    
    // Get user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user && $user['is_admin'] && $user_id == $admin_id) {
        $_SESSION['error_message'] = "❌ You cannot delete yourself";
    } else {
        // Get all proofs for this user
        $stmt = $pdo->prepare("SELECT proof_url FROM payments WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $proofs = $stmt->fetchAll();
        
        foreach ($proofs as $proof) {
            if ($proof['proof_url'] && file_exists($proof['proof_url'])) {
                unlink($proof['proof_url']);
            }
        }
        
        // Delete profile image if not default
        if ($user['profile_image'] != 'uploads/images/default.jpg' && file_exists($user['profile_image'])) {
            unlink($user['profile_image']);
        }
        
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        $_SESSION['success_message'] = "✓ User deleted";
    }
    
    header("Location: admin.php?section=users");
    exit;
}

// Handle add user
if (isset($_POST['add_user'])) {
    $phone = $_POST['phone'];
    $full_name = $_POST['full_name'];
    $country = $_POST['country'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    
    $check = $pdo->prepare("SELECT id FROM users WHERE phone_number = ?");
    $check->execute([$phone]);
    
    if ($check->rowCount() == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (phone_number, full_name, country, password_hash, is_admin, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$phone, $full_name, $country, $password, $is_admin]);
        
        $new_user_id = $pdo->lastInsertId();
        
        // Create welcome notification
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'welcome', ?, ?)");
        $notif->execute([
            $new_user_id,
            '👋 Welcome to Olavan',
            'Your account has been created by admin.'
        ]);
        
        $_SESSION['success_message'] = "✓ User added successfully";
    } else {
        $_SESSION['error_message'] = "❌ Phone number already exists";
    }
    
    header("Location: admin.php?section=users");
    exit;
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (!empty($new_password)) {
        if (password_verify($current_password, $admin['password_hash'])) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$full_name, $password_hash, $admin_id]);
            $_SESSION['success_message'] = "✓ Profile updated with new password";
        } else {
            $_SESSION['error_message'] = "❌ Current password is incorrect";
        }
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
        $stmt->execute([$full_name, $admin_id]);
        $_SESSION['success_message'] = "✓ Profile updated";
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    
    header("Location: admin.php?section=profile");
    exit;
}

// Handle backup creation
if (isset($_POST['create_backup'])) {
    $backup_dir = "backups/";
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    $backup_file = $backup_dir . 'olavan_backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Get all tables
    $tables = ['users', 'payments', 'notifications', 'logs'];
    $sql = "-- Olavan Database Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n-- Host: localhost\n-- Database: olavan\n\n";
    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\n\n";
    $sql .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
    $sql .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
    $sql .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
    $sql .= "/*!40101 SET NAMES utf8mb4 */;\n\n";
    
    foreach ($tables as $table) {
        // Get create table syntax
        $create_stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $create = $create_stmt->fetch();
        $sql .= "\n-- --------------------------------------------------------\n\n";
        $sql .= "-- Table structure for table `$table`\n\n";
        $sql .= $create['Create Table'] . ";\n\n";
        
        // Get data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            $sql .= "-- Dumping data for table `$table`\n\n";
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                $columns_str = "`" . implode("`, `", $columns) . "`";
                $values_str = "'" . implode("', '", array_map(function($value) use ($pdo) {
                    return is_null($value) ? 'NULL' : addslashes($value);
                }, $values)) . "'";
                
                $sql .= "INSERT INTO `$table` ($columns_str) VALUES ($values_str);\n";
            }
            $sql .= "\n";
        }
    }
    
    $sql .= "\n/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
    $sql .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
    $sql .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;";
    
    file_put_contents($backup_file, $sql);
    $_SESSION['success_message'] = "✓ Backup created successfully: " . basename($backup_file);
    
    header("Location: admin.php?section=backup");
    exit;
}

// Handle backup download
if (isset($_GET['download_backup'])) {
    $backup_file = 'backups/' . basename($_GET['download_backup']);
    if (file_exists($backup_file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backup_file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backup_file));
        readfile($backup_file);
        exit;
    }
}

// Handle backup delete
if (isset($_POST['delete_backup'])) {
    $backup_file = 'backups/' . basename($_POST['backup_file']);
    if (file_exists($backup_file)) {
        unlink($backup_file);
        $_SESSION['success_message'] = "✓ Backup deleted";
    }
    header("Location: admin.php?section=backup");
    exit;
}

// Mark notification as read
if (isset($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$notif_id]);
    header('Location: admin.php');
    exit;
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $pdo->query("UPDATE notifications SET is_read = 1");
    header('Location: admin.php');
    exit;
}

// Get backup files
$backup_files = glob('backups/*.sql');
usort($backup_files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

// Current section
$current_section = $_GET['section'] ?? 'dashboard';

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Favicons -->
    <link rel="apple-touch-icon" sizes="180x180" href="uploads/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="uploads/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="uploads/icons/favicon-16x16.png">
    <link rel="manifest" href="uploads/icons/site.webmanifest">
    <link rel="mask-icon" href="uploads/icons/safari-pinned-tab.svg" color="#d35400">
    <link rel="shortcut icon" href="uploads/icons/favicon.ico">
    <meta name="msapplication-TileColor" content="#d35400">
    <meta name="msapplication-TileImage" content="uploads/icons/android-chrome-192x192.png">
    <meta name="theme-color" content="#d35400">
    
    <title>Olavan — Admin Dashboard</title>
    <!-- Google Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- rest of your head -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        :root {
            --bg: #0a0a0a;
            --surface: #141414;
            --surface-light: #1e1e1e;
            --surface-lighter: #2a2a2a;
            --text: #f0f0f0;
            --text-secondary: #a0a0a0;
            --text-muted: #6c6c6c;
            --border: #2a2a2a;
            --border-light: #333;
            --accent: #d35400;
            --accent-hover: #e67e22;
            --accent-light: rgba(211, 84, 0, 0.15);
            --success: #2ecc71;
            --success-bg: rgba(46, 204, 113, 0.15);
            --warning: #f39c12;
            --warning-bg: rgba(243, 156, 18, 0.15);
            --danger: #e74c3c;
            --danger-bg: rgba(231, 76, 60, 0.15);
            --info: #3498db;
            --info-bg: rgba(52, 152, 219, 0.15);
            --input-bg: #1a1a1a;
            --sidebar-width: 280px;
        }

        [data-theme="light"] {
            --bg: #f5f5f5;
            --surface: #ffffff;
            --surface-light: #f0f0f0;
            --surface-lighter: #e8e8e8;
            --text: #1a1a1a;
            --text-secondary: #4a4a4a;
            --text-muted: #6c6c6c;
            --border: #e0e0e0;
            --border-light: #d0d0d0;
            --input-bg: #f8f8f8;
        }

        body {
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            transition: background 0.3s;
            overflow-x: hidden;
            
        }
a,
a:hover,
a:focus,
a:active,
a:visited {
    text-decoration: none;
    color: inherit;
}
        /* Hide scrollbar but keep functionality */
        body {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        body::-webkit-scrollbar {
            display: none;
        }

        /* HEADER */
        .header {
            background: var(--surface);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 90;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .menu-btn {
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-btn .material-icons {
            font-size: 28px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .logo .material-icons {
            color: var(--accent);
            font-size: 28px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* NOTIFICATIONS */
        .notif-wrapper {
            position: relative;
        }

        .notif-btn {
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            padding: 8px;
            position: relative;
        }

        .notif-btn .material-icons {
            font-size: 24px;
        }

        .notif-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: var(--accent);
            color: white;
            font-size: 11px;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        /* USER MENU */
        .user-menu-wrapper {
            position: relative;
        }

        .user-menu-btn {
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-menu-btn .material-icons {
            font-size: 24px;
        }

        .user-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            min-width: 200px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            display: none;
            z-index: 100;
            margin-top: 8px;
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-item {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text);
            text-decoration: none;
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item:hover {
            background: var(--surface-light);
        }

        .dropdown-item .material-icons {
            font-size: 20px;
            color: var(--text-secondary);
        }

        /* NOTIFICATIONS DROPDOWN */
        .notif-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            width: 350px;
            max-width: 90vw;
            max-height: 70vh;
            overflow-y: auto;
            display: none;
            z-index: 100;
            margin-top: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .notif-dropdown.show {
            display: block;
        }

        .notif-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-light);
            position: sticky;
            top: 0;
        }

        .notif-item {
            padding: 16px;
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
        }

        .notif-item.unread {
            background: var(--accent-light);
            border-left: 3px solid var(--accent);
        }

        .notif-item:hover {
            background: var(--surface-lighter);
        }

        /* SIDEBAR */
        .sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100vh;
            background: var(--surface);
            border-right: 1px solid var(--border);
            z-index: 1000;
            transition: left 0.3s ease;
            overflow-y: auto;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .sidebar::-webkit-scrollbar {
            display: none;
        }

        .sidebar.open {
            left: 0;
        }

        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-header .material-icons {
            font-size: 32px;
            color: var(--accent);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .sidebar-header small {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-left: auto;
        }

        .admin-profile {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .admin-avatar {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid var(--accent);
        }

        .admin-avatar-icon {
            font-size: 50px;
            color: var(--text-secondary);
        }

        .admin-info h3 {
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: var(--accent);
            color: white;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .admin-badge .material-icons {
            font-size: 14px;
        }

        .sidebar-menu {
            padding: 16px;
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 8px;
        }

        .sidebar-menu a {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .sidebar-menu a:hover {
            background: var(--surface-light);
            color: var(--text);
        }

        .sidebar-menu a.active {
            background: var(--accent-light);
            color: var(--accent);
        }

        .sidebar-menu .material-icons {
            font-size: 22px;
        }

        .menu-badge {
            margin-left: auto;
            background: var(--accent);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* MAIN CONTENT */
        .content {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* SEARCH BAR */
        .search-bar {
            margin-bottom: 24px;
            position: relative;
        }

        .search-bar .material-icons {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .search-bar input {
            width: 100%;
            padding: 16px 16px 16px 56px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 30px;
            font-size: 1rem;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .search-results-info {
            margin-bottom: 16px;
            padding: 12px 16px;
            background: var(--surface-light);
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            padding: 24px;
            border-radius: 20px;
            border: 1px solid var(--border);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--accent);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: var(--surface-light);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-icon .material-icons {
            font-size: 24px;
            color: var(--accent);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* CHARTS GRID */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: var(--surface);
            border-radius: 20px;
            border: 1px solid var(--border);
            padding: 24px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* CARDS */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .card-body {
            padding: 24px;
        }

        /* TABLES */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 16px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border);
            background: var(--surface-light);
        }

        td {
            padding: 16px;
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }

        tr:hover td {
            background: var(--surface-lighter);
        }

        /* BADGES */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-success {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .badge-warning {
            background: var(--warning-bg);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .badge-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .badge-info {
            background: var(--info-bg);
            color: var(--info);
            border: 1px solid var(--info);
        }

        /* BUTTONS */
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-outline:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface-light);
            color: var(--text);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-icon:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-icon.approve {
            color: var(--success);
            border-color: var(--success);
        }

        .btn-icon.reject {
            color: var(--danger);
            border-color: var(--danger);
        }

        .btn-icon.delete:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        /* FORMS */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper .material-icons {
            position: absolute;
            left: 12px;
            color: var(--text-muted);
            font-size: 20px;
        }

        .input-wrapper input,
        .input-wrapper select,
        .input-wrapper textarea {
            width: 100%;
            padding: 14px 14px 14px 48px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 12px;
            font-size: 0.95rem;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus,
        .input-wrapper textarea:focus {
            outline: none;
            border-color: var(--accent);
        }

        /* BACKUP LIST */
        .backup-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .backup-item {
            background: var(--surface-light);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .backup-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .backup-info .material-icons {
            color: var(--accent);
        }

        .backup-actions {
            display: flex;
            gap: 8px;
        }

        /* MESSAGES */
        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.success {
            background: var(--success-bg);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .message.error {
            background: var(--danger-bg);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        /* MODAL */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--surface);
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text);
            cursor: pointer;
            padding: 8px;
        }

        .close-btn .material-icons {
            font-size: 24px;
        }

        /* ACTION BUTTONS GROUP */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* IMAGE UPLOAD */
        .image-upload {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .image-upload:hover {
            border-color: var(--accent);
        }

        .image-upload .material-icons {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .image-upload input {
            display: none;
        }

        .avatar-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin: 0 auto 16px;
            overflow: hidden;
            border: 3px solid var(--accent);
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .content {
                padding: 16px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                width: 100%;
                justify-content: flex-end;
            }
            
            table {
                font-size: 0.85rem;
            }
            
            td, th {
                padding: 12px 8px;
            }
            
            .backup-info {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <span class="material-icons">pets</span>
            <h2>OLAVAN</h2>
            <small>v1.0</small>
        </div>
        
        <div class="admin-profile">
            <?php if ($admin['profile_image'] && file_exists($admin['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($admin['profile_image']); ?>" class="admin-avatar">
            <?php else: ?>
                <span class="material-icons admin-avatar-icon">account_circle</span>
            <?php endif; ?>
            <div class="admin-info">
                <h3><?php echo htmlspecialchars($admin['full_name'] ?? 'Admin'); ?></h3>
                <span class="admin-badge">
                    <span class="material-icons">shield</span> <span data-i18n="administrator">Administrator</span>
                </span>
            </div>
        </div>
        
        <ul class="sidebar-menu">
            <li><a onclick="showSection('dashboard'); closeSidebar()" class="<?php echo $current_section == 'dashboard' ? 'active' : ''; ?>" id="menuDashboard">
                <span class="material-icons">dashboard</span> <span data-i18n="dashboard">Dashboard</span>
            </a></li>
            <li><a onclick="showSection('users'); closeSidebar()" class="<?php echo $current_section == 'users' ? 'active' : ''; ?>" id="menuUsers">
                <span class="material-icons">people</span> <span data-i18n="users">Users</span>
            </a></li>
            <li><a onclick="showSection('payments'); closeSidebar()" class="<?php echo $current_section == 'payments' ? 'active' : ''; ?>" id="menuPayments">
                <span class="material-icons">payments</span> <span data-i18n="all_payments">All Payments</span>
            </a></li>
            <li><a onclick="showSection('pending'); closeSidebar()" class="<?php echo $current_section == 'pending' ? 'active' : ''; ?>" id="menuPending">
                <span class="material-icons">pending</span> <span data-i18n="pending">Pending</span>
                <?php if ($pending_payments > 0): ?>
                    <span class="menu-badge"><?php echo $pending_payments; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a onclick="showSection('expiring'); closeSidebar()" class="<?php echo $current_section == 'expiring' ? 'active' : ''; ?>" id="menuExpiring">
                <span class="material-icons">warning</span> <span data-i18n="expiring_soon">Expiring Soon</span>
                <?php if (count($expiring_soon) > 0): ?>
                    <span class="menu-badge"><?php echo count($expiring_soon); ?></span>
                <?php endif; ?>
            </a></li>
            <li><a onclick="showSection('add'); closeSidebar()" class="<?php echo $current_section == 'add' ? 'active' : ''; ?>" id="menuAdd">
                <span class="material-icons">person_add</span> <span data-i18n="add_user">Add User</span>
            </a></li>
            <li><a onclick="showSection('logs'); closeSidebar()" class="<?php echo $current_section == 'logs' ? 'active' : ''; ?>" id="menuLogs">
                <span class="material-icons">history</span> <span data-i18n="activity_logs">Activity Logs</span>
            </a></li>
            <li><a onclick="showSection('backup'); closeSidebar()" class="<?php echo $current_section == 'backup' ? 'active' : ''; ?>" id="menuBackup">
                <span class="material-icons">backup</span> <span data-i18n="backup">Backup</span>
            </a></li>
            <li><a onclick="showSection('profile'); closeSidebar()" class="<?php echo $current_section == 'profile' ? 'active' : ''; ?>" id="menuProfile">
                <span class="material-icons">person</span> <span data-i18n="profile">Profile</span>
            </a></li>
        </ul>
    </div>

    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <button class="menu-btn" onclick="toggleSidebar()">
                <span class="material-icons">menu</span>
            </button>
            <div class="logo">
                <span class="material-icons">pets</span>
                <span>OLAVAN <span data-i18n="admin">ADMIN</span></span>
            </div>
        </div>
        
        <div class="header-right">
            <!-- Notifications -->
            <div class="notif-wrapper">
                <button class="notif-btn" onclick="toggleNotifications()">
                    <span class="material-icons">notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <span class="material-icons" style="font-size: 20px;">notifications</span>
                            <span data-i18n="notifications">Notifications</span>
                        </span>
                        <?php if ($unread_count > 0): ?>
                            <a href="?mark_all_read=1" style="color: var(--accent); text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                <span class="material-icons" style="font-size: 18px;">done_all</span>
                                <span data-i18n="mark_all_read">Mark all read</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($recent_notifications)): ?>
                        <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
                            <span class="material-icons" style="font-size: 48px; margin-bottom: 10px;">notifications_off</span>
                            <p data-i18n="no_notifications">No new notifications</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_notifications as $notif): ?>
                            <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="window.location='?mark_read=<?php echo $notif['id']; ?>'">
                                <div style="display: flex; align-items: center; gap: 8px; font-weight: 600; margin-bottom: 4px;">
                                    <?php 
                                    if ($notif['type'] == 'payment_approved') echo '<span class="material-icons" style="color: var(--success);">check_circle</span>';
                                    elseif ($notif['type'] == 'payment_rejected') echo '<span class="material-icons" style="color: var(--danger);">cancel</span>';
                                    elseif ($notif['type'] == 'expiry_soon') echo '<span class="material-icons" style="color: var(--warning);">warning</span>';
                                    ?>
                                    <?php echo htmlspecialchars($notif['title']); ?>
                                </div>
                                <div style="font-size: 0.9rem; margin-left: 28px;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted); margin-left: 28px; margin-top: 4px;">
                                    <?php echo date('d M H:i', strtotime($notif['created_at'])); ?> · <?php echo htmlspecialchars($notif['phone_number']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- User Menu -->
            <div class="user-menu-wrapper">
                <button class="user-menu-btn" onclick="toggleUserMenu()">
                    <?php if (!empty($admin['profile_image']) && file_exists($admin['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($admin['profile_image']); ?>" class="user-avatar-small">
                    <?php else: ?>
                        <span class="material-icons">account_circle</span>
                    <?php endif; ?>
                    <span class="material-icons">arrow_drop_down</span>
                </button>
                
                <div class="dropdown-menu" id="userMenu">
                    <div class="dropdown-item" onclick="changeLanguage('en')">
                        <span class="material-icons">language</span> English
                    </div>
                    <div class="dropdown-item" onclick="changeLanguage('fr')">
                        <span class="material-icons">language</span> Français
                    </div>
                    <div class="dropdown-item" onclick="changeLanguage('rn')">
                        <span class="material-icons">language</span> Kirundi
                    </div>
                    <div class="dropdown-item" onclick="changeLanguage('sw')">
                        <span class="material-icons">language</span> Swahili
                    </div>
                    <div class="dropdown-item" onclick="toggleTheme()">
                        <span class="material-icons">dark_mode</span> <span data-i18n="theme">Theme</span>
                    </div>
                    <div class="dropdown-item" onclick="window.location='logout.php'">
                        <span class="material-icons">logout</span> <span data-i18n="logout">Logout</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success_message): ?>
        <div class="message success" style="margin: 16px 24px;">
            <span class="material-icons">check_circle</span>
            <?php echo $success_message; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="message error" style="margin: 16px 24px;">
            <span class="material-icons">error</span>
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="content">
        <!-- DASHBOARD SECTION -->
        <div id="dashboard" class="section <?php echo $current_section == 'dashboard' ? 'active' : ''; ?>">
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <span class="material-icons">people</span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <div class="stat-label" data-i18n="total_users">Total Users</div>
                    <div style="margin-top: 8px; color: var(--text-secondary);">
                        <span class="material-icons" style="font-size: 14px; vertical-align: middle;">shield</span> <?php echo $total_admins; ?> <span data-i18n="admins">Admins</span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <span class="material-icons">payments</span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $total_payments; ?></div>
                    <div class="stat-label" data-i18n="total_payments">Total Payments</div>
                    <div style="margin-top: 8px; color: var(--text-secondary);">
                        <span class="material-icons" style="color: var(--success); font-size: 14px; vertical-align: middle;">check_circle</span> <?php echo $completed_payments; ?> <span data-i18n="completed">Completed</span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <span class="material-icons">pending</span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $pending_payments; ?></div>
                    <div class="stat-label" data-i18n="pending">Pending</div>
                    <div style="margin-top: 8px; color: var(--text-secondary);">
                        <span class="material-icons" style="color: var(--warning); font-size: 14px; vertical-align: middle;">hourglass_empty</span> <span data-i18n="awaiting_review">Awaiting Review</span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <span class="material-icons">attach_money</span>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($total_revenue ?? 0, 0); ?></div>
                    <div class="stat-label" data-i18n="total_revenue">Total Revenue</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <span class="material-icons" style="color: var(--accent);">bar_chart</span>
                            <span data-i18n="monthly_payments">Monthly Payments</span>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <span class="material-icons" style="color: var(--accent);">pie_chart</span>
                            <span data-i18n="payment_status">Payment Status</span>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons" style="color: var(--accent);">bolt</span>
                        <span data-i18n="quick_actions">Quick Actions</span>
                    </div>
                </div>
                <div class="card-body">
                    <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                        <button class="btn btn-primary" onclick="window.location='?section=pending'">
                            <span class="material-icons">check_circle</span> <span data-i18n="review_pending">Review Pending</span> (<?php echo $pending_payments; ?>)
                        </button>
                        <button class="btn btn-primary" onclick="window.location='?section=expiring'">
                            <span class="material-icons">warning</span> <span data-i18n="check_expiring">Check Expiring</span> (<?php echo count($expiring_soon); ?>)
                        </button>
                        <button class="btn btn-primary" onclick="window.location='?section=add'">
                            <span class="material-icons">person_add</span> <span data-i18n="add_new_user">Add New User</span>
                        </button>
                        <button class="btn btn-primary" onclick="window.location='?section=backup'">
                            <span class="material-icons">backup</span> <span data-i18n="create_backup">Create Backup</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons" style="color: var(--accent);">history</span>
                        <span data-i18n="recent_activity">Recent Activity</span>
                    </div>
                    <a href="?section=logs" style="color: var(--accent);" data-i18n="view_all">View All</a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th data-i18n="user">User</th>
                                <th data-i18n="action">Action</th>
                                <th data-i18n="details">Details</th>
                                <th data-i18n="time">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($logs, 0, 5) as $log): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($log['full_name'] ?? $log['phone_number']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($log['phone_number']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log['action'] == 'payment_upload'): ?>
                                            <span class="badge badge-info"><span class="material-icons" style="font-size: 14px;">upload</span> <span data-i18n="upload">Upload</span></span>
                                        <?php elseif ($log['action'] == 'payment_approved'): ?>
                                            <span class="badge badge-success"><span class="material-icons" style="font-size: 14px;">check</span> <span data-i18n="approved">Approved</span></span>
                                        <?php elseif ($log['action'] == 'payment_rejected'): ?>
                                            <span class="badge badge-danger"><span class="material-icons" style="font-size: 14px;">close</span> <span data-i18n="rejected">Rejected</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td><?php echo date('H:i, d M', strtotime($log['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


<!-- USERS SECTION -->
<div id="users" class="section <?php echo $current_section == 'users' ? 'active' : ''; ?>">
    <!-- Search Bar -->
    <div class="search-bar">
        <span class="material-icons">search</span>
        <input type="text" id="userSearch" placeholder="🔍 Search users by phone or name..." value="<?php echo htmlspecialchars($search_term); ?>">
    </div>
    
    <!-- Status Filter Buttons -->
    <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
        <a href="?section=users&status=all" class="btn <?php echo (!isset($_GET['status']) || $_GET['status'] == 'all') ? 'btn-primary' : 'btn-outline'; ?>" style="padding: 10px 16px;">
            <span class="material-icons" style="font-size: 18px;">people</span> All
        </a>
        <a href="?section=users&status=pending" class="btn <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'btn-primary' : 'btn-outline'; ?>" style="padding: 10px 16px;">
            <span class="material-icons" style="font-size: 18px;">schedule</span> Pending
        </a>
        <a href="?section=users&status=accepted" class="btn <?php echo (isset($_GET['status']) && $_GET['status'] == 'accepted') ? 'btn-primary' : 'btn-outline'; ?>" style="padding: 10px 16px;">
            <span class="material-icons" style="font-size: 18px;">check_circle</span> Accepted
        </a>
        <a href="?section=users&status=rejected" class="btn <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'btn-primary' : 'btn-outline'; ?>" style="padding: 10px 16px;">
            <span class="material-icons" style="font-size: 18px;">cancel</span> Rejected
        </a>
    </div>
    
    <?php if (!empty($search_term)): ?>
        <div class="search-results-info">
            <span><span class="material-icons" style="font-size: 18px; vertical-align: middle;">search</span> <?php echo count($search_results); ?> <span data-i18n="results_for">results for</span> "<?php echo htmlspecialchars($search_term); ?>"</span>
            <a href="?section=users" class="btn-outline" style="padding: 8px 16px;"><span class="material-icons" style="font-size: 16px;">close</span> <span data-i18n="clear">Clear</span></a>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="material-icons">people</span>
                <span data-i18n="user_management">User Management</span> (<?php 
                    // Get status filter
                    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
                    
                    // Filter users based on status
                    $display_users = !empty($search_term) ? $search_results : $users;
                    
                    if ($status_filter != 'all') {
                        $filtered_users = array_filter($display_users, function($u) use ($status_filter) {
                            return $u['status'] == $status_filter;
                        });
                        $display_users = $filtered_users;
                    }
                    
                    echo count($display_users); 
                ?> <span data-i18n="total">total</span>)
            </div>
            <button class="btn btn-primary" onclick="window.location='?section=add'">
                <span class="material-icons">person_add</span> <span data-i18n="add_user">Add User</span>
            </button>
        </div>
        
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th data-i18n="user">User</th>
                        <th data-i18n="contact">Contact</th>
                        <th data-i18n="country">Country</th>
                        <th data-i18n="status">Status</th>
                        <th data-i18n="role">Role</th>
                        <th data-i18n="payments">Payments</th>
                        <th data-i18n="spent">Spent</th>
                        <th data-i18n="joined">Joined</th>
                        <th data-i18n="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($display_users as $user): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($user['profile_image'] && file_exists($user['profile_image'])): ?>
                                        <img src="<?php echo $user['profile_image']; ?>" style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <span class="material-icons" style="font-size: 35px;">account_circle</span>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></strong>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['phone_number']); ?></td>
                            <td><?php echo htmlspecialchars($user['country']); ?></td>
                            <td>
                                <?php if ($user['status'] == 'accepted'): ?>
                                    <span class="badge badge-success"><span class="material-icons" style="font-size: 14px;">check</span> <span data-i18n="active">Active</span></span>
                                <?php elseif ($user['status'] == 'rejected'): ?>
                                    <span class="badge badge-danger"><span class="material-icons" style="font-size: 14px;">close</span> <span data-i18n="rejected">Rejected</span></span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><span class="material-icons" style="font-size: 14px;">schedule</span> <span data-i18n="pending">Pending</span></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['is_admin']): ?>
                                    <span class="badge badge-info"><span class="material-icons" style="font-size: 14px;">shield</span> <span data-i18n="admin">Admin</span></span>
                                <?php else: ?>
                                    <span class="badge"><span data-i18n="user">User</span></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $user['total_payments']; ?></td>
                            <td><?php echo number_format($user['total_spent'] ?? 0, 0); ?></td>
                            <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($user['status'] == 'pending' && !$user['is_admin']): ?>
                                        <!-- Show accept/reject icons for pending users -->
                                        <a href="?accept_user=<?php echo $user['id']; ?>" class="btn-icon approve" title="Accept User" onclick="return confirm('Accept this user?')">
                                            <span class="material-icons">check_circle</span>
                                        </a>
                                        <a href="?reject_user=<?php echo $user['id']; ?>" class="btn-icon reject" title="Reject User" onclick="return confirm('Reject this user?')">
                                            <span class="material-icons">cancel</span>
                                        </a>
                                    <?php elseif ($user['status'] == 'accepted' && !$user['is_admin']): ?>
                                        <!-- Show accepted badge for accepted users -->
                                        <span class="badge badge-success" style="padding: 8px 12px;">
                                            <span class="material-icons" style="font-size: 16px;">check_circle</span> Accepted
                                        </span>
                                    <?php elseif ($user['status'] == 'rejected' && !$user['is_admin']): ?>
                                        <!-- Show rejected badge for rejected users -->
                                        <span class="badge badge-danger" style="padding: 8px 12px;">
                                            <span class="material-icons" style="font-size: 16px;">cancel</span> Rejected
                                        </span>
                                    <?php endif; ?>
                                    
                                    <!-- Delete button (only for non-admin or not self) -->
                                    <?php if (!$user['is_admin'] || $user['id'] != $admin_id): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user?')">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn-icon delete">
                                                <span class="material-icons">delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($display_users)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                <span class="material-icons" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;">people_outline</span>
                                <p>No users found with selected filter</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

        <!-- PAYMENTS SECTION -->
        <div id="payments" class="section <?php echo $current_section == 'payments' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">payments</span>
                        <span data-i18n="all_payments">All Payments</span> (<?php echo count($payments); ?> <span data-i18n="total">total</span>)
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th data-i18n="user">User</th>
                                <th data-i18n="amount">Amount</th>
                                <th data-i18n="months">Months</th>
                                <th data-i18n="payment_phone">Payment Phone</th>
                                <th data-i18n="date">Date</th>
                                <th data-i18n="end_date">End Date</th>
                                <th data-i18n="status">Status</th>
                                <th data-i18n="proof">Proof</th>
                                <th data-i18n="actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td>#<?php echo $payment['id']; ?></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($payment['full_name'] ?? $payment['phone_number']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($payment['phone_number']); ?></small>
                                        </div>
                                    </td>
                                    <td><strong><?php echo number_format($payment['amount_paid'] ?? 0, 0); ?></strong></td>
                                    <td><?php echo $payment['months_paid']; ?>mo</td>
                                    <td><?php echo htmlspecialchars($payment['payment_phone'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('d M', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo date('d M', strtotime($payment['end_date'])); ?></td>
                                    <td>
                                        <?php if ($payment['status'] == 'completed'): ?>
                                            <span class="badge badge-success"><span class="material-icons" style="font-size: 14px;">check</span> <span data-i18n="completed">Completed</span></span>
                                        <?php elseif ($payment['status'] == 'pending'): ?>
                                            <span class="badge badge-warning"><span class="material-icons" style="font-size: 14px;">schedule</span> <span data-i18n="pending">Pending</span></span>
                                        <?php else: ?>
                                            <span class="badge badge-danger"><span class="material-icons" style="font-size: 14px;">close</span> <span data-i18n="unpaid">Unpaid</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($payment['proof_url'] && file_exists($payment['proof_url'])): ?>
                                            <button class="btn-icon" onclick="viewProof('<?php echo $payment['proof_url']; ?>')">
                                                <span class="material-icons">image</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($payment['status'] == 'pending'): ?>
                                                <a href="?approve_payment=<?php echo $payment['id']; ?>" class="btn-icon approve" title="Approve">
                                                    <span class="material-icons">check</span>
                                                </a>
                                                <a href="?reject_payment=<?php echo $payment['id']; ?>" class="btn-icon reject" title="Reject" onclick="return confirm('Reject this payment?')">
                                                    <span class="material-icons">close</span>
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this payment?')">
                                                <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                <button type="submit" name="delete_payment" class="btn-icon delete">
                                                    <span class="material-icons">delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PENDING SECTION -->
        <div id="pending" class="section <?php echo $current_section == 'pending' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">pending</span>
                        <span data-i18n="pending_verifications">Pending Verifications</span> (<?php echo count($pending_subs); ?>)
                    </div>
                </div>
                
                <?php if (empty($pending_subs)): ?>
                    <div class="card-body" style="text-align: center; padding: 48px;">
                        <span class="material-icons" style="font-size: 64px; color: var(--success); margin-bottom: 16px;">check_circle</span>
                        <p data-i18n="no_pending">No pending verifications</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th data-i18n="user">User</th>
                                    <th data-i18n="amount">Amount</th>
                                    <th data-i18n="months">Months</th>
                                    <th data-i18n="payment_phone">Payment Phone</th>
                                    <th data-i18n="transaction">Transaction</th>
                                    <th data-i18n="submitted">Submitted</th>
                                    <th data-i18n="proof">Proof</th>
                                    <th data-i18n="actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_subs as $pending): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($pending['full_name'] ?? $pending['phone_number']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($pending['phone_number']); ?></small>
                                        </td>
                                        <td><strong><?php echo number_format($pending['amount_paid'] ?? 0, 0); ?></strong></td>
                                        <td><?php echo $pending['months_paid']; ?> <span data-i18n="months">months</span></td>
                                        <td><?php echo htmlspecialchars($pending['payment_phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pending['transaction_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('d M H:i', strtotime($pending['created_at'])); ?></td>
                                        <td>
                                            <?php if ($pending['proof_url'] && file_exists($pending['proof_url'])): ?>
                                                <button class="btn-icon" onclick="viewProof('<?php echo $pending['proof_url']; ?>')">
                                                    <span class="material-icons">image</span>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?approve_payment=<?php echo $pending['id']; ?>" class="btn-icon approve" title="Approve">
                                                    <span class="material-icons">check</span>
                                                </a>
                                                <a href="?reject_payment=<?php echo $pending['id']; ?>" class="btn-icon reject" title="Reject" onclick="return confirm('Reject this payment?')">
                                                    <span class="material-icons">close</span>
                                                </a>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this payment?')">
                                                    <input type="hidden" name="payment_id" value="<?php echo $pending['id']; ?>">
                                                    <button type="submit" name="delete_payment" class="btn-icon delete">
                                                        <span class="material-icons">delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- EXPIRING SOON SECTION -->
        <div id="expiring" class="section <?php echo $current_section == 'expiring' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons" style="color: var(--warning);">warning</span>
                        <span data-i18n="expiring_soon">Expiring Soon</span> (Next 7 Days)
                    </div>
                </div>
                
                <?php if (empty($expiring_soon)): ?>
                    <div class="card-body" style="text-align: center; padding: 48px;">
                        <span class="material-icons" style="font-size: 64px; color: var(--success); margin-bottom: 16px;">check_circle</span>
                        <p data-i18n="no_expiring">No subscriptions expiring soon</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th data-i18n="user">User</th>
                                    <th data-i18n="phone">Phone</th>
                                    <th data-i18n="end_date">End Date</th>
                                    <th data-i18n="days_left">Days Left</th>
                                    <th data-i18n="months_paid">Months Paid</th>
                                    <th data-i18n="last_payment">Last Payment</th>
                                    <th data-i18n="actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiring_soon as $exp): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($exp['full_name'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($exp['phone_number']); ?></td>
                                        <td><strong><?php echo date('d M Y', strtotime($exp['end_date'])); ?></strong></td>
                                        <td>
                                            <span class="badge badge-warning">
                                                <span class="material-icons" style="font-size: 14px;">schedule</span> <?php echo $exp['days_left']; ?> <span data-i18n="days">days</span>
                                            </span>
                                        </td>
                                        <td><?php echo $exp['months_paid']; ?></td>
                                        <td><?php echo date('d M', strtotime($exp['payment_date'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon" onclick="sendReminder(<?php echo $exp['user_id']; ?>)">
                                                    <span class="material-icons">notifications</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ADD USER SECTION -->
        <div id="add" class="section <?php echo $current_section == 'add' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">person_add</span>
                        <span data-i18n="add_new_user">Add New User / Admin</span>
                    </div>
                </div>
                
                <div class="card-body">
                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label data-i18n="phone_number">Phone Number</label>
                                <div class="input-wrapper">
                                    <span class="material-icons">phone</span>
                                    <input type="tel" name="phone" placeholder="+257 XX XXX XXX" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label data-i18n="full_name">Full Name</label>
                                <div class="input-wrapper">
                                    <span class="material-icons">person</span>
                                    <input type="text" name="full_name" placeholder="Enter full name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label data-i18n="country">Country</label>
                                <div class="input-wrapper">
                                    <span class="material-icons">public</span>
                                    <select name="country" required>
                                        <option value="">Select country</option>
                                        <option value="Burundi">🇧🇮 Burundi</option>
                                        <option value="Rwanda">🇷🇼 Rwanda</option>
                                        <option value="DRC">🇨🇩 DRC</option>
                                        <option value="Tanzania">🇹🇿 Tanzania</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label data-i18n="password">Password</label>
                                <div class="input-wrapper">
                                    <span class="material-icons">lock</span>
                                    <input type="password" name="password" placeholder="Enter password" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label data-i18n="role">Role</label>
                                <div style="display: flex; gap: 16px; align-items: center; padding: 8px 0;">
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="is_admin" value="1"> 
                                        <span class="material-icons" style="color: var(--accent);">shield</span> <span data-i18n="make_admin">Make Admin</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" name="add_user" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                            <span class="material-icons">person_add</span> <span data-i18n="create_user">Create User</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- LOGS SECTION -->
        <div id="logs" class="section <?php echo $current_section == 'logs' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">history</span>
                        <span data-i18n="activity_logs">Activity Logs</span> (Last 50)
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th data-i18n="time">Time</th>
                                <th data-i18n="user">User</th>
                                <th data-i18n="action">Action</th>
                                <th data-i18n="details">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo date('d M H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($log['full_name'] ?? $log['phone_number']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($log['phone_number']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log['action'] == 'payment_upload'): ?>
                                            <span class="badge badge-info"><span class="material-icons" style="font-size: 14px;">upload</span> <span data-i18n="upload">Upload</span></span>
                                        <?php elseif ($log['action'] == 'payment_approved'): ?>
                                            <span class="badge badge-success"><span class="material-icons" style="font-size: 14px;">check</span> <span data-i18n="approved">Approved</span></span>
                                        <?php elseif ($log['action'] == 'payment_rejected'): ?>
                                            <span class="badge badge-danger"><span class="material-icons" style="font-size: 14px;">close</span> <span data-i18n="rejected">Rejected</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- BACKUP SECTION -->
        <div id="backup" class="section <?php echo $current_section == 'backup' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">backup</span>
                        <span data-i18n="database_backup">Database Backup</span>
                    </div>
                    <form method="POST">
                        <button type="submit" name="create_backup" class="btn btn-primary">
                            <span class="material-icons">save</span> <span data-i18n="create_new_backup">Create New Backup</span>
                        </button>
                    </form>
                </div>
                
                <div class="card-body">
                    <div style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 24px; padding: 16px; background: var(--surface-light); border-radius: 12px;">
                        <div><span class="material-icons" style="vertical-align: middle;">folder</span> <strong data-i18n="total_backups">Total Backups:</strong> <?php echo count($backup_files); ?></div>
                        <div><span class="material-icons" style="vertical-align: middle;">storage</span> <strong data-i18n="total_size">Total Size:</strong> 
                            <?php 
                            $total_size = 0;
                            foreach ($backup_files as $bf) $total_size += filesize($bf);
                            echo round($total_size / 1048576, 2) . ' MB';
                            ?>
                        </div>
                        <div><span class="material-icons" style="vertical-align: middle;">folder_open</span> <strong data-i18n="location">Location:</strong> /backups/</div>
                    </div>
                    
                    <?php if (empty($backup_files)): ?>
                        <div style="text-align: center; padding: 48px;">
                            <span class="material-icons" style="font-size: 64px; color: var(--text-muted); margin-bottom: 16px;">backup</span>
                            <p data-i18n="no_backups">No backups yet. Create your first backup.</p>
                        </div>
                    <?php else: ?>
                        <div class="backup-list">
                            <?php foreach ($backup_files as $backup): ?>
                                <?php
                                $filename = basename($backup);
                                $size = filesize($backup);
                                $date = date('d M Y H:i', filemtime($backup));
                                ?>
                                <div class="backup-item">
                                    <div class="backup-info">
                                        <span class="material-icons" style="font-size: 40px; color: var(--accent);">description</span>
                                        <div>
                                            <strong><?php echo $filename; ?></strong>
                                            <br>
                                            <small style="color: var(--text-secondary);"><?php echo round($size / 1024, 2); ?> KB · <?php echo $date; ?></small>
                                        </div>
                                    </div>
                                    <div class="backup-actions">
                                        <a href="?download_backup=<?php echo urlencode($filename); ?>" class="btn-icon" title="Download">
                                            <span class="material-icons">download</span>
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this backup?')">
                                            <input type="hidden" name="backup_file" value="<?php echo $filename; ?>">
                                            <button type="submit" name="delete_backup" class="btn-icon delete">
                                                <span class="material-icons">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PROFILE SECTION -->
        <div id="profile" class="section <?php echo $current_section == 'profile' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">person</span>
                        <span data-i18n="admin_profile">Admin Profile</span>
                    </div>
                </div>
                
                <div class="card-body">
                    <div style="display: flex; flex-wrap: wrap; gap: 32px;">
                        <!-- Profile Image -->
                        <div style="flex: 0 0 200px; text-align: center;">
                            <div class="avatar-preview">
                                <?php if ($admin['profile_image'] && file_exists($admin['profile_image'])): ?>
                                    <img src="<?php echo $admin['profile_image']; ?>" id="profilePreview">
                                <?php else: ?>
                                    <img src="uploads/images/default.jpg" id="profilePreview">
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data" style="margin-top: 16px;">
                                <div class="image-upload" onclick="document.getElementById('profileImage').click()">
                                    <span class="material-icons">cloud_upload</span>
                                    <p data-i18n="click_to_upload">Click to upload new image</p>
                                    <input type="file" name="profile_image" id="profileImage" accept="image/*" onchange="previewImage(this)">
                                </div>
                                <button type="submit" name="upload_profile_image" class="btn btn-primary" style="width: 100%; margin-top: 16px;">
                                    <span class="material-icons">save</span> <span data-i18n="update_image">Update Image</span>
                                </button>
                            </form>
                        </div>
                        
                        <!-- Profile Form -->
                        <div style="flex: 1;">
                            <form method="POST">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label data-i18n="full_name">Full Name</label>
                                        <div class="input-wrapper">
                                            <span class="material-icons">person</span>
                                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label data-i18n="phone_number">Phone Number</label>
                                        <div class="input-wrapper">
                                            <span class="material-icons">phone</span>
                                            <input type="text" value="<?php echo htmlspecialchars($admin['phone_number']); ?>" disabled readonly>
                                        </div>
                                    </div>
                                </div>
                                
                                <h3 style="margin: 24px 0 16px;"><span data-i18n="change_password">Change Password</span></h3>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label data-i18n="current_password">Current Password</label>
                                        <div class="input-wrapper">
                                            <span class="material-icons">lock</span>
                                            <input type="password" name="current_password" placeholder="••••••••">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label data-i18n="new_password">New Password</label>
                                        <div class="input-wrapper">
                                            <span class="material-icons">lock</span>
                                            <input type="password" name="new_password" placeholder="••••••••">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label data-i18n="confirm_password">Confirm Password</label>
                                        <div class="input-wrapper">
                                            <span class="material-icons">lock</span>
                                            <input type="password" id="confirm_password" placeholder="••••••••">
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" name="update_profile" class="btn btn-primary" onclick="return validatePassword()">
                                    <span class="material-icons">save</span> <span data-i18n="save_changes">Save Changes</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Proof Modal -->
    <div class="modal" id="proofModal" onclick="closeProofModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 style="display: flex; align-items: center; gap: 8px;">
                    <span class="material-icons">image</span>
                    <span data-i18n="payment_proof">Payment Proof</span>
                </h3>
                <button class="close-btn" onclick="closeProofModal()">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <img id="modalImage" style="max-width: 100%; max-height: 400px; object-fit: contain;">
            </div>
        </div>
    </div>

    <script>
        // ==================== TRANSLATIONS ====================
        const translations = {
            en: {
                // General
                dashboard: 'Dashboard',
                users: 'Users',
                all_payments: 'All Payments',
                pending: 'Pending',
                expiring_soon: 'Expiring Soon',
                add_user: 'Add User',
                activity_logs: 'Activity Logs',
                backup: 'Backup',
                profile: 'Profile',
                admin: 'ADMIN',
                administrator: 'Administrator',
                
                // Stats
                total_users: 'Total Users',
                admins: 'Admins',
                total_payments: 'Total Payments',
                completed: 'Completed',
                awaiting_review: 'Awaiting Review',
                total_revenue: 'Total Revenue',
                
                // Quick Actions
                quick_actions: 'Quick Actions',
                review_pending: 'Review Pending',
                check_expiring: 'Check Expiring',
                add_new_user: 'Add New User',
                create_backup: 'Create Backup',
                
                // Tables
                user: 'User',
                action: 'Action',
                details: 'Details',
                time: 'Time',
                contact: 'Contact',
                country: 'Country',
                status: 'Status',
                role: 'Role',
                payments: 'Payments',
                spent: 'Spent',
                joined: 'Joined',
                actions: 'Actions',
                amount: 'Amount',
                months: 'Months',
                payment_phone: 'Payment Phone',
                date: 'Date',
                end_date: 'End Date',
                proof: 'Proof',
                transaction: 'Transaction',
                submitted: 'Submitted',
                phone: 'Phone',
                days_left: 'Days Left',
                months_paid: 'Months Paid',
                last_payment: 'Last Payment',
                
                // Badges
                active: 'Active',
                rejected: 'Rejected',
                admin: 'Admin',
                approved: 'Approved',
                upload: 'Upload',
                unpaid: 'Unpaid',
                
                // Forms
                phone_number: 'Phone Number',
                full_name: 'Full Name',
                password: 'Password',
                role: 'Role',
                make_admin: 'Make Admin',
                create_user: 'Create User',
                select_country: 'Select country',
                
                // Backup
                database_backup: 'Database Backup',
                create_new_backup: 'Create New Backup',
                total_backups: 'Total Backups',
                total_size: 'Total Size',
                location: 'Location',
                no_backups: 'No backups yet. Create your first backup.',
                
                // Profile
                admin_profile: 'Admin Profile',
                click_to_upload: 'Click to upload new image',
                update_image: 'Update Image',
                change_password: 'Change Password',
                current_password: 'Current Password',
                new_password: 'New Password',
                confirm_password: 'Confirm Password',
                save_changes: 'Save Changes',
                
                // Messages
                no_pending: 'No pending verifications',
                no_expiring: 'No subscriptions expiring soon',
                results_for: 'results for',
                clear: 'Clear',
                search_users: 'Search users by phone or name...',
                view_all: 'View All',
                recent_activity: 'Recent Activity',
                user_management: 'User Management',
                total: 'total',
                
                // Notifications
                notifications: 'Notifications',
                mark_all_read: 'Mark all read',
                no_notifications: 'No new notifications',
                
                // Theme & Language
                theme: 'Theme',
                logout: 'Logout',
                
                // Months
                month: 'Month',
                days: 'days',
                
                // Pending
                pending_verifications: 'Pending Verifications',
                
                // Payment Proof
                payment_proof: 'Payment Proof',
                
                // Charts
                monthly_payments: 'Monthly Payments',
                payment_status: 'Payment Status'
            },
            fr: {
                dashboard: 'Tableau de bord',
                users: 'Utilisateurs',
                all_payments: 'Tous les paiements',
                pending: 'En attente',
                expiring_soon: 'Expire bientôt',
                add_user: 'Ajouter',
                activity_logs: 'Journaux',
                backup: 'Sauvegarde',
                profile: 'Profil',
                admin: 'ADMIN',
                administrator: 'Administrateur',
                
                total_users: 'Utilisateurs',
                admins: 'Admins',
                total_payments: 'Paiements',
                completed: 'Terminé',
                awaiting_review: 'En attente',
                total_revenue: 'Revenu',
                
                quick_actions: 'Actions rapides',
                review_pending: 'Vérifier',
                check_expiring: 'Expirations',
                add_new_user: 'Nouvel utilisateur',
                create_backup: 'Sauvegarder',
                
                user: 'Utilisateur',
                action: 'Action',
                details: 'Détails',
                time: 'Heure',
                contact: 'Contact',
                country: 'Pays',
                status: 'Statut',
                role: 'Rôle',
                payments: 'Paiements',
                spent: 'Dépensé',
                joined: 'Inscrit',
                actions: 'Actions',
                amount: 'Montant',
                months: 'Mois',
                payment_phone: 'Téléphone',
                date: 'Date',
                end_date: 'Fin',
                proof: 'Preuve',
                transaction: 'Transaction',
                submitted: 'Soumis',
                phone: 'Téléphone',
                days_left: 'Jours',
                months_paid: 'Mois',
                last_payment: 'Dernier',
                
                active: 'Actif',
                rejected: 'Rejeté',
                admin: 'Admin',
                approved: 'Approuvé',
                upload: 'Télécharger',
                unpaid: 'Impayé',
                
                phone_number: 'Téléphone',
                full_name: 'Nom complet',
                password: 'Mot de passe',
                make_admin: 'Admin',
                create_user: 'Créer',
                
                database_backup: 'Sauvegarde',
                create_new_backup: 'Nouvelle',
                total_backups: 'Total',
                total_size: 'Taille',
                location: 'Emplacement',
                no_backups: 'Aucune sauvegarde',
                
                admin_profile: 'Profil',
                click_to_upload: 'Cliquez pour uploader',
                update_image: 'Mettre à jour',
                change_password: 'Changer mot de passe',
                current_password: 'Mot de passe actuel',
                new_password: 'Nouveau',
                confirm_password: 'Confirmer',
                save_changes: 'Enregistrer',
                
                no_pending: 'Aucune en attente',
                no_expiring: 'Aucune expiration',
                results_for: 'résultats pour',
                clear: 'Effacer',
                search_users: 'Rechercher...',
                view_all: 'Voir tout',
                recent_activity: 'Activité récente',
                user_management: 'Gestion',
                total: 'total',
                
                notifications: 'Notifications',
                mark_all_read: 'Tout lire',
                no_notifications: 'Aucune',
                
                theme: 'Thème',
                logout: 'Déconnexion',
                month: 'Mois',
                days: 'jours',
                pending_verifications: 'En attente',
                payment_proof: 'Preuve',
                monthly_payments: 'Paiements mensuels',
                payment_status: 'Statut des paiements'
            },
            rn: {
                dashboard: 'Dashubodi',
                users: 'Abakoresha',
                all_payments: 'Amahembe yose',
                pending: 'Birategereje',
                expiring_soon: 'Birasohoka',
                add_user: 'Ongera',
                activity_logs: 'Ibikorwa',
                backup: 'Kubika',
                profile: 'Igenamiterere',
                admin: 'ADMIN',
                administrator: 'Umuyobozi',
                
                total_users: 'Abakoresha',
                admins: 'Abayobozi',
                total_payments: 'Amahembe',
                completed: 'Yarangiye',
                awaiting_review: 'Birategerezwa',
                total_revenue: 'Amahera yose',
                
                quick_actions: 'Ibikorwa',
                review_pending: 'Raba',
                check_expiring: 'Raba ibisohoka',
                add_new_user: 'Ongera umukoresha',
                create_backup: 'Kora backup',
                
                user: 'Umukoresha',
                action: 'Igikorwa',
                details: 'Ibijanye',
                time: 'Igihe',
                contact: 'Terefone',
                country: 'Igihugu',
                status: 'Etat',
                role: 'Inshingano',
                payments: 'Amahembe',
                spent: 'Yishuye',
                joined: 'Yinjiye',
                actions: 'Ibikorwa',
                amount: 'Amahera',
                months: 'Amezi',
                payment_phone: 'Terefone',
                date: 'Itariki',
                end_date: 'Iherezo',
                proof: 'Ikimenyetso',
                transaction: 'ID',
                submitted: 'Yoherejwe',
                phone: 'Terefone',
                days_left: 'Imisi isigaye',
                months_paid: 'Amezi yishyuwe',
                last_payment: 'Iheruka',
                
                active: 'Igikora',
                rejected: 'Yanzwe',
                admin: 'Umuyobozi',
                approved: 'Yemewe',
                upload: 'Kwishura',
                unpaid: 'Ntayishuwe',
                
                phone_number: 'Numero ya terefone',
                full_name: 'Amazina yose',
                password: 'Ijambo ibanga',
                make_admin: 'Mugire umuyobozi',
                create_user: 'Remeka umukoresha',
                
                database_backup: 'Kubika database',
                create_new_backup: 'Kora backup',
                total_backups: 'Backup zose',
                total_size: 'Ingano',
                location: 'Aho biri',
                no_backups: 'Nta backup',
                
                admin_profile: 'Igenamiterere',
                click_to_upload: 'Kanda gushyiramwo ifoto',
                update_image: 'Vugurura ifoto',
                change_password: 'Hindura ijambo ibanga',
                current_password: 'Ijambo ibanga',
                new_password: 'Ijambo ibanga rishya',
                confirm_password: 'Emeza',
                save_changes: 'Kubika',
                
                no_pending: 'Nta biri mu gutegereza',
                no_expiring: 'Nta biri gusohoka',
                results_for: 'ibisubizo kuri',
                clear: 'Kuraho',
                search_users: 'Shakisha...',
                view_all: 'Raba vyose',
                recent_activity: 'Ibikorwa biherutse',
                user_management: 'Abakoresha',
                total: 'yose',
                
                notifications: 'Amatangazo',
                mark_all_read: 'Soma yose',
                no_notifications: 'Nta matangazo',
                
                theme: 'Igishushanyo',
                logout: 'Kuvayo',
                month: 'Ukwezi',
                days: 'imisi',
                pending_verifications: 'Birategerezwa',
                payment_proof: 'Ikimenyetso',
                monthly_payments: 'Amahembe y\'ukwezi',
                payment_status: 'Etat y\'amahembe'
            },
            sw: {
                dashboard: 'Dashibodi',
                users: 'Watumiaji',
                all_payments: 'Malipo yote',
                pending: 'Inasubiri',
                expiring_soon: 'Inakaribia kuisha',
                add_user: 'Ongeza',
                activity_logs: 'Shughuli',
                backup: 'Hifadhi',
                profile: 'Wasifu',
                admin: 'ADMIN',
                administrator: 'Msimamizi',
                
                total_users: 'Watumiaji',
                admins: 'Wasimamizi',
                total_payments: 'Malipo',
                completed: 'Imekamilika',
                awaiting_review: 'Inasubiri',
                total_revenue: 'Mapato',
                
                quick_actions: 'Vitendo',
                review_pending: 'Kagua',
                check_expiring: 'Angalia zinazoisha',
                add_new_user: 'Ongeza mtumiaji',
                create_backup: 'Fanya backup',
                
                user: 'Mtumiaji',
                action: 'Kitendo',
                details: 'Maelezo',
                time: 'Muda',
                contact: 'Mawasiliano',
                country: 'Nchi',
                status: 'Hali',
                role: 'Wajibu',
                payments: 'Malipo',
                spent: 'Ametumia',
                joined: 'Alijiunga',
                actions: 'Vitendo',
                amount: 'Kiasi',
                months: 'Miezi',
                payment_phone: 'Namba ya simu',
                date: 'Tarehe',
                end_date: 'Mwisho',
                proof: 'Uthibitisho',
                transaction: 'Txn ID',
                submitted: 'Imewasilishwa',
                phone: 'Simu',
                days_left: 'Siku zilizobaki',
                months_paid: 'Miezi iliyolipwa',
                last_payment: 'Malipo ya mwisho',
                
                active: 'Inatumika',
                rejected: 'Imekataliwa',
                admin: 'Msimamizi',
                approved: 'Imekubaliwa',
                upload: 'Pakia',
                unpaid: 'Haijalipwa',
                
                phone_number: 'Namba ya simu',
                full_name: 'Jina kamili',
                password: 'Nywila',
                make_admin: 'Fanya msimamizi',
                create_user: 'Unda mtumiaji',
                
                database_backup: 'Hifadhi ya database',
                create_new_backup: 'Fanya backup mpya',
                total_backups: 'Jumla ya backup',
                total_size: 'Ukubwa',
                location: 'Mahali',
                no_backups: 'Hakuna backup',
                
                admin_profile: 'Wasifu wa msimamizi',
                click_to_upload: 'Bonyeza kupakia picha',
                update_image: 'Sasisha picha',
                change_password: 'Badilisha nywila',
                current_password: 'Nywila ya sasa',
                new_password: 'Nywila mpya',
                confirm_password: 'Thibitisha',
                save_changes: 'Hifadhi mabadiliko',
                
                no_pending: 'Hakuna yanayosubiri',
                no_expiring: 'Hakuna yanayoisha karibuni',
                results_for: 'matokeo ya',
                clear: 'Futa',
                search_users: 'Tafuta...',
                view_all: 'Angalia zote',
                recent_activity: 'Shughuli za karibuni',
                user_management: 'Watumiaji',
                total: 'jumla',
                
                notifications: 'Arifa',
                mark_all_read: 'Soma zote',
                no_notifications: 'Hakuna arifa',
                
                theme: 'Mandhari',
                logout: 'Toka',
                month: 'Mwezi',
                days: 'siku',
                pending_verifications: 'Zinazosubiri',
                payment_proof: 'Uthibitisho wa malipo',
                monthly_payments: 'Malipo ya mwezi',
                payment_status: 'Hali ya malipo'
            }
        };

        let currentLang = localStorage.getItem('olavan_lang') || 'en';

        function applyTranslations(lang) {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) {
                    el.textContent = translations[lang][key];
                }
            });
            
            // Update placeholders
            const searchInput = document.getElementById('userSearch');
            if (searchInput) {
                searchInput.placeholder = '🔍 ' + (translations[lang]['search_users'] || 'Search users by phone or name...');
            }
            
            localStorage.setItem('olavan_lang', lang);
        }

        function changeLanguage(lang) {
            currentLang = lang;
            applyTranslations(lang);
            document.getElementById('userMenu').classList.remove('show');
        }

        // ==================== THEME ====================
        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('olavan_theme', next);
            document.getElementById('userMenu').classList.remove('show');
        }

        const savedTheme = localStorage.getItem('olavan_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);

        // ==================== SIDEBAR ====================
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('show');
        }

        // ==================== SECTION SWITCHING ====================
        function showSection(sectionId) {
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            
            // Update sidebar active state
            document.querySelectorAll('.sidebar-menu a').forEach(a => a.classList.remove('active'));
            const menuId = 'menu' + sectionId.charAt(0).toUpperCase() + sectionId.slice(1);
            const menuItem = document.getElementById(menuId);
            if (menuItem) menuItem.classList.add('active');
            
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('section', sectionId);
            window.history.pushState({}, '', url);
        }

        // ==================== DROPDOWNS ====================
        function toggleNotifications() {
            document.getElementById('notifDropdown').classList.toggle('show');
            document.getElementById('userMenu').classList.remove('show');
        }

        function toggleUserMenu() {
            document.getElementById('userMenu').classList.toggle('show');
            document.getElementById('notifDropdown').classList.remove('show');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.notif-wrapper') && !e.target.closest('.notif-dropdown')) {
                document.getElementById('notifDropdown').classList.remove('show');
            }
            if (!e.target.closest('.user-menu-wrapper') && !e.target.closest('.dropdown-menu')) {
                document.getElementById('userMenu').classList.remove('show');
            }
        });

        // ==================== SEARCH ====================
        document.getElementById('userSearch')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.trim();
                if (searchTerm) {
                    window.location.href = 'admin.php?section=users&search=' + encodeURIComponent(searchTerm);
                }
            }
        });

        // ==================== PROOF VIEWER ====================
        function viewProof(url) {
            document.getElementById('modalImage').src = url;
            document.getElementById('proofModal').classList.add('show');
        }

        function closeProofModal() {
            document.getElementById('proofModal').classList.remove('show');
        }

        // ==================== SEND REMINDER ====================
        function sendReminder(userId) {
            if (confirm('Send expiry reminder?')) {
                fetch('send_reminder.php?user_id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✓ Reminder sent');
                        } else {
                            alert('❌ Error sending reminder');
                        }
                    })
                    .catch(() => {
                        alert('✓ Reminder sent (demo)');
                    });
            }
        }

        // ==================== PROFILE IMAGE PREVIEW ====================
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profilePreview').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // ==================== PASSWORD VALIDATION ====================
        function validatePassword() {
            const newPass = document.querySelector('input[name="new_password"]')?.value || '';
            const confirm = document.getElementById('confirm_password')?.value || '';
            if (newPass && newPass !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            return true;
        }

        // ==================== AUTO-HIDE MESSAGES ====================
        setTimeout(() => {
            document.querySelectorAll('.message').forEach(msg => {
                msg.style.opacity = '0';
                msg.style.transition = 'opacity 0.5s';
                setTimeout(() => msg.remove(), 500);
            });
        }, 4000);

        // ==================== PHONE FORMATTING ====================
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function() {
                let v = this.value.replace(/\D/g, '');
                if (v && !v.startsWith('+')) v = '+' + v;
                this.value = v;
            });
        });

        // ==================== CLOSE MODALS WITH ESC ====================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProofModal();
                document.getElementById('notifDropdown')?.classList.remove('show');
                document.getElementById('userMenu')?.classList.remove('show');
            }
        });

        // ==================== CHARTS ====================
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly payments chart
            const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
            if (monthlyCtx) {
                // Get last 6 months data
                const months = [];
                const counts = [];
                const revenues = [];
                
                // This would normally come from database - for demo we'll use sample data
                for (let i = 5; i >= 0; i--) {
                    const d = new Date();
                    d.setMonth(d.getMonth() - i);
                    months.push(d.toLocaleString('default', { month: 'short' }));
                    counts.push(Math.floor(Math.random() * 10) + 1);
                    revenues.push(Math.floor(Math.random() * 50000) + 10000);
                }
                
                new Chart(monthlyCtx, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: translations[currentLang]['payments'] || 'Payments',
                            data: counts,
                            backgroundColor: 'rgba(211, 84, 0, 0.5)',
                            borderColor: '#d35400',
                            borderWidth: 1
                        }, {
                            label: translations[currentLang]['total_revenue'] || 'Revenue',
                            data: revenues,
                            backgroundColor: 'rgba(46, 204, 113, 0.5)',
                            borderColor: '#2ecc71',
                            borderWidth: 1,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                grid: { color: 'rgba(255,255,255,0.1)' },
                                title: { display: true, text: translations[currentLang]['payments'] || 'Payments' }
                            },
                            y1: { 
                                position: 'right', 
                                beginAtZero: true,
                                grid: { drawOnChartArea: false },
                                title: { display: true, text: translations[currentLang]['amount'] || 'Amount' }
                            }
                        }
                    }
                });
            }
            
            // Status chart
            const statusCtx = document.getElementById('statusChart')?.getContext('2d');
            if (statusCtx) {
                new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            translations[currentLang]['completed'] || 'Completed',
                            translations[currentLang]['pending'] || 'Pending',
                            translations[currentLang]['unpaid'] || 'Unpaid'
                        ],
                        datasets: [{
                            data: [
                                <?php echo $completed_payments ?: 0; ?>,
                                <?php echo $pending_payments ?: 0; ?>,
                                <?php echo $unpaid_payments ?: 0; ?>
                            ],
                            backgroundColor: ['#2ecc71', '#f39c12', '#e74c3c'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            }
        });

        // ==================== INIT ====================
        applyTranslations(currentLang);
        
        // Check URL for section
        const urlParams = new URLSearchParams(window.location.search);
        const section = urlParams.get('section');
        if (section && ['dashboard', 'users', 'payments', 'pending', 'expiring', 'add', 'logs', 'backup', 'profile'].includes(section)) {
            showSection(section);
        }
    </script>
</body>
</html>