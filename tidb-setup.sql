-- PULSE CHO - Jalankan sekali di TiDB Cloud SQL Editor.
CREATE DATABASE IF NOT EXISTS pulse_cho;
USE pulse_cho;

CREATE TABLE IF NOT EXISTS pulse_dataset (
  dataset_key VARCHAR(64) NOT NULL PRIMARY KEY,
  `rows` JSON NOT NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  saved_by VARCHAR(255) NOT NULL DEFAULT '-',
  saved_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  active_version VARCHAR(32) NULL
);

ALTER TABLE pulse_dataset
  ADD COLUMN IF NOT EXISTS active_version VARCHAR(32) NULL;

CREATE TABLE IF NOT EXISTS pulse_dataset_chunks (
  dataset_key VARCHAR(64) NOT NULL,
  version_id VARCHAR(32) NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  `rows` JSON NOT NULL,
  row_count INT UNSIGNED NOT NULL,
  PRIMARY KEY (dataset_key, version_id, chunk_index)
);

CREATE TABLE IF NOT EXISTS pulse_users (
  username VARCHAR(64) NOT NULL PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL,
  site VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pulse_users_role_active (role, is_active)
);

CREATE TABLE IF NOT EXISTS pulse_sessions (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pulse_sessions_user (username),
  INDEX idx_pulse_sessions_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS pulse_auth_attempts (
  attempt_key CHAR(64) NOT NULL PRIMARY KEY,
  fail_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until TIMESTAMP NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Riwayat aktivitas login/logout (untuk menu Last Activity).
-- Tabel ini juga dibuat otomatis saat runtime bila belum ada.
CREATE TABLE IF NOT EXISTS pulse_activity_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  activity VARCHAR(32) NOT NULL,
  detail VARCHAR(255) NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pulse_activity_user_time (username, created_at),
  INDEX idx_pulse_activity_time (created_at)
);

-- Hak akses menu per role (visibilitas navigasi/menu).
-- Tabel ini juga dibuat otomatis saat runtime bila belum ada.
CREATE TABLE IF NOT EXISTS pulse_role_menus (
  role VARCHAR(32) NOT NULL PRIMARY KEY,
  menus TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
