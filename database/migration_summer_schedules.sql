-- Summer schedule seeding
-- Creates baseline schedule rows for curriculum subjects tagged as Summer (semester = 0)
-- Safe to rerun: only inserts for subjects without existing schedules.

START TRANSACTION;

INSERT INTO schedules (
    curriculum_id,
    day_of_week,
    start_time,
    end_time,
    room,
    teacher_id,
    capacity,
    enrolled_count
)
SELECT
    c.curriculum_id,
    d.day_of_week,
    d.start_time,
    d.end_time,
    CONCAT('SUM-', LPAD(c.program_id, 2, '0')) AS room,
    NULL,
    40,
    0
FROM curriculum c
JOIN (
    SELECT 'Mon' AS day_of_week, '08:00:00' AS start_time, '09:30:00' AS end_time
    UNION ALL
    SELECT 'Wed', '08:00:00', '09:30:00'
) d
LEFT JOIN schedules s_existing
    ON s_existing.curriculum_id = c.curriculum_id
WHERE c.semester = 0
  AND s_existing.schedule_id IS NULL;

COMMIT;
