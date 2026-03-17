<?php
// api/check_student_conflicts.php
require_once __DIR__ . '/../config/db_helpers.php';
require_once __DIR__ . '/../config/api_response_helpers.php';

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

if ($student_id <= 0) {
    api_respond_error('Invalid student id', 422, 'invalid_student_id');
}

if ($ay === '') {
    api_respond_error('Invalid academic year', 422, 'invalid_academic_year');
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
    for ($j = $i + 1; $j < $count; $j++) {
        if (isOverlap($schedules[$i], $schedules[$j])) {
            $conflicts[] = [
                'day' => $schedules[$i]['day_of_week'],
                's1' => $schedules[$i]['course_code'],
                't1' => $schedules[$i]['start_time'] . '-' . $schedules[$i]['end_time'],
                's2' => $schedules[$j]['course_code'],
                't2' => $schedules[$j]['start_time'] . '-' . $schedules[$j]['end_time']
            ];
        }
    }
}

$conn->close();
api_respond_success([
    'conflicts' => $conflicts,
    'count' => count($conflicts)
], 200, [
    'student_id' => $student_id,
    'academic_year' => $ay
]);
?>
