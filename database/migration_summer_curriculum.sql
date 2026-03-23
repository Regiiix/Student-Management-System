-- Summer curriculum rollout (Philippines-aligned term usage)
-- Semester mapping: 0 = Summer Term, 1 = 1st Semester, 2 = 2nd Semester
-- This migration adds optional summer tracks per program/year for:
-- 1) GE summer load progression
-- 2) OJT/Practicum tracks commonly scheduled in summer windows

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1) GE Summer subjects for all programs
-- -----------------------------------------------------------------------------
INSERT INTO curriculum (
    program_id, course_code, course_name, year_level, semester, units, prerequisite_id, description
)
SELECT
    p.program_id,
    'GE-SUMMER-01',
    'General Education Elective (Summer 1)',
    1,
    0,
    3,
    NULL,
    'Summer GE elective aligned with CHED flexible curriculum offerings.'
FROM programs p
WHERE p.program_code IN ('BSIT', 'BSCS', 'BSIS', 'BSBA', 'BSE')
  AND NOT EXISTS (
      SELECT 1
      FROM curriculum c
      WHERE c.program_id = p.program_id
        AND c.course_code = 'GE-SUMMER-01'
        AND c.year_level = 1
        AND c.semester = 0
  );

INSERT INTO curriculum (
    program_id, course_code, course_name, year_level, semester, units, prerequisite_id, description
)
SELECT
    p.program_id,
    'GE-SUMMER-02',
    'General Education Elective (Summer 2)',
    2,
    0,
    3,
    c1.curriculum_id,
    'Second GE summer elective; should be taken after GE-SUMMER-01.'
FROM programs p
JOIN curriculum c1
  ON c1.program_id = p.program_id
 AND c1.course_code = 'GE-SUMMER-01'
 AND c1.year_level = 1
 AND c1.semester = 0
WHERE p.program_code IN ('BSIT', 'BSCS', 'BSIS', 'BSBA', 'BSE')
  AND NOT EXISTS (
      SELECT 1
      FROM curriculum c
      WHERE c.program_id = p.program_id
        AND c.course_code = 'GE-SUMMER-02'
        AND c.year_level = 2
        AND c.semester = 0
  );

-- -----------------------------------------------------------------------------
-- 2) Program-specific summer OJT / Practicum (Year 3)
-- -----------------------------------------------------------------------------
INSERT INTO curriculum (
    program_id, course_code, course_name, year_level, semester, units, prerequisite_id, description
)
SELECT
    p.program_id,
    CASE
        WHEN p.program_code = 'BSIT' THEN 'IT-OJT-SUMMER'
        WHEN p.program_code = 'BSCS' THEN 'CS-OJT-SUMMER'
        WHEN p.program_code = 'BSIS' THEN 'IS-PRACTICUM-SUMMER'
        WHEN p.program_code = 'BSBA' THEN 'BA-PRACTICUM-SUMMER'
        WHEN p.program_code = 'BSE' THEN 'ED-PRACTICUM-SUMMER'
    END AS course_code,
    CASE
        WHEN p.program_code = 'BSIT' THEN 'On-the-Job Training (Summer)'
        WHEN p.program_code = 'BSCS' THEN 'Computing Internship (Summer)'
        WHEN p.program_code = 'BSIS' THEN 'Systems Practicum (Summer)'
        WHEN p.program_code = 'BSBA' THEN 'Business Practicum (Summer)'
        WHEN p.program_code = 'BSE' THEN 'Teaching Practicum Preparation (Summer)'
    END AS course_name,
    3,
    0,
    6,
    c2.curriculum_id,
    'Summer immersion/practicum track. GE-SUMMER-02 is set as prerequisite gate.'
FROM programs p
JOIN curriculum c2
  ON c2.program_id = p.program_id
 AND c2.course_code = 'GE-SUMMER-02'
 AND c2.year_level = 2
 AND c2.semester = 0
WHERE p.program_code IN ('BSIT', 'BSCS', 'BSIS', 'BSBA', 'BSE')
  AND NOT EXISTS (
      SELECT 1
      FROM curriculum c
      WHERE c.program_id = p.program_id
        AND c.course_code = CASE
            WHEN p.program_code = 'BSIT' THEN 'IT-OJT-SUMMER'
            WHEN p.program_code = 'BSCS' THEN 'CS-OJT-SUMMER'
            WHEN p.program_code = 'BSIS' THEN 'IS-PRACTICUM-SUMMER'
            WHEN p.program_code = 'BSBA' THEN 'BA-PRACTICUM-SUMMER'
            WHEN p.program_code = 'BSE' THEN 'ED-PRACTICUM-SUMMER'
        END
        AND c.year_level = 3
        AND c.semester = 0
  );

COMMIT;
