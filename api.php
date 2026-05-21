<?php
// ═══════════════════════════════════════════════════════════
// ATITC Portal - MySQL REST API
// File: api.php
// Usage: fetch('api.php?action=getStudents')
//        fetch('api.php', {method:'POST', body: JSON.stringify({action:'addStudent', data:{...}})})
// ═══════════════════════════════════════════════════════════

require_once 'config.php';

// ─── CORS & HEADERS ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ─── ROUTE REQUEST ──────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} elseif ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? ($_POST['action'] ?? '');
    $data   = $body['data']   ?? [];
}

// ─── DISPATCH ───────────────────────────────────────────────
try {
    switch ($action) {

        // ── AUTH ──────────────────────────────────────────────
        case 'login':
            handleLogin($data);
            break;

        // ── PING ──────────────────────────────────────────────
        case 'ping':
            sendJSON(['status' => 'ok', 'msg' => '✅ ATITC MySQL API Running', 'time' => date('c'), 'version' => APP_VERSION]);

        // ── STUDENTS ──────────────────────────────────────────
        case 'getStudents':
            getStudents();
            break;

        case 'addStudent':
            addStudent($data);
            break;

        case 'updateStudent':
            updateStudent($data);
            break;

        case 'deleteStudent':
            deleteStudent($data['id'] ?? $data['student_id'] ?? '');
            break;

        case 'getNextId':
            getNextStudentId();
            break;

        // ── ATTENDANCE ────────────────────────────────────────
        case 'getAttendance':
            getAttendance($_GET['date'] ?? ($data['date'] ?? date('Y-m-d')));
            break;

        case 'saveAttendance':
            saveAttendance($data);
            break;

        case 'getAttendanceReport':
            getAttendanceReport($data);
            break;

        case 'getAttendanceSummary':
            getAttendanceSummary($data);
            break;

        // ── TRADES ────────────────────────────────────────────
        case 'getTrades':
            getTrades();
            break;

        case 'addTrade':
            addTrade($data);
            break;

        case 'deleteTrade':
            deleteTrade($data['name'] ?? '');
            break;

        // ── DASHBOARD ─────────────────────────────────────────
        case 'getDashboard':
            getDashboard();
            break;

        // ── SETTINGS ──────────────────────────────────────────
        case 'getSettings':
            getSettings();
            break;

        case 'saveSettings':
            saveSettings($data);
            break;

        // ── SYNC ALL (for import) ──────────────────────────────
        case 'syncAll':
            syncAll($data);
            break;

        // ── EXPORT ────────────────────────────────────────────
        case 'exportData':
            exportData();
            break;

        default:
            sendJSON(['status' => 'error', 'message' => "Unknown action: '$action'"], 400);
    }
} catch (PDOException $e) {
    logAction($action, 'error', $e->getMessage());
    sendJSON(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    logAction($action, 'error', $e->getMessage());
    sendJSON(['status' => 'error', 'message' => $e->getMessage()], 500);
}

// ═══════════════════════════════════════════════════════════
//  AUTH
// ═══════════════════════════════════════════════════════════
function handleLogin($data) {
    $user = trim($data['username'] ?? '');
    $pass = trim($data['password'] ?? '');
    if (!$user || !$pass) sendJSON(['status' => 'error', 'message' => 'Username & password required'], 400);

    $db = getDB();
    // Check settings table (plain text - simple auth)
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_user'");
    $stmt->execute();
    $adminUser = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_pass'");
    $stmt->execute();
    $adminPass = $stmt->fetchColumn();

    if ($user === $adminUser && $pass === $adminPass) {
        logAction('login', 'success', "User: $user");
        sendJSON(['status' => 'ok', 'role' => 'admin', 'username' => $user]);
    }
    logAction('login', 'failed', "User: $user");
    sendJSON(['status' => 'error', 'message' => 'Invalid username or password'], 401);
}

// ═══════════════════════════════════════════════════════════
//  STUDENTS
// ═══════════════════════════════════════════════════════════
function getStudents() {
    $db    = getDB();
    $trade = $_GET['trade'] ?? '';
    $search = $_GET['search'] ?? '';

    $sql  = "SELECT student_id as id, name, father, mother, mobile, aadhaar, qual, trade, session, address, 
                    DATE_FORMAT(dob,'%Y-%m-%d') as dob, photo,
                    DATE_FORMAT(enroll_date,'%Y-%m-%d') as date
             FROM students WHERE 1=1";
    $params = [];

    if ($trade) { $sql .= " AND trade = ?"; $params[] = $trade; }
    if ($search) {
        $sql .= " AND (name LIKE ? OR student_id LIKE ? OR mobile LIKE ?)";
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like]);
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    sendJSON(['status' => 'ok', 'data' => $stmt->fetchAll()]);
}

function getNextStudentId() {
    $db = getDB();
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT next_val FROM id_counter WHERE name = 'student' FOR UPDATE");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    $nextId = 'STD' . str_pad($val, 3, '0', STR_PAD_LEFT);
    $db->commit();
    sendJSON(['status' => 'ok', 'nextId' => $nextId, 'nextVal' => $val]);
}

function addStudent($d) {
    if (empty($d['name']) || empty($d['father']) || empty($d['mobile']) || empty($d['trade'])) {
        sendJSON(['status' => 'error', 'message' => 'Name, Father, Mobile, Trade required'], 400);
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        // Get and increment ID counter
        $stmt = $db->prepare("SELECT next_val FROM id_counter WHERE name = 'student' FOR UPDATE");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        $sid = 'STD' . str_pad($val, 3, '0', STR_PAD_LEFT);

        // Verify trade exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM trades WHERE name = ?");
        $stmt->execute([$d['trade']]);
        if (!$stmt->fetchColumn()) {
            $db->rollBack();
            sendJSON(['status' => 'error', 'message' => 'Trade not found: ' . $d['trade']], 400);
        }

        $dob   = !empty($d['dob']) ? $d['dob'] : null;
        $date  = !empty($d['date']) ? $d['date'] : date('Y-m-d');
        $photo = $d['photo'] ?? '';

        $stmt = $db->prepare("INSERT INTO students 
            (student_id, name, father, mother, mobile, aadhaar, qual, trade, session, address, dob, photo, enroll_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $sid, $d['name'], $d['father'], $d['mother'] ?? '', $d['mobile'],
            $d['aadhaar'] ?? '', $d['qual'] ?? '', $d['trade'], $d['session'] ?? date('Y').'-'.(date('Y')+1),
            $d['address'] ?? '', $dob, $photo, $date
        ]);

        $db->prepare("UPDATE id_counter SET next_val = next_val + 1 WHERE name = 'student'")->execute();
        $db->commit();

        logAction('addStudent', 'success', "ID: $sid, Name: {$d['name']}");
        sendJSON(['status' => 'ok', 'message' => "Student added: $sid", 'student_id' => $sid]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function updateStudent($d) {
    if (empty($d['id'])) sendJSON(['status' => 'error', 'message' => 'Student ID required'], 400);
    $db = getDB();
    $dob = !empty($d['dob']) ? $d['dob'] : null;

    $stmt = $db->prepare("UPDATE students SET
        name=?, father=?, mother=?, mobile=?, aadhaar=?, qual=?, trade=?, session=?, address=?, dob=?
        WHERE student_id=?");
    $stmt->execute([
        $d['name'] ?? '', $d['father'] ?? '', $d['mother'] ?? '', $d['mobile'] ?? '',
        $d['aadhaar'] ?? '', $d['qual'] ?? '', $d['trade'] ?? '', $d['session'] ?? '',
        $d['address'] ?? '', $dob, $d['id']
    ]);

    if ($stmt->rowCount() === 0) sendJSON(['status' => 'error', 'message' => 'Student not found'], 404);
    logAction('updateStudent', 'success', "ID: {$d['id']}");
    sendJSON(['status' => 'ok', 'message' => 'Student updated: ' . $d['id']]);
}

function deleteStudent($id) {
    if (!$id) sendJSON(['status' => 'error', 'message' => 'Student ID required'], 400);
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) sendJSON(['status' => 'error', 'message' => 'Student not found'], 404);
    logAction('deleteStudent', 'success', "ID: $id");
    sendJSON(['status' => 'ok', 'message' => "Deleted: $id"]);
}

// ═══════════════════════════════════════════════════════════
//  ATTENDANCE
// ═══════════════════════════════════════════════════════════
function getAttendance($date) {
    $db = getDB();
    $stmt = $db->prepare("SELECT student_id, status FROM attendance WHERE att_date = ?");
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();
    $att  = [];
    foreach ($rows as $r) $att[$r['student_id']] = $r['status'];
    sendJSON(['status' => 'ok', 'date' => $date, 'data' => $att]);
}

function saveAttendance($d) {
    $date = $d['date'] ?? date('Y-m-d');
    $att  = $d['attendance'] ?? [];
    if (empty($att)) sendJSON(['status' => 'error', 'message' => 'Attendance data missing'], 400);

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO attendance (att_date, student_id, status)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE status = VALUES(status), marked_at = NOW()");
    $count = 0;
    foreach ($att as $sid => $status) {
        if (in_array($status, ['P','A','L'])) {
            $stmt->execute([$date, $sid, $status]);
            $count++;
        }
    }
    logAction('saveAttendance', 'success', "Date: $date, Records: $count");
    sendJSON(['status' => 'ok', 'message' => "Attendance saved for $date", 'count' => $count]);
}

function getAttendanceReport($d) {
    $month  = $d['month'] ?? date('Y-m');
    $trade  = $d['trade'] ?? '';
    $db     = getDB();

    $sql = "SELECT s.student_id as id, s.name, s.trade, s.session,
                   COUNT(a.id) as total,
                   SUM(CASE WHEN a.status='P' THEN 1 ELSE 0 END) as present,
                   SUM(CASE WHEN a.status='A' THEN 1 ELSE 0 END) as absent,
                   SUM(CASE WHEN a.status='L' THEN 1 ELSE 0 END) as on_leave
            FROM students s
            LEFT JOIN attendance a ON a.student_id = s.student_id
                AND DATE_FORMAT(a.att_date,'%Y-%m') = ?
            WHERE 1=1";
    $params = [$month];
    if ($trade) { $sql .= " AND s.trade = ?"; $params[] = $trade; }
    $sql .= " GROUP BY s.student_id, s.name, s.trade, s.session ORDER BY s.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    sendJSON(['status' => 'ok', 'month' => $month, 'data' => $stmt->fetchAll()]);
}

function getAttendanceSummary($d) {
    $date = $d['date'] ?? date('Y-m-d');
    $db   = getDB();

    $stmt = $db->prepare("SELECT
        COUNT(s.student_id)                                          AS total,
        SUM(CASE WHEN a.status = 'P' THEN 1 ELSE 0 END)             AS present,
        SUM(CASE WHEN a.status = 'A' THEN 1 ELSE 0 END)             AS absent,
        SUM(CASE WHEN a.status = 'L' THEN 1 ELSE 0 END)             AS on_leave,
        SUM(CASE WHEN a.student_id IS NULL THEN 1 ELSE 0 END)       AS unmarked
        FROM students s
        LEFT JOIN attendance a ON a.student_id = s.student_id AND a.att_date = ?");
    $stmt->execute([$date]);
    sendJSON(['status' => 'ok', 'date' => $date, 'data' => $stmt->fetch()]);
}

// ═══════════════════════════════════════════════════════════
//  TRADES
// ═══════════════════════════════════════════════════════════
function getTrades() {
    $db = getDB();
    $stmt = $db->query("SELECT t.id, t.name, t.duration as dur, t.type,
                               DATE_FORMAT(t.added_date,'%Y-%m-%d') as date,
                               COUNT(s.student_id) as student_count
                        FROM trades t
                        LEFT JOIN students s ON s.trade = t.name
                        GROUP BY t.id, t.name, t.duration, t.type, t.added_date
                        ORDER BY t.name");
    sendJSON(['status' => 'ok', 'data' => $stmt->fetchAll()]);
}

function addTrade($d) {
    if (empty($d['name'])) sendJSON(['status' => 'error', 'message' => 'Trade name required'], 400);
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO trades (name, duration, type) VALUES (?,?,?)");
    try {
        $stmt->execute([$d['name'], $d['dur'] ?? '6 Months', $d['type'] ?? 'Short Term']);
        logAction('addTrade', 'success', "Trade: {$d['name']}");
        sendJSON(['status' => 'ok', 'message' => "Trade added: {$d['name']}"]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) sendJSON(['status' => 'error', 'message' => 'Trade already exists'], 409);
        throw $e;
    }
}

function deleteTrade($name) {
    if (!$name) sendJSON(['status' => 'error', 'message' => 'Trade name required'], 400);
    $db = getDB();

    // Check if any students enrolled in this trade
    $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE trade = ?");
    $stmt->execute([$name]);
    if ($stmt->fetchColumn() > 0) {
        sendJSON(['status' => 'error', 'message' => "Cannot delete: students enrolled in '$name'"], 409);
    }

    $stmt = $db->prepare("DELETE FROM trades WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->rowCount() === 0) sendJSON(['status' => 'error', 'message' => 'Trade not found'], 404);
    logAction('deleteTrade', 'success', "Trade: $name");
    sendJSON(['status' => 'ok', 'message' => "Trade deleted: $name"]);
}

// ═══════════════════════════════════════════════════════════
//  DASHBOARD
// ═══════════════════════════════════════════════════════════
function getDashboard() {
    $db    = getDB();
    $today = date('Y-m-d');

    // Total students
    $totalStudents = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();

    // Total trades
    $totalTrades = $db->query("SELECT COUNT(*) FROM trades")->fetchColumn();

    // Today's attendance
    $stmt = $db->prepare("SELECT
        SUM(CASE WHEN a.status='P' THEN 1 ELSE 0 END)  AS present,
        SUM(CASE WHEN a.status='A' THEN 1 ELSE 0 END)  AS absent,
        SUM(CASE WHEN a.status='L' THEN 1 ELSE 0 END)  AS on_leave,
        COUNT(a.id)                                     AS marked
        FROM attendance a WHERE a.att_date = ?");
    $stmt->execute([$today]);
    $todayAtt = $stmt->fetch();

    // Overall attendance percentage
    $avgAtt = $db->query("SELECT ROUND(AVG(CASE WHEN status='P' THEN 100 ELSE 0 END),1) FROM attendance")->fetchColumn();

    // Latest session
    $latestSession = $db->query("SELECT session FROM students ORDER BY created_at DESC LIMIT 1")->fetchColumn();

    // Recent 5 students
    $recentStmt = $db->query("SELECT student_id as id, name, trade, DATE_FORMAT(enroll_date,'%Y-%m-%d') as date FROM students ORDER BY created_at DESC LIMIT 5");
    $recentStudents = $recentStmt->fetchAll();

    // Trade summary
    $tradeSummary = $db->query("SELECT t.name, t.duration as dur, t.type, COUNT(s.student_id) as count
        FROM trades t LEFT JOIN students s ON s.trade = t.name
        GROUP BY t.name, t.duration, t.type ORDER BY count DESC")->fetchAll();

    sendJSON([
        'status' => 'ok',
        'data'   => [
            'total_students'  => (int) $totalStudents,
            'total_trades'    => (int) $totalTrades,
            'today_present'   => (int) ($todayAtt['present'] ?? 0),
            'today_absent'    => (int) ($todayAtt['absent']  ?? 0),
            'today_leave'     => (int) ($todayAtt['on_leave'] ?? 0),
            'today_marked'    => (int) ($todayAtt['marked']  ?? 0),
            'avg_attendance'  => $avgAtt ? round($avgAtt, 1) . '%' : '–',
            'latest_session'  => $latestSession ?: '–',
            'recent_students' => $recentStudents,
            'trade_summary'   => $tradeSummary,
        ]
    ]);
}

// ═══════════════════════════════════════════════════════════
//  SETTINGS
// ═══════════════════════════════════════════════════════════
function getSettings() {
    $db   = getDB();
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
    unset($settings['admin_pass']); // Don't send password to frontend
    sendJSON(['status' => 'ok', 'data' => $settings]);
}

function saveSettings($d) {
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value)
                          VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $allowed = ['inst_name','inst_mobile','inst_addr','admin_user','admin_pass'];
    foreach ($allowed as $key) {
        if (isset($d[$key])) $stmt->execute([$key, $d[$key]]);
    }
    logAction('saveSettings', 'success', '');
    sendJSON(['status' => 'ok', 'message' => 'Settings saved']);
}

// ═══════════════════════════════════════════════════════════
//  SYNC ALL (bulk import from old localStorage data)
// ═══════════════════════════════════════════════════════════
function syncAll($d) {
    $db       = getDB();
    $students = $d['students'] ?? [];
    $att      = $d['attendance'] ?? [];
    $trades   = $d['trades'] ?? [];

    $imported = ['students' => 0, 'trades' => 0, 'attendance' => 0];

    // Trades first
    $stmtT = $db->prepare("INSERT IGNORE INTO trades (name, duration, type) VALUES (?,?,?)");
    foreach ($trades as $t) {
        $stmtT->execute([$t['name'], $t['dur'] ?? '6 Months', $t['type'] ?? 'Short Term']);
        $imported['trades']++;
    }

    // Students
    $stmtS = $db->prepare("INSERT IGNORE INTO students
        (student_id, name, father, mother, mobile, aadhaar, qual, trade, session, address, dob, enroll_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($students as $s) {
        $dob  = !empty($s['dob'])  ? $s['dob']  : null;
        $date = !empty($s['date']) ? $s['date'] : date('Y-m-d');
        try {
            $stmtS->execute([
                $s['id'], $s['name'], $s['father'], $s['mother'] ?? '', $s['mobile'],
                $s['aadhaar'] ?? '', $s['qual'] ?? '', $s['trade'], $s['session'] ?? '',
                $s['address'] ?? '', $dob, $date
            ]);
            $imported['students']++;
        } catch (Exception $e) { /* skip duplicates */ }
    }

    // Update ID counter
    $maxId = $db->query("SELECT MAX(CAST(SUBSTRING(student_id,4) AS UNSIGNED)) FROM students")->fetchColumn();
    if ($maxId) {
        $db->prepare("UPDATE id_counter SET next_val = ? WHERE name = 'student'")->execute([$maxId + 1]);
    }

    // Attendance
    $stmtA = $db->prepare("INSERT INTO attendance (att_date, student_id, status)
                            VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)");
    foreach ($att as $date => $dayData) {
        foreach ($dayData as $sid => $status) {
            if (in_array($status, ['P','A','L'])) {
                try { $stmtA->execute([$date, $sid, $status]); $imported['attendance']++; }
                catch (Exception $e) { /* skip */ }
            }
        }
    }

    logAction('syncAll', 'success', json_encode($imported));
    sendJSON(['status' => 'ok', 'message' => 'Data synced to MySQL!', 'imported' => $imported]);
}

// ═══════════════════════════════════════════════════════════
//  EXPORT ALL DATA
// ═══════════════════════════════════════════════════════════
function exportData() {
    $db = getDB();
    $students = $db->query("SELECT student_id as id, name, father, mother, mobile, aadhaar, qual, trade, session, address, DATE_FORMAT(dob,'%Y-%m-%d') as dob, DATE_FORMAT(enroll_date,'%Y-%m-%d') as date FROM students ORDER BY student_id")->fetchAll();
    $trades   = $db->query("SELECT name, duration as dur, type FROM trades ORDER BY name")->fetchAll();
    $attRows  = $db->query("SELECT DATE_FORMAT(att_date,'%Y-%m-%d') as att_date, student_id, status FROM attendance ORDER BY att_date")->fetchAll();

    // Reconstruct attendance object format
    $att = [];
    foreach ($attRows as $r) $att[$r['att_date']][$r['student_id']] = $r['status'];

    sendJSON(['status' => 'ok', 'data' => compact('students', 'trades', 'att')]);
}
