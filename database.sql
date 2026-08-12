-- ============================================================
-- ConstructFlow: Severity-Based Inspection and Work Order
-- Coordination System for Small Construction Teams
-- Database Schema — based on ERD in the paper
-- ============================================================

-- ── USERS ─────────────────────────────────────────────────
-- Stores all system users with role-based access control.
-- Roles: field_inspector, supervisor, field_worker, administrator
CREATE TABLE IF NOT EXISTS Users (
  user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100)  NOT NULL,
  email         VARCHAR(150)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('field_inspector','supervisor','field_worker','administrator') NOT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── INSPECTION TASKS ─────────────────────────────────────────
-- Created by a Supervisor and assigned to a specific Field
-- Inspector. Must exist before an inspection can be performed.
-- A Supervisor may assign multiple tasks to the same Inspector;
-- a task can only ever be assigned to one Inspector.
CREATE TABLE IF NOT EXISTS InspectionTasks (
  task_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_code     VARCHAR(20)   NOT NULL UNIQUE,           -- e.g. TASK-001
  created_by    INT UNSIGNED  NOT NULL,                  -- FK → Users (Supervisor)
  assigned_to   INT UNSIGNED  NOT NULL,                  -- FK → Users (Field Inspector)
  title         VARCHAR(200)  NOT NULL,
  description   TEXT          DEFAULT NULL,
  location_text VARCHAR(255)  NOT NULL,
  status        ENUM('assigned','submitted','closed') NOT NULL DEFAULT 'assigned',
  due_date      DATE          DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_creator  FOREIGN KEY (created_by)  REFERENCES Users(user_id),
  CONSTRAINT fk_task_assignee FOREIGN KEY (assigned_to) REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- ── INSPECTION REPORTS ────────────────────────────────────
-- Submitted by Field Inspectors. Contains issue details,
-- site information, severity level, and geotag data.
CREATE TABLE IF NOT EXISTS InspectionReports (
  report_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_code    VARCHAR(20)   NOT NULL UNIQUE,         -- e.g. REP-001
  task_id        INT UNSIGNED  DEFAULT NULL,            -- FK → InspectionTasks (nullable for backward compatibility)
  submitted_by   INT UNSIGNED  NOT NULL,                -- FK → Users
  title          VARCHAR(200)  NOT NULL,
  issue_type     VARCHAR(100)  NOT NULL,
  description    TEXT          NOT NULL,
  location_text  VARCHAR(255)  NOT NULL,
  latitude       DECIMAL(10,7) DEFAULT NULL,
  longitude      DECIMAL(10,7) DEFAULT NULL,
  severity       ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'low',
  status         ENUM('pending','assigned','in_progress','completed','rejected') NOT NULL DEFAULT 'pending',
  submitted_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_report_user FOREIGN KEY (submitted_by) REFERENCES Users(user_id),
  CONSTRAINT fk_report_task FOREIGN KEY (task_id) REFERENCES InspectionTasks(task_id)
) ENGINE=InnoDB;

-- ── PHOTO EVIDENCE ────────────────────────────────────────
-- Geotagged photos attached to inspection reports.
-- A report can have multiple photos.
CREATE TABLE IF NOT EXISTS PhotoEvidence (
  photo_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id     INT UNSIGNED  NOT NULL,                 -- FK → InspectionReports
  file_path     VARCHAR(500)  NOT NULL,                 -- stored on server, path saved here
  file_name     VARCHAR(255)  NOT NULL,
  latitude      DECIMAL(10,7) DEFAULT NULL,
  longitude     DECIMAL(10,7) DEFAULT NULL,
  uploaded_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_report FOREIGN KEY (report_id) REFERENCES InspectionReports(report_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── WORK ORDERS ───────────────────────────────────────────
-- Created by Supervisors from inspection reports.
-- Assigned to a Field Worker.
CREATE TABLE IF NOT EXISTS WorkOrders (
  wo_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wo_code         VARCHAR(20)   NOT NULL UNIQUE,        -- e.g. WO-001
  report_id       INT UNSIGNED  NOT NULL,               -- FK → InspectionReports
  created_by      INT UNSIGNED  NOT NULL,               -- FK → Users (Supervisor)
  assigned_to     INT UNSIGNED  DEFAULT NULL,           -- FK → Users (Field Worker)
  severity        ENUM('low','moderate','high','critical') NOT NULL,
  instructions    TEXT          DEFAULT NULL,
  deadline        DATE          DEFAULT NULL,
  status          ENUM('pending','in_progress','on_hold','completed') NOT NULL DEFAULT 'pending',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wo_report     FOREIGN KEY (report_id)   REFERENCES InspectionReports(report_id),
  CONSTRAINT fk_wo_creator    FOREIGN KEY (created_by)  REFERENCES Users(user_id),
  CONSTRAINT fk_wo_assignee   FOREIGN KEY (assigned_to) REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- ── FIELD WORK UPDATES ────────────────────────────────────
-- Progress updates submitted by Field Workers on their work orders.
-- A work order can have multiple updates.
CREATE TABLE IF NOT EXISTS FieldWorkUpdates (
  update_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wo_id         INT UNSIGNED  NOT NULL,                 -- FK → WorkOrders
  updated_by    INT UNSIGNED  NOT NULL,                 -- FK → Users (Field Worker)
  status        ENUM('in_progress','on_hold','completed') NOT NULL,
  remarks       TEXT          DEFAULT NULL,
  photo_path    VARCHAR(500)  DEFAULT NULL,             -- completion evidence photo
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_update_wo   FOREIGN KEY (wo_id)        REFERENCES WorkOrders(wo_id),
  CONSTRAINT fk_update_user FOREIGN KEY (updated_by)   REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- ── FIELD WORK PHOTOS ──────────────────────────────────────
-- Completion evidence photos attached to a field work update.
-- A field work update can have multiple photos.
CREATE TABLE IF NOT EXISTS FieldWorkPhotos (
  photo_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  update_id     INT UNSIGNED  NOT NULL,                 -- FK → FieldWorkUpdates
  file_path     VARCHAR(500)  NOT NULL,
  file_name     VARCHAR(255)  NOT NULL,
  uploaded_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_update FOREIGN KEY (update_id) REFERENCES FieldWorkUpdates(update_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── GENERATED REPORTS ─────────────────────────────────────
-- System-generated summary reports (weekly sync, exports).
-- Administrators can export and download these.
CREATE TABLE IF NOT EXISTS GeneratedReports (
  gen_report_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200)  NOT NULL,
  report_type   ENUM('inspection_summary','work_order_summary','activity_log','full_export') NOT NULL,
  generated_by  INT UNSIGNED  NOT NULL,                 -- FK → Users (Administrator)
  file_path     VARCHAR(500)  DEFAULT NULL,
  generated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gen_report_user FOREIGN KEY (generated_by) REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- ── ACTIVITY LOGS (Audit Trail) ───────────────────────────
-- Every significant action is logged here.
-- Powers the Administrator's audit trail and system events.
CREATE TABLE IF NOT EXISTS ActivityLogs (
  log_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  DEFAULT NULL,             -- FK → Users (null = system)
  action        VARCHAR(100)  NOT NULL,                 -- e.g. 'login', 'submit_report'
  target_type   VARCHAR(50)   DEFAULT NULL,             -- e.g. 'report', 'work_order'
  target_id     INT UNSIGNED  DEFAULT NULL,             -- ID of the affected record
  description   VARCHAR(500)  DEFAULT NULL,
  ip_address    VARCHAR(45)   DEFAULT NULL,
  logged_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES Users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── SESSIONS ─────────────────────────────────────────────────
-- Stores active login sessions.
CREATE TABLE IF NOT EXISTS Sessions (
  session_id    VARCHAR(128)  PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  ip_address    VARCHAR(45)   DEFAULT NULL,
  expires_at    DATETIME      NOT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES Users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── INDEXES ──────────────────────────────────────────────────
CREATE INDEX idx_reports_status   ON InspectionReports(status);
CREATE INDEX idx_reports_severity ON InspectionReports(severity);
CREATE INDEX idx_reports_submitter ON InspectionReports(submitted_by);
CREATE INDEX idx_wo_status        ON WorkOrders(status);
CREATE INDEX idx_wo_assignee      ON WorkOrders(assigned_to);
CREATE INDEX idx_logs_user        ON ActivityLogs(user_id);
CREATE INDEX idx_logs_logged_at   ON ActivityLogs(logged_at);
CREATE INDEX idx_tasks_assignee ON InspectionTasks(assigned_to);
CREATE INDEX idx_tasks_status   ON InspectionTasks(status);

-- ── SEED DATA ────────────────────────────────────────────────
-- Default admin + one user per role for testing.
-- Passwords are bcrypt hashes of 'Password123!' — change before production.
INSERT INTO Users (name, email, password_hash, role) VALUES
  ('Admin User',      'admin@constructflow.com',      '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG', 'administrator'),
  ('Mike Inspector',  'inspector@constructflow.com',  '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG', 'field_inspector'),
  ('John Supervisor', 'supervisor@constructflow.com', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG', 'supervisor'),
  ('Dave Mechanic',   'fieldworker@constructflow.com','$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG', 'field_worker');

-- All default passwords: Password123!