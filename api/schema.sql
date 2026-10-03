-- Task Tap -- schema for MyDataWorld
-- Run in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER My Apps
-- Hub's own api/schema.sql (this uses the shared users/sessions/apps/
-- app_access/app_usage_log tables, and the Hub's user_preferences table holds
-- Task Tap's Maps/Calendar/default-reminder settings).
--
-- Tables are prefixed tt_. Every task row carries user_id (= users.id);
-- child rows (checklist, attachments, reminder, repeat) hang off their task
-- and are deleted with it.

-- ---------- PLATFORM TABLES (shared) -- idempotent for a standalone run ----------

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- TASK TAP ----------

-- task_date NULL = an undated task. Times are the user's local wall-clock
-- times. series_id groups the occurrences of a repeating task (= the first
-- occurrence's id); parent_task_id points at the occurrence this one was
-- generated from.
CREATE TABLE IF NOT EXISTS tt_tasks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  parent_task_id  INT NULL,
  series_id       INT NULL,
  title           VARCHAR(200) NOT NULL,
  notes           TEXT NULL,
  task_date       DATE NULL,
  start_time      TIME NULL,
  end_time        TIME NULL,
  leave_by_time   TIME NULL,
  contact_name    VARCHAR(150) NULL,
  phone_number    VARCHAR(40) NULL,
  address1        VARCHAR(200) NULL,
  address2        VARCHAR(200) NULL,
  city            VARCHAR(100) NULL,
  state           VARCHAR(50) NULL,
  postal_code     VARCHAR(20) NULL,
  is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
  is_completed    TINYINT(1) NOT NULL DEFAULT 0,
  completed_at    DATETIME NULL,
  calendar_added  TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tt_tasks_user_open (user_id, is_completed, task_date),
  KEY ix_tt_tasks_series (series_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_task_id) REFERENCES tt_tasks(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_checklist_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  task_id       INT NOT NULL,
  description   VARCHAR(300) NOT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  is_completed  TINYINT(1) NOT NULL DEFAULT 0,
  completed_at  DATETIME NULL,
  KEY ix_tt_checklist_task (task_id, sort_order),
  FOREIGN KEY (task_id) REFERENCES tt_tasks(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The file itself lives in api/uploads/<stored_name> (blocked from direct
-- web access); it's only served through the API to the task's owner.
CREATE TABLE IF NOT EXISTS tt_attachments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  task_id      INT NOT NULL,
  file_name    VARCHAR(255) NOT NULL,
  file_type    VARCHAR(100) NULL,
  file_size    INT NOT NULL DEFAULT 0,
  stored_name  VARCHAR(100) NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY ix_tt_attachments_task (task_id),
  FOREIGN KEY (task_id) REFERENCES tt_tasks(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- V1 keeps one reminder per task (enforced by the API). reminder_at is UTC.
-- offset_minutes = minutes before the task's start (relative reminder);
-- NULL = a custom fixed date/time.
CREATE TABLE IF NOT EXISTS tt_reminders (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  task_id         INT NOT NULL,
  reminder_at     DATETIME NOT NULL,
  offset_minutes  INT NULL,
  snoozed_until   DATETIME NULL,
  is_dismissed    TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY ix_tt_reminders_due (is_dismissed, reminder_at),
  FOREIGN KEY (task_id) REFERENCES tt_tasks(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- frequency: Daily | Weekly | Monthly | Yearly, every interval_value units.
-- days_of_week: e.g. '1,3,5' (1 = Monday) for Weekly; day_of_month for
-- Monthly. anchor_date = the series' first date, so "every 2 weeks" stays on
-- the right weeks. Next occurrence is computed from the due date, not the
-- day it was completed.
CREATE TABLE IF NOT EXISTS tt_repeat_rules (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  task_id          INT NOT NULL,
  frequency        VARCHAR(10) NOT NULL,
  interval_value   INT NOT NULL DEFAULT 1,
  days_of_week     VARCHAR(20) NULL,
  day_of_month     TINYINT NULL,
  end_date         DATE NULL,
  max_occurrences  INT NULL,
  anchor_date      DATE NOT NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY ux_tt_repeat_task (task_id),
  FOREIGN KEY (task_id) REFERENCES tt_tasks(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4-character, single-use, 5-minute codes for handing a copy of a task to
-- someone else. Validated server-side only.
CREATE TABLE IF NOT EXISTS tt_transfer_codes (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  source_task_id       INT NULL,
  source_user_id       INT NOT NULL,
  transfer_code        CHAR(4) NOT NULL,
  created_at           DATETIME NOT NULL,
  expires_at           DATETIME NOT NULL,
  redeemed_at          DATETIME NULL,
  redeemed_by_user_id  INT NULL,
  is_redeemed          TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_tt_transfer_code (transfer_code, is_redeemed, expires_at),
  FOREIGN KEY (source_task_id) REFERENCES tt_tasks(id) ON DELETE SET NULL,
  FOREIGN KEY (source_user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the task taken when the code was made, so the recipient gets
-- exactly what was shared. Attachment files are copied at that moment too
-- (payload lists their copies); unredeemed copies are cleaned up after expiry.
CREATE TABLE IF NOT EXISTS tt_transfer_packages (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  transfer_code_id  INT NOT NULL,
  payload           MEDIUMTEXT NOT NULL,
  created_at        DATETIME NOT NULL,
  expires_at        DATETIME NOT NULL,
  UNIQUE KEY ux_tt_package_code (transfer_code_id),
  FOREIGN KEY (transfer_code_id) REFERENCES tt_transfer_codes(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOOTSTRAP (run once, after you've signed up through My Apps Hub):
--
-- 1) In My Apps Hub's api/schema.sql, run the "SHARED: user_preferences"
--    block and the "NEW APP: Task Tap" block.
--
-- 2) Grant yourself the app (or use the Hub's admin.html):
--      INSERT INTO app_access (user_id, app_id)
--      SELECT u.id, a.id FROM users u, apps a
--      WHERE u.username = 'you@example.com' AND a.app_key = 'task-tap';
-- ============================================================
