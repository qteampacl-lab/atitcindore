<?php
// ═══════════════════════════════════════════════════════════
// ATITC Portal - Database Configuration
// File: config.php
// ─────────────────────────────────────────────────────────
// ⚠️  APNA DATABASE DETAILS YAHAN BHAREN ⚠️
// ═══════════════════════════════════════════════════════════

define('DB_HOST',   getenv('DB_HOST') ?: 'localhost');      // Hosting server (localhost for XAMPP/WAMP)
define('DB_PORT',   intval(getenv('DB_PORT') ?: 3306));      // MySQL port (default 3306)
define('DB_NAME',   getenv('DB_NAME') ?: 'atitc_portal');   // Database name
define('DB_USER',   getenv('DB_USER') ?: 'root');           // MySQL username (change if different)
define('DB_PASS',   getenv('DB_PASS') ?: '');               // MySQL password (XAMPP blank, WAMP blank, Hosting pe change karo)
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// ─── SECURITY ───────────────────────────────────────────────
// Allowed origins (CORS) - apna domain yahan add karo
define('ALLOWED_ORIGIN', getenv('ALLOWED_ORIGIN') ?: '*'); // Production mein specific domain likho

// Session timeout (seconds)
define('SESSION_TIMEOUT', 28800); // 8 hours

// ─── APP SETTINGS ───────────────────────────────────────────
define('APP_NAME',    'ATITC Portal');
define('APP_VERSION', '2.0 MySQL');

// ─── DO NOT EDIT BELOW ──────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode([
            'status'  => 'error',
            'message' => 'Database connection failed: ' . $e->getMessage(),
            'hint'    => 'config.php mein DB_HOST, DB_NAME, DB_USER, DB_PASS check karo'
        ]));
    }
}

function sendJSON($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function logAction($action, $status = 'success', $details = '') {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO sync_log (action, status, details, ip_address) VALUES (?,?,?,?)")
           ->execute([$action, $status, substr($details, 0, 500), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) { /* silent */ }
}
