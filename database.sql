-- ============================================================
-- ConstructFlow 
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS FieldWorkPhotos;
DROP TABLE IF EXISTS FieldWorkUpdates;
DROP TABLE IF EXISTS WorkOrders;
DROP TABLE IF EXISTS PhotoEvidence;
DROP TABLE IF EXISTS InspectionReports;
DROP TABLE IF EXISTS InspectionTasks;
DROP TABLE IF EXISTS GeneratedReports;
DROP TABLE IF EXISTS ActivityLogs;
DROP TABLE IF EXISTS LoginAttempts;
DROP TABLE IF EXISTS PasswordResetCodes;
DROP TABLE IF EXISTS PendingRegistrations;
DROP TABLE IF EXISTS Invitations;
DROP TABLE IF EXISTS TeamMembers;
DROP TABLE IF EXISTS Teams;
DROP TABLE IF EXISTS Users;
DROP TABLE IF EXISTS Sessions;   -- removed in v2, dropped here for upgrades

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- IDENTITY
-- ============================================================

-- ── USERS ─────────────────────────────────────────────────────
-- Identity only. No team role: see TeamMembers.
-- An account cannot sign in until email_verified_at is set.
--
-- account_type is the one permission that lives on the account
-- rather than on a membership, because it is decided by HOW the
-- account was created and can never change afterwards:
--
--   'owner'  — self-registered through the public signup form.
--              May create a team.
--   'member' — created by opening an invitation link. May never
--              create a team, including after being removed from
--              every team they belonged to.
--
-- DEFAULT 'member' makes the column fail closed: a code path that
-- forgets to set it produces the restricted account, not the
-- privileged one. Only auth.php's public registration writes 'owner'.
CREATE TABLE Users (
  user_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(100)  NOT NULL,
  gender            ENUM('male','female','other','prefer_not_to_say')
                    NOT NULL DEFAULT 'prefer_not_to_say',
  birthdate         DATE          DEFAULT NULL,
  address           VARCHAR(255)  DEFAULT NULL,
  email             VARCHAR(150)  NOT NULL UNIQUE,
  email_verified_at DATETIME      DEFAULT NULL,
  account_type      ENUM('owner','member') NOT NULL DEFAULT 'member',
  password_hash     VARCHAR(255)  NOT NULL,      -- bcrypt; never plain text
  is_active         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── PENDING REGISTRATIONS ─────────────────────────────────────
-- An in-progress signup: personal details plus a hashed 6-character
-- verification code. No row in Users exists until the person
-- verifies their email AND sets a password, at which point the row
-- here is deleted.
CREATE TABLE PendingRegistrations (
  pending_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name     VARCHAR(60)  NOT NULL,
  last_name      VARCHAR(60)  NOT NULL,
  gender         ENUM('male','female','other','prefer_not_to_say') NOT NULL,
  birthdate      DATE         DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  email          VARCHAR(150) NOT NULL UNIQUE,
  code_hash      CHAR(64)     NOT NULL,
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at    DATETIME     DEFAULT NULL,
  expires_at     DATETIME     NOT NULL,
  last_sent_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resend_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pending_email (email)
) ENGINE=InnoDB;

-- ── PASSWORD RESET CODES ──────────────────────────────────────
CREATE TABLE PasswordResetCodes (
  reset_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  code_hash     CHAR(64)     NOT NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at   DATETIME     DEFAULT NULL,       -- code confirmed, password not yet changed
  consumed_at   DATETIME     DEFAULT NULL,       -- password changed; code now dead
  expires_at    DATETIME     NOT NULL,
  last_sent_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resend_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prc_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
  INDEX idx_prc_user (user_id, consumed_at)
) ENGINE=InnoDB;

-- ── LOGIN ATTEMPTS ────────────────────────────────────────────
-- Anyone can reach the login form now that registration is self
-- service, so failed attempts are throttled: 5 failures per email
-- in 15 minutes locks further tries.
CREATE TABLE LoginAttempts (
  attempt_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(150) NOT NULL,
  ip_address   VARCHAR(45)  DEFAULT NULL,
  successful   TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_lookup (email, attempted_at),
  INDEX idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ============================================================
-- TEAMS
-- ============================================================

-- ── TEAMS ─────────────────────────────────────────────────────
CREATE TABLE Teams (
  team_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  description  VARCHAR(500) DEFAULT NULL,
  join_code    CHAR(8)      NOT NULL UNIQUE,
  owner_id     INT UNSIGNED NOT NULL,            -- who created it (record only)
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_team_owner FOREIGN KEY (owner_id) REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- ── TEAM MEMBERS ──────────────────────────────────────────────
CREATE TABLE TeamMembers (
  membership_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id       INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  role          ENUM('administrator','supervisor','field_inspector','field_worker','member')
                NOT NULL DEFAULT 'member',
  status        ENUM('pending','active','removed') NOT NULL DEFAULT 'pending',
  assigned_by   INT UNSIGNED DEFAULT NULL,
  assigned_at   DATETIME     DEFAULT NULL,
  joined_at     DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_team_user (team_id, user_id),
  CONSTRAINT fk_tm_team     FOREIGN KEY (team_id)     REFERENCES Teams(team_id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_user     FOREIGN KEY (user_id)     REFERENCES Users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_assigner FOREIGN KEY (assigned_by) REFERENCES Users(user_id) ON DELETE SET NULL,
  INDEX idx_tm_user (user_id, status),
  INDEX idx_tm_team (team_id, status)
) ENGINE=InnoDB;

-- ── INVITATIONS ───────────────────────────────────────────────
CREATE TABLE Invitations (
  invitation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id       INT UNSIGNED NOT NULL,
  email         VARCHAR(150) NOT NULL,
  first_name    VARCHAR(60)  NOT NULL DEFAULT '',
  last_name     VARCHAR(60)  NOT NULL DEFAULT '',
  birthdate     DATE         DEFAULT NULL,
  role          ENUM('administrator','supervisor','field_inspector','field_worker','member')
                NOT NULL DEFAULT 'member',
  token_hash    CHAR(64)     NOT NULL,
  invited_by    INT UNSIGNED NOT NULL,
  expires_at    DATETIME     NOT NULL,
  accepted_at   DATETIME     DEFAULT NULL,   -- set once; a used link is dead
  revoked_at    DATETIME     DEFAULT NULL,   -- administrator cancelled it
  last_sent_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resend_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_team    FOREIGN KEY (team_id)    REFERENCES Teams(team_id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_inviter FOREIGN KEY (invited_by) REFERENCES Users(user_id) ON DELETE CASCADE,
  UNIQUE KEY uq_inv_token (token_hash),
  INDEX idx_inv_team (team_id, accepted_at),
  INDEX idx_inv_email (email)
) ENGINE=InnoDB;

-- ============================================================
-- INSPECTION WORKFLOW
-- ============================================================

-- ── INSPECTION TASKS ──────────────────────────────────────────
CREATE TABLE InspectionTasks (
  task_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id       INT UNSIGNED  NOT NULL,
  task_code     VARCHAR(20)   NOT NULL,          -- e.g. TASK-001, unique per team
  created_by    INT UNSIGNED  NOT NULL,          -- Supervisor
  assigned_to   INT UNSIGNED  NOT NULL,          -- Field Inspector
  title         VARCHAR(200)  NOT NULL,
  description   TEXT          DEFAULT NULL,
  location_text VARCHAR(255)  NOT NULL,
  status        ENUM('assigned','submitted','closed') NOT NULL DEFAULT 'assigned',
  due_date      DATE          DEFAULT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_task_code     UNIQUE (team_id, task_code),
  CONSTRAINT fk_task_team     FOREIGN KEY (team_id)     REFERENCES Teams(team_id),
  CONSTRAINT fk_task_creator  FOREIGN KEY (created_by)  REFERENCES Users(user_id),
  CONSTRAINT fk_task_assignee FOREIGN KEY (assigned_to) REFERENCES Users(user_id),
  INDEX idx_tasks_team (team_id, status),
  INDEX idx_tasks_assignee (assigned_to)
) ENGINE=InnoDB;

-- ── INSPECTION REPORTS ────────────────────────────────────────
CREATE TABLE InspectionReports (
  report_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id        INT UNSIGNED  NOT NULL,
  report_code    VARCHAR(20)   NOT NULL,         -- e.g. REP-001, unique per team
  task_id        INT UNSIGNED  NOT NULL,
  submitted_by   INT UNSIGNED  NOT NULL,
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
  CONSTRAINT uq_report_code UNIQUE (team_id, report_code),
  CONSTRAINT fk_report_team FOREIGN KEY (team_id)      REFERENCES Teams(team_id),
  CONSTRAINT fk_report_task FOREIGN KEY (task_id)      REFERENCES InspectionTasks(task_id),
  CONSTRAINT fk_report_user FOREIGN KEY (submitted_by) REFERENCES Users(user_id),
  INDEX idx_reports_team (team_id, status),
  INDEX idx_reports_severity (severity),
  INDEX idx_reports_submitter (submitted_by)
) ENGINE=InnoDB;

-- ── PHOTO EVIDENCE ────────────────────────────────────────────
CREATE TABLE PhotoEvidence (
  photo_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id     INT UNSIGNED  NOT NULL,
  file_path     VARCHAR(500)  NOT NULL,
  file_name     VARCHAR(255)  NOT NULL,
  latitude      DECIMAL(10,7) DEFAULT NULL,
  longitude     DECIMAL(10,7) DEFAULT NULL,
  uploaded_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_report FOREIGN KEY (report_id) REFERENCES InspectionReports(report_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- WORK ORDER WORKFLOW
-- ============================================================

-- ── WORK ORDERS ───────────────────────────────────────────────
CREATE TABLE WorkOrders (
  wo_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id         INT UNSIGNED  NOT NULL,
  wo_code         VARCHAR(20)   NOT NULL,        -- e.g. WO-001, unique per team
  report_id       INT UNSIGNED  NOT NULL,
  created_by      INT UNSIGNED  NOT NULL,        -- Supervisor
  assigned_to     INT UNSIGNED  DEFAULT NULL,    -- Field Worker
  severity        ENUM('low','moderate','high','critical') NOT NULL,
  instructions    TEXT          DEFAULT NULL,
  deadline        DATE          DEFAULT NULL,
  status          ENUM('pending','in_progress','on_hold','completed') NOT NULL DEFAULT 'pending',
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_wo_code     UNIQUE (team_id, wo_code),
  -- One work order per report, enforced here rather than only in PHP.
  CONSTRAINT uq_wo_report   UNIQUE (report_id),
  CONSTRAINT fk_wo_team     FOREIGN KEY (team_id)     REFERENCES Teams(team_id),
  CONSTRAINT fk_wo_report   FOREIGN KEY (report_id)   REFERENCES InspectionReports(report_id),
  CONSTRAINT fk_wo_creator  FOREIGN KEY (created_by)  REFERENCES Users(user_id),
  CONSTRAINT fk_wo_assignee FOREIGN KEY (assigned_to) REFERENCES Users(user_id),
  INDEX idx_wo_team (team_id, status),
  INDEX idx_wo_assignee (assigned_to)
) ENGINE=InnoDB;

-- ── FIELD WORK UPDATES ────────────────────────────────────────
-- Progress updates from a Field Worker. A work order has many.
CREATE TABLE FieldWorkUpdates (
  update_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wo_id         INT UNSIGNED  NOT NULL,
  updated_by    INT UNSIGNED  NOT NULL,
  status        ENUM('in_progress','on_hold','completed') NOT NULL,
  remarks       TEXT          DEFAULT NULL,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_update_wo   FOREIGN KEY (wo_id)      REFERENCES WorkOrders(wo_id),
  CONSTRAINT fk_update_user FOREIGN KEY (updated_by) REFERENCES Users(user_id),
  INDEX idx_updates_wo (wo_id, updated_at)
) ENGINE=InnoDB;

-- ── FIELD WORK PHOTOS ─────────────────────────────────────────
CREATE TABLE FieldWorkPhotos (
  photo_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  update_id     INT UNSIGNED  NOT NULL,
  file_path     VARCHAR(500)  NOT NULL,
  file_name     VARCHAR(255)  NOT NULL,
  uploaded_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_photo_update FOREIGN KEY (update_id) REFERENCES FieldWorkUpdates(update_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- REPORTING AND AUDIT
-- ============================================================

-- ── GENERATED REPORTS ─────────────────────────────────────────
CREATE TABLE GeneratedReports (
  gen_report_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id       INT UNSIGNED  NOT NULL,
  title         VARCHAR(200)  NOT NULL,
  report_type   ENUM('inspection_summary','work_order_summary','activity_log','full_export') NOT NULL,
  generated_by  INT UNSIGNED  NOT NULL,
  file_path     VARCHAR(500)  DEFAULT NULL,
  generated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gen_team FOREIGN KEY (team_id)      REFERENCES Teams(team_id),
  CONSTRAINT fk_gen_user FOREIGN KEY (generated_by) REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- ── ACTIVITY LOGS ─────────────────────────────────────────────
CREATE TABLE ActivityLogs (
  log_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  DEFAULT NULL,      -- null = system event
  team_id       INT UNSIGNED  DEFAULT NULL,
  action        VARCHAR(100)  NOT NULL,          -- e.g. 'login', 'submit_report'
  target_type   VARCHAR(50)   DEFAULT NULL,
  target_id     INT UNSIGNED  DEFAULT NULL,
  description   VARCHAR(500)  DEFAULT NULL,
  ip_address    VARCHAR(45)   DEFAULT NULL,
  logged_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_log_team FOREIGN KEY (team_id) REFERENCES Teams(team_id) ON DELETE SET NULL,
  INDEX idx_logs_user (user_id),
  INDEX idx_logs_team (team_id, logged_at),
  INDEX idx_logs_logged_at (logged_at)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA — DEVELOPMENT ONLY
-- ============================================================
/* INSERT INTO Users (user_id, name, gender, email, email_verified_at, account_type, password_hash) VALUES
  (1, 'Admin User',      'prefer_not_to_say', 'admin@constructflow.com',       NOW(), 'owner',  '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG'),
  (2, 'John Supervisor', 'male',              'supervisor@constructflow.com',  NOW(), 'member', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG'),
  (3, 'Mike Inspector',  'male',              'inspector@constructflow.com',   NOW(), 'member', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG'),
  (4, 'Dave Mechanic',   'male',              'fieldworker@constructflow.com', NOW(), 'member', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG');

INSERT INTO Teams (team_id, name, description, join_code, owner_id) VALUES
  (1, 'Demo Construction Team',
      'Seeded team for development and demonstration.',
      'CFTEAM23', 1);

INSERT INTO TeamMembers (team_id, user_id, role, status, joined_at, assigned_at, assigned_by) VALUES
  (1, 1, 'administrator',   'active', NOW(), NOW(), 1),
  (1, 2, 'supervisor',      'active', NOW(), NOW(), 1),
  (1, 3, 'field_inspector', 'active', NOW(), NOW(), 1),
  (1, 4, 'field_worker',    'active', NOW(), NOW(), 1);
*/