<?php
// api/dashboard_stats.php
require_once __DIR__ . '/../config/db_helpers.php';
require_once __DIR__ . '/../config/api_response_helpers.php';

// Release PHP session lock so concurrent API calls are not serialized per user session.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Resolve dashboard_stats cache file path.
 *
 * @return string|null
 */
function getDashboardStatsCachePath() {
    $cache_dir = __DIR__ . '/../logs/cache';
    if (!is_dir($cache_dir) && !mkdir($cache_dir, 0777, true) && !is_dir($cache_dir)) {
        return null;
    }
    return $cache_dir . '/dashboard_stats_v1.json';
}

/**
 * Read cached dashboard stats if not stale.
 *
 * @param int $ttl_seconds
 * @return array|null
 */
function readDashboardStatsCache($ttl_seconds) {
    $cache_path = getDashboardStatsCachePath();
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
 * Write dashboard stats response to cache.
 *
 * @param array $payload
 * @return void
 */
function writeDashboardStatsCache($payload) {
    $cache_path = getDashboardStatsCachePath();
    if (!$cache_path) {
        return;
    }

    @file_put_contents($cache_path, json_encode($payload), LOCK_EX);
}

$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if (!$force_refresh) {
    $cached = readDashboardStatsCache(45);
    if ($cached !== null) {
        api_respond_success($cached, 200, ['cached' => true]);
    }
}

$conn = getDBConnection();

$response = [
    'programs' => [],
    'financials' => [],
    'population' => [],
    'totals' => []
];

// 1. Total Students
$response['totals']['students'] = db_count($conn, 'students');
$response['totals']['courses'] = db_count($conn, 'curriculum');

// 2. Students by Program (Pie Chart)
$prog_sql = "SELECT p.program_code, COUNT(s.student_id) as count 
             FROM students s 
             LEFT JOIN programs p ON s.program_id = p.program_id 
             GROUP BY p.program_code";
$programs_result = db_query($conn, $prog_sql);
if ($programs_result === false) {
    $conn->close();
    api_respond_error('Unable to load program statistics', 500, 'program_stats_query_failed');
}
$response['programs'] = db_fetch_all($programs_result);

// 3. Financials (Current Term Only) - Paid vs Expected
// Identify Latest Term
$latest_sql = "SELECT e.academic_year, c.semester 
               FROM enrollments e 
               JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
               ORDER BY e.academic_year DESC, c.semester DESC LIMIT 1";
$latest_result = db_query($conn, $latest_sql);
if ($latest_result === false) {
    $conn->close();
    api_respond_error('Unable to load latest term info', 500, 'latest_term_query_failed');
}
$latest = db_fetch_one($latest_result);

if ($latest) {
    $ay = $latest['academic_year'];
    $sem = $latest['semester'];
    
    // Get Fees
    $tuition_rate = 0;
    $total_fixed = 0;
    $res = db_query($conn, "SELECT * FROM fees");
    if ($res === false) {
        $conn->close();
        api_respond_error('Unable to load fee configuration', 500, 'fees_query_failed');
    }
    while($row = $res->fetch_assoc()) {
        if ($row['code'] === 'TUITION') $tuition_rate = $row['amount'];
        elseif ($row['type'] === 'fixed') $total_fixed += $row['amount'];
    }
    
    // Calculate Total Assessment for this Term
    // Sum of (Units * Rate) + (Students * Fixed)
    // Get distinct students enrolled this term
    $stud_sql = "SELECT COUNT(DISTINCT e.student_id) as count 
                 FROM enrollments e 
                 JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                 WHERE e.academic_year = ? AND c.semester = ? AND e.status='Enrolled'";
    $stud_result = db_query($conn, $stud_sql, 'si', [$ay, $sem]);
    if ($stud_result === false) {
        $conn->close();
        api_respond_error('Unable to load enrolled student count', 500, 'enrolled_count_query_failed');
    }
    $stud_row = db_fetch_one($stud_result);
    $student_count_term = $stud_row['count'] ?? 0;
    
    $unit_sql = "SELECT SUM(c.units) as total_units 
                 FROM enrollments e 
                 JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                 WHERE e.academic_year = ? AND c.semester = ? AND e.status='Enrolled'";
    $unit_result = db_query($conn, $unit_sql, 'si', [$ay, $sem]);
    if ($unit_result === false) {
        $conn->close();
        api_respond_error('Unable to load enrolled units', 500, 'enrolled_units_query_failed');
    }
    $unit_row = db_fetch_one($unit_result);
    $total_units_term = $unit_row['total_units'] ?? 0;
    
    $total_assessment = ($total_units_term * $tuition_rate) + ($student_count_term * $total_fixed);
    
    // Calculate Total Paid for this Term
    $paid_sql = "SELECT SUM(amount) as paid 
                 FROM payments 
                 WHERE academic_year = ? AND semester = ?";
    $paid_result = db_query($conn, $paid_sql, 'si', [$ay, $sem]);
    if ($paid_result === false) {
        $conn->close();
        api_respond_error('Unable to load payment totals', 500, 'payment_totals_query_failed');
    }
    $paid_row = db_fetch_one($paid_result);
    $total_paid = $paid_row['paid'] ?? 0;
    
    $response['financials'] = [
        'term' => "$ay - Sem $sem",
        'assessed' => $total_assessment,
        'collected' => $total_paid,
        'balance' => $total_assessment - $total_paid
    ];
}

$conn->close();
writeDashboardStatsCache($response);
api_respond_success($response, 200, ['cached' => false]);
?>
