<?php
// api/check_student_conflicts.php
require_once __DIR__ . '/../config/db_helpers.php';
require_once __DIR__ . '/../config/api_response_helpers.php';
require_once __DIR__ . '/../config/api_auth_helpers.php';

api_auth_require_valid_token();

// Release session lock to avoid serializing concurrent API calls.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!isset($_GET['student_id']) || !isset($_GET['ay'])) {
    api_respond_error(
        'Missing required parameters',
        422,
        'missing_params',
        ['required' => ['student_id', 'ay']]
    );
}

$student_id = intval($_GET['student_id']);
$ay = trim((string)$_GET['ay']);
$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if ($student_id <= 0) {
    api_respond_error('Invalid student id', 422, 'invalid_student_id');
}

if ($ay === '') {
    api_respond_error('Invalid academic year', 422, 'invalid_academic_year');
}

$cache_key = 'student_conflicts_' . sha1($student_id . '|' . $ay);
if (!$force_refresh) {
    $cached_payload = db_read_json_cache($cache_key, 20);
    if (is_array($cached_payload)) {
        api_respond_success($cached_payload, 200, [
            'student_id' => $student_id,
            'academic_year' => $ay,
            'cached' => true,
        ]);
    }
}

$conn = getDBConnection();

// Fetch Enrolled Schedule
$sql = "SELECT s.*, c.course_code 
        FROM enrollments e 
        JOIN schedules s ON e.curriculum_id = s.curriculum_id 
        JOIN curriculum c ON e.curriculum_id = c.curriculum_id
        WHERE e.student_id = ? AND e.academic_year = ? AND e.status = 'Enrolled'
        ORDER BY s.day_of_week, s.start_time";

$schedule_result = db_query($conn, $sql, 'is', [$student_id, $ay]);
if ($schedule_result === false) {
    $conn->close();
    api_respond_error('Unable to load schedule data', 500, 'schedule_query_failed');
}

$schedules = db_fetch_all($schedule_result);

$conflicts = [];
function isOverlap($s1, $s2) {
    if ($s1['day_of_week'] !== $s2['day_of_week']) return false;
    return ($s1['start_time'] < $s2['end_time'] && $s2['start_time'] < $s1['end_time']);
}

$count = count($schedules);
for ($i = 0; $i < $count; $i++) {
    $current = $schedules[$i];
    for ($j = $i + 1; $j < $count; $j++) {
        $candidate = $schedules[$j];

        // Because rows are sorted by day/start_time, once day changes there are no more
        // comparisons needed for the current row.
        if ($candidate['day_of_week'] !== $current['day_of_week']) {
            break;
        }

        // For the same day, no overlap is possible once the next class starts
        // at or after the current class end.
        if ($candidate['start_time'] >= $current['end_time']) {
            break;
        }

        if (isOverlap($current, $candidate)) {
            $conflicts[] = [
                'day' => $current['day_of_week'],
                's1' => $current['course_code'],
                't1' => $current['start_time'] . '-' . $current['end_time'],
                's2' => $candidate['course_code'],
                't2' => $candidate['start_time'] . '-' . $candidate['end_time']
            ];
        }
    }
}

$payload = [
    'conflicts' => $conflicts,
    'count' => count($conflicts),
];

$conn->close();
db_write_json_cache($cache_key, $payload);
api_respond_success($payload, 200, [
    'student_id' => $student_id,
    'academic_year' => $ay,
    'cached' => false,
]);
?>
