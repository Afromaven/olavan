<?php
/**
 * Olavan Database Connection File
 * Location: C:/xampp/htdocs/olavan/db.php
 */

$host = 'localhost';
$dbname = 'olavan';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("
        <div style='background: #0a0a0a; color: #ff6b6b; padding: 2rem; text-align: center; font-family: Arial; min-height: 100vh; display: flex; align-items: center; justify-content: center;'>
            <div style='background: #141414; padding: 2rem; border-radius: 1rem; border: 1px solid #2a2a2a; max-width: 400px;'>
                <h2 style='margin-bottom: 1rem; color: #d35400;'>🔌 Connection Error</h2>
                <p>Unable to connect to database. Please check:</p>
                <ul style='list-style: none; margin-top: 1rem; text-align: left; color: #a0a0a0;'>
                    <li>✓ XAMPP MySQL is running</li>
                    <li>✓ Database 'olavan' exists</li>
                </ul>
                <a href='index.php' style='display: inline-block; margin-top: 1.5rem; color: #d35400; text-decoration: none;'>⟲ Retry</a>
            </div>
        </div>
    ");
}

function getDB() {
    global $pdo;
    return $pdo;
}
?>