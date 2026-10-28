-- ============================================================
-- STEP 1 of 2 — run in phpMyAdmin BEFORE uploading the new files.
-- Sections 5–8 of upgrade_live.sql, without the confidence-column drop:
-- everything here only ADDS, so the code now on live keeps working.
-- Safe to run twice.
-- ============================================================

-- ── 5. Review flag replaces the confidence score ──

-- The engine no longer scores confidence. Whether a report needs the
-- supervisor's review is stored with it instead. Reports filed before
-- this change keep the flag they were shown: confidence below 80%.
-- Then the confidence column itself is dropped. Section 3 still adds it,
-- because matched_terms is placed after it, so a second run adds it empty
-- and drops it again.
ALTER TABLE InspectionReports
  ADD COLUMN IF NOT EXISTS needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER recommended_severity;

UPDATE InspectionReports
   SET needs_review = 1,
       updated_at = updated_at     -- keep it: the column refreshes itself on any change
 WHERE classification_confidence < 80 AND needs_review = 0;


-- ── 6. Negation scope and Tagalog negation words ──
-- ============================================================
-- Negation to the end of the clause; Tagalog "hindi" and "wala" (2026-10-26)
--
-- Safe to run twice. Needs the matching SeverityClassifier.php change
-- (negation scope), or REG-33 and REG-35 will fail.
-- ============================================================
INSERT IGNORE INTO LexiconTerms
  (term, kind, implies_severity, target_code, word_count, allow_fuzzy, source_note)
VALUES
('hindi', 'negation', NULL, '', 1, 0, 'Team research file v3 (researcher-developed): Tagalog "not"'),
('wala',  'negation', NULL, '', 1, 0, 'Team research file v3 (researcher-developed): Tagalog "none", like "walang"');

INSERT IGNORE INTO ClassificationTests
  (test_code, description, phase, test_kind, source_note, expected_severity, tolerance_bands)
VALUES
('REG-33', 'There are no visible signs of any cracking on the beam', 'construction', 'regression',
 'negation lasts to the end of the clause: crack and beam both cancelled', 'low', 0),
('REG-34', 'Hindi tumutulo, may malalim na bitak sa biga', 'construction', 'regression',
 'Tagalog hindi cancels the leak; the comma ends it, so the deep crack stays', 'critical', 0),
('REG-35', 'Without any sign of settlement around the footing', 'post_completion', 'regression',
 'negation lasts to the end of the clause: settlement and footing cancelled', 'low', 0);

INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'lexicon_term', 'negation_scope_2026_10_26', 'added',
       'hindi, wala (negation); negation now lasts to the end of its clause',
       'A three-word window missed longer phrases ("no visible signs of any cracking") and Tagalog "hindi" was not recognised.',
       'Researcher-developed Tagalog terms; scope rule after NegEx'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'negation_scope_2026_10_26');

-- ── 7. Due-date reminders; reports of closed tasks ──
-- ============================================================
-- Due-date reminders; reports of closed tasks (2026-10-26)
-- Safe to run twice.
-- ============================================================
ALTER TABLE InspectionTasks ADD COLUMN IF NOT EXISTS due_reminder_sent_at DATETIME DEFAULT NULL AFTER due_date;
ALTER TABLE WorkOrders ADD COLUMN IF NOT EXISTS deadline_reminder_sent_at DATETIME DEFAULT NULL AFTER deadline;

-- Reports whose task was closed without a work order: "Closed – No Action".
UPDATE InspectionReports r
  JOIN InspectionTasks t ON t.task_id = r.task_id
   SET r.status = 'rejected', r.updated_at = r.updated_at
 WHERE t.status = 'closed' AND r.status = 'pending'
   AND NOT EXISTS (SELECT 1 FROM WorkOrders w WHERE w.report_id = r.report_id);

-- ── 8. Missing railings; settlement names the foundation ──
-- ============================================================
-- Missing railings and settlement (2026-10-27)
-- "Missing handrail / guardrail / railing" is safety-critical work
-- missing (Paper Table 4c: incomplete work, level 4) on the railing
-- (Table 41, F-07). Settlement that names no part is read as the
-- footing (SURVEY-8); that rule is in SeverityClassifier.php.
-- Safe to run twice. Needs the matching SeverityClassifier.php.
-- ============================================================
INSERT IGNORE INTO LexiconTerms
  (term, kind, defect_type_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.t, 'defect', dt.defect_type_id, dt.code, 2, 1, 'Paper Table 4c (safety-critical work missing)'
  FROM (SELECT 'missing handrail' AS t UNION ALL SELECT 'missing guardrail' UNION ALL SELECT 'missing railing') v
  JOIN DefectTypes dt ON dt.code = 'incomplete_work';

INSERT IGNORE INTO LexiconTerms
  (term, kind, criterion_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.t, 'condition', dc.criterion_id, 'incomplete_work:4', 2, 1, 'Paper Table 4c (safety-critical work missing)'
  FROM (SELECT 'missing handrail' AS t UNION ALL SELECT 'missing guardrail' UNION ALL SELECT 'missing railing') v
  JOIN DefectTypes dt    ON dt.code = 'incomplete_work'
  JOIN DefectCriteria dc ON dc.defect_type_id = dt.defect_type_id AND dc.d_level = 4;

-- The phrase also names the part, so the railing is not lost to it.
INSERT IGNORE INTO LexiconTerms
  (term, kind, element_type_id, target_code, word_count, allow_fuzzy, source_note)
SELECT v.t, 'element', et.element_type_id, et.code, 2, 1, 'Paper Table 4c (safety-critical work missing)'
  FROM (SELECT 'missing handrail' AS t UNION ALL SELECT 'missing guardrail' UNION ALL SELECT 'missing railing') v
  JOIN ElementTypes et ON et.code = 'railing';

INSERT IGNORE INTO ClassificationTests
  (test_code, description, phase, test_kind, source_note, expected_severity, tolerance_bands)
VALUES
('REG-36', 'Missing handrail on the stair landing of the second floor', 'construction', 'regression',
 'Table 41 F-07: safety-critical work missing (D4) on the railing (C3)', 'critical', 0),
('REG-37', 'Settlement under the partition wall of the ground floor', 'post_completion', 'regression',
 'a named part still wins: settlement is read as the footing only when no part is named', 'low', 0);

INSERT INTO LexiconChangelog (object_type, object_code, action, after_value, reason, source_citation)
SELECT 'lexicon_term', 'railing_settlement_2026_10_27', 'added',
       'missing handrail, missing guardrail, missing railing (incomplete work, level 4, on the railing); settlement with no part named read as the footing',
       'Table 41 F-07 read a missing handrail as level 2; SURVEY-8 read settlement with no part named as a finish.',
       'Paper Table 4c (safety-critical work missing); settlement is foundation movement'
 WHERE NOT EXISTS (SELECT 1 FROM LexiconChangelog WHERE object_code = 'railing_settlement_2026_10_27');

