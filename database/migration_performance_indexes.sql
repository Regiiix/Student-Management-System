-- =============================================================================
-- Migration: Add Performance Indexes for High-Traffic Queries
-- Database: school_db21
-- Version: 1.2
-- Date: 2026-03-17
--
-- This migration adds missing composite indexes used by the most frequent
-- page and API filters/joins. It is idempotent and safe to run multiple times.
-- =============================================================================

USE school_db21;

DELIMITER //

DROP PROCEDURE IF EXISTS add_index_if_missing //
CREATE PROCEDURE add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_columns VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
    ) THEN
        SET @sql_stmt = CONCAT(
            'ALTER TABLE `', p_table_name, '` ADD INDEX `', p_index_name, '` (', p_index_columns, ')'
        );
        PREPARE stmt FROM @sql_stmt;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

-- Enrollments: analytics, dashboard stats, enrollment/class details
CALL add_index_if_missing('enrollments', 'idx_ay_status_student', '`academic_year`, `status`, `student_id`');
CALL add_index_if_missing('enrollments', 'idx_curr_ay_status', '`curriculum_id`, `academic_year`, `status`');

-- Payments: finance dashboard and term summaries
CALL add_index_if_missing('payments', 'idx_student_ay_sem', '`student_id`, `academic_year`, `semester`');
CALL add_index_if_missing('payments', 'idx_ay_sem', '`academic_year`, `semester`');

-- Semester status: frequent joins by student + term
CALL add_index_if_missing('semester_status', 'idx_student_ay_sem_status', '`student_id`, `academic_year`, `semester`, `status`');

-- Scholarships: scholarship pages and analytics rollups
CALL add_index_if_missing('student_scholarships', 'idx_ay_sem_status', '`academic_year`, `semester`, `status`');
CALL add_index_if_missing('student_scholarships', 'idx_scholarship_ay_sem', '`scholarship_id`, `academic_year`, `semester`');

-- Academic standings: AY/semester standing summaries
CALL add_index_if_missing('academic_standings', 'idx_ay_sem_standing', '`academic_year`, `semester`, `standing`');

-- Schedules: class details and conflict checks
CALL add_index_if_missing('schedules', 'idx_curr_day_time', '`curriculum_id`, `day_of_week`, `start_time`, `end_time`');

-- Students: student list filtering and sorting path
CALL add_index_if_missing('students', 'idx_status_program_name', '`status`, `program_id`, `last_name`, `first_name`');

DROP PROCEDURE IF EXISTS add_index_if_missing;

SELECT 'Performance index migration completed.' AS status;
