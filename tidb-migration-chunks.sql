-- ============================================================================
-- PULSE CHO — Migrasi skema dataset (jalankan sekali di TiDB Cloud SQL Editor)
-- Server hanya mengirim metadata + daftar chunk; isi tiap chunk diunduh
-- paralel oleh browser lewat dataset.php?chunk=<index>&version=<id>.
-- ============================================================================

CREATE TABLE IF NOT EXISTS pulse_dataset_chunk_index (
  dataset_key VARCHAR(64) NOT NULL,
  version_id  VARCHAR(32) NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  row_count   INT UNSIGNED NOT NULL,
  byte_length INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (dataset_key, version_id, chunk_index)
);

-- Cek hasil:
--   SELECT version_id, COUNT(*) AS chunk_count, SUM(row_count) AS total_rows
--   FROM pulse_dataset_chunk_index GROUP BY version_id;
