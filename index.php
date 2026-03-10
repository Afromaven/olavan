<?php
/**
 * Olavan - Login & Signup Page
 * Location: C:/xampp/htdocs/olavan/index.php
 */

require_once 'db.php';
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['is_admin'] ? 'admin.php' : 'user.php'));
    exit;
}

// Handle Login
if (isset($_POST['login'])) {
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone_number = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Check if user is approved (status = 'accepted') or is admin
        if ($user['status'] == 'accepted' || $user['is_admin'] == 1) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_admin'] = $user['is_admin'];
            header('Location: ' . ($user['is_admin'] ? 'admin.php' : 'user.php'));
            exit;
        } elseif ($user['status'] == 'pending') {
            $login_error = "Your account is pending approval. Please wait for admin confirmation.";
        } elseif ($user['status'] == 'rejected') {
            $login_error = "Your account has been rejected. Please contact support.";
        } else {
            $login_error = "Invalid account status";
        }
    } else {
        $login_error = "Invalid phone or password";
    }
}

// Handle Signup
if (isset($_POST['signup'])) {
    $phone = $_POST['phone'];
    $country = $_POST['country'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Check if phone exists
    $check = $pdo->prepare("SELECT id FROM users WHERE phone_number = ?");
    $check->execute([$phone]);
    
    if ($check->rowCount() == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (phone_number, country, password_hash, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$phone, $country, $password]);
        
        // Get the new user ID
        $new_user_id = $pdo->lastInsertId();
        
        // Create welcome notification
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'welcome', ?, ?)");
        $notif->execute([
            $new_user_id,
            '👋 Welcome to Olavan',
            'Your account has been created and is pending admin approval. You will be notified once approved.'
        ]);
        
        $signup_success = "Account created successfully! Please wait for admin approval before logging in.";
    } else {
        $signup_error = "Phone number already registered";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    
    <!-- Favicons - Using absolute paths from root -->
    <link rel="apple-touch-icon" sizes="180x180" href="/olavan/uploads/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/olavan/uploads/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/olavan/uploads/icons/favicon-16x16.png">
    <link rel="manifest" href="/olavan/uploads/icons/site.webmanifest">
    <link rel="mask-icon" href="/olavan/uploads/icons/safari-pinned-tab.svg" color="#d35400">
    <link rel="shortcut icon" href="/olavan/uploads/icons/favicon.ico">
    <meta name="msapplication-TileColor" content="#d35400">
    <meta name="msapplication-TileImage" content="/olavan/uploads/icons/android-chrome-192x192.png">
    <meta name="theme-color" content="#d35400">
    
    <title>Olavan — Welcome</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><!-- Register Service Worker for PWA -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/olavan/sw.php').then(function(registration) {
            console.log('ServiceWorker registered with scope:', registration.scope);
        }, function(err) {
            console.log('ServiceWorker registration failed:', err);
        });
    });
}
<!-- Register Service Worker for PWA -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/olavan/sw.php', {
            scope: '/olavan/'
        }).then(function(registration) {
            console.log('ServiceWorker registered with scope:', registration.scope);
        }).catch(function(err) {
            console.log('ServiceWorker registration failed:', err);
        });
    });
}
</script>
    <!-- rest of your head -->

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
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
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            transition: background 0.3s;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 20% 50%, rgba(211, 84, 0, 0.03) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(211, 84, 0, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .container {
            width: 100%;
            max-width: 400px;
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Header Controls */
        .header-controls {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-bottom: 20px;
        }

        .control-btn {
            width: 48px;
            height: 48px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text);
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .control-btn:active {
            background: var(--surface-light);
            transform: scale(0.95);
        }

        .language-selector {
            padding: 0 16px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 30px;
            color: var(--text);
            font-size: 0.95rem;
            cursor: pointer;
            outline: none;
            height: 48px;
        }

        /* Main Card */
        .auth-card {
            background: var(--surface);
            border-radius: 32px;
            border: 1px solid var(--border);
            padding: 30px 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo i {
            font-size: 3rem;
            color: var(--accent);
            margin-bottom: 8px;
        }

        .logo h1 {
            font-size: 2rem;
            font-weight: 600;
            background: linear-gradient(135deg, var(--text) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            background: var(--surface-light);
            padding: 6px;
            border-radius: 20px;
            margin-bottom: 30px;
        }

        .tab {
            flex: 1;
            text-align: center;
            padding: 12px;
            border-radius: 16px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .tab i {
            margin-right: 6px;
        }

        .tab.active {
            background: var(--accent);
            color: white;
        }

        /* Forms */
        .auth-form {
            transition: all 0.3s;
        }

        .auth-form.hidden {
            display: none;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            color: var(--text-muted);
            font-size: 1rem;
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 16px 16px 16px 48px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 20px;
            font-size: 1rem;
            -webkit-appearance: none;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            outline: none;
            border-color: var(--accent);
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            left: auto !important;
            cursor: pointer;
            z-index: 2;
        }

        /* Button */
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 20px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:active {
            background: var(--accent-hover);
            transform: scale(0.98);
        }

        /* Messages */
        .message {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            animation: slideDown 0.3s;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.error {
            background: var(--danger-bg);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .message.success {
            background: var(--success-bg);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .message.warning {
            background: var(--warning-bg);
            border: 1px solid var(--warning);
            color: var(--warning);
        }

        .message.info {
            background: var(--info-bg);
            border: 1px solid var(--info);
            color: var(--info);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .auth-card {
                padding: 20px;
            }
            
            .tabs {
                padding: 4px;
            }
            
            .tab {
                padding: 10px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Controls -->
        <div class="header-controls">
            <select class="language-selector" id="languageSelect" onchange="changeLanguage(this.value)">
                <option value="en">🇬🇧 EN</option>
                <option value="fr">🇫🇷 FR</option>
                <option value="rn">🇧🇮 RN</option>
                <option value="sw">🇹🇿 SW</option>
            </select>
            <div class="control-btn" onclick="toggleTheme()" id="themeToggle">
                <i class="fas fa-moon"></i>
            </div>
        </div>

        <!-- Main Auth Card -->
        <div class="auth-card">
            <div class="logo">
                <i class="fas fa-paw"></i>
                <h1>OLAVAN</h1>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" onclick="switchTab('login')" id="loginTab">
                    <i class="fas fa-sign-in-alt"></i>
                    <span data-i18n="login">Login</span>
                </div>
                <div class="tab" onclick="switchTab('signup')" id="signupTab">
                    <i class="fas fa-user-plus"></i>
                    <span data-i18n="signup">Sign Up</span>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($login_error)): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $login_error; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($signup_success)): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $signup_success; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($signup_error)): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $signup_error; ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="auth-form" id="loginForm" method="POST">
                <div class="form-group">
                    <label data-i18n="phone">Phone Number</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone-alt"></i>
                        <input type="tel" name="phone" placeholder="+257 XX XXX XXX" required>
                    </div>
                </div>

                <div class="form-group">
                    <label data-i18n="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="loginPassword" placeholder="••••••••" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('loginPassword')"></i>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-submit">
                    <i class="fas fa-sign-in-alt"></i>
                    <span data-i18n="login">Login</span>
                </button>
            </form>

            <!-- Signup Form -->
            <form class="auth-form hidden" id="signupForm" method="POST">
                <div class="form-group">
                    <label data-i18n="phone">Phone Number</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone-alt"></i>
                        <input type="tel" name="phone" placeholder="+257 XX XXX XXX" required>
                    </div>
                </div>

                <div class="form-group">
                    <label data-i18n="country">Country</label>
                    <div class="input-wrapper">
                        <i class="fas fa-globe-africa"></i>
                        <select name="country" required>
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
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="signupPassword" placeholder="••••••••" minlength="6" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('signupPassword')"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label data-i18n="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirmPassword" placeholder="••••••••" minlength="6" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword('confirmPassword')"></i>
                    </div>
                </div>

                <button type="submit" name="signup" class="btn-submit" onclick="return validateSignup()">
                    <i class="fas fa-user-plus"></i>
                    <span data-i18n="signup">Sign Up</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        // ==================== TRANSLATIONS (100% Client-side) ====================
        const translations = {
            en: {
                login: "Login",
                signup: "Sign Up",
                phone: "Phone Number",
                password: "Password",
                confirm_password: "Confirm Password",
                country: "Country"
            },
            fr: {
                login: "Connexion",
                signup: "S'inscrire",
                phone: "Numéro de téléphone",
                password: "Mot de passe",
                confirm_password: "Confirmer le mot de passe",
                country: "Pays"
            },
            rn: {
                login: "Kwinjira",
                signup: "Kwiyandikisha",
                phone: "Numéro ya téléfone",
                password: "Ijambo ibanga",
                confirm_password: "Emeza ijambo ibanga",
                country: "Igihugu"
            },
            sw: {
                login: "Ingia",
                signup: "Jisajili",
                phone: "Namba ya simu",
                password: "Nywila",
                confirm_password: "Thibitisha nywila",
                country: "Nchi"
            }
        };

        // Current language
        let currentLang = localStorage.getItem('olavan_lang') || 'en';

        // Apply translations
        function applyTranslations(lang) {
            document.querySelectorAll('[data-i18n]').forEach(element => {
                const key = element.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) {
                    element.textContent = translations[lang][key];
                }
            });
            
            // Update select
            document.getElementById('languageSelect').value = lang;
            localStorage.setItem('olavan_lang', lang);
        }

        // Change language
        function changeLanguage(lang) {
            currentLang = lang;
            applyTranslations(lang);
        }

        // ==================== THEME TOGGLE ====================
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'dark';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('olavan_theme', newTheme);
            
            const icon = document.querySelector('#themeToggle i');
            icon.className = newTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
        }

        // Load saved theme
        const savedTheme = localStorage.getItem('olavan_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
        document.querySelector('#themeToggle i').className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';

        // Load saved language
        applyTranslations(currentLang);

        // ==================== TAB SWITCHING ====================
        function switchTab(tab) {
            const loginForm = document.getElementById('loginForm');
            const signupForm = document.getElementById('signupForm');
            const loginTab = document.getElementById('loginTab');
            const signupTab = document.getElementById('signupTab');
            
            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                signupForm.classList.add('hidden');
                loginTab.classList.add('active');
                signupTab.classList.remove('active');
            } else {
                loginForm.classList.add('hidden');
                signupForm.classList.remove('hidden');
                signupTab.classList.add('active');
                loginTab.classList.remove('active');
            }
        }

        // ==================== PASSWORD TOGGLE ====================
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling;
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash toggle-password';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye toggle-password';
            }
        }

        // ==================== SIGNUP VALIDATION ====================
        function validateSignup() {
            const password = document.getElementById('signupPassword').value;
            const confirm = document.getElementById('confirmPassword').value;
            
            if (password !== confirm) {
                alert(translations[currentLang]?.confirm_password || 'Passwords do not match');
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
                let value = this.value.replace(/\D/g, '');
                if (value.length > 0 && !value.startsWith('+')) {
                    value = '+' + value;
                }
                this.value = value;
            });
        });
    </script>
</body>
</html>