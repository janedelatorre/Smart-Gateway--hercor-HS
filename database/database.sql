-- =====================================================================
-- SMART GATEWAY: CAMPUS ENTRY SYSTEM
-- Database Schema
-- Using Barcode Verification, Backup Facial Recognition & SMS Notification
-- =====================================================================

CREATE DATABASE IF NOT EXISTS smart_gateway_v1_12 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_gateway_v1_12;

-- ---------------------------------------------------------------------
-- Table: users  (Admin / Staff accounts)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,          -- hashed with password_hash() (Argon2id for new/changed passwords)
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    role ENUM('Administrator','Staff') NOT NULL DEFAULT 'Staff',
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    remember_token VARCHAR(255) DEFAULT NULL,
    failed_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: students
-- ---------------------------------------------------------------------
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(30) NOT NULL UNIQUE,   -- barcode value encodes this
    fullname VARCHAR(100) NOT NULL,
    grade VARCHAR(30) NOT NULL,
    section VARCHAR(30) DEFAULT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    guardian_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,           -- uploads/student/xxx.jpg
    face_encoding LONGTEXT DEFAULT NULL,       -- JSON descriptor array from face-api.js
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: entry_logs
-- ---------------------------------------------------------------------
CREATE TABLE entry_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(30) DEFAULT NULL,       -- may be NULL for denied/unknown scans
    fullname VARCHAR(100) DEFAULT NULL,
    grade VARCHAR(30) DEFAULT NULL,
    time_in DATETIME NOT NULL,
    verification_method ENUM('Barcode','Facial Recognition') NOT NULL DEFAULT 'Barcode',
    verified_by VARCHAR(100) DEFAULT NULL,     -- staff/admin username who was logged in
    status ENUM('Match','Denied') NOT NULL,
    reason VARCHAR(150) DEFAULT NULL,          -- e.g. 'Face not recognized', 'Invalid ID', 'Inactive status'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: sms_logs
-- ---------------------------------------------------------------------
CREATE TABLE sms_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_log_id INT DEFAULT NULL,
    recipient VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('Match','Denied','Pending') NOT NULL DEFAULT 'Pending', -- Match = Sent OK, Denied = Failed
    provider_response TEXT DEFAULT NULL,
    sent_by VARCHAR(50) DEFAULT 'System',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entry_log_id) REFERENCES entry_logs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: settings  (key-value system configuration)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL
) ENGINE=InnoDB;

-- =====================================================================
-- PHASE 1 SECURITY & AUDIT TABLES
-- =====================================================================

-- ---------------------------------------------------------------------
-- Table: audit_logs  (system-wide activity trail for security review)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,          -- e.g. 'Login', 'Logout', 'Password Change', 'User Created', 'User Deleted'
    description VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE INDEX idx_audit_logs_created ON audit_logs(created_at);

-- ---------------------------------------------------------------------
-- Table: login_attempts  (feeds rate limiting / lockout / CAPTCHA triggers)
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE INDEX idx_login_attempts_lookup ON login_attempts(username, attempted_at);

-- ---------------------------------------------------------------------
-- Table: password_history  (prevents reusing recent passwords)
-- ---------------------------------------------------------------------
CREATE TABLE password_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table: otp_codes  (Forgot Password verification codes, sent via SMS)
-- ---------------------------------------------------------------------
CREATE TABLE otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'password_reset',
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- PHASE 2: VERIFICATION RATE LIMITING
-- =====================================================================

-- ---------------------------------------------------------------------
-- Table: verification_attempts  (feeds scan.php / face.php abuse throttling)
-- ---------------------------------------------------------------------
CREATE TABLE verification_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    endpoint ENUM('scan','face') NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE INDEX idx_verification_attempts_lookup ON verification_attempts(ip_address, endpoint, attempted_at);

-- ---------------------------------------------------------------------
-- Table: notifications  (in-app notification center, built out in Phase 5)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,              -- NULL = broadcast to all logged-in users
    type ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PERFORMANCE INDEXES
-- These columns are hit on every Dashboard load, Reports run, and the
-- 30-second notification-bell poll from every logged-in user — indexing
-- them keeps those queries fast as the tables grow beyond demo size.
-- ---------------------------------------------------------------------
CREATE INDEX idx_entry_logs_time_in ON entry_logs(time_in);
CREATE INDEX idx_entry_logs_status ON entry_logs(status);
CREATE INDEX idx_sms_logs_sent_at ON sms_logs(sent_at);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX idx_students_status ON students(status);

-- =====================================================================
-- SEED DATA
-- =====================================================================

-- Default Administrator account -> username: admin / password: admin123
INSERT INTO users (username, password, fullname, email, contact_number, role, status) VALUES
('admin', '$2b$10$bKtmQNtyDjYIHHXjRHO3Tu/vud52BC13DKFNv/F5zuDv2hEK1/.cq', 'Juan Dela Cruz', 'admin@school.edu.ph', '09123456789', 'Administrator', 'Active'),
('staff1', '$2b$10$bKtmQNtyDjYIHHXjRHO3Tu/vud52BC13DKFNv/F5zuDv2hEK1/.cq', 'Maria Santos', 'staff1@school.edu.ph', '09123456780', 'Staff', 'Active'),
('staff2', '$2b$10$bKtmQNtyDjYIHHXjRHO3Tu/vud52BC13DKFNv/F5zuDv2hEK1/.cq', 'Pedro Reyes', 'staff2@school.edu.ph', '09123456781', 'Staff', 'Active');
-- NOTE: the hash above corresponds to the password "admin123" for ALL seeded accounts (demo only).

-- Sample students
INSERT INTO students (student_id, fullname, grade, section, contact_number, guardian_name, status) VALUES
('2024-400831', 'Juan Dela Cruz', 'Grade 7', 'A', '09123456789', 'Rosa Dela Cruz', 'Active'),
('2024-461738', 'Maria Santos', 'Grade 7', 'A', '09123456789', 'Ana Santos', 'Active'),
('2025-371892', 'Pedro Reyes', 'Grade 7', 'B', '09123456789', 'Lito Reyes', 'Active'),
('2024-800671', 'Kyle Bautista', 'Grade 7', 'B', '09123456789', 'Cora Bautista', 'Active'),
('23-00988', 'Test Student (edit me)', 'Grade 7', 'A', '09171234567', 'Parent/Guardian', 'Active');

-- Default settings
INSERT INTO settings (setting_key, setting_value) VALUES
('school_name', 'Hercor College'),
('school_address', 'Lawaan, Roxas City'),
('school_contact', '(111) 0123-456'),
('timezone', 'Asia/Manila'),
('enable_sms', '1'),
('enable_facial_recognition', '1'),
('enable_barcode', '1'),
('auto_backup', '1'),
('maintain_logs_days', '365'),
('sms_api_url', ''),
('sms_api_key', ''),
('sms_sender_id', 'SmartGateway'),
('max_login_attempts', '5'),
('lockout_duration_minutes', '15'),
('captcha_after_attempts', '3'),
('session_timeout_minutes', '30'),
('password_history_limit', '5'),
('verification_rate_limit_max', '30'),
('verification_rate_limit_window_seconds', '60');
