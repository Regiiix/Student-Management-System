-- =============================================================================
-- STUDENT MANAGEMENT SYSTEM - Database Schema
-- Database: school_db21
-- Version: 1.0
-- Last Updated: 2026-01-30
-- 
-- This file contains the database structure (tables only).
-- Run this first, then run data.sql to populate with sample data.
-- To import: mysql -u root < schema.sql
-- =============================================================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS school_db21;
USE school_db21;

-- Disable foreign key checks for clean import
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- TABLE STRUCTURE (DROP & CREATE)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Table: programs
-- Contains academic programs (e.g., BSIT, BSCS)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS programs;
CREATE TABLE programs (
    program_id INT PRIMARY KEY AUTO_INCREMENT,
    program_code VARCHAR(50) NOT NULL UNIQUE,
    program_name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- Table: curriculum
-- Contains courses/subjects for each program, year level, and semester
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS curriculum;
CREATE TABLE curriculum (
    curriculum_id INT PRIMARY KEY AUTO_INCREMENT,
    program_id INT NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    year_level INT NOT NULL,
    semester INT NOT NULL,
    units INT DEFAULT 3,
    prerequisite_id INT DEFAULT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE,
    FOREIGN KEY (prerequisite_id) REFERENCES curriculum(curriculum_id) ON DELETE SET NULL,
    INDEX idx_program_year_sem (program_id, year_level, semester)
);

-- -----------------------------------------------------------------------------
-- Table: students
-- Contains student information
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS students;
CREATE TABLE students (
    student_id INT PRIMARY KEY AUTO_INCREMENT,
    student_number VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    date_of_birth DATE,
    gender ENUM('Male', 'Female', 'Other'),
    program_id INT,
    year_level INT DEFAULT 1,
    current_semester INT DEFAULT 1,
    cumulative_gpa DECIMAL(3,2) DEFAULT NULL,
    academic_standing VARCHAR(50) DEFAULT 'Good Standing',
    status ENUM('Active', 'Inactive', 'Graduated') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL,
    INDEX idx_program (program_id),
    INDEX idx_status (status),
    INDEX idx_name_search (last_name, first_name),
    INDEX idx_status_program_name (status, program_id, last_name, first_name)
);

-- -----------------------------------------------------------------------------
-- Table: teachers
-- Contains instructor information
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS teachers;
CREATE TABLE teachers (
    teacher_id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    title VARCHAR(50),
    email VARCHAR(255),
    phone VARCHAR(20),
    department VARCHAR(100),
    status ENUM('Active', 'Inactive', 'On Leave') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_department (department),
    INDEX idx_status (status)
);

-- -----------------------------------------------------------------------------
-- Table: schedules
-- Contains class schedules for courses (day, time, room, instructor)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS schedules;
CREATE TABLE schedules (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    curriculum_id INT NOT NULL,
    day_of_week ENUM('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(50),
    teacher_id INT,
    capacity INT DEFAULT 40,
    enrolled_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(curriculum_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE SET NULL,
    INDEX idx_curriculum (curriculum_id),
    INDEX idx_schedule_day (curriculum_id, day_of_week),
    INDEX idx_curr_day_time (curriculum_id, day_of_week, start_time, end_time),
    INDEX idx_teacher (teacher_id)
);

-- -----------------------------------------------------------------------------
-- Table: enrollments
-- Tracks student enrollment in specific courses with midterm and final grades
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS enrollments;
CREATE TABLE enrollments (
    enrollment_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    curriculum_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    midterm_grade DECIMAL(3,2),
    final_grade DECIMAL(3,2),
    status ENUM('Enrolled', 'Passed', 'Failed') DEFAULT 'Enrolled',
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(curriculum_id) ON DELETE CASCADE,
    INDEX idx_student_year (student_id, academic_year),
    INDEX idx_enrollment_status (status),
    INDEX idx_ay_status_student (academic_year, status, student_id),
    INDEX idx_curr_ay_status (curriculum_id, academic_year, status),
    UNIQUE KEY unique_enrollment (student_id, curriculum_id, academic_year)
);

-- -----------------------------------------------------------------------------
-- Table: semester_status
-- Tracks student semester completion status and GPA
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS semester_status;
CREATE TABLE semester_status (
    status_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    year_level INT NOT NULL,
    semester INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    status ENUM('In Progress', 'Completed', 'Incomplete') DEFAULT 'In Progress',
    gpa DECIMAL(3,2) DEFAULT NULL,
    total_units INT DEFAULT 0,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    UNIQUE KEY unique_semester (student_id, year_level, semester, academic_year),
    INDEX idx_student_status (student_id, status),
    INDEX idx_student_ay_sem_status (student_id, academic_year, semester, status)
);

-- -----------------------------------------------------------------------------
-- Table: fees
-- Stores standardized fees (Tuition per unit, Fixed fees)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS fees;
CREATE TABLE fees (
    fee_id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    type ENUM('per_unit', 'fixed') NOT NULL DEFAULT 'fixed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- Table: program_tuition_rates
-- Stores program-specific tuition rates (BSCS/BSIT higher than BSE, etc.)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS program_tuition_rates;
CREATE TABLE program_tuition_rates (
    rate_id INT PRIMARY KEY AUTO_INCREMENT,
    program_id INT NOT NULL,
    tuition_per_unit DECIMAL(10,2) NOT NULL DEFAULT 800.00,
    lab_fee DECIMAL(10,2) NOT NULL DEFAULT 2000.00,
    effective_date DATE NOT NULL DEFAULT '2025-01-01',
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE,
    UNIQUE KEY unique_program_rate (program_id, effective_date),
    INDEX idx_program_active (program_id, is_active)
);

-- -----------------------------------------------------------------------------
-- Table: term_overpayments
-- Tracks overpayments to carry forward to next term
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS term_overpayments;
CREATE TABLE term_overpayments (
    overpayment_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    source_academic_year VARCHAR(20) NOT NULL,
    source_semester INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    applied_academic_year VARCHAR(20) DEFAULT NULL,
    applied_semester INT DEFAULT NULL,
    is_applied BOOLEAN DEFAULT FALSE,
    applied_date TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_student_overpayment (student_id, is_applied),
    INDEX idx_source_term (student_id, source_academic_year, source_semester)
);

-- -----------------------------------------------------------------------------
-- Table: payments
-- Stores student payment history
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    payment_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    academic_year VARCHAR(20) NOT NULL,
    semester INT NOT NULL,
    reference_no VARCHAR(50),
    notes TEXT,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_student_ay (student_id, academic_year, semester),
    INDEX idx_ay_sem (academic_year, semester)
);

-- -----------------------------------------------------------------------------
-- Table: system_settings
-- Global configuration
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS system_settings;
CREATE TABLE system_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value VARCHAR(255),
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- Table: course_prerequisites (Many-to-Many)
-- Supports multiple prerequisites per course
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS course_prerequisites;
CREATE TABLE course_prerequisites (
    prereq_id INT PRIMARY KEY AUTO_INCREMENT,
    curriculum_id INT NOT NULL,
    required_curriculum_id INT NOT NULL,
    min_grade DECIMAL(3,2) DEFAULT 3.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(curriculum_id) ON DELETE CASCADE,
    FOREIGN KEY (required_curriculum_id) REFERENCES curriculum(curriculum_id) ON DELETE CASCADE,
    UNIQUE KEY unique_prereq (curriculum_id, required_curriculum_id),
    INDEX idx_prereq_course (curriculum_id)
);

-- -----------------------------------------------------------------------------
-- Table: scholarships
-- Stores scholarship/discount types
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS scholarships;
CREATE TABLE scholarships (
    scholarship_id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    discount_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    applies_to ENUM('tuition', 'misc', 'all') DEFAULT 'tuition',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- Table: student_scholarships
-- Links students to scholarships per term
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS student_scholarships;
CREATE TABLE student_scholarships (
    student_scholarship_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    scholarship_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester INT NOT NULL,
    status ENUM('Active', 'Revoked', 'Expired') DEFAULT 'Active',
    awarded_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (scholarship_id) REFERENCES scholarships(scholarship_id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_scholarship_term (student_id, scholarship_id, academic_year, semester),
    INDEX idx_student_term (student_id, academic_year, semester),
    INDEX idx_ay_sem_status (academic_year, semester, status),
    INDEX idx_scholarship_ay_sem (scholarship_id, academic_year, semester)
);

-- -----------------------------------------------------------------------------
-- Table: late_fee_config
-- Configuration for late payment penalties
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS late_fee_config;
CREATE TABLE late_fee_config (
    config_id INT PRIMARY KEY AUTO_INCREMENT,
    fee_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    fee_value DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    grace_period_days INT DEFAULT 30,
    max_penalty_percent DECIMAL(5,2) DEFAULT 25.00,
    apply_per ENUM('month', 'week', 'once') DEFAULT 'month',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- Table: student_late_fees
-- Tracks applied late fees per student
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS student_late_fees;
CREATE TABLE student_late_fees (
    late_fee_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    applied_date DATE NOT NULL,
    reason VARCHAR(255),
    is_waived BOOLEAN DEFAULT FALSE,
    waived_by VARCHAR(100),
    waived_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    INDEX idx_student_term_late (student_id, academic_year, semester)
);

-- -----------------------------------------------------------------------------
-- Table: academic_standings
-- Tracks student academic standing history
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS academic_standings;
CREATE TABLE academic_standings (
    standing_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester INT NOT NULL,
    gpa_at_time DECIMAL(3,2) NOT NULL,
    standing ENUM('Good Standing', 'Dean''s List', 'With Honors', 'Probation', 'Dismissed', 'Warning') NOT NULL DEFAULT 'Good Standing',
    total_units_taken INT DEFAULT 0,
    total_units_passed INT DEFAULT 0,
    remarks TEXT,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    UNIQUE KEY unique_standing (student_id, academic_year, semester),
    INDEX idx_standing (standing),
    INDEX idx_student_standing (student_id, academic_year),
    INDEX idx_ay_sem_standing (academic_year, semester, standing)
);

-- -----------------------------------------------------------------------------
-- Table: academic_standing_config
-- Configuration for academic standing thresholds
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS academic_standing_config;
CREATE TABLE academic_standing_config (
    config_id INT PRIMARY KEY AUTO_INCREMENT,
    standing ENUM('Good Standing', 'Dean''s List', 'With Honors', 'Probation', 'Dismissed', 'Warning') NOT NULL,
    min_gpa DECIMAL(3,2),
    max_gpa DECIMAL(3,2),
    min_units INT DEFAULT 0,
    description VARCHAR(255),
    priority INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    UNIQUE KEY unique_standing_config (standing)
);

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- End of schema.sql
