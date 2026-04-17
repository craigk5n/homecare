-- Migration 027: Photo / document attachments (HC-130)
-- BACKUP FIRST: ./dump.sh
--
-- Stores metadata for uploaded files (photos, PDFs) attached to a
-- patient, schedule, or caregiver note. The actual file content lives
-- on disk under data/attachments/<sha256[:2]>/<sha256>; this table
-- only records the pointer and metadata.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type VARCHAR(16) NOT NULL,
  owner_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(64) NOT NULL,
  size_bytes INT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  uploaded_by VARCHAR(25) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_attachments_owner ON hc_attachments (owner_type, owner_id);
