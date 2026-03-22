-- One-time/maintenance cleanup for merit scholarship statuses.
-- Keeps at most one active merit scholarship per student (the latest by AY, semester, and row id).

USE school_db21;

-- Preview before cleanup
SELECT COUNT(*) AS active_merit_before
FROM student_scholarships ss
JOIN scholarships s ON s.scholarship_id = ss.scholarship_id
WHERE ss.status = 'Active'
  AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75');

SELECT ss.student_id, COUNT(*) AS active_merit_terms
FROM student_scholarships ss
JOIN scholarships s ON s.scholarship_id = ss.scholarship_id
WHERE ss.status = 'Active'
  AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75')
GROUP BY ss.student_id
HAVING COUNT(*) > 1
ORDER BY active_merit_terms DESC, ss.student_id;

START TRANSACTION;

UPDATE student_scholarships ss
JOIN scholarships s ON s.scholarship_id = ss.scholarship_id
JOIN (
    SELECT ss2.student_id,
           MAX(CONCAT(ss2.academic_year, '|', LPAD(ss2.semester, 2, '0'), '|', LPAD(ss2.student_scholarship_id, 10, '0'))) AS keep_key
    FROM student_scholarships ss2
    JOIN scholarships s2 ON s2.scholarship_id = ss2.scholarship_id
    WHERE ss2.status = 'Active'
      AND s2.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75')
    GROUP BY ss2.student_id
) latest ON latest.student_id = ss.student_id
SET ss.status = 'Revoked',
    ss.updated_at = NOW()
WHERE ss.status = 'Active'
  AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75')
  AND CONCAT(ss.academic_year, '|', LPAD(ss.semester, 2, '0'), '|', LPAD(ss.student_scholarship_id, 10, '0')) <> latest.keep_key;

SELECT ROW_COUNT() AS rows_revoked;

COMMIT;

-- Verify after cleanup
SELECT COUNT(*) AS active_merit_after
FROM student_scholarships ss
JOIN scholarships s ON s.scholarship_id = ss.scholarship_id
WHERE ss.status = 'Active'
  AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75');

SELECT ss.student_id, COUNT(*) AS active_merit_terms
FROM student_scholarships ss
JOIN scholarships s ON s.scholarship_id = ss.scholarship_id
WHERE ss.status = 'Active'
  AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75')
GROUP BY ss.student_id
HAVING COUNT(*) > 1
ORDER BY active_merit_terms DESC, ss.student_id;
