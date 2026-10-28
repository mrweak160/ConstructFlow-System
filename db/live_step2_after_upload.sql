-- ============================================================
-- STEP 2 of 2 — run in phpMyAdmin AFTER the new files are uploaded.
-- The new code no longer uses the confidence score; the old code did,
-- which is why this waits until the upload is done. Safe to run twice.
-- ============================================================
ALTER TABLE InspectionReports DROP COLUMN IF EXISTS classification_confidence;
