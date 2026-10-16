-- ============================================================
-- ConstructFlow 
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS FieldWorkPhotos;
DROP TABLE IF EXISTS FieldWorkUpdates;
DROP TABLE IF EXISTS WorkOrderAssignees;
DROP TABLE IF EXISTS WorkOrders;
DROP TABLE IF EXISTS PhotoEvidence;
DROP TABLE IF EXISTS TestResults;
DROP TABLE IF EXISTS TestRuns;
DROP TABLE IF EXISTS ClassificationTests;
DROP TABLE IF EXISTS LexiconChangelog;
DROP TABLE IF EXISTS LexiconTerms;
DROP TABLE IF EXISTS DefectCriteria;
DROP TABLE IF EXISTS SeverityMatrix;
DROP TABLE IF EXISTS ElementTypes;
DROP TABLE IF EXISTS DefectTypes;
DROP TABLE IF EXISTS InspectionReports;
DROP TABLE IF EXISTS InspectionTasks;
DROP TABLE IF EXISTS GeneratedReports;
DROP TABLE IF EXISTS LogChains;
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
  contact_number    VARCHAR(20)   DEFAULT NULL,  -- PH mobile, stored as 09XXXXXXXXX
  email             VARCHAR(150)  NOT NULL UNIQUE,
  email_verified_at DATETIME      DEFAULT NULL,
  account_type      ENUM('owner','member') NOT NULL DEFAULT 'member',
  password_hash     VARCHAR(255)  NOT NULL,      -- bcrypt; never plain text
  is_active         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  terms_accepted_at DATETIME      DEFAULT NULL,
  avatar_path       VARCHAR(255)  DEFAULT NULL
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
  contact_number VARCHAR(20)  DEFAULT NULL,
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
  status        ENUM('assigned','submitted','closed','cancelled') NOT NULL DEFAULT 'assigned',
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
  location_text  VARCHAR(255)  NOT NULL,           -- GPS coordinates
  address_text   VARCHAR(255)  DEFAULT NULL,       -- "Province, Municipality, Barangay"
  province_code  CHAR(4)       DEFAULT NULL,    -- PSGC, NULL if unvalidated
  city_code      CHAR(6)       DEFAULT NULL,
  brgy_code      CHAR(9)       DEFAULT NULL,
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
  -- A field worker marking a job done does not close it. It goes to the
  -- supervisor, then to a nominated inspector, then back to the supervisor
  -- who reviews the whole record and closes it. Any stage may send it back.
  status          ENUM('pending','in_progress','on_hold',
                       'pending_supervisor','pending_inspector',
                       'pending_closure','rework','completed')
                  NOT NULL DEFAULT 'pending',
  -- The inspector the supervisor nominates to re-inspect the site.
  verify_inspector_id    INT UNSIGNED DEFAULT NULL,
  supervisor_verified_by INT UNSIGNED DEFAULT NULL,
  supervisor_verified_at DATETIME     DEFAULT NULL,
  inspector_verified_by  INT UNSIGNED DEFAULT NULL,
  inspector_verified_at  DATETIME     DEFAULT NULL,
  -- The supervisor sign-off that actually closes the work order. Kept apart
  -- from supervisor_verified_by, which records the first supervisor stage.
  closed_by              INT UNSIGNED DEFAULT NULL,
  closed_at              DATETIME     DEFAULT NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_wo_code     UNIQUE (team_id, wo_code),
  -- One work order per report, enforced here rather than only in PHP.
  CONSTRAINT uq_wo_report   UNIQUE (report_id),
  CONSTRAINT fk_wo_team     FOREIGN KEY (team_id)     REFERENCES Teams(team_id),
  CONSTRAINT fk_wo_report   FOREIGN KEY (report_id)   REFERENCES InspectionReports(report_id),
  CONSTRAINT fk_wo_creator  FOREIGN KEY (created_by)  REFERENCES Users(user_id),
  CONSTRAINT fk_wo_assignee FOREIGN KEY (assigned_to) REFERENCES Users(user_id),
  CONSTRAINT fk_wo_verify_insp FOREIGN KEY (verify_inspector_id)    REFERENCES Users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_wo_sup_verify  FOREIGN KEY (supervisor_verified_by) REFERENCES Users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_wo_insp_verify FOREIGN KEY (inspector_verified_by)  REFERENCES Users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_wo_closed_by   FOREIGN KEY (closed_by)              REFERENCES Users(user_id) ON DELETE SET NULL,
  INDEX idx_wo_team (team_id, status),
  INDEX idx_wo_assignee (assigned_to)
) ENGINE=InnoDB;


-- ── WORK ORDER ASSIGNEES ──────────────────────────────────────
-- A work order can carry up to three field workers (critical) or two
-- (anything else). One of them is the lead: the only person who may
-- update the status. The others see the job but cannot edit it.
-- WorkOrders.assigned_to holds the lead, so existing queries and the
-- assigned_to_name displays keep working unchanged.
CREATE TABLE WorkOrderAssignees (
  wo_id       INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  is_lead     TINYINT(1) NOT NULL DEFAULT 0,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (wo_id, user_id),
  CONSTRAINT fk_woa_wo   FOREIGN KEY (wo_id)   REFERENCES WorkOrders(wo_id) ON DELETE CASCADE,
  CONSTRAINT fk_woa_user FOREIGN KEY (user_id) REFERENCES Users(user_id),
  INDEX idx_woa_user (user_id)
) ENGINE=InnoDB;

-- ── FIELD WORK UPDATES ────────────────────────────────────────
-- Progress updates from a Field Worker. A work order has many.
CREATE TABLE FieldWorkUpdates (
  update_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wo_id         INT UNSIGNED  NOT NULL,
  updated_by    INT UNSIGNED  NOT NULL,
  status      ENUM('in_progress','on_hold','completed','pending_supervisor','pending_inspector','pending_closure','rework') NOT NULL,
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
  chain_seq     INT UNSIGNED  DEFAULT NULL,      -- place in the team's sealed chain
  prev_hash     CHAR(64)      DEFAULT NULL,      -- signature of the entry before
  row_hash      CHAR(64)      DEFAULT NULL,      -- this entry's signature (config/logseal.php)
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_log_team FOREIGN KEY (team_id) REFERENCES Teams(team_id) ON DELETE SET NULL,
  INDEX idx_logs_user (user_id),
  INDEX idx_logs_team (team_id, logged_at),
  INDEX idx_logs_logged_at (logged_at),
  INDEX idx_logs_chain (team_id, chain_seq)
) ENGINE=InnoDB;

-- Each sealed chain's length and last signature, signed, so entries cut
-- off the end of the log show too.
CREATE TABLE LogChains (
  chain_key     INT UNSIGNED  NOT NULL PRIMARY KEY,   -- team_id; 0 = outside any team
  length        INT UNSIGNED  NOT NULL,
  head_hash     CHAR(64)      NOT NULL,
  head_sig      CHAR(64)      NOT NULL,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA — DEVELOPMENT ONLY
-- ============================================================
/* INSERT INTO Users (user_id, name, gender, email, email_verified_at, account_type, password_hash) VALUES
  (1, 'Admin User',      'prefer_not_to_say', 'admin@constructflow.com',       NOW(), 'owner',  '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG'),
  (2, 'John Supervisor', 'male',              'supervisor@constructflow.com',  NOW(), 'member', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG'),
  (3, 'Mike Inspector',  'male',              'inspector@constructflow.com',   NOW(), 'member', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG'),
  (4, 'Dave Mechanic',   'male',              'fieldworker@constructflow.com', NOW(), 'member', '$2y$12$qBpTSWSg9L6GFE06vL2BkOTRjEOZSqhEMEnaDqtJUFVZSmjqMBzXG');

INSERT INTO Teams (team_id, name, description, owner_id) VALUES
  (1, 'Demo Construction Team',
      'Seeded team for development and demonstration.',
      1);

INSERT INTO TeamMembers (team_id, user_id, role, status, joined_at, assigned_at, assigned_by) VALUES
  (1, 1, 'administrator',   'active', NOW(), NOW(), 1),
  (1, 2, 'supervisor',      'active', NOW(), NOW(), 1),
  (1, 3, 'field_inspector', 'active', NOW(), NOW(), 1),
  (1, 4, 'field_worker',    'active', NOW(), NOW(), 1);
*/

-- ============================================================
-- Severity Classification Engine  (v2)
--   Tables + schema : severity_engine_install.sql
--   Knowledge base  : severity_engine_knowledge_base.sql
-- ============================================================

-- PART 1 — KNOWLEDGE BASE
-- ============================================================

CREATE TABLE DefectTypes (
  defect_type_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                    VARCHAR(50)  NOT NULL UNIQUE,
  name                    VARCHAR(100) NOT NULL,
  applies_construction    TINYINT(1)   NOT NULL DEFAULT 1,
  applies_post_completion TINYINT(1)   NOT NULL DEFAULT 1,
  is_general              TINYINT(1)   NOT NULL DEFAULT 0,
  is_active               TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order              INT          NOT NULL DEFAULT 0,
  source_note             VARCHAR(255) DEFAULT NULL,
  INDEX idx_dt_phase (is_active, applies_construction, applies_post_completion)
) ENGINE=InnoDB;

CREATE TABLE DefectCriteria (
  criterion_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  defect_type_id INT UNSIGNED NOT NULL,
  d_level        TINYINT      NOT NULL,
  descriptor     VARCHAR(255) NOT NULL,
  is_default     TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_dc_type_level (defect_type_id, d_level),
  CONSTRAINT fk_dc_type FOREIGN KEY (defect_type_id)
    REFERENCES DefectTypes(defect_type_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ElementTypes (
  element_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(50)  NOT NULL UNIQUE,
  name            VARCHAR(100) NOT NULL,
  c_level         TINYINT      NOT NULL,
  category        VARCHAR(100) NOT NULL,
  is_default      TINYINT(1)   NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- The words the engine reads.
--
-- kind says what a matched term does:
--   defect       names a defect type          -> defect_type_id
--   condition    sets D within a defect type  -> criterion_id
--   element      names the affected element   -> element_type_id
--   negation     cancels nearby terms ("no visible cracks")
--   intensifier  nudges D up (at most one level in total)
--   dampener     nudges D down (at most one level in total)
--   exposure     people at risk: raises C by one, capped at 4
--   verdict      the inspector's own conclusion ("stop work",
--                "for NCR"). NEVER changes the classification. It is
--                only compared with the result; a gap of two bands or
--                more flags the report for supervisor review.
--
-- target_code is a readable copy of what the row points at
-- ('concrete_cracking', 'concrete_cracking:4', 'column', or '' for
-- modifiers). It exists so the same word can legitimately point at
-- two targets ("section loss" is level 4 for both spalling and
-- exposed rebar) while exact duplicates are still rejected.
--
-- word_count is informational only. The engine counts words itself
-- after normalising the term, so a wrong value here cannot break
-- matching.
CREATE TABLE LexiconTerms (
  term_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term             VARCHAR(100) NOT NULL,
  kind             ENUM('defect','condition','element','negation',
                        'intensifier','dampener','exposure','verdict') NOT NULL,
  defect_type_id   INT UNSIGNED DEFAULT NULL,
  criterion_id     INT UNSIGNED DEFAULT NULL,
  element_type_id  INT UNSIGNED DEFAULT NULL,
  implies_severity ENUM('low','moderate','high','critical') DEFAULT NULL,
  target_code      VARCHAR(60)  NOT NULL DEFAULT '',
  word_count       TINYINT      NOT NULL DEFAULT 1,
  priority         INT          NOT NULL DEFAULT 0,
  allow_fuzzy      TINYINT(1)   NOT NULL DEFAULT 1,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  source_note      VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uq_lt_term_target (term, kind, target_code),
  INDEX idx_lt_lookup (kind, is_active),
  CONSTRAINT fk_lt_defect FOREIGN KEY (defect_type_id)
    REFERENCES DefectTypes(defect_type_id) ON DELETE CASCADE,
  CONSTRAINT fk_lt_criterion FOREIGN KEY (criterion_id)
    REFERENCES DefectCriteria(criterion_id) ON DELETE CASCADE,
  CONSTRAINT fk_lt_element FOREIGN KEY (element_type_id)
    REFERENCES ElementTypes(element_type_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table 4d as data. The engine looks up; it never multiplies D and C.
CREATE TABLE SeverityMatrix (
  d_level  TINYINT NOT NULL,
  c_level  TINYINT NOT NULL,
  severity ENUM('low','moderate','high','critical') NOT NULL,
  PRIMARY KEY (d_level, c_level)
) ENGINE=InnoDB;


-- ============================================================
-- PART 2 — TESTING AND AUDIT
-- ============================================================

CREATE TABLE ClassificationTests (
  test_id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_code                VARCHAR(50) NOT NULL,
  description              TEXT NOT NULL,
  phase                    ENUM('construction','post_completion') NOT NULL DEFAULT 'construction',
  test_kind                ENUM('anchor','regression') NOT NULL,
  source_note              VARCHAR(255) DEFAULT NULL,
  expected_defect_type_id  INT UNSIGNED DEFAULT NULL,
  expected_element_type_id INT UNSIGNED DEFAULT NULL,
  expected_d               TINYINT DEFAULT NULL,
  expected_c               TINYINT DEFAULT NULL,
  expected_severity        ENUM('low','moderate','high','critical') NOT NULL,
  tolerance_bands          TINYINT NOT NULL DEFAULT 0,
  is_active                TINYINT(1) NOT NULL DEFAULT 1,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ct_code (test_code),
  INDEX idx_ct_kind (is_active, test_kind),
  CONSTRAINT fk_ct_defect FOREIGN KEY (expected_defect_type_id)
    REFERENCES DefectTypes(defect_type_id) ON DELETE SET NULL,
  CONSTRAINT fk_ct_element FOREIGN KEY (expected_element_type_id)
    REFERENCES ElementTypes(element_type_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE TestRuns (
  run_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_by       INT UNSIGNED DEFAULT NULL,
  trigger_note VARCHAR(255) DEFAULT NULL,
  total_tests  INT NOT NULL DEFAULT 0,
  passed       INT NOT NULL DEFAULT 0,
  failed       INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_tr_user FOREIGN KEY (run_by)
    REFERENCES Users(user_id) ON DELETE SET NULL,
  INDEX idx_tr_at (run_at)
) ENGINE=InnoDB;

CREATE TABLE TestResults (
  result_id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id                 INT UNSIGNED NOT NULL,
  test_id                INT UNSIGNED NOT NULL,
  actual_defect_type_id  INT UNSIGNED DEFAULT NULL,
  actual_element_type_id INT UNSIGNED DEFAULT NULL,
  actual_d               TINYINT DEFAULT NULL,
  actual_c               TINYINT DEFAULT NULL,
  actual_severity        ENUM('low','moderate','high','critical') DEFAULT NULL,
  band_delta             TINYINT DEFAULT NULL,
  passed                 TINYINT(1) NOT NULL DEFAULT 0,
  matched_terms          TEXT DEFAULT NULL,
  INDEX idx_res_run (run_id),
  CONSTRAINT fk_res_run  FOREIGN KEY (run_id)  REFERENCES TestRuns(run_id) ON DELETE CASCADE,
  CONSTRAINT fk_res_test FOREIGN KEY (test_id) REFERENCES ClassificationTests(test_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE LexiconChangelog (
  change_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  object_type     ENUM('defect_type','defect_criterion','element_type',
                       'lexicon_term','matrix') NOT NULL,
  object_code     VARCHAR(100) DEFAULT NULL,
  object_id       INT UNSIGNED DEFAULT NULL,
  action          ENUM('added','modified','deactivated') NOT NULL,
  before_value    TEXT DEFAULT NULL,
  after_value     TEXT DEFAULT NULL,
  reason          VARCHAR(500) DEFAULT NULL,
  source_citation VARCHAR(500) DEFAULT NULL,
  changed_by      INT UNSIGNED DEFAULT NULL,
  changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_by_run INT UNSIGNED DEFAULT NULL,
  INDEX idx_lc_at (changed_at),
  CONSTRAINT fk_lc_user FOREIGN KEY (changed_by)
    REFERENCES Users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_lc_run  FOREIGN KEY (verified_by_run)
    REFERENCES TestRuns(run_id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
-- PART 3 — EXTEND InspectionReports
--
-- `severity` KEEPS its current meaning: the effective severity.
-- `recommended_severity` is what the engine produced.
-- An override is simply: severity <> recommended_severity.
-- ============================================================

ALTER TABLE InspectionReports
  ADD COLUMN phase ENUM('construction','post_completion')
      NOT NULL DEFAULT 'construction' AFTER issue_type,
  ADD COLUMN defect_type_id       INT UNSIGNED DEFAULT NULL AFTER phase,
  ADD COLUMN element_type_id      INT UNSIGNED DEFAULT NULL AFTER defect_type_id,
  ADD COLUMN d_level              TINYINT      DEFAULT NULL AFTER element_type_id,
  ADD COLUMN c_level              TINYINT      DEFAULT NULL AFTER d_level,
  ADD COLUMN recommended_severity ENUM('low','moderate','high','critical')
      DEFAULT NULL AFTER c_level,
  ADD COLUMN classification_confidence TINYINT UNSIGNED DEFAULT NULL AFTER recommended_severity,
  ADD COLUMN matched_terms        TEXT         DEFAULT NULL AFTER classification_confidence,
  ADD COLUMN classification_notes TEXT         DEFAULT NULL AFTER matched_terms,
  ADD COLUMN severity_overridden_by INT UNSIGNED DEFAULT NULL AFTER classification_notes,
  ADD COLUMN severity_overridden_at DATETIME   DEFAULT NULL AFTER severity_overridden_by,
  ADD CONSTRAINT fk_rep_defect  FOREIGN KEY (defect_type_id)
      REFERENCES DefectTypes(defect_type_id)  ON DELETE SET NULL,
  ADD CONSTRAINT fk_rep_element FOREIGN KEY (element_type_id)
      REFERENCES ElementTypes(element_type_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rep_override FOREIGN KEY (severity_overridden_by)
      REFERENCES Users(user_id) ON DELETE SET NULL;

--
-- This knowledge base is a DEFECT TAXONOMY (DefectTypes,
-- DefectCriteria, ElementTypes, SeverityMatrix: what can be found and
-- how serious each level is) plus a DOMAIN LEXICON (LexiconTerms: the
-- words inspectors use, each mapped onto the taxonomy).
--
-- Sources, as recorded on each vocabulary row:
--   Paper Table 4c           the write-up plan's defect criteria
--   Team research file v2/v3 the team's compiled PH QA/QC vocabulary
--                            (DPWH Vol. IV, ICRI, ACI 562, ACI 222R,
--                            ACI 364.6T). Rows marked
--                            "(researcher-developed)" are the team's own
--                            Tagalog and inspector-phrase layer.
--   ICRI 2022                ICRI Concrete Repair Terminology (2022)
--   ACI 364.6T-02            Concrete Removal in Repairs Involving
--                            Corroded Reinforcing Steel
--   Engine v1 lexicon        words drafted with the first version
--   Engine default           small additions made while building this
--                            file; review these first
--
-- Deliberately NOT included (see the design note):
--   * the 0-100 scores, weights and override rules in the research
--     file. Severity comes from the D x C matrix, never from points.
--   * "movement", "gumalaw", "displacement", "kulang" on their own:
--     too generic, they misfire.
--   * "rebar exposed" as an automatic Critical. Both survey
--     respondents rated exposed rebar on a column High.
-- ============================================================


-- ============================================================
-- 1. SEVERITY MATRIX — paper Table 4d (Cox, 2008: lookup, not D x C)
-- ============================================================
INSERT INTO SeverityMatrix (d_level, c_level, severity) VALUES
(4,1,'moderate'), (4,2,'high'),     (4,3,'critical'), (4,4,'critical'),
(3,1,'low'),      (3,2,'moderate'), (3,3,'high'),     (3,4,'critical'),
(2,1,'low'),      (2,2,'low'),      (2,3,'moderate'), (2,4,'high'),
(1,1,'low'),      (1,2,'low'),      (1,3,'low'),      (1,4,'moderate')
ON DUPLICATE KEY UPDATE severity = VALUES(severity);


-- ============================================================
-- 2. ELEMENT TYPES — paper Table 4b (the C axis)
--    The default element is C=2 on purpose. Never default to C=4.
-- ============================================================
INSERT INTO ElementTypes (code, name, c_level, category, is_default) VALUES
('column',           'Column',                 4, 'Primary load-bearing', 0),
('beam',             'Beam',                   4, 'Primary load-bearing', 0),
('footing',          'Footing',                4, 'Primary load-bearing', 0),
('structural_slab',  'Structural slab',        4, 'Primary load-bearing', 0),
('structural_wall',  'Structural wall',        4, 'Primary load-bearing', 0),
('stair',            'Stair',                  3, 'Secondary structural or safety-related', 0),
('railing',          'Railing',                3, 'Secondary structural or safety-related', 0),
('retaining_wall',   'Retaining wall',         3, 'Secondary structural or safety-related', 0),
('canopy',           'Canopy',                 3, 'Secondary structural or safety-related', 0),
('partition',        'Partition wall',         2, 'Functional non-structural', 0),
('drainage',         'Drainage',               2, 'Functional non-structural', 0),
('functional_finish','Finishes affecting use', 2, 'Functional non-structural', 1),
('paint',            'Paint',                  1, 'Cosmetic', 0),
('cosmetic_finish',  'Non-functional finish',  1, 'Cosmetic', 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), c_level = VALUES(c_level),
  category = VALUES(category), is_default = VALUES(is_default);


-- ============================================================
-- 3. DEFECT TYPES — paper Table 4c rows, with phase
--    Material non-conformance applies to both phases: the research
--    file notes it can be discovered after completion.
-- ============================================================
INSERT INTO DefectTypes
  (code, name, applies_construction, applies_post_completion, is_general, sort_order, source_note) VALUES
('concrete_cracking',      'Concrete cracking',        1, 1, 0, 10,  'Paper Table 4c; ICRI 2022 crack definitions'),
('spalling_honeycombing',  'Spalling / honeycombing',  1, 1, 0, 20,  'Paper Table 4c; ICRI 2022'),
('exposed_corroded_rebar', 'Exposed / corroded rebar', 1, 1, 0, 30,  'Paper Table 4c'),
('dimensional_deviation',  'Dimensional deviation',    1, 0, 0, 40,  'Paper Table 4c; construction only per team research file v2'),
('material_nonconformance','Material non-conformance', 1, 1, 0, 50,  'Paper Table 4c; post-completion discovery per team research file v2'),
('poor_workmanship',       'Poor workmanship',         1, 1, 0, 60,  'Paper Table 4c'),
('water_leakage',          'Water leakage',            1, 1, 0, 70,  'Paper Table 4c'),
('settlement_movement',    'Settlement / movement',    1, 1, 0, 80,  'Paper Table 4c'),
('incomplete_work',        'Incomplete work',          1, 0, 0, 90,  'Paper Table 4c; construction only per team research file v2'),
('other',                  'Other (not listed)',       1, 1, 1, 999, 'Generic path, paper Table 4a')
ON DUPLICATE KEY UPDATE name = VALUES(name),
  applies_construction = VALUES(applies_construction),
  applies_post_completion = VALUES(applies_post_completion),
  is_general = VALUES(is_general), sort_order = VALUES(sort_order),
  source_note = VALUES(source_note);


-- ============================================================
-- 4. DEFECT CRITERIA — the D=1..4 ladder for every defect type
--    (paper Table 4c). c_default marks the level used when a defect
--    is named but no condition word is found. It is level 2 for
--    every type: conservative, never the top.
--
--    Settlement and dimensional deviation have NO level 1 (team
--    decision: "none visible" and "within tolerance" are not defects).
-- ============================================================
DROP TEMPORARY TABLE IF EXISTS kb_criteria;
CREATE TEMPORARY TABLE kb_criteria (
  c_code       VARCHAR(50)  NOT NULL,
  c_level      TINYINT      NOT NULL,
  c_descriptor VARCHAR(255) NOT NULL,
  c_default    TINYINT      NOT NULL
);

INSERT INTO kb_criteria (c_code, c_level, c_descriptor, c_default) VALUES
('concrete_cracking', 1, 'Hairline (about 0.10 mm wide, per ICRI 2022)', 0),
('concrete_cracking', 2, 'Within tolerable width for exposure', 1),
('concrete_cracking', 3, 'Exceeds tolerable width', 0),
('concrete_cracking', 4, 'Wide, active, or through-section', 0),

('spalling_honeycombing', 1, 'Surface blemish', 0),
('spalling_honeycombing', 2, 'Shallow, no rebar visible', 1),
('spalling_honeycombing', 3, 'Deep, rebar partially visible', 0),
('spalling_honeycombing', 4, 'Rebar exposed, section loss', 0),

('exposed_corroded_rebar', 2, 'Exposed, no corrosion', 1),
('exposed_corroded_rebar', 3, 'Surface corrosion present', 0),
('exposed_corroded_rebar', 4, 'Section loss, heavy corrosion', 0),

('dimensional_deviation', 2, 'At tolerance limit', 1),
('dimensional_deviation', 3, 'Exceeds tolerance', 0),
('dimensional_deviation', 4, 'Far exceeds; affects fit or capacity', 0),

('material_nonconformance', 1, 'Minor variance', 0),
('material_nonconformance', 2, 'Below spec, non-critical use', 1),
('material_nonconformance', 3, 'Below spec, structural use', 0),
('material_nonconformance', 4, 'Untested or substandard in structural use', 0),

('poor_workmanship', 1, 'Cosmetic', 0),
('poor_workmanship', 2, 'Affects appearance and finish', 1),
('poor_workmanship', 3, 'Affects durability or function', 0),
('poor_workmanship', 4, 'Affects structural performance', 0),

('water_leakage', 1, 'Damp staining', 0),
('water_leakage', 2, 'Intermittent seepage', 1),
('water_leakage', 3, 'Continuous leak', 0),
('water_leakage', 4, 'Active flow; affects structure', 0),

('settlement_movement', 2, 'Minor, stable', 1),
('settlement_movement', 3, 'Visible, monitored', 0),
('settlement_movement', 4, 'Active, progressive', 0),

('incomplete_work', 1, 'Cosmetic pending', 0),
('incomplete_work', 2, 'Non-structural pending', 1),
('incomplete_work', 3, 'Structural work pending', 0),
('incomplete_work', 4, 'Safety-critical work missing', 0),

('other', 1, 'Cosmetic only; no effect on function or durability', 0),
('other', 2, 'Affects appearance or minor function; no durability impact', 1),
('other', 3, 'Affects durability, serviceability, or safety of use', 0),
('other', 4, 'Affects structural integrity or poses immediate danger', 0);

-- Remove a level 1 row for the two types that no longer have one
-- (deleting a criterion also deletes the words that pointed at it).
DELETE FROM DefectCriteria
 WHERE d_level = 1
   AND defect_type_id IN (SELECT defect_type_id FROM DefectTypes
                           WHERE code IN ('settlement_movement', 'dimensional_deviation'));

INSERT INTO DefectCriteria (defect_type_id, d_level, descriptor, is_default)
SELECT * FROM (
  SELECT dt.defect_type_id AS src_type, c.c_level AS src_level,
         c.c_descriptor AS src_descriptor, c.c_default AS src_default
    FROM kb_criteria c
    JOIN DefectTypes dt ON dt.code = c.c_code
) AS src
ON DUPLICATE KEY UPDATE descriptor = VALUES(descriptor), is_default = VALUES(is_default);


-- ============================================================
-- 5. VOCABULARY
--
-- v_kind:
--   defect       names the defect type
--   condition    sets the level (v_level) within the defect type
--   both         a phrase that does both, e.g. "deep crack" names
--                cracking AND sets level 3. Needed because the engine
--                matches the longest phrase first, so "deep crack"
--                uses up the word "crack".
--   element      names the element (v_target = element code)
--   negation / intensifier / dampener / exposure   modifiers
--   verdict      the inspector's conclusion; v_implies is the
--                severity it points to. Cross-check only.
--
-- A condition word may point at more than one defect type ("deep",
-- "section loss"). The engine uses the one that matches the defect
-- actually named in the description.
--
-- Terms are written the way inspectors write them. The engine
-- lowercases, strips punctuation, drops filler words (the, a, is,
-- ang, sa, ng, may ...) and reduces plurals and -ing/-ed endings,
-- so "cracks", "cracking" and "cracked" all match "crack".
-- ============================================================
DROP TEMPORARY TABLE IF EXISTS kb_vocab;
CREATE TEMPORARY TABLE kb_vocab (
  v_target  VARCHAR(50)  NOT NULL,
  v_term    VARCHAR(100) NOT NULL,
  v_kind    VARCHAR(20)  NOT NULL,
  v_level   TINYINT      DEFAULT NULL,
  v_implies VARCHAR(10)  DEFAULT NULL,
  v_source  VARCHAR(120) NOT NULL
);

-- ---------- Concrete cracking ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('concrete_cracking', 'crack',                'defect', NULL, NULL, 'Team research file v2'),
('concrete_cracking', 'cracking',             'defect', NULL, NULL, 'Team research file v2'),
('concrete_cracking', 'fissure',              'defect', NULL, NULL, 'ICRI 2022'),
('concrete_cracking', 'lamat',                'defect', NULL, NULL, 'Team research file v2'),
('concrete_cracking', 'bitak',                'defect', NULL, NULL, 'Team research file v2'),
('concrete_cracking', 'basag',                'defect', NULL, NULL, 'Engine v1 lexicon'),
('concrete_cracking', 'settlement crack',     'defect', NULL, NULL, 'Team research file v2'),
('concrete_cracking', 'flexural crack',       'defect', NULL, NULL, 'Team research file v2'),
('concrete_cracking', 'map cracking',         'defect', NULL, NULL, 'ICRI 2022'),
('concrete_cracking', 'pattern cracking',     'defect', NULL, NULL, 'ICRI 2022'),
('concrete_cracking', 'diagonal crack',       'defect', NULL, NULL, 'ICRI 2022'),
('concrete_cracking', 'dormant crack',        'defect', NULL, NULL, 'ICRI 2022'),
('concrete_cracking', 'hairline crack',       'both', 1, NULL, 'Team research file v2'),
('concrete_cracking', 'fine crack',           'both', 1, NULL, 'Team research file v2'),
('concrete_cracking', 'minor crack',          'both', 1, NULL, 'Team research file v2'),
('concrete_cracking', 'shrinkage crack',      'both', 1, NULL, 'Team research file v2'),
('concrete_cracking', 'craze cracks',         'both', 1, NULL, 'ICRI 2022'),
('concrete_cracking', 'crazing',              'both', 1, NULL, 'ICRI 2022'),
('concrete_cracking', 'visible crack',        'both', 2, NULL, 'Team research file v2'),
('concrete_cracking', 'moderate crack',       'both', 2, NULL, 'Team research file v2'),
('concrete_cracking', 'wider crack',          'both', 2, NULL, 'Team research file v2'),
('concrete_cracking', 'deep crack',           'both', 3, NULL, 'Team research file v2'),
('concrete_cracking', 'structural crack',     'both', 3, NULL, 'Team research file v2'),
('concrete_cracking', 'significant cracking', 'both', 3, NULL, 'Team research file v2'),
('concrete_cracking', 'wide crack',           'both', 4, NULL, 'Team research file v2'),
('concrete_cracking', 'active crack',         'both', 4, NULL, 'Team research file v2'),
('concrete_cracking', 'progressive crack',    'both', 4, NULL, 'Team research file v2'),
('concrete_cracking', 'through section crack','both', 4, NULL, 'Team research file v2'),
('concrete_cracking', 'full depth crack',     'both', 4, NULL, 'Team research file v2'),
('concrete_cracking', 'hairline',             'condition', 1, NULL, 'Paper Table 4c'),
('concrete_cracking', 'deep',                 'condition', 3, NULL, 'Team research file v2'),
('concrete_cracking', 'wide',                 'condition', 4, NULL, 'Paper Table 4c'),
('concrete_cracking', 'through section',      'condition', 4, NULL, 'Paper Table 4c'),
('concrete_cracking', 'full depth',           'condition', 4, NULL, 'Team research file v2');

-- ---------- Spalling / honeycombing ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('spalling_honeycombing', 'spalling',              'defect', NULL, NULL, 'Team research file v2'),
('spalling_honeycombing', 'honeycombing',          'defect', NULL, NULL, 'Team research file v2'),
('spalling_honeycombing', 'honeycomb',             'defect', NULL, NULL, 'ICRI 2022'),
('spalling_honeycombing', 'rock pocket',           'defect', NULL, NULL, 'Team research file v2'),
('spalling_honeycombing', 'void',                  'defect', NULL, NULL, 'Team research file v2'),
('spalling_honeycombing', 'segregation',           'defect', NULL, NULL, 'Team research file v2'),
('spalling_honeycombing', 'exposed aggregate',     'defect', NULL, NULL, 'Team research file v2'),
('spalling_honeycombing', 'delamination',          'defect', NULL, NULL, 'ICRI 2022'),
('spalling_honeycombing', 'surface void',          'both', 1, NULL, 'Team research file v2'),
('spalling_honeycombing', 'minor honeycombing',    'both', 1, NULL, 'Team research file v2'),
('spalling_honeycombing', 'slight honeycomb',      'both', 1, NULL, 'Team research file v2'),
('spalling_honeycombing', 'bug holes',             'both', 1, NULL, 'ICRI 2022'),
('spalling_honeycombing', 'localized honeycombing','both', 2, NULL, 'Team research file v2'),
('spalling_honeycombing', 'voids observed',        'both', 2, NULL, 'Team research file v2'),
('spalling_honeycombing', 'deep honeycombing',     'both', 3, NULL, 'Team research file v2'),
('spalling_honeycombing', 'large voids',           'both', 3, NULL, 'Team research file v2'),
('spalling_honeycombing', 'surface blemish',       'condition', 1, NULL, 'Paper Table 4c'),
('spalling_honeycombing', 'shallow',               'condition', 2, NULL, 'Paper Table 4c'),
('spalling_honeycombing', 'deep',                  'condition', 3, NULL, 'Paper Table 4c'),
('spalling_honeycombing', 'rebar partially visible','condition', 3, NULL, 'Paper Table 4c'),
('spalling_honeycombing', 'section loss',          'condition', 4, NULL, 'Paper Table 4c');
-- Concrete cover words moved to exposed/corroded rebar in research
-- file v3 (see the Corrections block after the vocabulary is loaded).

-- ---------- Exposed / corroded rebar ----------
-- Named on its own, exposed rebar is level 2 ("exposed, no
-- corrosion"). Corrosion words raise it; section loss is level 4.
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('exposed_corroded_rebar', 'exposed rebar',         'defect', NULL, NULL, 'Engine v1 lexicon'),
('exposed_corroded_rebar', 'exposed reinforcement', 'defect', NULL, NULL, 'Engine v1 lexicon'),
('exposed_corroded_rebar', 'rebar exposed',         'defect', NULL, NULL, 'Team research file v2'),
('exposed_corroded_rebar', 'reinforcement exposed', 'defect', NULL, NULL, 'Team research file v2'),
('exposed_corroded_rebar', 'steel exposed',         'defect', NULL, NULL, 'Team research file v2'),
('exposed_corroded_rebar', 'exposed steel',         'defect', NULL, NULL, 'Engine default (word order of "steel exposed")'),
('exposed_corroded_rebar', 'corroded rebar',        'both', 3, NULL, 'Engine v1 lexicon'),
('exposed_corroded_rebar', 'rusting rebar',         'both', 3, NULL, 'Engine v1 lexicon'),
('exposed_corroded_rebar', 'no corrosion',          'condition', 2, NULL, 'Paper Table 4c'),
('exposed_corroded_rebar', 'surface corrosion',     'condition', 3, NULL, 'Paper Table 4c'),
('exposed_corroded_rebar', 'section loss',          'condition', 4, NULL, 'Paper Table 4c'),
('exposed_corroded_rebar', 'heavy corrosion',       'condition', 4, NULL, 'Paper Table 4c'),
('exposed_corroded_rebar', 'severe corrosion',      'condition', 4, NULL, 'Engine default');

-- Research file v3 (exposed rebar). Table 4c has no level 1 for this
-- type, so v3's level-1 phrases are loaded as plain names: "localized"
-- or "minor" nudges D down, and D stops at 2. If the team wants a
-- level 1 here, Table 4c in the paper must change first.
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('exposed_corroded_rebar', 'reinforcement exposure',    'defect', NULL, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'steel exposure',            'defect', NULL, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'rebar exposure',            'defect', NULL, NULL, 'Engine default (variant of "reinforcement exposure")'),
('exposed_corroded_rebar', 'exposed bar',               'defect', NULL, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'cover loss',                'defect', NULL, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'insufficient cover',        'defect', NULL, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'reduced concrete cover',    'both', 2, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'reduced cover',             'both', 2, NULL, 'Team research file v2 (moved per v3)'),
('exposed_corroded_rebar', 'concrete cover compromised','both', 2, NULL, 'Team research file v2 (moved per v3)'),
('exposed_corroded_rebar', 'loss of concrete cover',    'both', 3, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'corroded reinforcement',    'both', 3, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'significant rebar exposure','both', 3, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'severe reinforcement exposure','both', 4, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'extensive corrosion',       'condition', 4, NULL, 'Team research file v3'),
('exposed_corroded_rebar', 'advanced corrosion',        'condition', 4, NULL, 'ACI 364.6T-02 wording'),
-- Tagalog (researcher-developed layer)
('exposed_corroded_rebar', 'nakalitaw ang bakal',       'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'lantad ang bakal',          'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'exposed ang bakal',         'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'nakalabas ang bakal',       'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'bakal na ang nakikita',     'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'manipis ang cover',         'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'kulang sa cover',           'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'kalawangin ang bakal',      'both', 3, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'may kalawang ang bakal',    'both', 3, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'kalawang',                  'condition', 3, NULL, 'Team research file v3 (researcher-developed)'),
('exposed_corroded_rebar', 'rust',                      'condition', 3, NULL, 'Engine default (English of "kalawang"; Table 4c "surface corrosion")'),
('exposed_corroded_rebar', 'rusting',                   'condition', 3, NULL, 'Engine default (English of "kalawang"; Table 4c "surface corrosion")');

-- ---------- Water leakage ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('water_leakage', 'leak',                'defect', NULL, NULL, 'Engine v1 lexicon'),
('water_leakage', 'leakage',             'defect', NULL, NULL, 'ICRI 2022'),
('water_leakage', 'seepage',             'defect', NULL, NULL, 'Engine v1 lexicon'),
('water_leakage', 'tagas',               'defect', NULL, NULL, 'Engine v1 lexicon'),
('water_leakage', 'water ingress',       'defect', NULL, NULL, 'Engine v1 lexicon'),
('water_leakage', 'infiltration',        'defect', NULL, NULL, 'ICRI 2022'),
('water_leakage', 'damp stain',          'both', 1, NULL, 'Paper Table 4c'),
('water_leakage', 'intermittent seepage','both', 2, NULL, 'Paper Table 4c'),
('water_leakage', 'continuous leak',     'both', 3, NULL, 'Paper Table 4c'),
('water_leakage', 'damp',                'condition', 1, NULL, 'Paper Table 4c'),
('water_leakage', 'intermittent',        'condition', 2, NULL, 'Paper Table 4c'),
('water_leakage', 'continuous',          'condition', 3, NULL, 'Paper Table 4c'),
('water_leakage', 'active flow',         'condition', 4, NULL, 'Paper Table 4c');

-- Research file v3 (water leakage). "Observed", "noted", "found" are
-- dropped by the engine, so "leakage noted" is simply "leakage".
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('water_leakage', 'water leakage',        'defect', NULL, NULL, 'Team research file v3'),
('water_leakage', 'water seepage',        'defect', NULL, NULL, 'Team research file v3'),
('water_leakage', 'water intrusion',      'defect', NULL, NULL, 'Team research file v3'),
('water_leakage', 'water infiltration',   'defect', NULL, NULL, 'Team research file v3; ICRI 2022 (infiltration)'),
('water_leakage', 'leaking crack',        'defect', NULL, NULL, 'Team research file v3'),
('water_leakage', 'dampness',             'both', 1, NULL, 'Team research file v3; ICRI 2022 (damp)'),
('water_leakage', 'moisture stain',       'both', 1, NULL, 'Team research file v3'),
('water_leakage', 'water marks',          'both', 1, NULL, 'Team research file v3'),
('water_leakage', 'moisture',             'condition', 1, NULL, 'Team research file v3'),
('water_leakage', 'water ingress',        'both', 3, NULL, 'Team research file v3 ("water ingress observed" = level 3)'),
('water_leakage', 'active leak',          'both', 3, NULL, 'Team research file v3'),
('water_leakage', 'active leakage',       'both', 3, NULL, 'Team research file v3'),
('water_leakage', 'recurrent',            'condition', 3, NULL, 'Team research file v3 ("recurrent leakage")'),
('water_leakage', 'recurring',            'condition', 3, NULL, 'Engine default (variant of "recurrent")'),
('water_leakage', 'heavy water intrusion','both', 4, NULL, 'Team research file v3'),
('water_leakage', 'significant leakage',  'both', 4, NULL, 'Team research file v3'),
('water_leakage', 'critical leakage',     'both', 4, NULL, 'Team research file v3'),
('water_leakage', 'affecting electrical components','condition', 4, NULL, 'Team research file v3'),
-- Tagalog (researcher-developed layer)
('water_leakage', 'tumutulo',             'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('water_leakage', 'tumutulong tubig',     'defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('water_leakage', 'nakakapasok ang tubig','defect', NULL, NULL, 'Team research file v3 (researcher-developed)'),
('water_leakage', 'basang pader',         'both', 1, NULL, 'Team research file v3 (researcher-developed)'),
('water_leakage', 'basang kisame',        'both', 1, NULL, 'Team research file v3 (researcher-developed)'),
('water_leakage', 'basa',                 'condition', 1, NULL, 'Team research file v3 (researcher-developed)');

-- ---------- Settlement / movement (no level 1) ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('settlement_movement', 'settlement',             'defect', NULL, NULL, 'Team research file v2'),
('settlement_movement', 'differential settlement','defect', NULL, NULL, 'Team research file v2'),
('settlement_movement', 'subsidence',             'defect', NULL, NULL, 'Engine v1 lexicon'),
('settlement_movement', 'lumubog',                'defect', NULL, NULL, 'Team research file v2'),
('settlement_movement', 'lubog',                  'defect', NULL, NULL, 'Engine v1 lexicon'),
('settlement_movement', 'minor settlement',       'both', 2, NULL, 'Paper Table 4c'),
('settlement_movement', 'visible settlement',     'both', 3, NULL, 'Paper Table 4c'),
('settlement_movement', 'active settlement',      'both', 4, NULL, 'Team research file v2'),
('settlement_movement', 'progressive settlement', 'both', 4, NULL, 'Paper Table 4c'),
('settlement_movement', 'progressive movement',   'both', 4, NULL, 'Team research file v2'),
('settlement_movement', 'stable',                 'condition', 2, NULL, 'Paper Table 4c'),
('settlement_movement', 'monitored',              'condition', 3, NULL, 'Paper Table 4c');

-- ---------- Dimensional deviation (no level 1) ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('dimensional_deviation', 'dimensional deviation',     'defect', NULL, NULL, 'Paper Table 4c'),
('dimensional_deviation', 'out of plumb',              'defect', NULL, NULL, 'Team research file v2'),
('dimensional_deviation', 'out of level',              'defect', NULL, NULL, 'Team research file v2'),
('dimensional_deviation', 'out of line',               'defect', NULL, NULL, 'Team research file v2'),
('dimensional_deviation', 'misalignment',              'defect', NULL, NULL, 'Team research file v2'),
('dimensional_deviation', 'misaligned',                'defect', NULL, NULL, 'Engine default (variant of "misalignment")'),
('dimensional_deviation', 'uneven',                    'defect', NULL, NULL, 'Engine default (English of "hindi pantay", team research file v2)'),
('dimensional_deviation', 'tabingi',                   'defect', NULL, NULL, 'Team research file v2'),
('dimensional_deviation', 'hindi pantay',              'defect', NULL, NULL, 'Team research file v2'),
('dimensional_deviation', 'dimension exceeds tolerance','both', 3, NULL, 'Team research file v2'),
('dimensional_deviation', 'at tolerance limit',        'condition', 2, NULL, 'Paper Table 4c'),
('dimensional_deviation', 'exceeds tolerance',         'condition', 3, NULL, 'Paper Table 4c'),
('dimensional_deviation', 'beyond tolerance',          'condition', 3, NULL, 'Team research file v2'),
('dimensional_deviation', 'out of tolerance',          'condition', 3, NULL, 'Engine default'),
('dimensional_deviation', 'far exceeds',               'condition', 4, NULL, 'Paper Table 4c');

-- ---------- Material non-conformance ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('material_nonconformance', 'material non-conformance','defect', NULL, NULL, 'Paper Table 4c'),
('material_nonconformance', 'non-conforming material', 'defect', NULL, NULL, 'Team research file v2'),
('material_nonconformance', 'rejected material',       'defect', NULL, NULL, 'Team research file v2'),
('material_nonconformance', 'substandard',             'defect', NULL, NULL, 'Team research file v2'),
('material_nonconformance', 'below specification',     'defect', NULL, NULL, 'Paper Table 4c'),
('material_nonconformance', 'below spec',              'defect', NULL, NULL, 'Paper Table 4c'),
('material_nonconformance', 'minor variance',          'both', 1, NULL, 'Paper Table 4c'),
('material_nonconformance', 'failed test',             'both', 3, NULL, 'Team research file v2'),
('material_nonconformance', 'untested',                'both', 4, NULL, 'Paper Table 4c');

-- ---------- Poor workmanship ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('poor_workmanship', 'poor workmanship',        'defect', NULL, NULL, 'Team research file v2'),
('poor_workmanship', 'defective workmanship',   'defect', NULL, NULL, 'Team research file v2'),
('poor_workmanship', 'poor finishing',          'defect', NULL, NULL, 'Team research file v2'),
('poor_workmanship', 'poor installation',       'defect', NULL, NULL, 'Team research file v2'),
('poor_workmanship', 'pangit ang gawa',         'defect', NULL, NULL, 'Team research file v2'),
('poor_workmanship', 'sablay ang pagkakagawa',  'defect', NULL, NULL, 'Team research file v2'),
('poor_workmanship', 'cosmetic',                'condition', 1, NULL, 'Paper Table 4c'),
('poor_workmanship', 'affects appearance',      'condition', 2, NULL, 'Paper Table 4c'),
('poor_workmanship', 'affects durability',      'condition', 3, NULL, 'Paper Table 4c'),
('poor_workmanship', 'affects function',        'condition', 3, NULL, 'Paper Table 4c'),
('poor_workmanship', 'affects structural performance','condition', 4, NULL, 'Paper Table 4c');

-- ---------- Incomplete work ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('incomplete_work', 'incomplete work',            'defect', NULL, NULL, 'Team research file v2'),
('incomplete_work', 'unfinished work',            'defect', NULL, NULL, 'Team research file v2'),
('incomplete_work', 'pending work',               'defect', NULL, NULL, 'Team research file v2'),
('incomplete_work', 'incomplete',                 'defect', NULL, NULL, 'Engine default'),
('incomplete_work', 'unfinished',                 'defect', NULL, NULL, 'Engine default'),
('incomplete_work', 'cosmetic pending',           'both', 1, NULL, 'Paper Table 4c'),
('incomplete_work', 'non-structural pending',     'both', 2, NULL, 'Paper Table 4c'),
('incomplete_work', 'structural work pending',    'both', 3, NULL, 'Paper Table 4c'),
('incomplete_work', 'critical work incomplete',   'both', 4, NULL, 'Team research file v2'),
('incomplete_work', 'safety-critical work missing','both', 4, NULL, 'Paper Table 4c');

-- ---------- Other (generic path) ----------
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('other', 'structural distress', 'condition', 4, NULL, 'Team research file v2');

-- ---------- Elements (the C axis) ----------
-- "non structural wall" must stay: without it, "non-structural wall"
-- would match "structural wall" (C=4).
-- A bare "wall" of unknown type is C=2, the same as the default, so
-- it can never inflate severity. It exists so that "crack on the wall
-- near the column" picks the wall and flags the column, instead of
-- silently rating the column.
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('column',           'column',              'element', NULL, NULL, 'Engine v1 lexicon'),
('column',           'haligi',              'element', NULL, NULL, 'Engine v1 lexicon'),
('beam',             'beam',                'element', NULL, NULL, 'Engine v1 lexicon'),
('beam',             'girder',              'element', NULL, NULL, 'Engine v1 lexicon'),
('footing',          'footing',             'element', NULL, NULL, 'Engine v1 lexicon'),
('footing',          'foundation',          'element', NULL, NULL, 'Engine v1 lexicon'),
('structural_slab',  'slab',                'element', NULL, NULL, 'Engine v1 lexicon'),
('structural_wall',  'structural wall',     'element', NULL, NULL, 'Engine v1 lexicon'),
('structural_wall',  'load bearing wall',   'element', NULL, NULL, 'Engine v1 lexicon'),
('structural_wall',  'shear wall',          'element', NULL, NULL, 'Engine v1 lexicon'),
('stair',            'stair',               'element', NULL, NULL, 'Engine v1 lexicon'),
('stair',            'staircase',           'element', NULL, NULL, 'Engine default'),
('railing',          'railing',             'element', NULL, NULL, 'Engine v1 lexicon'),
('railing',          'handrail',            'element', NULL, NULL, 'Engine v1 lexicon'),
('railing',          'guardrail',           'element', NULL, NULL, 'Engine default'),
('retaining_wall',   'retaining wall',      'element', NULL, NULL, 'Engine v1 lexicon'),
('canopy',           'canopy',              'element', NULL, NULL, 'Engine v1 lexicon'),
('partition',        'partition',           'element', NULL, NULL, 'Engine v1 lexicon'),
('partition',        'partition wall',      'element', NULL, NULL, 'Engine v1 lexicon'),
('partition',        'non structural wall', 'element', NULL, NULL, 'Engine v1 lexicon'),
('partition',        'nonstructural wall',  'element', NULL, NULL, 'Engine v1 lexicon'),
('partition',        'wall',                'element', NULL, NULL, 'Engine default (unknown wall type = C2, never C4)'),
('drainage',         'drainage',            'element', NULL, NULL, 'Engine v1 lexicon'),
('drainage',         'canal',               'element', NULL, NULL, 'Engine v1 lexicon'),
('drainage',         'drain',               'element', NULL, NULL, 'Engine default'),
('drainage',         'gutter',              'element', NULL, NULL, 'Engine default'),
('functional_finish','ceiling',             'element', NULL, NULL, 'Engine v1 lexicon'),
('functional_finish','kisame',              'element', NULL, NULL, 'Engine default (Tagalog, from "basang kisame")'),
('partition',        'pader',               'element', NULL, NULL, 'Engine default (Tagalog, from "basang pader"; unknown wall = C2)'),
('functional_finish','floor tile',          'element', NULL, NULL, 'Engine v1 lexicon'),
('paint',            'paint',               'element', NULL, NULL, 'Engine v1 lexicon');

-- ---------- Modifiers ----------
-- Intensifiers and dampeners move D by ONE level at most, however
-- many of them appear. The condition decides; modifiers only nudge.
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('', 'no',          'negation', NULL, NULL, 'Engine v1 lexicon'),
('', 'not',         'negation', NULL, NULL, 'Engine v1 lexicon'),
('', 'none',        'negation', NULL, NULL, 'Engine v1 lexicon'),
('', 'without',     'negation', NULL, NULL, 'Engine v1 lexicon'),
('', 'free of',     'negation', NULL, NULL, 'Engine v1 lexicon'),
('', 'walang',      'negation', NULL, NULL, 'Engine v1 lexicon'),

('', 'severe',      'intensifier', NULL, NULL, 'Engine v1 lexicon'),
('', 'major',       'intensifier', NULL, NULL, 'Engine v1 lexicon'),
('', 'extensive',   'intensifier', NULL, NULL, 'Engine v1 lexicon'),
('', 'widespread',  'intensifier', NULL, NULL, 'Engine v1 lexicon'),
('', 'worsening',   'intensifier', NULL, NULL, 'Engine v1 lexicon'),
('', 'progressive', 'intensifier', NULL, NULL, 'Engine v1 lexicon'),
('', 'significant', 'intensifier', NULL, NULL, 'Team research file v2'),
('', 'structural distress', 'intensifier', NULL, NULL, 'Team research file v2'),
('', 'malakas',     'intensifier', NULL, NULL, 'Team research file v3 ("malakas ang tagas")'),

('', 'minor',       'dampener', NULL, NULL, 'Team research file v2'),
('', 'slight',      'dampener', NULL, NULL, 'Team research file v2'),
('', 'minimal',     'dampener', NULL, NULL, 'Team research file v2'),
('', 'cosmetic',    'dampener', NULL, NULL, 'Team research file v2'),
('', 'localized',   'dampener', NULL, NULL, 'Team research file v2'),
('', 'isolated',    'dampener', NULL, NULL, 'Team research file v2'),
('', 'superficial', 'dampener', NULL, NULL, 'Engine v1 lexicon'),
('', 'bahagya',     'dampener', NULL, NULL, 'Team research file v3 ("may bahagyang tagas")'),
('', 'bahagyang',   'dampener', NULL, NULL, 'Team research file v3 ("may bahagyang tagas")'),
('', 'stable',      'dampener', NULL, NULL, 'Engine v1 lexicon'),

-- Exposure: people at risk. Raises C by one, never D. The survey's
-- "work continuing below" gave the only Critical in 16 judgments, and
-- the Project Inspector prioritises by "impact to the public".
('', 'work continuing below', 'exposure', NULL, NULL, 'Survey (City Engineer Office)'),
('', 'work below',            'exposure', NULL, NULL, 'Engine v1 lexicon'),
('', 'workers below',         'exposure', NULL, NULL, 'Engine v1 lexicon'),
('', 'people below',          'exposure', NULL, NULL, 'Engine default'),
('', 'occupied',              'exposure', NULL, NULL, 'Engine v1 lexicon'),
('', 'occupants',             'exposure', NULL, NULL, 'Engine default'),
('', 'public access',         'exposure', NULL, NULL, 'Engine v1 lexicon'),
('', 'public area',           'exposure', NULL, NULL, 'Engine default'),
('', 'pedestrians',           'exposure', NULL, NULL, 'Engine default'),
('', 'egress',                'exposure', NULL, NULL, 'Engine v1 lexicon'),
('', 'walkway',               'exposure', NULL, NULL, 'Engine v1 lexicon'),
('', 'in use',                'exposure', NULL, NULL, 'Engine v1 lexicon');

-- ---------- Verdicts ----------
-- The inspector's own conclusion. These never change the result.
-- If the engine lands two or more bands away from what a verdict
-- implies, the report is flagged for the supervisor. One band apart
-- is not flagged: that is the level of disagreement the survey
-- showed between two qualified experts.
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
('', 'for monitoring',        'verdict', NULL, 'low', 'Team research file v2'),
('', 'touch up required',     'verdict', NULL, 'low', 'Team research file v2'),
('', 'requires patch repair', 'verdict', NULL, 'low', 'Team research file v3'),
('', 'acceptable',            'verdict', NULL, 'low', 'Team research file v2'),
('', 'within tolerance',      'verdict', NULL, 'low', 'Team research file v2'),

('', 'repair required',       'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'rectification required','verdict', NULL, 'moderate', 'Team research file v2'),
('', 'correction required',   'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'needs repair',          'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'needs correction',      'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'requires attention',    'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'remedial work',         'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'repair recommended',    'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'requires sealing',      'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'visible defect',        'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'for compliance',        'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'for correction',        'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'for repair',            'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'for rework',            'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'for rectification',     'verdict', NULL, 'moderate', 'Team research file v3'),
('', 'requires waterproofing repair','verdict', NULL, 'moderate', 'Team research file v3'),
('', 'requires concrete repair','verdict', NULL, 'moderate', 'Team research file v3'),
('', 'paki ayos',             'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'back job',              'verdict', NULL, 'moderate', 'Team research file v2'),
('', 'balik gawa',            'verdict', NULL, 'moderate', 'Team research file v2'),

('', 'requires corrective action','verdict', NULL, 'high', 'Team research file v2'),
('', 'for engineer review',   'verdict', NULL, 'high', 'Team research file v2'),
('', 'requires investigation','verdict', NULL, 'high', 'Team research file v2'),
('', 'requires engineer assessment','verdict', NULL, 'high', 'Team research file v2'),
('', 'for assessment',        'verdict', NULL, 'high', 'Team research file v2'),
('', 'for engineer assessment','verdict', NULL, 'high', 'Team research file v3'),
('', 'failed inspection',     'verdict', NULL, 'high', 'Team research file v2'),
('', 'cannot be accepted',    'verdict', NULL, 'high', 'Team research file v2'),
('', 'major defect',          'verdict', NULL, 'high', 'Team research file v2'),
('', 'ncr',                   'verdict', NULL, 'high', 'Team research file v2 (DPWH Vol. IV NCR)'),
('', 'for ncr',               'verdict', NULL, 'high', 'Team research file v2 (DPWH Vol. IV NCR)'),
('', 'ncr recommended',       'verdict', NULL, 'high', 'Team research file v2 (DPWH Vol. IV NCR)'),
('', 'non-conforming',        'verdict', NULL, 'high', 'Team research file v2'),
('', 'non compliant',         'verdict', NULL, 'high', 'Team research file v2'),
('', 'not in accordance with specifications','verdict', NULL, 'high', 'Team research file v2'),
('', 'hindi ayon sa plano',   'verdict', NULL, 'high', 'Team research file v2'),
('', 'hindi ayon sa specs',   'verdict', NULL, 'high', 'Team research file v2'),

('', 'unsafe',                'verdict', NULL, 'critical', 'Team research file v2'),
('', 'unsafe condition',      'verdict', NULL, 'critical', 'Team research file v2'),
('', 'dangerous condition',   'verdict', NULL, 'critical', 'Team research file v2'),
('', 'hazardous condition',   'verdict', NULL, 'critical', 'Team research file v2'),
('', 'critical defect',       'verdict', NULL, 'critical', 'Team research file v2'),
('', 'structural concern',    'verdict', NULL, 'critical', 'Team research file v2'),
('', 'structural integrity affected','verdict', NULL, 'critical', 'Team research file v2'),
('', 'possible structural failure','verdict', NULL, 'critical', 'Team research file v2'),
('', 'collapse risk',         'verdict', NULL, 'critical', 'Team research file v2'),
('', 'life safety',           'verdict', NULL, 'critical', 'Team research file v2'),
('', 'stop work',             'verdict', NULL, 'critical', 'Team research file v2'),
('', 'immediate corrective action required','verdict', NULL, 'critical', 'Team research file v2'),
('', 'immediate repair required','verdict', NULL, 'critical', 'Team research file v3'),
('', 'replacement required',  'verdict', NULL, 'critical', 'Team research file v2'),
('', 'subject for replacement','verdict', NULL, 'critical', 'Team research file v2'),
('', 'demolition required',   'verdict', NULL, 'critical', 'Team research file v2'),
('', 'cannot be occupied',    'verdict', NULL, 'critical', 'Team research file v2'),
('', 'occupancy not allowed', 'verdict', NULL, 'critical', 'Team research file v2'),
('', 'cannot be commissioned','verdict', NULL, 'critical', 'Team research file v2');


-- ---------- Load the vocabulary into LexiconTerms ----------
-- Defect names (and the defect half of "both" phrases)
INSERT IGNORE INTO LexiconTerms
  (term, kind, defect_type_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, 'defect', dt.defect_type_id, dt.code,
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 1, v.v_source
  FROM kb_vocab v
  JOIN DefectTypes dt ON dt.code = v.v_target
 WHERE v.v_kind IN ('defect', 'both');

-- Conditions (and the condition half of "both" phrases)
INSERT IGNORE INTO LexiconTerms
  (term, kind, criterion_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, 'condition', dc.criterion_id, CONCAT(dt.code, ':', dc.d_level),
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 1, v.v_source
  FROM kb_vocab v
  JOIN DefectTypes dt    ON dt.code = v.v_target
  JOIN DefectCriteria dc ON dc.defect_type_id = dt.defect_type_id AND dc.d_level = v.v_level
 WHERE v.v_kind IN ('condition', 'both');

-- Elements
INSERT IGNORE INTO LexiconTerms
  (term, kind, element_type_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, 'element', et.element_type_id, et.code,
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 1, v.v_source
  FROM kb_vocab v
  JOIN ElementTypes et ON et.code = v.v_target
 WHERE v.v_kind = 'element';

-- Modifiers and verdicts (never typo-matched)
INSERT IGNORE INTO LexiconTerms
  (term, kind, implies_severity, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, v.v_kind, v.v_implies, '',
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 0, v.v_source
  FROM kb_vocab v
 WHERE v.v_kind IN ('negation', 'intensifier', 'dampener', 'exposure', 'verdict');

-- ---------- Corrections ----------
-- This file only adds words. A word that moves or is withdrawn is
-- switched off here, with the reason, so the change stays traceable.
-- On a fresh database these statements change nothing.

-- v3: concrete cover words belong to exposed/corroded rebar, not spalling.
UPDATE LexiconTerms SET is_active = 0
 WHERE kind = 'condition' AND target_code = 'spalling_honeycombing:3'
   AND term IN ('reduced cover', 'concrete cover compromised', 'kulang ang cover');

-- v3: "section loss" on its own is filed under exposed rebar. It is
-- level 4 for both spalling and rebar, so severity is the same either
-- way; this only decides the label when no defect is named.
UPDATE LexiconTerms SET priority = 1
 WHERE term = 'section loss' AND kind = 'condition'
   AND target_code = 'exposed_corroded_rebar:4';


-- ============================================================
-- 6. SURVEY ANCHOR TESTS
--    City Engineer's Office, Tarlac City — Project Inspector and
--    Engineer I. Expected values are the matrix answer for each
--    scenario; all fall within one band of BOTH respondents, hence
--    tolerance 1.
--
--    These sentences must NOT be used to pick vocabulary. If an
--    anchor fails, record it. Adding its exact wording to the lexicon
--    makes the accuracy figure measure nothing.
-- ============================================================
DROP TEMPORARY TABLE IF EXISTS kb_anchors;
CREATE TEMPORARY TABLE kb_anchors (
  a_code        VARCHAR(50)  NOT NULL,
  a_description VARCHAR(255) NOT NULL,
  a_phase       VARCHAR(20)  NOT NULL,
  a_source      VARCHAR(255) NOT NULL,
  a_defect      VARCHAR(50)  NOT NULL,
  a_element     VARCHAR(50)  NOT NULL,
  a_d           TINYINT      NOT NULL,
  a_c           TINYINT      NOT NULL,
  a_severity    VARCHAR(10)  NOT NULL
);

INSERT INTO kb_anchors VALUES
('SURVEY-1', 'Hairline crack on a non-structural wall', 'construction',
 'Survey: Inspector=moderate, Engr I=low', 'concrete_cracking', 'partition', 1, 2, 'low'),
('SURVEY-2', 'Wide crack on a concrete beam with work continuing below', 'construction',
 'Survey: Inspector=critical, Engr I=high. Only critical in the set.', 'concrete_cracking', 'beam', 4, 4, 'critical'),
('SURVEY-3', 'Exposed reinforcement bar on a column', 'construction',
 'Survey: both high.', 'exposed_corroded_rebar', 'column', 2, 4, 'high'),
('SURVEY-4', 'Minor surface finishing defect', 'construction',
 'Survey: both low.', 'poor_workmanship', 'cosmetic_finish', 1, 1, 'low'),
('SURVEY-5', 'Dimensional deviation exceeding the specified tolerance', 'construction',
 'Survey: both moderate. No element stated.', 'dimensional_deviation', 'functional_finish', 3, 2, 'moderate'),
('SURVEY-6', 'Water leakage into a finished area', 'post_completion',
 'Survey: both moderate.', 'water_leakage', 'functional_finish', 3, 2, 'moderate'),
('SURVEY-7', 'Honeycombing found on a newly poured column', 'construction',
 'Survey: both high.', 'spalling_honeycombing', 'column', 2, 4, 'high'),
('SURVEY-8', 'Settlement observed near a completed structure', 'post_completion',
 'Survey: Inspector=moderate, Engr I=high. THE AMBIGUOUS ONE: experts split here.',
 'settlement_movement', 'footing', 2, 4, 'high');

INSERT INTO ClassificationTests
  (test_code, description, phase, test_kind, source_note,
   expected_defect_type_id, expected_element_type_id,
   expected_d, expected_c, expected_severity, tolerance_bands)
SELECT * FROM (
  SELECT a.a_code AS src_code, a.a_description AS src_description,
         a.a_phase AS src_phase, 'anchor' AS src_kind, a.a_source AS src_source,
         dt.defect_type_id AS src_defect, et.element_type_id AS src_element,
         a.a_d AS src_d, a.a_c AS src_c, a.a_severity AS src_severity, 1 AS src_tolerance
    FROM kb_anchors a
    LEFT JOIN DefectTypes dt  ON dt.code = a.a_defect
    LEFT JOIN ElementTypes et ON et.code = a.a_element
) AS src
ON DUPLICATE KEY UPDATE description = VALUES(description), phase = VALUES(phase),
  source_note = VALUES(source_note),
  expected_defect_type_id = VALUES(expected_defect_type_id),
  expected_element_type_id = VALUES(expected_element_type_id),
  expected_d = VALUES(expected_d), expected_c = VALUES(expected_c),
  expected_severity = VALUES(expected_severity), tolerance_bands = VALUES(tolerance_bands);


-- ============================================================
-- 7. REGRESSION TESTS (internal quality control, NOT accuracy)
--    Expected values are the engine's own output on the day it was
--    checked. They guard the tricky rules: longest-phrase matching,
--    negation, sentence breaks, typos, Taglish, verdict cross-check.
--    Tolerance 0: any change means a rule or word changed behaviour.
--    Never quote these in Chapter 4 accuracy figures.
-- ============================================================
DROP TEMPORARY TABLE IF EXISTS kb_regression;
CREATE TEMPORARY TABLE kb_regression (
  r_code        VARCHAR(50)  NOT NULL,
  r_description VARCHAR(255) NOT NULL,
  r_phase       VARCHAR(20)  NOT NULL,
  r_note        VARCHAR(255) NOT NULL,
  r_severity    VARCHAR(10)  NOT NULL
);

INSERT INTO kb_regression VALUES
('REG-01', 'Exposed rebar on a column, no corrosion yet', 'construction',
 '"no corrosion" is a condition phrase, not a negation', 'high'),
('REG-02', 'No cracks observed, the surface is sound', 'construction',
 'negated defect: nothing found, routed to general path', 'low'),
('REG-03', 'Noted crack at the beam soffit', 'construction',
 '"noted" must not be read as the negation "not"', 'high'),
('REG-04', 'No leak. Wide crack on the beam.', 'construction',
 'a full stop ends the negation, so the crack survives', 'critical'),
('REG-05', 'Hairline crack on the non-structural wall', 'construction',
 '"non structural wall" must not match "structural wall"', 'low'),
('REG-06', 'May bitak sa haligi, deep', 'construction',
 'Taglish: bitak = crack, haligi = column, filler words dropped', 'critical'),
('REG-07', 'Severe widespread extensive hairline cracking on partition', 'construction',
 'modifiers move D by one level at most', 'low'),
('REG-08', 'Hairline crack on the partition, stop work', 'construction',
 'verdict far above the result: flagged for review, result unchanged', 'low'),
('REG-09', 'Settlment near the foundation, progressive', 'post_completion',
 'typo "settlment" still matches settlement', 'critical'),
('REG-10', 'Continuous leak through the ceiling of the occupied unit', 'post_completion',
 'exposure raises C by one', 'high'),
('REG-11', 'Crack on the wall near the column', 'construction',
 'nearest element (wall) wins; the column is flagged, not silently used', 'low'),
('REG-12', 'May tumutulo sa kisame', 'post_completion',
 'Tagalog: tumutulo = leak, kisame = ceiling', 'low'),
('REG-13', 'Malakas ang tagas sa ceiling ng occupied unit', 'post_completion',
 'malakas raises D by one; occupied raises C by one', 'high'),
('REG-14', 'Nakalitaw ang bakal sa haligi, may kalawang', 'construction',
 'Tagalog rebar name + rust (level 3) on a column', 'critical'),
('REG-15', 'Localized reinforcement exposure on the beam', 'construction',
 'rebar has no level 1 in Table 4c: "localized" cannot push it below 2', 'high'),
('REG-16', 'No leak observed, dampness on the wall only', 'post_completion',
 '"observed" is filler; the leak is negated, dampness (level 1) remains', 'low'),
('REG-17', 'Walang kalawang, exposed ang bakal sa beam', 'construction',
 'Tagalog negation cancels the rust, not the exposed steel after the comma', 'high'),
('REG-18', 'Crack on the beam, 0.2 mm wide', 'construction',
 'a measurement is not graded: "wide" after a number is set aside and flagged', 'high'),
('REG-19', 'Exposed rebar at slab soffit, rusting, for NCR', 'construction',
 'rusting = surface corrosion (level 3) on a structural slab', 'critical');

INSERT INTO ClassificationTests
  (test_code, description, phase, test_kind, source_note, expected_severity, tolerance_bands)
SELECT * FROM (
  SELECT r.r_code AS src_code, r.r_description AS src_description, r.r_phase AS src_phase,
         'regression' AS src_kind, r.r_note AS src_note, r.r_severity AS src_severity,
         0 AS src_tolerance
    FROM kb_regression r
) AS src
ON DUPLICATE KEY UPDATE description = VALUES(description), phase = VALUES(phase),
  source_note = VALUES(source_note), expected_severity = VALUES(expected_severity),
  tolerance_bands = VALUES(tolerance_bands);


-- ============================================================
-- 8. CHANGELOG
-- ============================================================
INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'matrix', 'SeverityMatrix', 'added', '16 D x C cells',
       'Matrix assignment, not arithmetic product.',
       'Cox, L. A., Jr. (2008). What''s wrong with risk matrices? Risk Analysis, 28(2), 497-512.'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'SeverityMatrix');

INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'lexicon_term', 'knowledge_base_v2', 'added',
       'Criteria for all 10 defect types; vocabulary expanded; verdict cross-check terms',
       'Settlement and dimensional deviation start at level 2 (team decision). Material non-conformance applies to both phases.',
       'Paper Table 4c; team research file v2; ICRI Concrete Repair Terminology (2022)'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'knowledge_base_v2');

INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'lexicon_term', 'knowledge_base_v3', 'added',
       'Water leakage and exposed rebar vocabulary, Tagalog terms, conclusion phrases; cover words moved from spalling to rebar',
       'Team research file v3. Tagalog terms and inspector phrases are researcher-developed. Rebar keeps no level 1 (paper Table 4c).',
       'Team research file v3; ICRI Concrete Repair Terminology (2022); ACI 364.6T-02(11)'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'knowledge_base_v3');


-- ============================================================
-- 9. CHECKS — both result sets should be EMPTY
-- ============================================================

-- Criteria rows that did not load
SELECT c.c_code, c.c_level
  FROM kb_criteria c
 WHERE NOT EXISTS (SELECT 1 FROM DefectCriteria dc
                     JOIN DefectTypes dt ON dt.defect_type_id = dc.defect_type_id
                    WHERE dt.code = c.c_code AND dc.d_level = c.c_level);

-- Vocabulary rows that did not load
SELECT v.v_target, v.v_term, v.v_kind, v.v_level
  FROM kb_vocab v
 WHERE (v.v_kind IN ('defect', 'both') AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = 'defect' AND t.target_code = v.v_target))
    OR (v.v_kind IN ('condition', 'both') AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = 'condition'
             AND t.target_code = CONCAT(v.v_target, ':', v.v_level)))
    OR (v.v_kind = 'element' AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = 'element' AND t.target_code = v.v_target))
    OR (v.v_kind IN ('negation', 'intensifier', 'dampener', 'exposure', 'verdict') AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = v.v_kind));

-- ============================================================
-- ConstructFlow — Severity Engine — ADD SIZE WORDS (2026-09-30)
--
-- For a database that is ALREADY running. Paste into phpMyAdmin's
-- SQL tab and run once. Safe to run twice (duplicates are skipped).
-- No existing data is touched.
--
-- Size words are vague, so they move the level ONE step, the same
-- way "major" and "minor" already do. They never set a level alone:
--   "big crack"   = one above the default (level 3, same as "deep")
--   "small crack" = one below the default (level 1, same as "hairline")
-- ============================================================

INSERT IGNORE INTO LexiconTerms
  (term, kind, implies_severity, target_code, word_count, allow_fuzzy, source_note)
VALUES
('big',      'intensifier', NULL, '', 1, 0, 'Engine default (size word, one step like "major")'),
('large',    'intensifier', NULL, '', 1, 0, 'Engine default (size word, one step like "major")'),
('huge',     'intensifier', NULL, '', 1, 0, 'Engine default (size word, one step like "major")'),
('malaki',   'intensifier', NULL, '', 1, 0, 'Engine default (Tagalog "big")'),
('malaking', 'intensifier', NULL, '', 1, 0, 'Engine default (Tagalog "big")'),
('small',    'dampener',    NULL, '', 1, 0, 'Engine default (size word, one step like "minor")'),
('tiny',     'dampener',    NULL, '', 1, 0, 'Engine default (size word, one step like "minor")'),
('maliit',   'dampener',    NULL, '', 1, 0, 'Engine default (Tagalog "small")');

INSERT IGNORE INTO ClassificationTests
  (test_code, description, phase, test_kind, source_note, expected_severity, tolerance_bands)
VALUES
('REG-20', 'Small crack on the column', 'construction', 'regression',
 'size word lowers the default level by one (same as hairline)', 'moderate', 0),
('REG-21', 'Malaking bitak sa haligi', 'construction', 'regression',
 'Tagalog size word raises the default level by one (same as deep)', 'critical', 0);

INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'lexicon_term', 'size_words_2026_09_30', 'added',
       'big, large, huge, malaki, malaking (raise one level); small, tiny, maliit (lower one level)',
       'Inspectors describe size in plain words; these were ignored. One step only, like major/minor.',
       'Engine default; to be confirmed by the team'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'size_words_2026_09_30');


-- ============================================================
-- ConstructFlow — Severity Engine — ADD SYNONYMS (2026-09-30)
--
-- For a database that is ALREADY running. Run AFTER
-- severity_engine_add_size_words.sql.
--
-- Paste the WHOLE file into phpMyAdmin's SQL tab and run it in one go
-- (the temporary table only exists for that one run). Safe to run
-- twice: duplicates are skipped. No existing word is changed.
--
-- Adds other ways inspectors write words the engine already knows
-- ("rusty rebar", "lintel", "residents", "malalim", "getting wider"...).
-- Every word copies the meaning and level of an existing sourced word.
--
-- Needs the matching SeverityClassifier.php change ("no 5" / "no of"
-- and "2 m wide"), or REG-22, REG-28 and REG-29 will fail.
-- ============================================================

DROP TEMPORARY TABLE IF EXISTS kb_vocab;
CREATE TEMPORARY TABLE kb_vocab (
  v_target  VARCHAR(50)  NOT NULL,
  v_term    VARCHAR(100) NOT NULL,
  v_kind    VARCHAR(20)  NOT NULL,
  v_level   TINYINT      DEFAULT NULL,
  v_implies VARCHAR(10)  DEFAULT NULL,
  v_source  VARCHAR(120) NOT NULL
);

-- ---------- Synonyms and variants (2026-09-30) ----------
-- Other ways inspectors write words the engine already knows. Each row
-- has the SAME meaning and level as an existing, sourced word (named in
-- v_source). None of them introduces a new defect type or a new level
-- decision. Words deliberately NOT added:
--   "sinking"  reduces to "sink" and would read "leak under the sink"
--              as settlement; "sunk", "sunken", "subsided" are used instead
--   "chipping" is also the name of intentional concrete removal work
--   "steps"    reduces to "step" ("next step"), too common in prose
--   "pile"     alone also means a heap ("pile of sand"); only "pile cap" is used
--   "pedestal" alone also means a fixture ("pedestal lavatory");
--              only "column pedestal" is used
--   "flaking"  alone is mostly paint ("paint flaking"); only the
--              concrete phrases are used
INSERT INTO kb_vocab (v_target, v_term, v_kind, v_level, v_implies, v_source) VALUES
-- Cracking
('concrete_cracking', 'thin crack',          'both', 1, NULL, 'Engine default (synonym of "fine crack")'),
('concrete_cracking', 'micro crack',         'both', 1, NULL, 'Engine default (synonym of "fine crack")'),
('concrete_cracking', 'microcrack',          'both', 1, NULL, 'Engine default (synonym of "fine crack")'),
('concrete_cracking', 'manipis',             'condition', 1, NULL, 'Engine default (Tagalog "thin")'),
('concrete_cracking', 'malalim',             'condition', 3, NULL, 'Engine default (Tagalog of "deep")'),
('concrete_cracking', 'malapad',             'condition', 4, NULL, 'Engine default (Tagalog of "wide")'),
('concrete_cracking', 'through crack',       'both', 4, NULL, 'Engine default (synonym of "through section crack")'),
('concrete_cracking', 'widening crack',      'both', 4, NULL, 'Engine default (synonym of "active crack")'),
('concrete_cracking', 'growing crack',       'both', 4, NULL, 'Engine default (synonym of "active crack")'),
('concrete_cracking', 'spreading crack',     'both', 4, NULL, 'Engine default (synonym of "active crack")'),
('concrete_cracking', 'propagating crack',   'both', 4, NULL, 'Engine default (synonym of "active crack")'),
('concrete_cracking', 'widening',            'condition', 4, NULL, 'Engine default (synonym of "active")'),
('concrete_cracking', 'wider',               'condition', 4, NULL, 'Engine default ("getting wider" = widening)'),
('concrete_cracking', 'lumalapad',           'condition', 4, NULL, 'Engine default (Tagalog "widening")'),
-- Spalling / honeycombing
('spalling_honeycombing', 'malalim',         'condition', 3, NULL, 'Engine default (Tagalog of "deep")'),
('spalling_honeycombing', 'chipped concrete','defect', NULL, NULL, 'Engine default (synonym of "spalling")'),
('spalling_honeycombing', 'chipped edge',    'defect', NULL, NULL, 'Engine default (synonym of "spalling")'),
('spalling_honeycombing', 'scaling',         'defect', NULL, NULL, 'ICRI 2022 (deterioration: scaling)'),
('spalling_honeycombing', 'flaking concrete','defect', NULL, NULL, 'ICRI 2022 (deterioration: flaking)'),
('spalling_honeycombing', 'concrete flaking','defect', NULL, NULL, 'ICRI 2022 (deterioration: flaking)'),
('spalling_honeycombing', 'delaminated',     'defect', NULL, NULL, 'ICRI 2022 (form of "delamination")'),
('spalling_honeycombing', 'popout',          'both', 1, NULL, 'ICRI 2022 (blemish: popout)'),
-- Exposed / corroded rebar ("bars" is too short to be reduced, so both forms are listed)
('exposed_corroded_rebar', 'exposed bars',             'defect', NULL, NULL, 'Engine default (plural of "exposed bar")'),
('exposed_corroded_rebar', 'exposed reinforcing bar',  'defect', NULL, NULL, 'Engine default (synonym of "exposed reinforcement")'),
('exposed_corroded_rebar', 'exposed reinforcing bars', 'defect', NULL, NULL, 'Engine default (synonym of "exposed reinforcement")'),
('exposed_corroded_rebar', 'exposed reinforcing steel','defect', NULL, NULL, 'Engine default (synonym of "exposed reinforcement")'),
('exposed_corroded_rebar', 'visible rebar',            'defect', NULL, NULL, 'Engine default (synonym of "exposed rebar")'),
('exposed_corroded_rebar', 'visible reinforcement',    'defect', NULL, NULL, 'Engine default (synonym of "exposed reinforcement")'),
('exposed_corroded_rebar', 'rebar showing',            'defect', NULL, NULL, 'Engine default (synonym of "rebar exposed")'),
('exposed_corroded_rebar', 'rusty rebar',              'both', 3, NULL, 'Engine default (synonym of "rusting rebar")'),
('exposed_corroded_rebar', 'rusty',                    'condition', 3, NULL, 'Engine default (synonym of "rust")'),
('exposed_corroded_rebar', 'kinakalawang',             'condition', 3, NULL, 'Engine default (Tagalog "rusting", like "kalawang")'),
('exposed_corroded_rebar', 'kinakalawang na bakal',    'both', 3, NULL, 'Engine default (Tagalog "rusting steel", like "kalawangin ang bakal")'),
-- Water leakage
('water_leakage', 'drip',          'defect', NULL, NULL, 'Engine default (covers dripping/drips; synonym of "tumutulo")'),
('water_leakage', 'tulo',          'defect', NULL, NULL, 'Engine default (Tagalog "drip", like "tumutulo")'),
('water_leakage', 'seep',          'defect', NULL, NULL, 'Engine default (covers seeping/seeps; form of "seepage")'),
('water_leakage', 'water stain',   'both', 1, NULL, 'Engine default (synonym of "damp stain")'),
('water_leakage', 'wet spot',      'both', 1, NULL, 'Engine default (synonym of "damp")'),
('water_leakage', 'wet wall',      'both', 1, NULL, 'Engine default (synonym of "damp", like "basang pader")'),
('water_leakage', 'wet ceiling',   'both', 1, NULL, 'Engine default (synonym of "damp", like "basang kisame")'),
('water_leakage', 'moist',         'condition', 1, NULL, 'Engine default (synonym of "moisture")'),
-- Settlement / movement
('settlement_movement', 'settled',               'defect', NULL, NULL, 'Engine default (covers settle/settled/settling)'),
('settlement_movement', 'sunk',                  'defect', NULL, NULL, 'Engine default (synonym of "lumubog")'),
('settlement_movement', 'sunken',                'defect', NULL, NULL, 'Engine default (synonym of "lumubog")'),
('settlement_movement', 'subsided',              'defect', NULL, NULL, 'Engine default (form of "subsidence")'),
('settlement_movement', 'uneven settlement',     'defect', NULL, NULL, 'Engine default (so "uneven" is not read as dimensional deviation)'),
('settlement_movement', 'ongoing settlement',    'both', 4, NULL, 'Engine default (synonym of "active settlement")'),
('settlement_movement', 'continuing settlement', 'both', 4, NULL, 'Engine default (synonym of "active settlement")'),
-- Dimensional deviation
('dimensional_deviation', 'not plumb',     'defect', NULL, NULL, 'Engine default (synonym of "out of plumb")'),
('dimensional_deviation', 'not level',     'defect', NULL, NULL, 'Engine default (synonym of "out of level")'),
('dimensional_deviation', 'not aligned',   'defect', NULL, NULL, 'Engine default (synonym of "misalignment")'),
('dimensional_deviation', 'out of square', 'defect', NULL, NULL, 'Engine default (like "out of plumb/level/line")'),
('dimensional_deviation', 'crooked',       'defect', NULL, NULL, 'Engine default (English of "tabingi")'),
-- Material non-conformance
('material_nonconformance', 'low strength',   'defect', NULL, NULL, 'Engine default (synonym of "below spec")'),
('material_nonconformance', 'below strength', 'defect', NULL, NULL, 'Engine default (synonym of "below spec")'),
('material_nonconformance', 'wrong material', 'defect', NULL, NULL, 'Engine default (synonym of "non-conforming material")'),
-- Poor workmanship
('poor_workmanship', 'bad workmanship',         'defect', NULL, NULL, 'Engine default (synonym of "poor workmanship")'),
('poor_workmanship', 'substandard workmanship', 'defect', NULL, NULL, 'Engine default (synonym of "poor workmanship")'),
('poor_workmanship', 'substandard work',        'defect', NULL, NULL, 'Engine default (synonym of "poor workmanship")'),
('poor_workmanship', 'poor quality',            'defect', NULL, NULL, 'Engine default (synonym of "poor workmanship")'),
('poor_workmanship', 'poorly done',             'defect', NULL, NULL, 'Engine default (synonym of "poor workmanship")'),
('poor_workmanship', 'sloppy',                  'defect', NULL, NULL, 'Engine default (synonym of "poor workmanship")'),
-- Incomplete work
('incomplete_work', 'missing',           'defect', NULL, NULL, 'Engine default (Table 4c: "work missing")'),
('incomplete_work', 'not installed',     'defect', NULL, NULL, 'Engine default (synonym of "pending work")'),
('incomplete_work', 'not yet installed', 'defect', NULL, NULL, 'Engine default (synonym of "pending work")'),
('incomplete_work', 'not finished',      'defect', NULL, NULL, 'Engine default (synonym of "unfinished")'),
('incomplete_work', 'not yet finished',  'defect', NULL, NULL, 'Engine default (synonym of "unfinished")'),
('incomplete_work', 'hindi pa tapos',    'defect', NULL, NULL, 'Engine default (Tagalog "not yet finished")'),
-- Building parts
('beam',     'lintel',     'element', NULL, NULL, 'Engine default (a lintel is a beam)'),
('beam',     'biga',       'element', NULL, NULL, 'Engine default (Tagalog "beam")'),
('column',   'pier',       'element', NULL, NULL, 'Engine default (building pier = short column)'),
('column',   'column pedestal', 'element', NULL, NULL, 'Engine default (a pedestal is the base of a column)'),
('footing',  'pile cap',   'element', NULL, NULL, 'Engine default (foundation element)'),
('footing',  'pundasyon',  'element', NULL, NULL, 'Engine default (Tagalog "foundation")'),
('stair',    'stairway',   'element', NULL, NULL, 'Engine default (synonym of "stair")'),
('stair',    'stairwell',  'element', NULL, NULL, 'Engine default (synonym of "stair")'),
('stair',    'hagdan',     'element', NULL, NULL, 'Engine default (Tagalog "stair")'),
('stair',    'hagdanan',   'element', NULL, NULL, 'Engine default (Tagalog "stairway")'),
('railing',  'balustrade', 'element', NULL, NULL, 'Engine default (synonym of "railing")'),
('railing',  'baluster',   'element', NULL, NULL, 'Engine default (synonym of "railing")'),
('railing',  'rehas',      'element', NULL, NULL, 'Engine default (Tagalog "railing")'),
('functional_finish', 'sahig', 'element', NULL, NULL, 'Engine default (Tagalog "floor", C2 like the default)'),
-- Size comparisons (one step, same as "big" / "large")
('', 'bigger',        'intensifier', NULL, NULL, 'Engine default (size word, like "big")'),
('', 'larger',        'intensifier', NULL, NULL, 'Engine default (size word, like "large")'),
-- People at risk (survey: "impact to the public")
('', 'residents',     'exposure', NULL, NULL, 'Engine default (like "occupants")'),
('', 'tenants',       'exposure', NULL, NULL, 'Engine default (like "occupants")'),
('', 'students',      'exposure', NULL, NULL, 'Engine default (like "occupants")'),
('', 'children',      'exposure', NULL, NULL, 'Engine default (like "occupants")'),
('', 'passersby',     'exposure', NULL, NULL, 'Engine default (like "pedestrians")'),
('', 'people nearby', 'exposure', NULL, NULL, 'Engine default (like "people below")'),
('', 'tao',           'exposure', NULL, NULL, 'Engine default (Tagalog "people": "may tao sa ilalim")'),
('', 'exit',          'exposure', NULL, NULL, 'Engine default (English of "egress")'),
('', 'fire exit',     'exposure', NULL, NULL, 'Engine default (English of "egress")'),
-- Conclusions (cross-check only)
('', 'urgent',           'verdict', NULL, 'high',     'Engine default (like "requires corrective action")'),
('', 'dangerous',        'verdict', NULL, 'critical', 'Engine default (like "dangerous condition")'),
('', 'danger',           'verdict', NULL, 'critical', 'Engine default (like "dangerous condition")'),
('', 'hazard',           'verdict', NULL, 'critical', 'Engine default (like "hazardous condition")'),
('', 'hazardous',        'verdict', NULL, 'critical', 'Engine default (like "hazardous condition")'),
('', 'risk of collapse', 'verdict', NULL, 'critical', 'Engine default (like "collapse risk")'),
('', 'evacuate',         'verdict', NULL, 'critical', 'Engine default (like "cannot be occupied")'),
('', 'immediate repair', 'verdict', NULL, 'critical', 'Engine default (like "immediate repair required")'),
('', 'delikado',         'verdict', NULL, 'critical', 'Engine default (Tagalog "dangerous")'),
('', 'mapanganib',       'verdict', NULL, 'critical', 'Engine default (Tagalog "dangerous")');


-- ---------- Load the vocabulary into LexiconTerms ----------
-- Defect names (and the defect half of "both" phrases)
INSERT IGNORE INTO LexiconTerms
  (term, kind, defect_type_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, 'defect', dt.defect_type_id, dt.code,
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 1, v.v_source
  FROM kb_vocab v
  JOIN DefectTypes dt ON dt.code = v.v_target
 WHERE v.v_kind IN ('defect', 'both');

-- Conditions (and the condition half of "both" phrases)
INSERT IGNORE INTO LexiconTerms
  (term, kind, criterion_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, 'condition', dc.criterion_id, CONCAT(dt.code, ':', dc.d_level),
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 1, v.v_source
  FROM kb_vocab v
  JOIN DefectTypes dt    ON dt.code = v.v_target
  JOIN DefectCriteria dc ON dc.defect_type_id = dt.defect_type_id AND dc.d_level = v.v_level
 WHERE v.v_kind IN ('condition', 'both');

-- Elements
INSERT IGNORE INTO LexiconTerms
  (term, kind, element_type_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, 'element', et.element_type_id, et.code,
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 1, v.v_source
  FROM kb_vocab v
  JOIN ElementTypes et ON et.code = v.v_target
 WHERE v.v_kind = 'element';

-- Modifiers and verdicts (never typo-matched)
INSERT IGNORE INTO LexiconTerms
  (term, kind, implies_severity, target_code, word_count, allow_fuzzy, source_note)
SELECT v.v_term, v.v_kind, v.v_implies, '',
       LENGTH(v.v_term) - LENGTH(REPLACE(v.v_term, ' ', '')) + 1, 0, v.v_source
  FROM kb_vocab v
 WHERE v.v_kind IN ('negation', 'intensifier', 'dampener', 'exposure', 'verdict');

-- ---------- Regression tests for the new words ----------
INSERT IGNORE INTO ClassificationTests
  (test_code, description, phase, test_kind, source_note, expected_severity, tolerance_bands)
VALUES
('REG-22', 'Column no 5 has a wide crack', 'construction', 'regression',
 '"no" before a number means "number", not a negation', 'critical', 0),
('REG-23', 'Rusty rebar on the beam', 'construction', 'regression',
 'synonym: rusty = rust (level 3)', 'critical', 0),
('REG-24', 'Crack on the lintel', 'construction', 'regression',
 'synonym: a lintel is a beam (C4)', 'high', 0),
('REG-25', 'Widening crack on the partition wall', 'construction', 'regression',
 'synonym: widening = active (level 4)', 'high', 0),
('REG-26', 'Deep crack on the stair, residents nearby', 'post_completion', 'regression',
 'people at risk: residents raise C by one', 'critical', 0),
('REG-27', 'Malalim na bitak sa biga', 'construction', 'regression',
 'Tagalog: malalim = deep, biga = beam', 'critical', 0),
('REG-28', 'No of wide cracks on the column: 3', 'construction', 'regression',
 '"no of" means "number of", not a negation', 'critical', 0),
('REG-29', 'Crack on the wall beside a 2 m wide opening', 'construction', 'regression',
 '"2 m wide" is a measurement: "wide" is set aside and flagged', 'low', 0),
('REG-30', 'Hairline crack near the pile of sand', 'construction', 'regression',
 '"pile" alone is a heap, not a foundation; no element named = C2', 'low', 0),
('REG-31', 'Crack on the pedestal lavatory', 'construction', 'regression',
 '"pedestal" alone is a fixture, not a column', 'low', 0),
('REG-32', 'Crack is getting wider on the column', 'construction', 'regression',
 'synonym: getting wider = widening (level 4)', 'critical', 0);

-- ---------- Changelog ----------
INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'lexicon_term', 'synonyms_2026_09_30', 'added',
       'Synonyms and variants of existing words (cracking, spalling, rebar, water, settlement, dimensional, material, workmanship, incomplete work, building parts, people at risk, conclusions)',
       'Other wordings were not recognised and gave a wrong severity. Every row copies the meaning and level of an existing sourced word; no new defect type or level decision.',
       'Engine default; ICRI Concrete Repair Terminology (2022); to be confirmed by the team'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'synonyms_2026_09_30');


-- ---------- Check: should return NO rows ----------
-- Vocabulary rows that did not load
SELECT v.v_target, v.v_term, v.v_kind, v.v_level
  FROM kb_vocab v
 WHERE (v.v_kind IN ('defect', 'both') AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = 'defect' AND t.target_code = v.v_target))
    OR (v.v_kind IN ('condition', 'both') AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = 'condition'
             AND t.target_code = CONCAT(v.v_target, ':', v.v_level)))
    OR (v.v_kind = 'element' AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = 'element' AND t.target_code = v.v_target))
    OR (v.v_kind IN ('negation', 'intensifier', 'dampener', 'exposure', 'verdict') AND NOT EXISTS
         (SELECT 1 FROM LexiconTerms t
           WHERE t.term = v.v_term AND t.kind = v.v_kind));

DROP TEMPORARY TABLE IF EXISTS kb_vocab;
