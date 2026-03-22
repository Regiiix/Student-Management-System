<?php
/**
 * Enhanced Dashboard Analytics API
 * Provides comprehensive statistics for the analytics dashboard
 */
require_once __DIR__ . '/../config/db_helpers.php';
require_once __DIR__ . '/../config/finance_helpers.php';
require_once __DIR__ . '/../config/academic_helpers.php';
require_once __DIR__ . '/../config/api_response_helpers.php';
require_once __DIR__ . '/../config/api_auth_helpers.php';

api_auth_require_valid_token();

// Release PHP session lock so concurrent dashboard API requests can run in parallel.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Resolve file cache path for analytics responses.
 *
 * @param string $cache_key
 * @return string|null
 */
function getAnalyticsCachePath($cache_key) {
    $cache_dir = __DIR__ . '/../logs/cache';
    if (!is_dir($cache_dir) && !mkdir($cache_dir, 0777, true) && !is_dir($cache_dir)) {
        return null;
    }
    return $cache_dir . '/' . $cache_key . '.json';
}

/**
 * Read cached analytics response if still fresh.
 *
 * @param string $cache_key
 * @param int $ttl_seconds
 * @return array|null
 */
function readAnalyticsCache($cache_key, $ttl_seconds) {
    $cache_path = getAnalyticsCachePath($cache_key);
    if (!$cache_path || !file_exists($cache_path)) {
        return null;
    }

    if ((time() - filemtime($cache_path)) > $ttl_seconds) {
        return null;
    }

    $raw = @file_get_contents($cache_path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

/**
 * Write analytics response to file cache.
 *
 * @param string $cache_key
 * @param array $payload
 * @return void
 */
function writeAnalyticsCache($cache_key, $payload) {
    $cache_path = getAnalyticsCachePath($cache_key);
    if (!$cache_path) {
        return;
    }

    @file_put_contents($cache_path, json_encode($payload), LOCK_EX);
}

// Get parameters
$report_type = $_GET['type'] ?? 'overview';
$academic_year = $_GET['ay'] ?? null;
$semester = isset($_GET['sem']) ? intval($_GET['sem']) : null;

$allowed_reports = ['overview', 'enrollment', 'grades', 'retention', 'revenue', 'standings'];
if (!in_array($report_type, $allowed_reports, true)) {
    api_respond_error(
        'Invalid report type',
        422,
        'invalid_report_type',
        ['allowed_reports' => $allowed_reports]
    );
}

$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$cache_ttl_seconds = 45;
$cache_key = 'analytics_' . sha1($report_type . '|' . ($academic_year ?: 'auto') . '|' . ($semester === null ? 'all' : $semester));

if (!$force_refresh) {
    $cached = readAnalyticsCache($cache_key, $cache_ttl_seconds);
    if ($cached !== null) {
        api_respond_success($cached, 200, [
            'report_type' => $report_type,
            'cached' => true,
        ]);
    }
}

$conn = getDBConnection();

// Get current academic year from settings if not specified
if (!$academic_year) {
    $academic_year = (string)getSystemSetting($conn, 'current_academic_year', (date('Y') . '-' . (date('Y') + 1)));
}

$response = [];

switch ($report_type) {
    case 'overview':
        $response = getOverviewStats($conn, $academic_year, $semester);
        break;
    case 'enrollment':
        $response = getEnrollmentStats($conn, $academic_year, $semester);
        break;
    case 'grades':
        $response = getGradeDistribution($conn, $academic_year, $semester);
        break;
    case 'retention':
        $response = getRetentionStats($conn);
        break;
    case 'revenue':
        $response = getRevenueStats($conn, $academic_year, $semester);
        break;
    case 'standings':
        $response = getAcademicStandingStats($conn, $academic_year, $semester);
        break;
}

$conn->close();

if (is_array($response) && array_key_exists('error', $response)) {
    api_respond_error(
        'Failed to generate analytics report',
        500,
        'analytics_generation_failed',
        ['report_type' => $report_type, 'details' => $response['error']],
        ['report_type' => $report_type, 'cached' => false]
    );
}

if (!isset($response['error'])) {
    writeAnalyticsCache($cache_key, $response);
}

api_respond_success($response, 200, [
    'report_type' => $report_type,
    'cached' => false,
]);

// ============================================================
// REPORT FUNCTIONS
// ============================================================

/**
 * Get overview statistics for the dashboard
 */
function getOverviewStats($conn, $ay, $sem) {
    $stats = [
        'totals' => [],
        'programs' => [],
        'financials' => [],
        'year_levels' => [],
        'quick_stats' => []
    ];
    
    // Total counts
    $stats['totals'] = [
        'students' => db_count($conn, 'students', "status = 'Active'"),
        'all_students' => db_count($conn, 'students'),
        'courses' => db_count($conn, 'curriculum'),
        'teachers' => db_count($conn, 'teachers', "status = 'Active'"),
        'programs' => db_count($conn, 'programs'),
        'scholarships_active' => db_count($conn, 'student_scholarships', "status = 'Active' AND academic_year = ?", 's', [$ay])
    ];
    
    // Students by Program
    $prog_sql = "SELECT p.program_code, p.program_name, COUNT(s.student_id) as count 
                 FROM programs p
                 LEFT JOIN students s ON p.program_id = s.program_id AND s.status = 'Active'
                 GROUP BY p.program_id
                 ORDER BY count DESC";
    $stats['programs'] = db_fetch_all(db_query($conn, $prog_sql));
    
    // Students by Year Level
    $year_sql = "SELECT year_level, COUNT(*) as count 
                 FROM students 
                 WHERE status = 'Active'
                 GROUP BY year_level
                 ORDER BY year_level";
    $stats['year_levels'] = db_fetch_all(db_query($conn, $year_sql));
    
    // Financial summary
    $stats['financials'] = getFinancialSummary($conn, $ay, $sem);
    
    // Quick stats for current term (single DB round-trip).
    if ($sem !== null && intval($sem) > 0) {
        $quick_stats_sql = "SELECT
            (SELECT COUNT(DISTINCT e.student_id)
             FROM enrollments e
             JOIN curriculum c ON e.curriculum_id = c.curriculum_id
             WHERE e.academic_year = ? AND c.semester = ? AND e.status = 'Enrolled') as enrolled_this_term,
            (SELECT COUNT(*) FROM academic_standings WHERE academic_year = ? AND semester = ? AND standing = 'Dean''s List') as deans_list,
            (SELECT COUNT(*) FROM academic_standings WHERE academic_year = ? AND semester = ? AND standing IN ('Probation', 'Warning')) as at_risk";
        $quick_stats = db_fetch_one(db_query($conn, $quick_stats_sql, 'sisisi', [$ay, $sem, $ay, $sem, $ay, $sem])) ?: [];
    } else {
        $quick_stats_sql = "SELECT
            (SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE academic_year = ? AND status = 'Enrolled') as enrolled_this_term,
            (SELECT COUNT(*) FROM academic_standings WHERE academic_year = ? AND standing = 'Dean''s List') as deans_list,
            (SELECT COUNT(*) FROM academic_standings WHERE academic_year = ? AND standing IN ('Probation', 'Warning')) as at_risk";
        $quick_stats = db_fetch_one(db_query($conn, $quick_stats_sql, 'sss', [$ay, $ay, $ay])) ?: [];
    }
    $stats['quick_stats'] = [
        'enrolled_this_term' => intval($quick_stats['enrolled_this_term'] ?? 0),
        'deans_list' => intval($quick_stats['deans_list'] ?? 0),
        'at_risk' => intval($quick_stats['at_risk'] ?? 0),
    ];
    
    return $stats;
}

/**
 * Get enrollment statistics
 */
function getEnrollmentStats($conn, $ay, $sem) {
    $stats = [
        'by_program' => [],
        'by_year_level' => [],
        'by_semester' => [],
        'trend' => [],
        'course_popularity' => []
    ];
    
    // Enrollment by Program
    $prog_sql = "SELECT p.program_code, p.program_name, COUNT(DISTINCT e.student_id) as enrolled
                 FROM enrollments e
                 JOIN students s ON e.student_id = s.student_id
                 JOIN programs p ON s.program_id = p.program_id
                 WHERE e.academic_year = ? AND e.status = 'Enrolled'
                 GROUP BY p.program_id
                 ORDER BY enrolled DESC";
    $stats['by_program'] = db_fetch_all(db_query($conn, $prog_sql, 's', [$ay]));
    
    // Enrollment by Year Level
    $year_sql = "SELECT s.year_level, COUNT(DISTINCT e.student_id) as enrolled
                 FROM enrollments e
                 JOIN students s ON e.student_id = s.student_id
                 WHERE e.academic_year = ? AND e.status = 'Enrolled'
                 GROUP BY s.year_level
                 ORDER BY s.year_level";
    $stats['by_year_level'] = db_fetch_all(db_query($conn, $year_sql, 's', [$ay]));
    
    // Enrollment by Semester
    $sem_sql = "SELECT c.semester, COUNT(DISTINCT e.student_id) as enrolled
                FROM enrollments e
                JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                WHERE e.academic_year = ? AND e.status = 'Enrolled'
                GROUP BY c.semester";
    $stats['by_semester'] = db_fetch_all(db_query($conn, $sem_sql, 's', [$ay]));
    
    // Enrollment trend (last 5 academic years)
    $trend_sql = "SELECT e.academic_year, COUNT(DISTINCT e.student_id) as total_enrolled
                  FROM enrollments e
                  GROUP BY e.academic_year
                  ORDER BY e.academic_year DESC
                  LIMIT 5";
    $stats['trend'] = array_reverse(db_fetch_all(db_query($conn, $trend_sql)));
    
    // Most popular courses
    $pop_sql = "SELECT c.course_code, c.course_name, COUNT(e.enrollment_id) as enrollments
                FROM enrollments e
                JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                WHERE e.academic_year = ?
                GROUP BY c.curriculum_id
                ORDER BY enrollments DESC
                LIMIT 10";
    $stats['course_popularity'] = db_fetch_all(db_query($conn, $pop_sql, 's', [$ay]));
    
    return $stats;
}

/**
 * Get grade distribution statistics
 */
function getGradeDistribution($conn, $ay, $sem) {
    $stats = [
        'overall' => [],
        'by_program' => [],
        'by_course' => [],
        'pass_fail_rate' => []
    ];
    
    // Build semester condition
    $sem_condition = $sem ? " AND c.semester = $sem" : "";
    
    // Overall grade distribution
    $overall_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN e.final_grade <= 1.50 THEN 1 ELSE 0 END) as excellent,
        SUM(CASE WHEN e.final_grade > 1.50 AND e.final_grade <= 2.00 THEN 1 ELSE 0 END) as very_good,
        SUM(CASE WHEN e.final_grade > 2.00 AND e.final_grade <= 2.50 THEN 1 ELSE 0 END) as good,
        SUM(CASE WHEN e.final_grade > 2.50 AND e.final_grade <= 3.00 THEN 1 ELSE 0 END) as passing,
        SUM(CASE WHEN e.final_grade > 3.00 AND e.final_grade <= 4.00 THEN 1 ELSE 0 END) as conditional,
        SUM(CASE WHEN e.final_grade > 4.00 THEN 1 ELSE 0 END) as failed,
        AVG(e.final_grade) as average_grade
    FROM enrollments e
    JOIN curriculum c ON e.curriculum_id = c.curriculum_id
    WHERE e.academic_year = ? AND e.final_grade IS NOT NULL" . $sem_condition;
    
    $stats['overall'] = db_fetch_one(db_query($conn, $overall_sql, 's', [$ay]));
    
    // Convert to distribution array for charts
    if ($stats['overall']) {
        $stats['distribution'] = [
            ['label' => 'Excellent (1.0-1.5)', 'count' => (int)$stats['overall']['excellent']],
            ['label' => 'Very Good (1.5-2.0)', 'count' => (int)$stats['overall']['very_good']],
            ['label' => 'Good (2.0-2.5)', 'count' => (int)$stats['overall']['good']],
            ['label' => 'Passing (2.5-3.0)', 'count' => (int)$stats['overall']['passing']],
            ['label' => 'Conditional (3.0-4.0)', 'count' => (int)$stats['overall']['conditional']],
            ['label' => 'Failed (4.0-5.0)', 'count' => (int)$stats['overall']['failed']]
        ];
    }
    
    // Pass/Fail rate by program
    $pf_sql = "SELECT p.program_code,
        COUNT(*) as total,
        SUM(CASE WHEN e.final_grade <= 3.00 THEN 1 ELSE 0 END) as passed,
        SUM(CASE WHEN e.final_grade > 3.00 THEN 1 ELSE 0 END) as failed,
        ROUND(SUM(CASE WHEN e.final_grade <= 3.00 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as pass_rate
    FROM enrollments e
    JOIN curriculum c ON e.curriculum_id = c.curriculum_id
    JOIN programs p ON c.program_id = p.program_id
    WHERE e.academic_year = ? AND e.final_grade IS NOT NULL" . $sem_condition . "
    GROUP BY p.program_id
    ORDER BY pass_rate DESC";
    
    $stats['pass_fail_rate'] = db_fetch_all(db_query($conn, $pf_sql, 's', [$ay]));
    
    // Average grade by course (top 10 highest and lowest)
    $course_sql = "SELECT c.course_code, c.course_name, 
        AVG(e.final_grade) as avg_grade,
        COUNT(*) as students
    FROM enrollments e
    JOIN curriculum c ON e.curriculum_id = c.curriculum_id
    WHERE e.academic_year = ? AND e.final_grade IS NOT NULL" . $sem_condition . "
    GROUP BY c.curriculum_id
    HAVING students >= 5
    ORDER BY avg_grade ASC
    LIMIT 10";
    
    $stats['top_performing_courses'] = db_fetch_all(db_query($conn, $course_sql, 's', [$ay]));
    
    return $stats;
}

/**
 * Get retention and dropout statistics
 */
function getRetentionStats($conn) {
    $stats = [
        'current_status' => [],
        'retention_by_year' => [],
        'dropout_reasons' => [],
        'graduation_rate' => []
    ];
    
    // Current status distribution
    $status_sql = "SELECT status, COUNT(*) as count 
                   FROM students 
                   GROUP BY status";
    $stats['current_status'] = db_fetch_all(db_query($conn, $status_sql));
    
    // Calculate retention rate by cohort (students who enrolled in year X and are still active)
    $cohort_sql = "SELECT 
        SUBSTRING(student_number, 1, 4) as cohort_year,
        COUNT(*) as total_enrolled,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as still_active,
        SUM(CASE WHEN status = 'Graduated' THEN 1 ELSE 0 END) as graduated,
        SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as dropped
    FROM students
    GROUP BY cohort_year
    ORDER BY cohort_year DESC
    LIMIT 5";
    $cohorts = db_fetch_all(db_query($conn, $cohort_sql));
    
    foreach ($cohorts as &$c) {
        $total = (int)$c['total_enrolled'];
        $c['retention_rate'] = $total > 0 ? round(((int)$c['still_active'] + (int)$c['graduated']) / $total * 100, 1) : 0;
        $c['graduation_rate'] = $total > 0 ? round((int)$c['graduated'] / $total * 100, 1) : 0;
        $c['dropout_rate'] = $total > 0 ? round((int)$c['dropped'] / $total * 100, 1) : 0;
    }
    $stats['retention_by_cohort'] = $cohorts;
    
    // Students at risk (based on academic standing)
    $risk_sql = "SELECT 
        a.standing,
        COUNT(*) as count,
        GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', s.last_name) SEPARATOR ', ') as students
    FROM academic_standings a
    JOIN students s ON a.student_id = s.student_id
    WHERE a.standing IN ('Warning', 'Probation', 'Dismissed')
    AND s.status = 'Active'
    GROUP BY a.standing";
    $stats['at_risk'] = db_fetch_all(db_query($conn, $risk_sql));
    
    return $stats;
}

/**
 * Get revenue statistics
 */
function getRevenueStats($conn, $ay, $sem) {
    $stats = [
        'summary' => [],
        'by_term' => [],
        'collection_rate' => [],
        'scholarships_impact' => [],
        'late_fees' => []
    ];
    
    // Get fee configuration in one query.
    $fee_sql = "SELECT
        MAX(CASE WHEN code = 'TUITION' THEN amount END) as tuition_rate,
        COALESCE(SUM(CASE WHEN type = 'fixed' AND code <> 'TUITION' THEN amount ELSE 0 END), 0) as total_fixed
        FROM fees";
    $fee_config = db_fetch_one(db_query($conn, $fee_sql)) ?: [];
    $tuition_rate = floatval($fee_config['tuition_rate'] ?? 0);
    $total_fixed = floatval($fee_config['total_fixed'] ?? 0);
    
    // Current term summary
    $sem_condition = $sem ? " AND c.semester = $sem" : "";
    
    // Total units enrolled
    $units_sql = "SELECT SUM(c.units) as total_units, COUNT(DISTINCT e.student_id) as students
                  FROM enrollments e
                  JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                  WHERE e.academic_year = ? AND e.status = 'Enrolled'" . $sem_condition;
    $units_data = db_fetch_one(db_query($conn, $units_sql, 's', [$ay]));
    
    $total_units = $units_data['total_units'] ?? 0;
    $student_count = $units_data['students'] ?? 0;
    
    $gross_assessment = ($total_units * $tuition_rate) + ($student_count * $total_fixed);
    
    // Total scholarships
    $schol_sql = "SELECT ss.*, s.discount_type, s.discount_value, s.applies_to
                  FROM student_scholarships ss
                  JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
                  WHERE ss.academic_year = ? AND ss.status = 'Active'" . ($sem ? " AND ss.semester = $sem" : "");
    $scholarships = db_fetch_all(db_query($conn, $schol_sql, 's', [$ay]));
    
    $total_discount = 0;
    foreach ($scholarships as $sch) {
        if ($sch['discount_type'] === 'percentage') {
            // Approximate per-student discount
            $base = ($sch['applies_to'] === 'all') ? ($gross_assessment / max($student_count, 1)) : 
                    ($sch['applies_to'] === 'tuition' ? ($total_units / max($student_count, 1)) * $tuition_rate : $total_fixed);
            $total_discount += $base * ($sch['discount_value'] / 100);
        } else {
            $total_discount += $sch['discount_value'];
        }
    }
    
    $net_assessment = $gross_assessment - $total_discount;
    
    // Total payments
    $pay_sql = "SELECT SUM(amount) as paid FROM payments WHERE academic_year = ?" . ($sem ? " AND semester = $sem" : "");
    $total_paid = db_fetch_one(db_query($conn, $pay_sql, 's', [$ay]))['paid'] ?? 0;
    
    // Late fees
    $late_sql = "SELECT SUM(amount) as total FROM student_late_fees 
                 WHERE academic_year = ? AND is_waived = 0" . ($sem ? " AND semester = $sem" : "");
    $total_late = db_fetch_one(db_query($conn, $late_sql, 's', [$ay]))['total'] ?? 0;
    
    $stats['summary'] = [
        'term' => $ay . ($sem ? " Sem $sem" : ''),
        'students' => $student_count,
        'total_units' => $total_units,
        'gross_assessment' => round($gross_assessment, 2),
        'scholarships_count' => count($scholarships),
        'total_discount' => round($total_discount, 2),
        'net_assessment' => round($net_assessment, 2),
        'total_collected' => round($total_paid, 2),
        'late_fees' => round($total_late, 2),
        'balance' => round($net_assessment + $total_late - $total_paid, 2),
        'collection_rate' => $net_assessment > 0 ? round(($total_paid / $net_assessment) * 100, 1) : 0
    ];
    
    // Collection trend by term
    $trend_sql = "SELECT academic_year, semester,
                  SUM(amount) as collected
                  FROM payments
                  GROUP BY academic_year, semester
                  ORDER BY academic_year DESC, semester DESC
                  LIMIT 6";
    $stats['by_term'] = array_reverse(db_fetch_all(db_query($conn, $trend_sql)));
    
    // Top scholarships by discount amount
    $top_schol_sql = "SELECT s.name, s.code, COUNT(ss.student_scholarship_id) as recipients,
                      s.discount_type, s.discount_value
                      FROM student_scholarships ss
                      JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
                      WHERE ss.academic_year = ? AND ss.status = 'Active'
                      GROUP BY s.scholarship_id
                      ORDER BY recipients DESC";
    $stats['scholarships_impact'] = db_fetch_all(db_query($conn, $top_schol_sql, 's', [$ay]));
    
    return $stats;
}

/**
 * Get academic standing statistics
 */
function getAcademicStandingStats($conn, $ay, $sem) {
    $stats = [
        'distribution' => [],
        'by_program' => [],
        'trend' => []
    ];
    
    $sem_condition = $sem ? " AND a.semester = $sem" : "";
    
    // Standing distribution
    $dist_sql = "SELECT a.standing, COUNT(*) as count
                 FROM academic_standings a
                 WHERE a.academic_year = ?" . $sem_condition . "
                 GROUP BY a.standing
                 ORDER BY FIELD(a.standing, 'Dean''s List', 'With Honors', 'Good Standing', 'Warning', 'Probation', 'Dismissed')";
    $stats['distribution'] = db_fetch_all(db_query($conn, $dist_sql, 's', [$ay]));
    
    // By program
    $prog_sql = "SELECT p.program_code, a.standing, COUNT(*) as count
                 FROM academic_standings a
                 JOIN students s ON a.student_id = s.student_id
                 JOIN programs p ON s.program_id = p.program_id
                 WHERE a.academic_year = ?" . $sem_condition . "
                 GROUP BY p.program_id, a.standing
                 ORDER BY p.program_code, a.standing";
    $by_prog_raw = db_fetch_all(db_query($conn, $prog_sql, 's', [$ay]));
    
    // Reorganize for easier charting
    $programs = [];
    foreach ($by_prog_raw as $r) {
        if (!isset($programs[$r['program_code']])) {
            $programs[$r['program_code']] = [];
        }
        $programs[$r['program_code']][$r['standing']] = (int)$r['count'];
    }
    $stats['by_program'] = $programs;
    
    // GPA distribution
    $gpa_sql = "SELECT 
        FLOOR(a.gpa) as gpa_floor,
        COUNT(*) as count
    FROM academic_standings a
    WHERE a.academic_year = ?" . $sem_condition . "
    GROUP BY gpa_floor
    ORDER BY gpa_floor";
    $stats['gpa_distribution'] = db_fetch_all(db_query($conn, $gpa_sql, 's', [$ay]));
    
    return $stats;
}

/**
 * Helper: Get financial summary
 */
function getFinancialSummary($conn, $ay, $sem) {
    // Get fee rates
    $tuition_rate = 0;
    $total_fixed = 0;
    $res = db_query($conn, "SELECT * FROM fees");
    while($row = $res->fetch_assoc()) {
        if ($row['code'] === 'TUITION') $tuition_rate = floatval($row['amount']);
        elseif ($row['type'] === 'fixed') $total_fixed += floatval($row['amount']);
    }
    
    $sem_cond = $sem ? " AND c.semester = $sem" : "";
    
    // Get enrollment data
    $e_sql = "SELECT COUNT(DISTINCT e.student_id) as students, SUM(c.units) as units
              FROM enrollments e
              JOIN curriculum c ON e.curriculum_id = c.curriculum_id
              WHERE e.academic_year = ? AND e.status = 'Enrolled'" . $sem_cond;
    $e_data = db_fetch_one(db_query($conn, $e_sql, 's', [$ay]));
    
    $students = (int)($e_data['students'] ?? 0);
    $units = (int)($e_data['units'] ?? 0);
    
    $assessed = ($units * $tuition_rate) + ($students * $total_fixed);
    
    // Payments
    $p_sql = "SELECT SUM(amount) as paid FROM payments WHERE academic_year = ?" . ($sem ? " AND semester = $sem" : "");
    $paid = db_fetch_one(db_query($conn, $p_sql, 's', [$ay]))['paid'] ?? 0;
    
    return [
        'term' => $ay . ($sem ? " Sem $sem" : ''),
        'assessed' => round($assessed, 2),
        'collected' => round($paid, 2),
        'balance' => round($assessed - $paid, 2),
        'collection_rate' => $assessed > 0 ? round(($paid / $assessed) * 100, 1) : 0
    ];
}
