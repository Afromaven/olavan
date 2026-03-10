<?php
/**
 * Olavan - User Dashboard (Fully Working Version)
 * Location: C:/xampp/htdocs/olavan/user.php
 */

require_once 'db.php';
session_start();

// Prevent form resubmission on refresh
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Check if user is logged in and not admin
if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] == 1) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle profile image upload
if (isset($_POST['upload_profile_image'])) {
    if ($_FILES['profile_image']['error'] == 0) {
        $target_dir = "uploads/images/";
        $file_extension = strtolower(pathinfo($_FILES["profile_image"]["name"], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed)) {
            $filename = 'user_' . $user_id . '_' . time() . '.' . $file_extension;
            $target_file = $target_dir . $filename;
            
            // Delete old profile image if not default
            if ($user['profile_image'] != 'uploads/images/default.jpg' && file_exists($user['profile_image'])) {
                unlink($user['profile_image']);
            }
            
            if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $target_file)) {
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$target_file, $user_id]);
                $_SESSION['success_message'] = "✓ Photo updated successfully";
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $_SESSION['error_message'] = "❌ Upload failed";
            }
        } else {
            $_SESSION['error_message'] = "❌ Invalid file type. Allowed: JPG, PNG, GIF";
        }
    }
    header("Location: user.php?section=profile");
    exit;
}

// Get user's payments
$stmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$stmt->execute([$user_id]);
$payments = $stmt->fetchAll();

// Get latest payment for status
$stmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE user_id = ? 
    ORDER BY end_date DESC 
    LIMIT 1
");
$stmt->execute([$user_id]);
$current_payment = $stmt->fetch();

// Get unread notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$notification_count = $stmt->fetchColumn();

// Get all notifications for this user
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

// Handle payment upload
if (isset($_POST['upload_proof'])) {
    $months = intval($_POST['months']);
    $payment_method = $_POST['payment_method'];
    $payment_phone = $_POST['payment_phone'];
    $transaction_id = $_POST['transaction_id'] ?? null;
    $amount_paid = floatval($_POST['amount_paid']);
    $terms_accepted = isset($_POST['terms']) ? 1 : 0;
    
    if (!$terms_accepted) {
        $_SESSION['error_message'] = "❌ You must accept the terms";
    } elseif ($_FILES['proof']['error'] == 0) {
        $target_dir = "uploads/proofs/";
        $file_extension = strtolower(pathinfo($_FILES["proof"]["name"], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png'];
        
        if (in_array($file_extension, $allowed)) {
            $filename = time() . '_' . $user_id . '_' . bin2hex(random_bytes(4)) . '.' . $file_extension;
            $target_file = $target_dir . $filename;
            
            if (move_uploaded_file($_FILES["proof"]["tmp_name"], $target_file)) {
                $payment_date = date('Y-m-d');
                $end_date = date('Y-m-d', strtotime("+$months months"));
                
                $stmt = $pdo->prepare("
                    INSERT INTO payments (
                        user_id, payment_date, months_paid, payment_method, 
                        payment_phone, transaction_id, amount_paid, proof_url, 
                        status, end_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                $stmt->execute([
                    $user_id, $payment_date, $months, $payment_method,
                    $payment_phone, $transaction_id, $amount_paid, $target_file,
                    $end_date
                ]);
                
                // Log activity
                $log = $pdo->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, 'payment_upload', ?)");
                $log->execute([$user_id, "Uploaded proof for $months months"]);
                
                $_SESSION['success_message'] = "✓ Payment uploaded! Pending admin review";
            } else {
                $_SESSION['error_message'] = "❌ Upload failed";
            }
        } else {
            $_SESSION['error_message'] = "❌ Only JPG/PNG files allowed";
        }
    } else {
        $_SESSION['error_message'] = "❌ Please select a file";
    }
    header("Location: user.php?section=upload");
    exit;
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'];
    $country = $_POST['country'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (!empty($new_password)) {
        if (password_verify($current_password, $user['password_hash'])) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, country = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$full_name, $country, $password_hash, $user_id]);
            $_SESSION['success_message'] = "✓ Profile updated with new password";
        } else {
            $_SESSION['error_message'] = "❌ Current password is incorrect";
        }
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, country = ? WHERE id = ?");
        $stmt->execute([$full_name, $country, $user_id]);
        $_SESSION['success_message'] = "✓ Profile updated successfully";
    }
    
    // Refresh user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    header("Location: user.php?section=profile");
    exit;
}

// Mark notification as read
if (isset($_GET['mark_read'])) {
    $notif_id = intval($_GET['mark_read']);
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $user_id]);
    header('Location: user.php');
    exit;
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header('Location: user.php');
    exit;
}

// Calculate days remaining
$days_remaining = 0;
$subscription_active = false;
$subscription_pending = false;

if ($current_payment) {
    if ($current_payment['status'] == 'completed') {
        $subscription_active = true;
        $end_date = new DateTime($current_payment['end_date']);
        $today = new DateTime();
        $days_remaining = $today->diff($end_date)->days;
        
        // Create notification if expiring soon (only once)
        if ($days_remaining <= 3 && $days_remaining > 0) {
            $check = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'expiry_soon' AND DATE(created_at) = CURDATE()");
            $check->execute([$user_id]);
            
            if ($check->rowCount() == 0) {
                $title = $days_remaining == 1 ? "⚠️ Expires tomorrow!" : "⚠️ $days_remaining days left";
                $message = "Your subscription expires on " . date('d M Y', strtotime($current_payment['end_date']));
                
                $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'expiry_soon', ?, ?)");
                $notif->execute([$user_id, $title, $message]);
            }
        }
    } elseif ($current_payment['status'] == 'pending') {
        $subscription_pending = true;
    }
}

// Check if user status is pending
$user_pending = ($user['status'] == 'pending');

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Current section
$current_section = $_GET['section'] ?? 'dashboard';
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
    
    <title>Olavan — Dashboard</title>
    <!-- Google Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
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

        /* PENDING BANNER */
        .pending-banner {
            background: var(--warning-bg);
            border: 1px solid var(--warning);
            color: var(--warning);
            padding: 16px 24px;
            margin: 16px 24px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1rem;
        }

        .pending-banner .material-icons {
            font-size: 24px;
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

        .sidebar-user {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sidebar-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
        }

        .sidebar-user-info h3 {
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .sidebar-user-info p {
            font-size: 0.8rem;
            color: var(--text-secondary);
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
            max-width: 1200px;
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

        /* PROFILE CARD */
        .profile-card {
            background: var(--surface);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .profile-card:hover {
            border-color: var(--accent);
        }

        .profile-image-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--surface-light);
            border: 3px solid var(--accent);
            overflow: hidden;
            flex-shrink: 0;
        }

        .profile-image-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-image-large .material-icons {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: var(--text-secondary);
        }

        .profile-info {
            flex: 1;
        }

        .profile-info h2 {
            font-size: 1.4rem;
            margin-bottom: 4px;
        }

        .profile-info p {
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
        }

        .stat-card h3 {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--accent);
            line-height: 1;
        }

        /* EXPIRY TIMER */
        .expiry-timer {
            background: linear-gradient(135deg, var(--accent), #e67e22);
            border-radius: 24px;
            padding: 28px;
            color: white;
            margin-bottom: 24px;
        }

        .timer-label {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 12px;
        }

        .timer-value {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1;
        }

        .timer-unit {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-left: 8px;
        }

        /* PENDING CARD */
        .pending-card {
            background: var(--warning-bg);
            border: 1px solid var(--warning);
            border-radius: 24px;
            padding: 32px;
            text-align: center;
            margin-bottom: 24px;
        }

        .pending-card .material-icons {
            font-size: 64px;
            color: var(--warning);
            margin-bottom: 16px;
        }

        .pending-card h3 {
            font-size: 1.5rem;
            color: var(--warning);
            margin-bottom: 8px;
        }

        .pending-card p {
            color: var(--text-secondary);
        }

        /* QUICK ACTIONS */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }

        .action-btn {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .action-btn .material-icons {
            font-size: 32px;
            color: var(--accent);
        }

        /* MESSAGES */
        .message {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
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

        /* FORMS */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper .material-icons {
            position: absolute;
            left: 16px;
            color: var(--text-muted);
            font-size: 20px;
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 16px 16px 16px 52px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 16px;
            font-size: 1rem;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            outline: none;
            border-color: var(--accent);
        }

        /* FILE UPLOAD */
        .file-upload {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            background: var(--surface-light);
            cursor: pointer;
            transition: all 0.2s;
        }

        .file-upload:hover {
            border-color: var(--accent);
        }

        .file-upload .material-icons {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .file-upload input {
            display: none;
        }

        /* TERMS BOX */
        .terms-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: var(--surface-light);
            border-radius: 16px;
            margin: 20px 0;
        }

        .terms-box input {
            width: 20px;
            height: 20px;
            accent-color: var(--accent);
        }

        /* BUTTONS */
        .btn {
            padding: 16px 24px;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            font-size: 1rem;
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
            width: 100%;
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
        }

        /* PAYMENT LIST */
        .payment-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .payment-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px;
        }

        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .payment-date {
            font-weight: 600;
            font-size: 1rem;
        }

        .payment-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent);
        }

        .payment-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 16px 0;
        }

        .payment-detail span {
            display: block;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .payment-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-light);
        }

        /* BADGES */
        .badge {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
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

        /* IMAGE UPLOAD SECTION (like admin.php) */
        .image-upload-section {
            text-align: center;
            margin-bottom: 32px;
        }

        .avatar-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            margin: 0 auto 16px;
            overflow: hidden;
            border: 3px solid var(--accent);
            cursor: pointer;
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-preview .material-icons {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
            color: var(--text-secondary);
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
            border-radius: 24px;
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
            text-align: center;
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

        .modal-image {
            max-width: 100%;
            max-height: 300px;
            object-fit: contain;
            border-radius: 12px;
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
        </div>
        
        <div class="sidebar-user">
            <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" class="sidebar-avatar">
            <?php else: ?>
                <span class="material-icons sidebar-avatar" style="font-size: 50px; color: var(--text-secondary);">account_circle</span>
            <?php endif; ?>
            <div class="sidebar-user-info">
                <h3><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></h3>
                <p><?php echo htmlspecialchars($user['phone_number']); ?></p>
            </div>
        </div>
        
        <ul class="sidebar-menu">
            <li><a onclick="showSection('dashboard'); closeSidebar()" class="<?php echo $current_section == 'dashboard' ? 'active' : ''; ?>" id="menuDashboard">
                <span class="material-icons">dashboard</span> <span data-i18n="dashboard">Dashboard</span>
            </a></li>
            <li><a onclick="showSection('upload'); closeSidebar()" class="<?php echo $current_section == 'upload' ? 'active' : ''; ?>" id="menuUpload">
                <span class="material-icons">upload</span> <span data-i18n="upload">Upload Payment</span>
            </a></li>
            <li><a onclick="showSection('history'); closeSidebar()" class="<?php echo $current_section == 'history' ? 'active' : ''; ?>" id="menuHistory">
                <span class="material-icons">history</span> <span data-i18n="history">Payment History</span>
            </a></li>
            <li><a onclick="showSection('profile'); closeSidebar()" class="<?php echo $current_section == 'profile' ? 'active' : ''; ?>" id="menuProfile">
                <span class="material-icons">person</span> <span data-i18n="profile">Profile Settings</span>
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
                <span>OLAVAN</span>
            </div>
        </div>
        
        <div class="header-right">
            <!-- Notifications -->
            <div class="notif-wrapper">
                <button class="notif-btn" onclick="toggleNotifications()">
                    <span class="material-icons">notifications</span>
                    <?php if ($notification_count > 0): ?>
                        <span class="notif-badge"><?php echo $notification_count; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <span class="material-icons" style="font-size: 20px;">notifications</span>
                            <span data-i18n="notifications">Notifications</span>
                        </span>
                        <?php if ($notification_count > 0): ?>
                            <a href="?mark_all_read=1" style="color: var(--accent); text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                <span class="material-icons" style="font-size: 18px;">done_all</span>
                                <span data-i18n="mark_all_read">Mark all read</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($notifications)): ?>
                        <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
                            <span class="material-icons" style="font-size: 48px; margin-bottom: 10px;">notifications_off</span>
                            <p data-i18n="no_notifications">No new notifications</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" onclick="window.location='?mark_read=<?php echo $notif['id']; ?>'">
                                <div style="display: flex; align-items: center; gap: 8px; font-weight: 600; margin-bottom: 4px;">
                                    <?php 
                                    if ($notif['type'] == 'payment_approved') echo '<span class="material-icons" style="color: var(--success);">check_circle</span>';
                                    elseif ($notif['type'] == 'payment_rejected') echo '<span class="material-icons" style="color: var(--danger);">cancel</span>';
                                    elseif ($notif['type'] == 'expiry_soon') echo '<span class="material-icons" style="color: var(--warning);">warning</span>';
                                    else echo '<span class="material-icons" style="color: var(--info);">info</span>';
                                    ?>
                                    <?php echo htmlspecialchars($notif['title']); ?>
                                </div>
                                <div style="font-size: 0.9rem; margin-left: 28px;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted); margin-left: 28px; margin-top: 4px;">
                                    <?php echo date('d M H:i', strtotime($notif['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- User Menu -->
            <div class="user-menu-wrapper">
                <button class="user-menu-btn" onclick="toggleUserMenu()">
                    <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" class="user-avatar-small">
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

    <!-- Pending User Banner -->
    <?php if ($user_pending): ?>
        <div class="pending-banner">
            <span class="material-icons">info</span>
            <span data-i18n="pending_approval">⏳ Your account is pending admin approval. You'll be notified once approved.</span>
        </div>
    <?php endif; ?>

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
            <!-- Profile Card -->
            <div class="profile-card" onclick="showSection('profile')">
                <div class="profile-image-large">
                    <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_image']); ?>">
                    <?php else: ?>
                        <span class="material-icons">account_circle</span>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></h2>
                    <p><?php echo htmlspecialchars($user['phone_number']); ?></p>
                    <?php if ($subscription_active): ?>
                        <span class="badge badge-success">
                            <span class="material-icons" style="font-size: 14px;">check_circle</span>
                            <span data-i18n="active">Active</span>
                        </span>
                    <?php elseif ($subscription_pending): ?>
                        <span class="badge badge-warning">
                            <span class="material-icons" style="font-size: 14px;">schedule</span>
                            <span data-i18n="pending">Pending</span>
                        </span>
                    <?php else: ?>
                        <span class="badge badge-danger">
                            <span class="material-icons" style="font-size: 14px;">cancel</span>
                            <span data-i18n="inactive">Inactive</span>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="material-icons" style="margin-left: auto; color: var(--text-muted);">chevron_right</span>
            </div>

            <?php if ($user_pending): ?>
                <!-- Pending Approval Card -->
                <div class="pending-card">
                    <span class="material-icons">hourglass_empty</span>
                    <h3 data-i18n="pending_approval_title">Account Pending Approval</h3>
                    <p data-i18n="pending_approval_message">Your account is awaiting admin verification. This usually takes 24-48 hours.</p>
                </div>
            <?php elseif (!$subscription_active && !$subscription_pending): ?>
                <!-- No Subscription Card -->
                <div class="pending-card" style="background: var(--info-bg); border-color: var(--info);">
                    <span class="material-icons" style="color: var(--info);">info</span>
                    <h3 style="color: var(--info);" data-i18n="no_subscription">No Active Subscription</h3>
                    <p data-i18n="make_payment">Make a payment to activate your subscription</p>
                    <button class="btn btn-primary" onclick="showSection('upload')" style="margin-top: 16px; width: auto;">
                        <span class="material-icons">upload</span> <span data-i18n="pay_now">Pay Now</span>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><span data-i18n="status">Status</span></h3>
                    <div class="stat-value">
                        <?php 
                        if ($subscription_active) echo '✓';
                        elseif ($subscription_pending) echo '⏳';
                        else echo '⛔';
                        ?>
                    </div>
                </div>
                
                <?php if ($subscription_active): ?>
                    <div class="stat-card">
                        <h3 data-i18n="days_left">Days Left</h3>
                        <div class="stat-value"><?php echo $days_remaining; ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <h3 data-i18n="months">Months</h3>
                        <div class="stat-value"><?php echo $current_payment['months_paid']; ?></div>
                    </div>
                <?php endif; ?>
                
                <div class="stat-card">
                    <h3 data-i18n="payments">Payments</h3>
                    <div class="stat-value"><?php echo count($payments); ?></div>
                </div>
            </div>

            <!-- Expiry Timer -->
            <?php if ($subscription_active): ?>
                <div class="expiry-timer">
                    <div class="timer-label" data-i18n="expires_in">Your subscription expires in</div>
                    <div>
                        <span class="timer-value"><?php echo $days_remaining; ?></span>
                        <span class="timer-unit" data-i18n="days">days</span>
                    </div>
                    <div style="margin-top: 12px; font-size: 1rem; opacity: 0.9;">
                        <?php echo date('d M Y', strtotime($current_payment['end_date'])); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="action-btn" onclick="showSection('upload')">
                    <span class="material-icons">upload</span>
                    <span data-i18n="pay_now">Pay Now</span>
                </div>
                <div class="action-btn" onclick="showSection('history')">
                    <span class="material-icons">history</span>
                    <span data-i18n="history">History</span>
                </div>
                <div class="action-btn" onclick="showSection('profile')">
                    <span class="material-icons">person</span>
                    <span data-i18n="profile">Profile</span>
                </div>
            </div>
        </div>

        <!-- UPLOAD SECTION -->
        <div id="upload" class="section <?php echo $current_section == 'upload' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <span class="material-icons" style="color: var(--accent);">upload</span>
                <span data-i18n="upload_payment">Upload Payment</span>
            </h2>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label data-i18n="months">Months</label>
                    <div class="input-wrapper">
                        <span class="material-icons">calendar_today</span>
                        <select name="months" required>
                            <option value="1">1 <span data-i18n="month">Month</span></option>
                            <option value="2">2 <span data-i18n="months">Months</span></option>
                            <option value="3">3 <span data-i18n="months">Months</span></option>
                            <option value="6">6 <span data-i18n="months">Months</span></option>
                            <option value="12">12 <span data-i18n="months">Months</span></option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="method">Payment Method</label>
                    <div class="input-wrapper">
                        <span class="material-icons">payment</span>
                        <select name="payment_method" required>
                            <option value="Mobile Money">📱 Mobile Money</option>
                            <option value="Bank Transfer">🏦 Bank Transfer</option>
                            <option value="Cash">💵 Cash</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="phone_used">Phone Used</label>
                    <div class="input-wrapper">
                        <span class="material-icons">phone</span>
                        <input type="tel" name="payment_phone" placeholder="+257 XX XXX XXX" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="transaction_id">Transaction ID</label>
                    <div class="input-wrapper">
                        <span class="material-icons">qr_code</span>
                        <input type="text" name="transaction_id" placeholder="Optional">
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="amount">Amount</label>
                    <div class="input-wrapper">
                        <span class="material-icons">attach_money</span>
                        <input type="number" name="amount_paid" step="0.01" placeholder="0.00" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="proof">Proof</label>
                    <div class="file-upload" onclick="document.getElementById('proofFile').click()">
                        <span class="material-icons">cloud_upload</span>
                        <p data-i18n="tap_to_upload">Tap to upload</p>
                        <small style="color: var(--text-muted);">JPG/PNG only</small>
                        <input type="file" name="proof" id="proofFile" accept=".jpg,.jpeg,.png" required>
                    </div>
                </div>
                
                <div class="terms-box">
                    <input type="checkbox" name="terms" id="terms" required>
                    <label for="terms" data-i18n="terms">I confirm the information is correct</label>
                </div>
                
                <button type="submit" name="upload_proof" class="btn btn-primary">
                    <span class="material-icons">check</span>
                    <span data-i18n="submit">Submit Payment</span>
                </button>
            </form>
        </div>

        <!-- HISTORY SECTION -->
        <div id="history" class="section <?php echo $current_section == 'history' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <span class="material-icons" style="color: var(--accent);">history</span>
                <span data-i18n="payment_history">Payment History</span>
            </h2>
            
            <?php if (empty($payments)): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <span class="material-icons" style="font-size: 64px; color: var(--text-muted); margin-bottom: 16px;">receipt</span>
                    <p data-i18n="no_history">No payment history yet</p>
                    <button class="btn btn-primary" onclick="showSection('upload')" style="margin-top: 20px; width: auto;">
                        <span class="material-icons">upload</span>
                        <span data-i18n="pay_now">Pay Now</span>
                    </button>
                </div>
            <?php else: ?>
                <div class="payment-list">
                    <?php foreach ($payments as $payment): ?>
                        <div class="payment-item">
                            <div class="payment-header">
                                <span class="payment-date"><?php echo date('d M Y', strtotime($payment['created_at'])); ?></span>
                                <span class="payment-amount"><?php echo number_format($payment['amount_paid'] ?? 0, 0); ?></span>
                            </div>
                            
                            <div class="payment-details">
                                <div class="payment-detail">
                                    <span data-i18n="months">Months</span>
                                    <?php echo $payment['months_paid']; ?>
                                </div>
                                <div class="payment-detail">
                                    <span data-i18n="method">Method</span>
                                    <?php echo substr($payment['payment_method'] ?? '', 0, 10); ?>
                                </div>
                                <div class="payment-detail">
                                    <span data-i18n="expires">Expires</span>
                                    <?php echo date('d M', strtotime($payment['end_date'])); ?>
                                </div>
                                <div class="payment-detail">
                                    <span data-i18n="txn">Txn ID</span>
                                    <?php echo substr($payment['transaction_id'] ?? 'N/A', 0, 6); ?>
                                </div>
                            </div>
                            
                            <div class="payment-footer">
                                <span class="badge 
                                    <?php 
                                    if ($payment['status'] == 'completed') echo 'badge-success';
                                    elseif ($payment['status'] == 'pending') echo 'badge-warning';
                                    else echo 'badge-danger';
                                    ?>">
                                    <span class="material-icons" style="font-size: 14px;">
                                        <?php 
                                        if ($payment['status'] == 'completed') echo 'check_circle';
                                        elseif ($payment['status'] == 'pending') echo 'schedule';
                                        else echo 'cancel';
                                        ?>
                                    </span>
                                    <?php 
                                    if ($payment['status'] == 'completed') echo '<span data-i18n="completed">Completed</span>';
                                    elseif ($payment['status'] == 'pending') echo '<span data-i18n="pending">Pending</span>';
                                    else echo '<span data-i18n="unpaid">Unpaid</span>';
                                    ?>
                                </span>
                                
                                <?php if ($payment['proof_url'] && file_exists($payment['proof_url'])): ?>
                                    <button class="btn-outline" style="padding: 10px 20px;" onclick="viewProof('<?php echo $payment['proof_url']; ?>')">
                                        <span class="material-icons" style="font-size: 18px;">visibility</span>
                                        <span data-i18n="view">View</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- PROFILE SECTION (like admin.php) -->
        <div id="profile" class="section <?php echo $current_section == 'profile' ? 'active' : ''; ?>">
            <h2 style="margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <span class="material-icons" style="color: var(--accent);">person</span>
                <span data-i18n="profile_settings">Profile Settings</span>
            </h2>
            
            <!-- Profile Image Upload Section (like admin.php) -->
            <div class="image-upload-section">
                <div class="avatar-preview" onclick="document.getElementById('profileImageInput').click()">
                    <?php if (!empty($user['profile_image']) && file_exists($user['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" id="profilePreview">
                    <?php else: ?>
                        <span class="material-icons" id="profilePreview">account_circle</span>
                    <?php endif; ?>
                </div>
                
                <form method="POST" enctype="multipart/form-data" style="margin-top: 16px;">
                    <div class="file-upload" style="padding: 20px;" onclick="document.getElementById('profileImageInput').click()">
                        <span class="material-icons">cloud_upload</span>
                        <p data-i18n="click_to_upload">Click to upload new image</p>
                        <input type="file" name="profile_image" id="profileImageInput" accept="image/*" onchange="previewImage(this)">
                    </div>
                    <button type="submit" name="upload_profile_image" class="btn btn-primary" style="margin-top: 16px;">
                        <span class="material-icons">save</span> <span data-i18n="update_image">Update Image</span>
                    </button>
                </form>
            </div>
            
            <!-- Profile Form -->
            <form method="POST">
                <div class="form-group">
                    <label data-i18n="full_name">Full Name</label>
                    <div class="input-wrapper">
                        <span class="material-icons">person</span>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="phone">Phone</label>
                    <div class="input-wrapper">
                        <span class="material-icons">phone</span>
                        <input type="text" value="<?php echo htmlspecialchars($user['phone_number']); ?>" disabled>
                    </div>
                </div>
                
                <div class="form-group">
                    <label data-i18n="country">Country</label>
                    <div class="input-wrapper">
                        <span class="material-icons">public</span>
                        <select name="country">
                            <option value="Burundi" <?php echo $user['country'] == 'Burundi' ? 'selected' : ''; ?>>🇧🇮 Burundi</option>
                            <option value="Rwanda" <?php echo $user['country'] == 'Rwanda' ? 'selected' : ''; ?>>🇷🇼 Rwanda</option>
                            <option value="DRC" <?php echo $user['country'] == 'DRC' ? 'selected' : ''; ?>>🇨🇩 DRC</option>
                            <option value="Tanzania" <?php echo $user['country'] == 'Tanzania' ? 'selected' : ''; ?>>🇹🇿 Tanzania</option>
                        </select>
                    </div>
                </div>
                
                <h3 style="margin: 32px 0 16px; display: flex; align-items: center; gap: 8px;">
                    <span class="material-icons">lock</span>
                    <span data-i18n="change_password">Change Password</span>
                </h3>
                
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
                
                <button type="submit" name="update_profile" class="btn btn-primary" onclick="return validatePassword()">
                    <span class="material-icons">save</span>
                    <span data-i18n="save">Save Changes</span>
                </button>
            </form>
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
            <div class="modal-body">
                <img id="modalImage" class="modal-image">
            </div>
        </div>
    </div>

    <script>
        // ==================== TRANSLATIONS ====================
        const translations = {
            en: {
                dashboard: 'Dashboard',
                upload: 'Upload Payment',
                history: 'Payment History',
                profile: 'Profile Settings',
                notifications: 'Notifications',
                mark_all_read: 'Mark all read',
                no_notifications: 'No new notifications',
                status: 'Status',
                days_left: 'Days Left',
                months: 'Months',
                payments: 'Payments',
                expires_in: 'Your subscription expires in',
                days: 'days',
                pay_now: 'Pay Now',
                profile: 'Profile',
                upload_payment: 'Upload Payment',
                month: 'Month',
                method: 'Payment Method',
                phone_used: 'Phone Used',
                transaction_id: 'Transaction ID',
                amount: 'Amount',
                proof: 'Proof',
                tap_to_upload: 'Tap to upload',
                terms: 'I confirm the information is correct',
                submit: 'Submit Payment',
                payment_history: 'Payment History',
                no_history: 'No payment history yet',
                expires: 'Expires',
                txn: 'Txn ID',
                view: 'View',
                change_photo: 'Change Photo',
                full_name: 'Full Name',
                phone: 'Phone',
                country: 'Country',
                change_password: 'Change Password',
                current_password: 'Current Password',
                new_password: 'New Password',
                confirm_password: 'Confirm Password',
                save: 'Save Changes',
                payment_proof: 'Payment Proof',
                theme: 'Theme',
                logout: 'Logout',
                active: 'Active',
                pending: 'Pending',
                inactive: 'Inactive',
                completed: 'Completed',
                unpaid: 'Unpaid',
                pending_approval: '⏳ Your account is pending admin approval. You\'ll be notified once approved.',
                pending_approval_title: 'Account Pending Approval',
                pending_approval_message: 'Your account is awaiting admin verification. This usually takes 24-48 hours.',
                no_subscription: 'No Active Subscription',
                make_payment: 'Make a payment to activate your subscription',
                profile_settings: 'Profile Settings',
                click_to_upload: 'Click to upload new image',
                update_image: 'Update Image'
            },
            fr: {
                dashboard: 'Tableau de bord',
                upload: 'Télécharger paiement',
                history: 'Historique',
                profile: 'Paramètres',
                notifications: 'Notifications',
                mark_all_read: 'Tout lire',
                no_notifications: 'Aucune notification',
                status: 'Statut',
                days_left: 'Jours restants',
                months: 'Mois',
                payments: 'Paiements',
                expires_in: 'Votre abonnement expire dans',
                days: 'jours',
                pay_now: 'Payer',
                profile: 'Profil',
                upload_payment: 'Télécharger paiement',
                month: 'Mois',
                method: 'Méthode',
                phone_used: 'Téléphone',
                transaction_id: 'ID Transaction',
                amount: 'Montant',
                proof: 'Preuve',
                tap_to_upload: 'Appuyez pour télécharger',
                terms: 'Je confirme les informations',
                submit: 'Soumettre',
                payment_history: 'Historique',
                no_history: 'Aucun historique',
                expires: 'Expire',
                txn: 'ID',
                view: 'Voir',
                change_photo: 'Changer photo',
                full_name: 'Nom complet',
                phone: 'Téléphone',
                country: 'Pays',
                change_password: 'Changer mot de passe',
                current_password: 'Mot de passe actuel',
                new_password: 'Nouveau',
                confirm_password: 'Confirmer',
                save: 'Enregistrer',
                payment_proof: 'Preuve de paiement',
                theme: 'Thème',
                logout: 'Déconnexion',
                active: 'Actif',
                pending: 'En attente',
                inactive: 'Inactif',
                completed: 'Terminé',
                unpaid: 'Impayé',
                pending_approval: '⏳ Votre compte est en attente d\'approbation admin.',
                pending_approval_title: 'Compte en attente',
                pending_approval_message: 'Vérification en cours (24-48h)',
                no_subscription: 'Pas d\'abonnement actif',
                make_payment: 'Effectuez un paiement',
                profile_settings: 'Paramètres',
                click_to_upload: 'Cliquez pour uploader',
                update_image: 'Mettre à jour'
            },
            rn: {
                dashboard: 'Dashubodi',
                upload: 'Kwishura',
                history: 'Amateka',
                profile: 'Igenamiterere',
                notifications: 'Amatangazo',
                mark_all_read: 'Soma yose',
                no_notifications: 'Nta matangazo',
                status: 'Etat',
                days_left: 'Imisi isigaye',
                months: 'Amezi',
                payments: 'Amayishwe',
                expires_in: 'Ukwiyandikisha kwawe kurashira',
                days: 'imisi',
                pay_now: 'Kwishura',
                profile: 'Igenamiterere',
                upload_payment: 'Kwishura ikimenyetso',
                month: 'Ukwezi',
                method: 'Uburyo',
                phone_used: 'Telefone',
                transaction_id: 'ID',
                amount: 'Amahera',
                proof: 'Ikimenyetso',
                tap_to_upload: 'Kanda gushyira',
                terms: 'Ndemeza amakuru',
                submit: 'Ohereza',
                payment_history: 'Amateka yishura',
                no_history: 'Nta mateka',
                expires: 'Birashira',
                txn: 'ID',
                view: 'Raba',
                change_photo: 'Hindura ifoto',
                full_name: 'Amazina',
                phone: 'Telefone',
                country: 'Igihugu',
                change_password: 'Hindura ijambo ibanga',
                current_password: 'Ijambo ibanga',
                new_password: 'Ijambo ibanga rishya',
                confirm_password: 'Emeza',
                save: 'Kubika',
                payment_proof: 'Ikimenyetso',
                theme: 'Igitondo',
                logout: 'Kuvayo',
                active: 'Igikora',
                pending: 'Kirategereje',
                inactive: 'Ntigikora',
                completed: 'Yarangiye',
                unpaid: 'Ntayishuwe',
                pending_approval: '⏳ Konti yawe irategerezwa kwemezwa.',
                pending_approval_title: 'Konti irategereje',
                pending_approval_message: 'Kugenzura biratwara 24-48h',
                no_subscription: 'Nta kwiyandikisha',
                make_payment: 'Kwishura',
                profile_settings: 'Igenamiterere',
                click_to_upload: 'Kanda gushyiramwo',
                update_image: 'Vugurura'
            },
            sw: {
                dashboard: 'Dashibodi',
                upload: 'Pakia',
                history: 'Historia',
                profile: 'Wasifu',
                notifications: 'Arifa',
                mark_all_read: 'Soma zote',
                no_notifications: 'Hakuna arifa',
                status: 'Hali',
                days_left: 'Siku zilizobaki',
                months: 'Miezi',
                payments: 'Malipo',
                expires_in: 'Usajili wako unaisha',
                days: 'siku',
                pay_now: 'Lipa Sasa',
                profile: 'Wasifu',
                upload_payment: 'Pakia Malipo',
                month: 'Mwezi',
                method: 'Njia',
                phone_used: 'Namba ya simu',
                transaction_id: 'Namba ya malipo',
                amount: 'Kiasi',
                proof: 'Uthibitisho',
                tap_to_upload: 'Bonyeza kupakia',
                terms: 'Nathibitisha taarifa ni sahihi',
                submit: 'Wasilisha',
                payment_history: 'Historia ya Malipo',
                no_history: 'Hakuna historia',
                expires: 'Inaisha',
                txn: 'Namba',
                view: 'Angalia',
                change_photo: 'Badilisha picha',
                full_name: 'Jina kamili',
                phone: 'Simu',
                country: 'Nchi',
                change_password: 'Badilisha nywila',
                current_password: 'Nywila ya sasa',
                new_password: 'Nywila mpya',
                confirm_password: 'Thibitisha',
                save: 'Hifadhi',
                payment_proof: 'Uthibitisho',
                theme: 'Mandhari',
                logout: 'Toka',
                active: 'Inatumika',
                pending: 'Inasubiri',
                inactive: 'Haifanyi kazi',
                completed: 'Imekamilika',
                unpaid: 'Haijalipwa',
                pending_approval: '⏳ Akaunti yako inasubiri kuidhinishwa.',
                pending_approval_title: 'Akaunti Inasubiri',
                pending_approval_message: 'Uthibitishaji unachukua saa 24-48',
                no_subscription: 'Hakuna usajili',
                make_payment: 'Fanya malipo',
                profile_settings: 'Mipangilio',
                click_to_upload: 'Bonyeza kupakia',
                update_image: 'Sasisha picha'
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

        // ==================== PROOF VIEWER ====================
        function viewProof(url) {
            document.getElementById('modalImage').src = url;
            document.getElementById('proofModal').classList.add('show');
        }

        function closeProofModal() {
            document.getElementById('proofModal').classList.remove('show');
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
                alert(translations[currentLang]['confirm_password'] || 'Passwords do not match!');
                return false;
            }
            return true;
        }

        // ==================== FILE UPLOAD PREVIEW ====================
        document.getElementById('proofFile')?.addEventListener('change', function(e) {
            if (e.target.files[0]) {
                document.querySelector('.file-upload p').innerHTML = `✓ ${e.target.files[0].name}`;
            }
        });

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

        // ==================== INIT ====================
        applyTranslations(currentLang);
    </script>
</body>
</html>