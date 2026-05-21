-- ═══════════════════════════════════════════════════════════
-- ATITC Portal - MySQL Database Schema
-- Advanced Technical & Industrial Training Center, Mhow, Indore
-- Run this in phpMyAdmin or MySQL CLI:
--   mysql -u root -p < atitc_database.sql
-- ═══════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS atitc_portal
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE atitc_portal;

-- ─── TRADES TABLE ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trades (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL UNIQUE,
  duration    VARCHAR(50)  NOT NULL DEFAULT '6 Months',
  type        VARCHAR(50)  NOT NULL DEFAULT 'Short Term',
  added_date  DATE         NOT NULL DEFAULT (CURDATE()),
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── STUDENTS TABLE ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
  student_id  VARCHAR(20)   PRIMARY KEY,
  name        VARCHAR(150)  NOT NULL,
  father      VARCHAR(150)  NOT NULL,
  mother      VARCHAR(150)  DEFAULT '',
  mobile      VARCHAR(15)   NOT NULL,
  aadhaar     VARCHAR(20)   DEFAULT '',
  qual        VARCHAR(50)   DEFAULT '',
  trade       VARCHAR(150)  NOT NULL,
  session     VARCHAR(20)   NOT NULL,
  address TEXT,
  dob         DATE          DEFAULT NULL,
  photo TEXT,
  enroll_date DATE          NOT NULL DEFAULT (CURDATE()),
  created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (trade) REFERENCES trades(name) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── ATTENDANCE TABLE ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  att_date    DATE        NOT NULL,
  student_id  VARCHAR(20) NOT NULL,
  status      ENUM('P','A','L') NOT NULL DEFAULT 'A',
  marked_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_att (att_date, student_id),
  FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── USERS TABLE (Login) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(50)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('admin','staff') DEFAULT 'admin',
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── SETTINGS TABLE ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value TEXT,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── SYNC LOG TABLE ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sync_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  action      VARCHAR(100) NOT NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'success',
  details     TEXT,
  ip_address  VARCHAR(45),
  logged_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── ID COUNTER TABLE ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS id_counter (
  name        VARCHAR(50)  PRIMARY KEY,
  next_val    INT          NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════════════
-- DEFAULT DATA
-- ═══════════════════════════════════════════════════════════

-- Default login: admin / atitc2024
INSERT IGNORE INTO users (username, password, role)
VALUES ('admin', '$2y$10$8K1p/a0dR1lsEBFoQCLmg.vJn9ZBkLOPFzHGhWnFBHjfJvE2.IXgO', 'admin');
-- Note: Above hash = bcrypt of 'atitc2024'
-- If bcrypt doesn't work, fallback plain password is stored in settings

INSERT IGNORE INTO id_counter (name, next_val) VALUES ('student', 1);

-- Default Settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('inst_name',   'Advanced Technical & Industrial Training Center (ATITC)'),
  ('inst_mobile', '9713910210'),
  ('inst_addr',   '13/2-B, Veterinary College Ke Samne, Harnya Khedi, Mhow, Indore, MP – 453446'),
  ('admin_user',  'admin'),
  ('admin_pass',  'atitc2024');

-- Default Trades
INSERT IGNORE INTO trades (name, duration, type) VALUES
  ('Computer Operator',      '6 Months',  'Short Term'),
  ('DTP Operator',           '6 Months',  'Short Term'),
  ('Web Design',             '6 Months',  'Short Term'),
  ('Beautician',             '6 Months',  'Short Term'),
  ('Cutting & Tailoring',    '1 Year',    'Long Term'),
  ('Electrical Technician',  '1 Year',    'Long Term'),
  ('Plumber',                '6 Months',  'Short Term'),
  ('Tally Prime',            '6 Months',  'Short Term');

-- ─── USEFUL VIEWS ───────────────────────────────────────────

-- Today's attendance summary per trade
CREATE OR REPLACE VIEW v_today_summary AS
SELECT
  s.trade,
  COUNT(s.student_id)                                         AS total,
  SUM(CASE WHEN a.status = 'P' THEN 1 ELSE 0 END)            AS present,
  SUM(CASE WHEN a.status = 'A' THEN 1 ELSE 0 END)            AS absent,
  SUM(CASE WHEN a.status = 'L' THEN 1 ELSE 0 END)            AS on_leave,
  SUM(CASE WHEN a.status IS NULL THEN 1 ELSE 0 END)          AS unmarked
FROM students s
LEFT JOIN attendance a ON a.student_id = s.student_id AND a.att_date = CURDATE()
GROUP BY s.trade;

-- Student attendance percentage
CREATE OR REPLACE VIEW v_student_att_pct AS
SELECT
  s.student_id,
  s.name,
  s.trade,
  s.session,
  COUNT(a.id)                                            AS total_marked,
  SUM(CASE WHEN a.status = 'P' THEN 1 ELSE 0 END)       AS present_count,
  ROUND(
    SUM(CASE WHEN a.status='P' THEN 1 ELSE 0 END) * 100.0
    / NULLIF(COUNT(a.id), 0)
  , 1)                                                   AS att_pct
FROM students s
LEFT JOIN attendance a ON a.student_id = s.student_id
GROUP BY s.student_id, s.name, s.trade, s.session;

