<?php
require_once '../config/db_helpers.php';
require_once '../config/csrf_helpers.php';
require_once '../config/api_response_helpers.php';

$conn = getDBConnection();
$message = '';
$message_type = '';
$csrf_scope = 'enrollment_management';
csrf_ensure_session();

/**
 * Get the current global academic year start based on calendar year.
 *
 * @return int
 */
function getCurrentCalendarAcademicYearStart() {
    return intval(date('Y'));
}

/**
 * Get the allowed enrollment AY window based on current calendar year.
 *
 * @return array
 */
function getEnrollmentAcademicYearWindow() {
    $current_start = getCurrentCalendarAcademicYearStart();

    return [
        'current_start' => $current_start,
        'min_start' => max(2000, $current_start - 1),
        'max_start' => $current_start + 3,
    ];
}

/**
 * Parse YYYY-YYYY academic year string and return start year.
 *
 * @param string $academic_year
 * @return int|null
 */
function parseAcademicYearStart($academic_year) {
    $academic_year = trim((string)$academic_year);
    if (!preg_match('/^(\d{4})-(\d{4})$/', $academic_year, $matches)) {
        return null;
    }

    $start = intval($matches[1]);
    $end = intval($matches[2]);
    if ($end !== ($start + 1)) {
        return null;
    }

    return $start;
}

/**
 * Validate enrollment AY against allowed window.
 *
 * @param string $academic_year
 * @param string|null $error_message
 * @return bool
 */
function validateEnrollmentAcademicYearWindow($academic_year, &$error_message = null) {
    $start_year = parseAcademicYearStart($academic_year);
    if ($start_year === null) {
        $error_message = 'Invalid academic year format. Expected YYYY-YYYY.';
        return false;
    }

    $window = getEnrollmentAcademicYearWindow();
    $min_start = intval($window['min_start']);
    $max_start = intval($window['max_start']);

    if ($start_year < $min_start || $start_year > $max_start) {
        $error_message = 'Academic year is outside the allowed range ('
            . $min_start . '-' . ($min_start + 1)
            . ' to '
            . $max_start . '-' . ($max_start + 1)
            . ').';
        return false;
    }

    return true;
}

/**
 * Keep global AY aligned to current calendar year.
 *
 * @param mysqli $conn
 * @return void
 */
function ensureGlobalAcademicYearIsCurrent($conn) {
    $window = getEnrollmentAcademicYearWindow();
    $current_start = intval($window['current_start']);
    $target_ay = $current_start . '-' . ($current_start + 1);

    $stored_ay = (string)getSystemSetting($conn, 'current_academic_year', '', true);
    if ($stored_ay === $target_ay) {
        return;
    }

    $upsert_sql = "INSERT INTO system_settings (setting_key, setting_value, description, updated_at)
                   VALUES (?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE
                     setting_value = VALUES(setting_value),
                     description = COALESCE(NULLIF(description, ''), VALUES(description)),
                     updated_at = NOW()";

    if (db_query($conn, $upsert_sql, 'sss', ['current_academic_year', $target_ay, 'The currently active academic year for enrollment'])) {
        clearCache();
        clearEnrollmentAnalyticsCaches();
    }
}

$academic_year_window = getEnrollmentAcademicYearWindow();
$current_academic_year_start = intval($academic_year_window['current_start']);
$min_allowed_year_start = intval($academic_year_window['min_start']);
$max_allowed_year_start = intval($academic_year_window['max_start']);

ensureGlobalAcademicYearIsCurrent($conn);

/**
 * Get failed subjects that still require retake (failed with no passed attempt yet).
 *
 * @param mysqli $conn
 * @param int $student_id
 * @return array|null Returns array on success, null on query failure.
 */
function getPendingFailedCoursesForRetake($conn, $student_id) {
    $student_id = intval($student_id);
    if ($student_id <= 0) {
        return [];
    }

    $sql = "SELECT c.curriculum_id, c.course_code, c.course_name, c.year_level, c.semester
            FROM curriculum c
            WHERE c.curriculum_id IN (
                SELECT DISTINCT ef.curriculum_id
                FROM enrollments ef
                WHERE ef.student_id = ?
                  AND ef.status = 'Failed'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM enrollments ep
                      WHERE ep.student_id = ef.student_id
                        AND ep.curriculum_id = ef.curriculum_id
                        AND ep.status = 'Passed'
                  )
            )
            ORDER BY c.course_code";

    $result = db_query($conn, $sql, 'i', [$student_id]);
    if ($result === false) {
        return null;
    }

    return db_fetch_all($result);
}

/**
 * Clear dashboard analytics cache files after enrollment writes.
 *
 * @return void
 */
function clearEnrollmentAnalyticsCaches() {
    $cacheDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($cacheDir)) {
        return;
    }

    $patterns = [
        $cacheDir . DIRECTORY_SEPARATOR . 'analytics_*.json',
        $cacheDir . DIRECTORY_SEPARATOR . 'dashboard_stats_v1.json',
    ];

    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

/**
 * Sync active system term settings to match successful enrollment term.
 *
 * @param mysqli $conn
 * @param string $academic_year
 * @param int $semester
 * @return void
 */
function syncEnrollmentSystemTerm($conn, $academic_year, $semester) {
    $academic_year = trim((string)$academic_year);
    $semester = intval($semester);

    $targetAyStart = parseAcademicYearStart($academic_year);
    if ($targetAyStart === null) {
        return;
    }

    if ($semester < 0 || $semester > 2) {
        return;
    }

    // Global AY is calendar-year based and must not be auto-shifted by future enrollments.
    $currentAyStart = getCurrentCalendarAcademicYearStart();
    if ($targetAyStart !== $currentAyStart) {
        return;
    }

    $currentAcademicYear = $currentAyStart . '-' . ($currentAyStart + 1);

    $settingsToSync = [
        ['key' => 'current_academic_year', 'value' => $currentAcademicYear, 'description' => 'The currently active academic year for enrollment'],
        ['key' => 'current_semester', 'value' => (string)$semester, 'description' => 'The currently active semester (0 for summer, 1 or 2)'],
    ];

    $upsertSql = "INSERT INTO system_settings (setting_key, setting_value, description, updated_at)
                  VALUES (?, ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    description = COALESCE(NULLIF(description, ''), VALUES(description)),
                    updated_at = NOW()";

    foreach ($settingsToSync as $entry) {
        db_query($conn, $upsertSql, 'sss', [$entry['key'], $entry['value'], $entry['description']]);
    }

    clearCache();
    clearEnrollmentAnalyticsCaches();
}

// Handle AJAX request for recent students
if (isset($_GET['action']) && $_GET['action'] === 'get_recent_students') {
    $recent_sql = "SELECT s.student_id, s.student_number, s.first_name, s.last_name, 
                          p.program_code
                   FROM students s 
                   LEFT JOIN programs p ON s.program_id = p.program_id 
                   ORDER BY s.created_at DESC 
                   LIMIT 10";
    $recent_result = db_query($conn, $recent_sql);
    if ($recent_result === false) {
        $conn->close();
        api_respond_error('Unable to load recent students', 500, 'recent_students_query_failed');
    }

    $recent_students = db_fetch_all($recent_result);

    $conn->close();
    api_respond_success([
        'students' => $recent_students,
    ], 200, ['action' => 'get_recent_students']);
}

// Handle AJAX request for student lookup
if (isset($_GET['action']) && $_GET['action'] === 'lookup_student') {
    $student_number = trim($_GET['student_number'] ?? '');

    if (empty($student_number)) {
        $conn->close();
        api_respond_error('Student number is required', 422, 'missing_student_number');
    }

    $student_sql = "SELECT s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name, 
                           s.program_id, s.year_level, s.current_semester,
                           p.program_code, p.program_name
                    FROM students s 
                    LEFT JOIN programs p ON s.program_id = p.program_id 
                    WHERE s.student_number = ?";
    $student_result = db_query($conn, $student_sql, 's', [$student_number]);
    if ($student_result === false) {
        $conn->close();
        api_respond_error('Unable to look up student', 500, 'student_lookup_query_failed');
    }

    $student = db_fetch_one($student_result);

    if ($student) {
        $pending_failed_courses = getPendingFailedCoursesForRetake($conn, intval($student['student_id']));
        if ($pending_failed_courses === null) {
            $conn->close();
            api_respond_error('Unable to evaluate failed subjects for retake', 500, 'retake_evaluation_failed');
        }

        $full_name = $student['first_name'];
        if (!empty($student['middle_name'])) {
            $full_name .= ' ' . $student['middle_name'];
        }
        $full_name .= ' ' . $student['last_name'];

        $payload = [
            'student' => [
                'id' => $student['student_id'],
                'student_number' => $student['student_number'],
                'name' => $full_name,
                'program_id' => $student['program_id'],
                'program_code' => $student['program_code'],
                'program_name' => $student['program_name'],
                'year_level' => $student['year_level'],
                'current_semester' => $student['current_semester'],
                'retake_mode' => !empty($pending_failed_courses),
                'pending_failed_count' => count($pending_failed_courses),
                'pending_failed_courses' => array_map(
                    function ($course) {
                        return [
                            'curriculum_id' => intval($course['curriculum_id'] ?? 0),
                            'course_code' => $course['course_code'] ?? '',
                            'course_name' => $course['course_name'] ?? '',
                        ];
                    },
                    $pending_failed_courses
                ),
            ]
        ];

        $conn->close();
        api_respond_success($payload, 200, ['action' => 'lookup_student']);
    } else {
        $conn->close();
        api_respond_error('Student not found', 404, 'student_not_found', ['student_number' => $student_number]);
    }
}

// Handle AJAX request for schedule lookup
if (isset($_GET['action']) && $_GET['action'] === 'lookup_schedule') {
    $schedule_id = intval($_GET['schedule_id'] ?? 0);

    if ($schedule_id <= 0) {
        $conn->close();
        api_respond_error('Invalid schedule ID', 422, 'invalid_schedule_id');
    }

    $schedule_sql = "SELECT s.schedule_id, s.day_of_week, s.start_time, s.end_time, s.room, 
                            s.capacity, s.enrolled_count, s.curriculum_id,
                            c.course_code, c.course_name, c.units, c.year_level, c.semester,
                            CONCAT(t.title, ' ', t.first_name, ' ', t.last_name) as teacher_name,
                            p.program_id, p.program_code,
                            COALESCE(ptr.tuition_per_unit, 800.00) as tuition_per_unit
                     FROM schedules s
                     JOIN curriculum c ON s.curriculum_id = c.curriculum_id
                     LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
                     LEFT JOIN programs p ON c.program_id = p.program_id
                     LEFT JOIN program_tuition_rates ptr ON p.program_id = ptr.program_id AND ptr.is_active = 1
                     WHERE s.schedule_id = ?";
    $schedule_result = db_query($conn, $schedule_sql, 'i', [$schedule_id]);
    if ($schedule_result === false) {
        $conn->close();
        api_respond_error('Unable to look up schedule', 500, 'schedule_lookup_query_failed');
    }

    $schedule = db_fetch_one($schedule_result);

    if ($schedule) {
        $payload = [
            'schedule' => [
                'schedule_id' => $schedule['schedule_id'],
                'curriculum_id' => $schedule['curriculum_id'],
                'course_code' => $schedule['course_code'],
                'course_name' => $schedule['course_name'],
                'units' => $schedule['units'],
                'day_of_week' => $schedule['day_of_week'],
                'start_time' => date('h:i A', strtotime($schedule['start_time'])),
                'end_time' => date('h:i A', strtotime($schedule['end_time'])),
                'room' => $schedule['room'] ?? 'TBA',
                'teacher_name' => $schedule['teacher_name'] ?? 'TBA',
                'capacity' => $schedule['capacity'],
                'enrolled_count' => $schedule['enrolled_count'],
                'program_id' => $schedule['program_id'],
                'program_code' => $schedule['program_code'],
                'year_level' => $schedule['year_level'],
                'semester' => $schedule['semester'],
                'tuition_per_unit' => $schedule['tuition_per_unit']
            ]
        ];

        $conn->close();
        api_respond_success($payload, 200, ['action' => 'lookup_schedule']);
    } else {
        $conn->close();
        api_respond_error('Schedule not found', 404, 'schedule_not_found', ['schedule_id' => $schedule_id]);
    }
}

// Handle AJAX request for filtered schedule suggestions
if (isset($_GET['action']) && $_GET['action'] === 'get_schedule_suggestions') {
    $program_id = intval($_GET['program_id'] ?? 0);
    $year_level = intval($_GET['year_level'] ?? 0);
    $semester = intval($_GET['semester'] ?? 0);
    $search = trim($_GET['search'] ?? '');
    $student_id = intval($_GET['student_id'] ?? 0);
    $academic_year = trim($_GET['academic_year'] ?? '');

    if ($program_id <= 0) {
        $conn->close();
        api_respond_error('Program is required', 422, 'missing_program_id');
    }

    $pending_failed_courses = [];
    $pending_failed_curriculum_ids = [];
    $retake_mode = false;

    if ($student_id > 0) {
        $pending_failed_courses = getPendingFailedCoursesForRetake($conn, $student_id);
        if ($pending_failed_courses === null) {
            $conn->close();
            api_respond_error('Unable to evaluate failed subjects for retake', 500, 'retake_evaluation_failed');
        }

        $pending_failed_curriculum_ids = array_map(
            function ($course) {
                return intval($course['curriculum_id'] ?? 0);
            },
            $pending_failed_courses
        );
        $pending_failed_curriculum_ids = array_values(array_filter($pending_failed_curriculum_ids));
        $retake_mode = !empty($pending_failed_curriculum_ids);
    }

    // Build query with GROUP BY to consolidate multiple day entries per course
    // Uses MIN(schedule_id) as representative ID and GROUP_CONCAT for days/times
    $sql = "SELECT MIN(s.schedule_id) as schedule_id, 
                   s.curriculum_id,
                   GROUP_CONCAT(DISTINCT CONCAT(s.day_of_week, ' ', DATE_FORMAT(s.start_time, '%h:%i %p'), '-', DATE_FORMAT(s.end_time, '%h:%i %p')) ORDER BY FIELD(s.day_of_week, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun') SEPARATOR ', ') as schedule_days,
                   MAX(s.room) as room,
                   MAX(s.capacity) as capacity, 
                   MAX(s.enrolled_count) as enrolled_count,
                   c.course_code, c.course_name, c.units, c.year_level, c.semester,
                   MAX(CONCAT(t.title, ' ', t.first_name, ' ', t.last_name)) as teacher_name
            FROM schedules s
            JOIN curriculum c ON s.curriculum_id = c.curriculum_id
            LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
            WHERE c.program_id = ?";

    $params = [$program_id];
    $types = 'i';

    if ($retake_mode) {
        $failedPlaceholders = implode(',', array_fill(0, count($pending_failed_curriculum_ids), '?'));
        $sql .= " AND c.curriculum_id IN ($failedPlaceholders)";
        foreach ($pending_failed_curriculum_ids as $failedCurriculumId) {
            $params[] = $failedCurriculumId;
            $types .= 'i';
        }

        // Keep summer schedules out of the generic "Show All Schedules" list
        // unless the selected term code explicitly targets Summer.
        if ($semester !== 0) {
            $sql .= " AND c.semester <> 0";
        }
    } else {
        $sql .= " AND c.year_level = ? AND c.semester = ?";
        $params[] = $year_level;
        $params[] = $semester;
        $types .= 'ii';
    }
    
    if (!empty($search)) {
        $sql .= " AND (s.schedule_id LIKE ? OR c.course_code LIKE ? OR c.course_name LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'sss';
    }
    
    $sql .= " GROUP BY s.curriculum_id, c.course_code, c.course_name, c.units, c.year_level, c.semester";
    $sql .= " ORDER BY c.course_code";

    $result = db_query($conn, $sql, $types, $params);
    if ($result === false) {
        $conn->close();
        api_respond_error('Unable to load schedule suggestions', 500, 'schedule_suggestions_query_failed');
    }

    $schedules = db_fetch_all($result);

    // Check enrollment status for each course if student_id is provided
    if ($student_id > 0) {
        // Get ALL courses student is currently enrolled in (any academic year with status 'Enrolled')
        $enrolled_sql = "SELECT DISTINCT e.curriculum_id 
                         FROM enrollments e
                         WHERE e.student_id = ? AND e.status = 'Enrolled'";
        $enrolled_result = db_query($conn, $enrolled_sql, 'i', [$student_id]);
        if ($enrolled_result === false) {
            $conn->close();
            api_respond_error('Unable to load enrolled courses', 500, 'enrolled_courses_query_failed');
        }

        $enrolled_courses = [];
        if ($enrolled_result && $enrolled_result !== true) {
            while ($row = mysqli_fetch_assoc($enrolled_result)) {
                $enrolled_courses[] = intval($row['curriculum_id'] ?? 0);
            }
        }
        
        // Get courses already passed (status = 'Passed')
        $passed_sql = "SELECT DISTINCT e.curriculum_id 
                       FROM enrollments e
                       WHERE e.student_id = ? AND e.status = 'Passed'";
        $passed_result = db_query($conn, $passed_sql, 'i', [$student_id]);
        if ($passed_result === false) {
            $conn->close();
            api_respond_error('Unable to load passed courses', 500, 'passed_courses_query_failed');
        }

        $passed_courses = [];
        if ($passed_result && $passed_result !== true) {
            while ($row = mysqli_fetch_assoc($passed_result)) {
                $passed_courses[] = intval($row['curriculum_id'] ?? 0);
            }
        }

        $failed_courses = $pending_failed_curriculum_ids;
        
        // Add status to each schedule
        foreach ($schedules as &$schedule) {
            $curriculum_id = intval($schedule['curriculum_id'] ?? 0);
            if (in_array($curriculum_id, $enrolled_courses, true)) {
                $schedule['enrollment_status'] = 'enrolled';
            } elseif (in_array($curriculum_id, $passed_courses, true)) {
                $schedule['enrollment_status'] = 'passed';
            } elseif (in_array($curriculum_id, $failed_courses, true)) {
                $schedule['enrollment_status'] = 'failed';
            } else {
                $schedule['enrollment_status'] = 'available';
            }
        }
    }

    $conn->close();
    api_respond_success([
        'schedules' => $schedules,
        'count' => count($schedules),
        'retake_mode' => $retake_mode,
        'pending_failed_count' => count($pending_failed_curriculum_ids),
    ], 200, [
        'action' => 'get_schedule_suggestions',
        'program_id' => $program_id,
        'year_level' => $year_level,
        'semester' => $semester,
        'academic_year' => $academic_year,
        'search' => $search,
    ]);
}

// Handle AJAX request to enroll a subject
if (isset($_GET['action']) && $_GET['action'] === 'enroll_subject') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $conn->close();
        api_respond_error('Method not allowed', 405, 'method_not_allowed');
    }

    if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
        $conn->close();
        api_respond_error('Invalid or missing CSRF token', 403, 'csrf_token_invalid');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $student_id = intval($_POST['student_id'] ?? $input['student_id'] ?? 0);
    $schedule_id = intval($_POST['schedule_id'] ?? $input['schedule_id'] ?? 0);
    $academic_year = trim((string)($_POST['academic_year'] ?? $input['academic_year'] ?? ''));

    if ($student_id <= 0 || $schedule_id <= 0 || empty($academic_year)) {
        $conn->close();
        api_respond_error('Missing required parameters', 422, 'missing_required_parameters', [
            'required' => ['student_id', 'schedule_id', 'academic_year'],
        ]);
    }

    $academic_year_error = null;
    if (!validateEnrollmentAcademicYearWindow($academic_year, $academic_year_error)) {
        $conn->close();
        api_respond_error($academic_year_error ?? 'Invalid academic year.', 422, 'invalid_academic_year');
    }

    // Get curriculum_id from schedule
    $sched_result = db_query(
        $conn,
        "SELECT s.curriculum_id, s.capacity, s.enrolled_count, c.semester
         FROM schedules s
         JOIN curriculum c ON s.curriculum_id = c.curriculum_id
         WHERE s.schedule_id = ?",
        'i',
        [$schedule_id]
    );
    if ($sched_result === false) {
        $conn->close();
        api_respond_error('Unable to load schedule', 500, 'schedule_query_failed');
    }

    $sched_info = db_fetch_one($sched_result);

    if (!$sched_info) {
        $conn->close();
        api_respond_error('Schedule not found', 404, 'schedule_not_found', ['schedule_id' => $schedule_id]);
    }

    $curriculum_id = $sched_info['curriculum_id'];

    $pending_failed_courses = getPendingFailedCoursesForRetake($conn, $student_id);
    if ($pending_failed_courses === null) {
        $conn->close();
        api_respond_error('Unable to evaluate failed subjects for retake', 500, 'retake_evaluation_failed');
    }

    if (!empty($pending_failed_courses)) {
        $pending_failed_curriculum_ids = array_map(
            function ($course) {
                return intval($course['curriculum_id'] ?? 0);
            },
            $pending_failed_courses
        );

        if (!in_array(intval($curriculum_id), $pending_failed_curriculum_ids, true)) {
            $conn->close();
            api_respond_error(
                'Student is in irregular retake mode. Only failed subjects can be enrolled this term.',
                409,
                'retake_only_failed_subjects'
            );
        }
    }

    // Check capacity
    if ($sched_info['enrolled_count'] >= $sched_info['capacity']) {
        $conn->close();
        api_respond_error('This schedule is full', 409, 'schedule_full', ['schedule_id' => $schedule_id]);
    }

    // Check if already enrolled
    $check_sql = "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND curriculum_id = ? AND academic_year = ?";
    $check_result = db_query($conn, $check_sql, 'iis', [$student_id, $curriculum_id, $academic_year]);
    if ($check_result === false) {
        $conn->close();
        api_respond_error('Unable to validate enrollment', 500, 'enrollment_check_query_failed');
    }

    $existing = db_fetch_one($check_result);

    if ($existing) {
        $conn->close();
        api_respond_error('Already enrolled in this course', 409, 'already_enrolled');
    }

    // Insert enrollment
    $ins_sql = "INSERT INTO enrollments (student_id, curriculum_id, academic_year, status) VALUES (?, ?, ?, 'Enrolled')";
    if (db_query($conn, $ins_sql, 'iis', [$student_id, $curriculum_id, $academic_year])) {
        // Increment enrolled count
        db_query($conn, "UPDATE schedules SET enrolled_count = enrolled_count + 1 WHERE schedule_id = ?", 'i', [$schedule_id]);

        // Keep system active term aligned with successful enrollment term.
        syncEnrollmentSystemTerm($conn, $academic_year, intval($sched_info['semester'] ?? 0));

        // Get course info for response
        $course_info = db_fetch_one(db_query($conn, "SELECT course_code, course_name FROM curriculum WHERE curriculum_id = ?", 'i', [$curriculum_id]));

        $course_label = ($course_info && isset($course_info['course_code'], $course_info['course_name']))
            ? ($course_info['course_code'] . ' - ' . $course_info['course_name'])
            : 'the selected course';

        $conn->close();
        api_respond_success([
            'message' => 'Successfully enrolled in ' . $course_label,
            'course' => $course_info,
        ], 200, ['action' => 'enroll_subject']);
    } else {
        $conn->close();
        api_respond_error('Failed to enroll', 500, 'enrollment_insert_failed');
    }
}

// Handle AJAX request for bulk enrollment
if (isset($_GET['action']) && $_GET['action'] === 'bulk_enroll') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $conn->close();
        api_respond_error('Method not allowed', 405, 'method_not_allowed');
    }

    if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
        $conn->close();
        api_respond_error('Invalid or missing CSRF token', 403, 'csrf_token_invalid');
    }

    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    if (!is_array($input) || empty($input)) {
        $conn->close();
        api_respond_error('Invalid request payload', 422, 'invalid_request_payload');
    }

    $student_id = intval($input['student_id'] ?? 0);
    $program_id = intval($input['program_id'] ?? 0);
    $academic_year = trim($input['academic_year'] ?? '');
    $semester = intval($input['semester'] ?? 0);
    $schedule_ids = $input['schedule_ids'] ?? [];

    if ($student_id <= 0 || $program_id <= 0 || empty($academic_year) || empty($schedule_ids)) {
        $conn->close();
        api_respond_error('Missing required parameters', 422, 'missing_required_parameters', [
            'required' => ['student_id', 'program_id', 'academic_year', 'schedule_ids'],
        ]);
    }

    $academic_year_error = null;
    if (!validateEnrollmentAcademicYearWindow($academic_year, $academic_year_error)) {
        $conn->close();
        api_respond_error($academic_year_error ?? 'Invalid academic year.', 422, 'invalid_academic_year');
    }

    $pending_failed_courses = getPendingFailedCoursesForRetake($conn, $student_id);
    if ($pending_failed_courses === null) {
        $conn->close();
        api_respond_error('Unable to evaluate failed subjects for retake', 500, 'retake_evaluation_failed');
    }

    $pending_failed_map = [];
    foreach ($pending_failed_courses as $pending_failed_course) {
        $cid = intval($pending_failed_course['curriculum_id'] ?? 0);
        if ($cid > 0) {
            $pending_failed_map[$cid] = $pending_failed_course['course_code'] ?? ('Curriculum ' . $cid);
        }
    }
    $retake_mode = !empty($pending_failed_map);

    $conn->begin_transaction();

    try {
        // Update student's program_id and current_semester
        $update_sql = "UPDATE students SET program_id = ?, current_semester = ? WHERE student_id = ?";
        if (!db_query($conn, $update_sql, 'iii', [$program_id, $semester, $student_id])) {
            throw new Exception('Failed to update student program');
        }

        $enrolled_courses = [];
        $errors = [];

        foreach ($schedule_ids as $schedule_id) {
            $schedule_id = intval($schedule_id);

            // Get schedule info
            $sched_query = db_query($conn, "SELECT s.curriculum_id, s.capacity, s.enrolled_count, c.course_code, c.course_name FROM schedules s JOIN curriculum c ON s.curriculum_id = c.curriculum_id WHERE s.schedule_id = ?", 'i', [$schedule_id]);
            if ($sched_query === false) {
                $errors[] = "Unable to load schedule $schedule_id";
                continue;
            }

            $sched_info = db_fetch_one($sched_query);

            if (!$sched_info) {
                $errors[] = "Schedule $schedule_id not found";
                continue;
            }

            if ($retake_mode && !array_key_exists(intval($sched_info['curriculum_id']), $pending_failed_map)) {
                $errors[] = "{$sched_info['course_code']} is not a pending failed subject. Only failed subjects can be enrolled for irregular retake.";
                continue;
            }

            // Check capacity
            if ($sched_info['enrolled_count'] >= $sched_info['capacity']) {
                $errors[] = "{$sched_info['course_code']} is full";
                continue;
            }

            // Check if already enrolled
            $check_sql = "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND curriculum_id = ? AND academic_year = ?";
            $existing_query = db_query($conn, $check_sql, 'iis', [$student_id, $sched_info['curriculum_id'], $academic_year]);
            if ($existing_query === false) {
                $errors[] = "Unable to validate existing enrollment for {$sched_info['course_code']}";
                continue;
            }

            $existing = db_fetch_one($existing_query);

            if ($existing) {
                $errors[] = "Already enrolled in {$sched_info['course_code']}";
                continue;
            }

            // Insert enrollment
            $ins_sql = "INSERT INTO enrollments (student_id, curriculum_id, academic_year, status) VALUES (?, ?, ?, 'Enrolled')";
            if (!db_query($conn, $ins_sql, 'iis', [$student_id, $sched_info['curriculum_id'], $academic_year])) {
                $errors[] = "Failed to enroll in {$sched_info['course_code']}";
                continue;
            }

            // Update enrolled count for ALL schedule rows of this curriculum (not just the one schedule_id)
            db_query($conn, "UPDATE schedules SET enrolled_count = enrolled_count + 1 WHERE curriculum_id = ?", 'i', [$sched_info['curriculum_id']]);

            $enrolled_courses[] = $sched_info['course_code'] . ' - ' . $sched_info['course_name'];
        }

        if (count($enrolled_courses) > 0) {
            $conn->commit();

            // Keep system active term aligned with successful enrollment term.
            syncEnrollmentSystemTerm($conn, $academic_year, $semester);

            $conn->close();
            api_respond_success([
                'message' => 'Successfully enrolled in ' . count($enrolled_courses) . ' course(s)',
                'enrolled' => $enrolled_courses,
                'errors' => $errors,
                'retake_mode' => $retake_mode,
            ], 200, ['action' => 'bulk_enroll']);
        } else {
            $conn->rollback();

            $conn->close();
            api_respond_error(
                'No courses enrolled. ' . implode(', ', $errors),
                409,
                'no_courses_enrolled',
                ['errors' => $errors]
            );
        }

    } catch (Exception $e) {
        $conn->rollback();

        $conn->close();
        api_respond_error('Error processing enrollment', 500, 'bulk_enroll_failed', [
            'message' => $e->getMessage(),
        ]);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/forms_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-enrollment">
    <?php require_once '../config/sidebar.php'; ?>
    <?php renderAppSidebar(['active' => 'enrollment', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Enrollment</h1>
        </header>

        <div id="enrollmentApiErrorNotice" role="alert" aria-live="polite" class="hidden" style="display:none; margin-bottom:16px; padding:12px 14px; border-radius:10px; border:1px solid rgba(189,69,48,0.35); background: var(--status-error-bg); color: var(--status-error); font-size:14px;"></div>

        <div class="student-details">
            <div class="enrollment-form" id="enrollmentForm">
                <div class="current-info">
                    <strong>Current Academic Year:</strong> <?php echo $current_academic_year_start . '-' . ($current_academic_year_start + 1); ?> | 
                    <strong>Allowed Range:</strong> <?php echo $min_allowed_year_start . '-' . ($min_allowed_year_start + 1); ?> to <?php echo $max_allowed_year_start . '-' . ($max_allowed_year_start + 1); ?>
                </div>

                <div class="form-section">
                    <h3>Term Information</h3>
                    <div class="form-group">
                        <label for="term_code">Term Code <span class="required">*</span></label>
                        <input type="text" id="term_code" name="term_code" 
                               maxlength="3" placeholder="e.g., 251" autofocus>
                        <p class="help-text">Enter a 3-digit code: <strong>YYS</strong> (Year + Semester). Press Enter to continue.</p>
                        <div id="termError" class="inline-error hidden"></div>
                        <div id="termDisplay" class="term-display hidden"></div>
                    </div>
                </div>

                <div class="form-section hidden" id="studentSection">
                    <h3>Student Information</h3>
                    <div class="form-group">
                        <label for="student_number">Student Number <span class="required">*</span></label>
                        <input type="text" id="student_number" name="student_number" placeholder="e.g., 2026-00001">
                        <p class="help-text">Press Enter to lookup student.</p>
                        <div id="studentError" class="inline-error hidden"></div>
                    </div>
                    
                    <button type="button" class="btn btn-toggle" id="toggleRecentStudentsBtn" style="margin-top: 10px;">Show Recent Students</button>
                    <div class="recent-students-section hidden" id="recentStudentsSection">
                        <label>Recent Students (click to select):</label>
                        <div id="recentStudentsList" class="recent-students-list">Loading...</div>
                    </div>

                    <div id="studentInfoDisplay" class="student-info-section hidden">
                        <h4>Student Found</h4>
                        <div class="form-group">
                            <label for="student_name">Name</label>
                            <input type="text" id="student_name" readonly>
                        </div>
                        <div class="form-group">
                            <label for="program_input">Program <span class="required">*</span></label>
                            <input type="text" id="program_input" name="program_input" placeholder="Enter program code (e.g., BSIT)">
                            <input type="hidden" id="program_id" name="program_id">
                            <p class="help-text">Type program code and press Enter to confirm.</p>
                            <div id="programError" class="inline-error hidden"></div>
                            <div id="programConfirmed" class="term-display hidden"></div>
                        </div>
                        
                        <button type="button" class="btn btn-toggle" id="toggleProgramsBtn" style="margin-top: 10px;">Show Available Programs</button>
                        <div class="programs-section hidden" id="programsSection">
                            <label>Available Programs (click to select):</label>
                            <div id="programsList" class="programs-list">
                                <div class="program-item" data-id="1" data-code="BSIT">BSIT - Bachelor of Science in Information Technology</div>
                                <div class="program-item" data-id="2" data-code="BSCS">BSCS - Bachelor of Science in Computer Science</div>
                                <div class="program-item" data-id="3" data-code="BSIS">BSIS - Bachelor of Science in Information Systems</div>
                                <div class="program-item" data-id="4" data-code="BSBA">BSBA - Bachelor of Science in Business Administration</div>
                                <div class="program-item" data-id="5" data-code="BSE">BSE - Bachelor of Science in Education</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="year_level">Year Level</label>
                                <input type="text" id="year_level" readonly>
                            </div>
                            <div class="form-group">
                                <label for="semester">Semester</label>
                                <input type="text" id="semester" readonly>
                            </div>
                        </div>
                        
                        <div id="termMismatchWarning" class="term-mismatch-warning hidden">
                            <div class="warning-title">Term Code Mismatch</div>
                            <div class="warning-details" id="termMismatchDetails"></div>
                            <div class="warning-recommendation">
                                Recommended term code: <span class="recommended-code" id="recommendedTermCode"></span>
                                <br>
                                <button type="button" class="btn btn-use-recommended" data-enrollment-action="use-recommended-term">Use Recommended Code</button>
                            </div>
                        </div>

                        <div id="retakeModeNotice" class="term-mismatch-warning hidden">
                            <div class="warning-title">Irregular Retake Mode</div>
                            <div class="warning-details" id="retakeModeDetails"></div>
                        </div>
                    </div>
                </div>

                <div class="form-section hidden" id="scheduleSection">
                    <h3>Schedule Selection</h3>
                    <div class="form-group" style="position: relative;">
                        <label for="schedule_code">Schedule Code <span class="required">*</span></label>
                        <input type="text" id="schedule_code" name="schedule_code" placeholder="Enter schedule ID or type to search" autocomplete="off">
                        <p class="help-text">Type to search schedules or enter ID and press Enter.</p>
                        <div id="scheduleAutocomplete" class="autocomplete-dropdown hidden"></div>
                        <div id="scheduleError" class="inline-error hidden"></div>
                    </div>
                    
                    <button type="button" class="btn btn-toggle" id="toggleScheduleListBtn">Show All Schedules</button>
                    <div class="schedule-suggestions-section hidden" id="scheduleSuggestionsSection">
                        <label>Available Schedules for Year <span id="filterYearLevel">-</span>, Semester <span id="filterSemester">-</span>:</label>
                        <div id="schedulesList" class="schedules-list">Loading...</div>
                    </div>
                    


                    <div id="scheduleDisplay" class="hidden">
                        <table class="schedule-table">
                            <thead>
                                <tr>
                                    <th>Day of Week</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                    <th>Room</th>
                                    <th>Units</th>
                                    <th>Price/Unit</th>
                                </tr>
                            </thead>
                            <tbody id="scheduleTableBody">
                            </tbody>
                        </table>
                        <div id="courseInfo" style="margin-top: 10px; font-size: 14px; color: #495057;"></div>
                        <div class="schedule-actions">
                            <button type="button" class="btn btn-add-subject" id="addSubjectBtn">Add Subject</button>
                        </div>
                    </div>

                    <div id="pendingList" class="pending-list hidden">
                        <h4>Pending Subjects (Not yet enrolled)</h4>
                        <div id="pendingItems"></div>
                        <div class="enroll-actions">
                            <button type="button" class="btn btn-enroll-student" id="enrollStudentBtn" disabled>Enroll Student</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <script>
        const currentAcademicYearStart = <?php echo $current_academic_year_start; ?>;
        const minAllowedYearStart = <?php echo $min_allowed_year_start; ?>;
        const maxAllowedYearStart = <?php echo $max_allowed_year_start; ?>;
        const enrollmentCsrfToken = <?php echo json_encode(csrf_get_token($csrf_scope)); ?>;
        
        let validatedTermCode = null;
        let currentStudent = null;
        let confirmedProgramId = null;
        let currentSchedule = null;
        // Rename to pendingSubjects for clarity - subjects queued for enrollment
        let pendingSubjects = [];
        let successModalRedirectUrl = '';
        let successModalRedirectTimer = null;
        let retakeModeActive = false;

        function buildStudentRecordsUrl(studentId) {
            const parsedId = parseInt(studentId, 10);
            if (!Number.isInteger(parsedId) || parsedId <= 0) {
                return '';
            }

            return `student_schedule_grades.php?id=${encodeURIComponent(String(parsedId))}&tab=schedule`;
        }
        
        // DOM Elements
        const termCodeInput = document.getElementById('term_code');
        const termError = document.getElementById('termError');
        const termDisplay = document.getElementById('termDisplay');
        const studentSection = document.getElementById('studentSection');
        const studentNumberInput = document.getElementById('student_number');
        const studentError = document.getElementById('studentError');
        const studentInfoDisplay = document.getElementById('studentInfoDisplay');
        const programInput = document.getElementById('program_input');
        const programIdField = document.getElementById('program_id');
        const programsList = document.getElementById('programsList');
        const recentStudentsSection = document.getElementById('recentStudentsSection');
        const toggleRecentStudentsBtn = document.getElementById('toggleRecentStudentsBtn');
        const programsSection = document.getElementById('programsSection');
        const toggleProgramsBtn = document.getElementById('toggleProgramsBtn');
        const scheduleSection = document.getElementById('scheduleSection');
        const scheduleCodeInput = document.getElementById('schedule_code');
        const scheduleError = document.getElementById('scheduleError');
        const scheduleDisplay = document.getElementById('scheduleDisplay');
        const scheduleTableBody = document.getElementById('scheduleTableBody');
        const courseInfo = document.getElementById('courseInfo');
        const addSubjectBtn = document.getElementById('addSubjectBtn');
        const pendingList = document.getElementById('pendingList');
        const pendingItems = document.getElementById('pendingItems');
        const enrollStudentBtn = document.getElementById('enrollStudentBtn');
        const scheduleAutocomplete = document.getElementById('scheduleAutocomplete');
        const toggleScheduleListBtn = document.getElementById('toggleScheduleListBtn');
        const scheduleSuggestionsSection = document.getElementById('scheduleSuggestionsSection');
        const schedulesList = document.getElementById('schedulesList');
        const filterYearLevel = document.getElementById('filterYearLevel');
        const filterSemester = document.getElementById('filterSemester');
        const enrollmentApiErrorNotice = document.getElementById('enrollmentApiErrorNotice');
        const retakeModeNotice = document.getElementById('retakeModeNotice');
        const retakeModeDetails = document.getElementById('retakeModeDetails');
        
        let autocompleteTimeout = null;

        function clearEnrollmentApiError() {
            if (typeof clearApiErrorNotice === 'function') {
                clearApiErrorNotice(enrollmentApiErrorNotice);
                return;
            }
            if (!enrollmentApiErrorNotice) {
                return;
            }
            enrollmentApiErrorNotice.textContent = '';
            enrollmentApiErrorNotice.style.display = 'none';
            enrollmentApiErrorNotice.classList.add('hidden');
        }

        function showEnrollmentApiError(message, onRetry) {
            if (typeof showApiErrorNotice === 'function') {
                showApiErrorNotice(
                    enrollmentApiErrorNotice,
                    message,
                    onRetry,
                    { fallbackMessage: 'Unable to complete the request right now. Please try again.' }
                );
                return;
            }

            if (!enrollmentApiErrorNotice) {
                return;
            }

            enrollmentApiErrorNotice.textContent = message || 'Unable to complete the request right now. Please try again.';
            enrollmentApiErrorNotice.style.display = 'block';
            enrollmentApiErrorNotice.classList.remove('hidden');
        }

        function getEnrollmentApiErrorMessage(payload, fallbackMessage) {
            if (!payload || typeof payload !== 'object' || !payload.error) {
                return fallbackMessage;
            }

            if (typeof payload.error === 'string' && payload.error.trim() !== '') {
                return payload.error;
            }

            if (payload.error && typeof payload.error.message === 'string' && payload.error.message.trim() !== '') {
                return payload.error.message;
            }

            return fallbackMessage;
        }

        function unwrapEnrollmentApiResponse(response, payload, fallbackMessage) {
            if (!response || !payload || payload.success !== true) {
                throw new Error(getEnrollmentApiErrorMessage(payload, fallbackMessage));
            }

            return payload.data || {};
        }

        function applyScheduleContextLabels() {
            if (retakeModeActive) {
                filterYearLevel.textContent = 'Irregular';
                filterSemester.textContent = 'Failed Subject Retakes';
                return;
            }

            filterYearLevel.textContent = currentStudent ? currentStudent.year_level : '-';
            filterSemester.textContent = validatedTermCode
                ? (validatedTermCode.semester === 0 ? 'Summer' : (validatedTermCode.semester === 1 ? '1st' : '2nd'))
                : '-';
        }

        function renderRetakeModeNotice(student) {
            retakeModeActive = !!(student && student.retake_mode);

            if (!retakeModeNotice || !retakeModeDetails) {
                return;
            }

            if (!retakeModeActive) {
                retakeModeNotice.classList.add('hidden');
                return;
            }

            const failedCourses = Array.isArray(student.pending_failed_courses) ? student.pending_failed_courses : [];
            const failedCount = Number.isInteger(parseInt(student.pending_failed_count, 10))
                ? parseInt(student.pending_failed_count, 10)
                : failedCourses.length;
            const previewCodes = failedCourses
                .slice(0, 5)
                .map(course => course.course_code)
                .filter(Boolean)
                .join(', ');
            const remaining = failedCourses.length > 5 ? ` and ${failedCourses.length - 5} more` : '';

            retakeModeDetails.innerHTML = `
                This student has <strong>${failedCount}</strong> failed subject(s) pending retake.
                Only failed subjects can be enrolled for this term.
                ${previewCodes ? `<br><br><strong>Pending failed subjects:</strong> ${previewCodes}${remaining}` : ''}
            `;

            retakeModeNotice.classList.remove('hidden');
        }
        
        // Toggle buttons for Recent Students and Available Programs
        toggleRecentStudentsBtn.addEventListener('click', function() {
            const isHidden = recentStudentsSection.classList.toggle('hidden');
            this.textContent = isHidden ? 'Show Recent Students' : 'Hide Recent Students';
        });
        
        toggleProgramsBtn.addEventListener('click', function() {
            const isHidden = programsSection.classList.toggle('hidden');
            this.textContent = isHidden ? 'Show Available Programs' : 'Hide Available Programs';
        });
        
        // Toggle schedule suggestions list
        toggleScheduleListBtn.addEventListener('click', function() {
            if (!currentStudent || !confirmedProgramId) {
                showNotification('warning', 'Action Required', 'Please select a student and confirm program first.');
                return;
            }
            
            const isHidden = scheduleSuggestionsSection.classList.toggle('hidden');
            this.textContent = isHidden ? 'Show All Schedules' : 'Hide Schedules';
            
            if (!isHidden) {
                loadScheduleSuggestions();
            }
        });
        
        function loadScheduleSuggestions() {
            if (!currentStudent || !confirmedProgramId || !validatedTermCode) return;
            
            clearEnrollmentApiError();
            applyScheduleContextLabels();
            schedulesList.innerHTML = '<span style="color: #6c757d;">Loading...</span>';
            
            const apiUrl = `?action=get_schedule_suggestions&program_id=${confirmedProgramId}&year_level=${currentStudent.year_level}&semester=${validatedTermCode.semester}&student_id=${currentStudent.id}&academic_year=${encodeURIComponent(validatedTermCode.academicYear)}`;
            
            fetch(apiUrl)
                .then(async response => {
                    const payload = await response.json();
                    return unwrapEnrollmentApiResponse(response, payload, 'Unable to load schedule suggestions right now.');
                })
                .then(data => {
                    clearEnrollmentApiError();
                    retakeModeActive = !!data.retake_mode;
                    applyScheduleContextLabels();
                    const schedules = Array.isArray(data.schedules) ? data.schedules : [];

                    if (schedules.length > 0) {
                        schedulesList.innerHTML = schedules.map(s => {
                            const slotsAvailable = s.capacity - s.enrolled_count;
                            const isFull = slotsAvailable <= 0;
                            const status = s.enrollment_status || 'available';
                            const isEnrolled = status === 'enrolled';
                            const isPassed = status === 'passed';
                            const isFailed = status === 'failed';
                            const statusClass = isEnrolled ? 'is-enrolled' : (isPassed ? 'is-passed' : (isFailed ? 'is-failed' : ''));
                            const statusLabel = isEnrolled ? '<span class="enrollment-status enrolled">Currently Enrolled</span>' : 
                                               (isPassed ? '<span class="enrollment-status passed">Passed</span>' : 
                                               (isFailed ? '<span class="enrollment-status failed">Failed - Retake</span>' : ''));
                            
                            return `
                                <div class="schedule-item ${statusClass}" data-id="${s.schedule_id}" data-status="${status}">
                                    <span class="schedule-id">ID: ${s.schedule_id}${statusLabel}</span>
                                    <div class="course-info"><strong>${s.course_code}</strong> - ${s.course_name} (${s.units} units)</div>
                                    <div class="schedule-details">
                                        ${s.schedule_days || 'TBA'} | Room: ${s.room || 'TBA'} | ${s.teacher_name || 'TBA'}
                                    </div>
                                    <span class="slots ${isFull ? 'full' : ''}">Slots: ${slotsAvailable}/${s.capacity} ${isFull ? '(FULL)' : 'available'}</span>
                                </div>
                            `;
                        }).join('');
                        
                        // Add click handlers (skip enrolled courses, confirm for passed)
                        schedulesList.querySelectorAll('.schedule-item').forEach(item => {
                            item.addEventListener('click', function() {
                                const itemEl = this;
                                const status = itemEl.dataset.status;
                                const scheduleId = itemEl.dataset.id;
                                
                                if (status === 'enrolled') {
                                    showNotification('info', 'Already Enrolled', 'This student is already enrolled in this course for the current term.');
                                    return;
                                }
                                if (status === 'passed') {
                                    showConfirmation('warning', 'Course Already Passed', 
                                        'This student has already passed this course. Are you sure you want to enroll them again?',
                                        'Enroll Anyway',
                                        function() {
                                            scheduleCodeInput.value = scheduleId;
                                            scheduleSuggestionsSection.classList.add('hidden');
                                            toggleScheduleListBtn.textContent = 'Show All Schedules';
                                            lookupSchedule();
                                        }
                                    );
                                    return;
                                }
                                // For available or failed courses, proceed normally
                                scheduleCodeInput.value = scheduleId;
                                scheduleSuggestionsSection.classList.add('hidden');
                                toggleScheduleListBtn.textContent = 'Show All Schedules';
                                lookupSchedule();
                            });
                        });
                    } else {
                        schedulesList.innerHTML = '<span style="color: #dc3545;">No schedules found for this year level and semester.</span>';
                    }
                })
                .catch(() => {
                    schedulesList.innerHTML = '<span style="color: #dc3545;">Error loading schedules.</span>';
                    showEnrollmentApiError('Unable to load schedule suggestions right now.', loadScheduleSuggestions);
                });
        }
        
        // Schedule autocomplete
        scheduleCodeInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (autocompleteTimeout) clearTimeout(autocompleteTimeout);
            
            if (!searchTerm || searchTerm.length < 1 || !currentStudent || !confirmedProgramId) {
                scheduleAutocomplete.classList.add('hidden');
                return;
            }
            
            autocompleteTimeout = setTimeout(() => {
                const autocompleteUrl = `?action=get_schedule_suggestions&program_id=${confirmedProgramId}&year_level=${currentStudent.year_level}&semester=${validatedTermCode.semester}&search=${encodeURIComponent(searchTerm)}&student_id=${currentStudent.id}&academic_year=${encodeURIComponent(validatedTermCode.academicYear)}`;
                
                fetch(autocompleteUrl)
                    .then(async response => {
                        const payload = await response.json();
                        return unwrapEnrollmentApiResponse(response, payload, 'Unable to load schedule suggestions right now.');
                    })
                    .then(data => {
                        const schedules = Array.isArray(data.schedules) ? data.schedules : [];

                        if (schedules.length > 0) {
                            scheduleAutocomplete.innerHTML = schedules.slice(0, 8).map(s => {
                                const slotsAvailable = s.capacity - s.enrolled_count;
                                const status = s.enrollment_status || 'available';
                                const statusBadge = status === 'enrolled' ? ' <span style="color:#004085;font-size:10px;">[ENROLLED]</span>' : 
                                                   (status === 'passed' ? ' <span style="color:#155724;font-size:10px;">[PASSED]</span>' :
                                                   (status === 'failed' ? ' <span style="color:#721c24;font-size:10px;">[FAILED]</span>' : ''));
                                return `
                                    <div class="autocomplete-item" data-id="${s.schedule_id}" data-status="${status}">
                                        <span class="schedule-id">ID: ${s.schedule_id}${statusBadge}</span>
                                        <div class="course-info">${s.course_code} - ${s.course_name}</div>
                                        <div class="schedule-details">${s.schedule_days || 'TBA'} | Slots: ${slotsAvailable}/${s.capacity}</div>
                                    </div>
                                `;
                            }).join('');
                            
                            scheduleAutocomplete.querySelectorAll('.autocomplete-item').forEach(item => {
                                item.addEventListener('click', function() {
                                    const status = this.dataset.status;
                                    if (status === 'enrolled') {
                                        showNotification('info', 'Already Enrolled', 'This student is already enrolled in this course.');
                                        return;
                                    }
                                    scheduleCodeInput.value = this.dataset.id;
                                    scheduleAutocomplete.classList.add('hidden');
                                    lookupSchedule();
                                });
                            });
                            
                            scheduleAutocomplete.classList.remove('hidden');
                        } else {
                            scheduleAutocomplete.classList.add('hidden');
                        }
                    })
                    .catch(() => scheduleAutocomplete.classList.add('hidden'));
            }, 200);
        });
        
        // Hide autocomplete on blur (with delay for click)
        scheduleCodeInput.addEventListener('blur', function() {
            setTimeout(() => scheduleAutocomplete.classList.add('hidden'), 200);
        });
        
        // Term code validation
        termCodeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                validateTermCode();
            }
        });
        
        function validateTermCode() {
            const termCode = termCodeInput.value.trim();
            termError.classList.add('hidden');
            termDisplay.classList.add('hidden');
            
            if (!termCode) {
                showTermError('Term code is required');
                return;
            }
            
            if (!/^\d{3}$/.test(termCode)) {
                showTermError('Term code must be exactly 3 digits');
                return;
            }
            
            const yearPart = parseInt(termCode.substring(0, 2));
            const semesterPart = parseInt(termCode.substring(2, 3));
            const academicYearStart = 2000 + yearPart;
            const academicYear = academicYearStart + '-' + (academicYearStart + 1);
            const isBackEnrollment = academicYearStart < currentAcademicYearStart;
            
            if (semesterPart < 0 || semesterPart > 2) {
                showTermError('Invalid semester digit. Use 0 for summer, 1 for 1st semester, 2 for 2nd semester');
                return;
            }
            
            if (academicYearStart < minAllowedYearStart) {
                const minAcademicYear = minAllowedYearStart + '-' + (minAllowedYearStart + 1);
                showTermError(`Academic year ${academicYear} is too far behind. Minimum allowed is ${minAcademicYear}.`);
                return;
            }
            
            if (academicYearStart > maxAllowedYearStart) {
                const yearsAhead = academicYearStart - currentAcademicYearStart;
                showTermError(`Academic year ${academicYear} is ${yearsAhead} years ahead. Max 3 years advance.`);
                return;
            }
            
            validatedTermCode = { code: termCode, academicYear: academicYear, semester: semesterPart };
            const semesterNames = {0: 'Summer Term', 1: '1st Semester', 2: '2nd Semester'};
            const backEnrollmentNotice = isBackEnrollment
                ? '<br><span style="color: #856404;"><strong>Back-enrollment mode:</strong> enrolling for a prior academic year.</span>'
                : '';
            termDisplay.innerHTML = `<strong>Academic Year:</strong> ${academicYear} | <strong>Term:</strong> ${semesterNames[semesterPart]}${backEnrollmentNotice}`;
            termDisplay.classList.remove('hidden');
            studentSection.classList.remove('hidden');
            studentNumberInput.focus();
            
            // Re-check term mismatch if student is already loaded
            if (currentStudent) {
                checkTermMismatch(currentStudent);
            }
            
            // Load recent students when student section shows
            loadRecentStudents();
        }
        
        function showTermError(msg) {
            termError.textContent = msg;
            termError.classList.remove('hidden');
        }
        
        // Student lookup
        studentNumberInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                lookupStudent();
            }
        });
        
        function lookupStudent() {
            const studentNumber = studentNumberInput.value.trim();
            clearEnrollmentApiError();
            studentError.classList.add('hidden');
            studentInfoDisplay.classList.add('hidden');
            renderRetakeModeNotice(null);
            
            if (!studentNumber) {
                showStudentError('Student number is required');
                return;
            }
            
            fetch(`?action=lookup_student&student_number=${encodeURIComponent(studentNumber)}`)
                .then(async response => {
                    const payload = await response.json();
                    if (!response.ok || !payload || payload.success !== true) {
                        const err = new Error(getEnrollmentApiErrorMessage(payload, 'Error looking up student'));
                        err.status = response.status;
                        throw err;
                    }

                    return payload.data || {};
                })
                .then(data => {
                    if (data.student) {
                        clearEnrollmentApiError();
                        currentStudent = data.student;
                        displayStudentInfo(data.student);
                    } else {
                        showStudentError('Student not found');
                    }
                })
                .catch(error => {
                    const message = error && error.message ? error.message : 'Error looking up student';
                    showStudentError(message);

                    if (!error || typeof error.status !== 'number' || error.status >= 500 || error.status === 0) {
                        showEnrollmentApiError('Unable to look up student details right now.', lookupStudent);
                    }
                });
        }
        
        function showStudentError(msg) {
            studentError.textContent = msg;
            studentError.classList.remove('hidden');
        }
        
        function displayStudentInfo(student) {
            document.getElementById('student_name').value = student.name;
            if (student.program_code) {
                programInput.value = student.program_code;
                programIdField.value = student.program_id;
            }
            
            const yearLevelNames = {1: '1st Year', 2: '2nd Year', 3: '3rd Year', 4: '4th Year'};
            const semesterNames = {0: 'Summer Term', 1: '1st Semester', 2: '2nd Semester'};
            const hasAssignedSemester = student.current_semester !== null && student.current_semester !== '';
            document.getElementById('year_level').value = yearLevelNames[student.year_level] || student.year_level;
            document.getElementById('semester').value = hasAssignedSemester
                ? (semesterNames[student.current_semester] || `Semester ${student.current_semester}`)
                : 'Not set yet';
            
            studentInfoDisplay.classList.remove('hidden');
            renderRetakeModeNotice(student);
            
            // Check for term mismatch
            checkTermMismatch(student);
            
            programInput.focus();
        }
        
        function checkTermMismatch(student) {
            const termMismatchWarning = document.getElementById('termMismatchWarning');
            const termMismatchDetails = document.getElementById('termMismatchDetails');
            const recommendedTermCode = document.getElementById('recommendedTermCode');
            
            if (!validatedTermCode) {
                termMismatchWarning.classList.add('hidden');
                return;
            }

            if (retakeModeActive) {
                termMismatchWarning.classList.add('hidden');
                return;
            }
            
            const termSemester = validatedTermCode.semester;
            const studentSemester = parseInt(student.current_semester);
            const studentYearLevel = parseInt(student.year_level);
            const termCode = validatedTermCode.code;
            const termYearPart = termCode.substring(0, 2);
            
            const semesterNames = {0: 'Summer', 1: '1st Semester', 2: '2nd Semester'};
            
            let issues = [];
            let recommendations = [];

            if (Number.isNaN(studentSemester)) {
                termMismatchWarning.classList.add('hidden');
                return;
            }
            
            // Check semester mismatch
            if (termSemester !== studentSemester) {
                issues.push(`The term code <strong>${termCode}</strong> indicates <strong>${semesterNames[termSemester]}</strong>, but the student is currently in <strong>${semesterNames[studentSemester]}</strong>.`);
                recommendations.push(`semester digit from ${termSemester} to ${studentSemester}`);
            }
            
            if (issues.length > 0) {
                // Build recommended term code
                const newTermCode = termYearPart + studentSemester;
                
                termMismatchDetails.innerHTML = issues.join('<br><br>');
                recommendedTermCode.textContent = newTermCode;
                termMismatchWarning.classList.remove('hidden');
                
                // Disable proceed until fixed
                scheduleSection.classList.add('hidden');
            } else {
                termMismatchWarning.classList.add('hidden');
            }
        }
        
        function useRecommendedTermCode() {
            const recommendedCode = document.getElementById('recommendedTermCode').textContent;
            if (recommendedCode) {
                termCodeInput.value = recommendedCode;
                validateTermCode();
                
                // Re-check with current student
                if (currentStudent) {
                    setTimeout(() => {
                        checkTermMismatch(currentStudent);
                        if (document.getElementById('termMismatchWarning').classList.contains('hidden')) {
                            // No more issues, show schedule section
                            if (confirmedProgramId) {
                                scheduleSection.classList.remove('hidden');
                            }
                        }
                    }, 100);
                }
            }
        }
        
        // Program selection (direct, no confirmation)
        function selectProgram(programId, programCode) {
            confirmedProgramId = parseInt(programId, 10);
            programIdField.value = confirmedProgramId;
            programInput.value = programCode;
            
            // Only show schedule section if no term mismatch
            const termMismatchWarning = document.getElementById('termMismatchWarning');
            if (termMismatchWarning.classList.contains('hidden')) {
                scheduleSection.classList.remove('hidden');
                scheduleCodeInput.focus();
            }
        }
        
        programInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const code = this.value.trim().toUpperCase();
                if (!code) return;
                
                // Find matching program
                const items = programsList.querySelectorAll('.program-item');
                for (const item of items) {
                    if (item.dataset.code.toUpperCase() === code) {
                        selectProgram(item.dataset.id, item.dataset.code);
                        return;
                    }
                }
                showNotification('warning', 'Program Not Found', 'Program code not found. Please select from the list below.');
            }
        });
        
        // Program item click handlers
        programsList.addEventListener('click', function(e) {
            const item = e.target.closest('.program-item');
            if (item) {
                selectProgram(item.dataset.id, item.dataset.code);
            }
        });
        
        // Schedule lookup
        scheduleCodeInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                lookupSchedule();
            }
        });
        
        function lookupSchedule() {
            const scheduleId = scheduleCodeInput.value.trim();
            clearEnrollmentApiError();
            scheduleError.classList.add('hidden');
            scheduleDisplay.classList.add('hidden');
            
            if (!scheduleId) {
                showScheduleError('Schedule code is required');
                return;
            }
            
            fetch(`?action=lookup_schedule&schedule_id=${encodeURIComponent(scheduleId)}`)
                .then(async response => {
                    const payload = await response.json();
                    if (!response.ok || !payload || payload.success !== true) {
                        const err = new Error(getEnrollmentApiErrorMessage(payload, 'Error looking up schedule'));
                        err.status = response.status;
                        throw err;
                    }

                    return payload.data || {};
                })
                .then(data => {
                    if (data.schedule) {
                        clearEnrollmentApiError();
                        currentSchedule = data.schedule;
                        displaySchedule(data.schedule);
                    } else {
                        showScheduleError('Schedule not found');
                    }
                })
                .catch(error => {
                    const message = error && error.message ? error.message : 'Error looking up schedule';
                    showScheduleError(message);

                    if (!error || typeof error.status !== 'number' || error.status >= 500 || error.status === 0) {
                        showEnrollmentApiError('Unable to load schedule details right now.', lookupSchedule);
                    }
                });
        }
        
        function showScheduleError(msg) {
            scheduleError.textContent = msg;
            scheduleError.classList.remove('hidden');
        }
        
        function displaySchedule(schedule) {
            const pricePerUnit = parseFloat(schedule.tuition_per_unit).toFixed(2);
            const isProgramMismatch = schedule.program_id != confirmedProgramId;
            
            scheduleTableBody.innerHTML = `
                <tr>
                    <td>${schedule.day_of_week}</td>
                    <td>${schedule.start_time}</td>
                    <td>${schedule.end_time}</td>
                    <td>${schedule.room}</td>
                    <td>${schedule.units}</td>
                    <td>₱${pricePerUnit}</td>
                </tr>
            `;
            
            let infoHTML = `<strong>${schedule.course_code}</strong> - ${schedule.course_name} | Slots: ${schedule.enrolled_count}/${schedule.capacity}`;
            
            if (isProgramMismatch) {
                infoHTML += `<div class="program-mismatch-warning">This schedule is for <strong>${schedule.program_code}</strong> program and cannot be enrolled for this student.</div>`;
                addSubjectBtn.disabled = true;
                addSubjectBtn.style.opacity = '0.5';
            } else {
                addSubjectBtn.disabled = false;
                addSubjectBtn.style.opacity = '1';
            }
            
            courseInfo.innerHTML = infoHTML;
            scheduleDisplay.classList.remove('hidden');
        }
        
        // Add subject to pending list (no immediate enrollment)
        addSubjectBtn.addEventListener('click', function() {
            if (!currentSchedule) {
                showNotification('warning', 'Schedule Required', 'Please select a schedule first.');
                return;
            }
            if (!currentStudent) {
                showNotification('warning', 'Student Required', 'Please select a student first.');
                return;
            }
            if (!validatedTermCode) {
                showNotification('warning', 'Term Code Required', 'Please enter a valid term code first.');
                return;
            }
            
            // Check if already in pending list
            const alreadyPending = pendingSubjects.some(s => s.schedule_id === currentSchedule.schedule_id);
            if (alreadyPending) {
                showNotification('info', 'Already Added', 'This schedule is already in the pending list.');
                return;
            }
            
            pendingSubjects.push({
                schedule_id: currentSchedule.schedule_id,
                course_code: currentSchedule.course_code,
                course_name: currentSchedule.course_name,
                units: currentSchedule.units,
                day_of_week: currentSchedule.day_of_week,
                time: currentSchedule.start_time + '-' + currentSchedule.end_time
            });
            
            updatePendingList();
            scheduleCodeInput.value = '';
            scheduleDisplay.classList.add('hidden');
            currentSchedule = null;
            scheduleCodeInput.focus();
        });
        
        function updatePendingList() {
            if (pendingSubjects.length === 0) {
                pendingList.classList.add('hidden');
                enrollStudentBtn.disabled = true;
                return;
            }
            pendingItems.innerHTML = pendingSubjects.map((s, index) => `
                <div class="pending-item">
                    <span><strong>${s.course_code}</strong> - ${s.course_name} (${s.units} units)</span>
                    <button type="button" class="remove-btn" data-index="${index}">Remove</button>
                </div>
            `).join('');
            
            // Add remove handlers
            pendingItems.querySelectorAll('.remove-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const idx = parseInt(this.dataset.index);
                    pendingSubjects.splice(idx, 1);
                    updatePendingList();
                });
            });
            
            pendingList.classList.remove('hidden');
            enrollStudentBtn.disabled = false;
        }
        
        // Direct enrollment - skip modal for simplicity
        enrollStudentBtn.addEventListener('click', function() {
            console.log('Enroll button clicked');
            console.log('currentStudent:', currentStudent);
            console.log('confirmedProgramId:', confirmedProgramId);
            console.log('validatedTermCode:', validatedTermCode);
            console.log('pendingSubjects:', pendingSubjects);
            
            if (!currentStudent) {
                showNotification('warning', 'Student Required', 'Please select a student first.');
                return;
            }
            if (!confirmedProgramId) {
                showNotification('warning', 'Program Required', 'Please select a program first.');
                return;
            }
            if (!validatedTermCode) {
                showNotification('warning', 'Term Code Required', 'Please enter a valid term code first.');
                return;
            }
            if (pendingSubjects.length === 0) {
                showNotification('warning', 'No Subjects', 'Please add at least one subject before enrolling.');
                return;
            }
            
            // Show confirmation modal
            const subjectList = pendingSubjects.map(s => s.course_code).join(', ');
            const confirmMsg = `<strong>${currentStudent.name}</strong><br><br>
                <strong>Subjects (${pendingSubjects.length}):</strong> ${subjectList}<br>
                <strong>Term:</strong> ${validatedTermCode.academicYear} (Semester ${validatedTermCode.semester})`;
            
            showConfirmation('info', 'Confirm Enrollment', confirmMsg, 'Enroll Student',
                function() {
                    // Proceed with enrollment
                    processEnrollment();
                }
            );
        });
        
        function processEnrollment() {
            clearEnrollmentApiError();

            // Disable button during request
            enrollStudentBtn.disabled = true;
            enrollStudentBtn.textContent = 'Enrolling...';
            
            const enrollData = {
                student_id: currentStudent.id,
                program_id: confirmedProgramId,
                academic_year: validatedTermCode.academicYear,
                semester: validatedTermCode.semester,
                schedule_ids: pendingSubjects.map(s => s.schedule_id)
            };
            
            console.log('Sending enrollData:', enrollData);
            
            fetch('?action=bulk_enroll', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': enrollmentCsrfToken
                },
                body: JSON.stringify(enrollData)
            })
                .then(async response => {
                    console.log('Response status:', response.status);

                    const payload = await response.json();
                    if (!payload || typeof payload !== 'object') {
                        throw new Error('Invalid server response.');
                    }

                    if (payload.success !== true) {
                        const errorMessage = getEnrollmentApiErrorMessage(payload, 'Unknown error occurred.');

                        if (!response.ok && response.status >= 500) {
                            throw new Error(errorMessage);
                        }

                        showNotification('error', 'Enrollment Failed', errorMessage);
                        return null;
                    }

                    return payload.data || {};
                })
                .then(data => {
                    if (!data) {
                        return;
                    }

                    console.log('Parsed data:', data);

                    clearEnrollmentApiError();

                    // Get enrolled course names for display
                    const enrolledCourses = pendingSubjects.map(s => `${s.course_code} - ${s.course_name}`);
                    const warnings = Array.isArray(data.errors) && data.errors.length > 0 ? data.errors : null;
                    const recordsUrl = buildStudentRecordsUrl(currentStudent ? currentStudent.id : 0);

                    showSuccessModal(data.message || 'Enrollment completed successfully.', enrolledCourses, warnings, recordsUrl);

                    // Reset form
                    pendingSubjects = [];
                    updatePendingList();
                    scheduleCodeInput.value = '';
                    scheduleDisplay.classList.add('hidden');
                })
                .catch(e => {
                    console.error('Enrollment error:', e);
                    showEnrollmentApiError('Error processing enrollment: ' + e.message, processEnrollment);
                })
                .finally(() => {
                    enrollStudentBtn.disabled = false;
                    enrollStudentBtn.textContent = 'Enroll Student';
                });
        }
        
        // Load recent students
        function loadRecentStudents() {
            const recentList = document.getElementById('recentStudentsList');
            clearEnrollmentApiError();
            
            fetch('?action=get_recent_students')
                .then(async response => {
                    const payload = await response.json();
                    return unwrapEnrollmentApiResponse(response, payload, 'Unable to load recent students right now.');
                })
                .then(data => {
                    clearEnrollmentApiError();
                    const students = Array.isArray(data.students) ? data.students : [];

                    if (students.length > 0) {
                        recentList.innerHTML = students.map(s => `
                            <div class="recent-student-item" data-sn="${s.student_number}">
                                <span class="sn">${s.student_number}</span><br>
                                <span class="name">${s.first_name} ${s.last_name}</span>
                            </div>
                        `).join('');
                        
                        // Add click handlers
                        recentList.querySelectorAll('.recent-student-item').forEach(item => {
                            item.addEventListener('click', function() {
                                studentNumberInput.value = this.dataset.sn;
                                lookupStudent();
                            });
                        });
                    } else {
                        recentList.innerHTML = '<span style="color: #6c757d; font-size: 12px;">No recent students found</span>';
                    }
                })
                .catch(() => {
                    recentList.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error loading recent students</span>';
                    showEnrollmentApiError('Unable to load recent students right now.', loadRecentStudents);
                });
        }
        

    </script>

<!-- Success Modal -->
<div class="success-modal-overlay" id="successModal">
    <div class="success-modal">
        <div class="success-modal-header">
            <h3 id="successModalTitle">Enrollment Successful</h3>
        </div>
        <div class="success-modal-body">
            <p id="successModalMessage"></p>
            <div class="success-modal-courses" id="successModalCourses"></div>
            <div class="success-modal-warnings hidden" id="successModalWarnings"></div>
        </div>
        <div class="success-modal-footer">
            <button type="button" class="btn btn-primary" data-enrollment-action="close-success-modal">Done</button>
        </div>
    </div>
</div>

<script>
function showSuccessModal(message, courses, warnings, redirectUrl) {
    const modal = document.getElementById('successModal');
    const msgEl = document.getElementById('successModalMessage');
    const coursesEl = document.getElementById('successModalCourses');
    const warningsEl = document.getElementById('successModalWarnings');
    const closeBtn = modal.querySelector('[data-enrollment-action="close-success-modal"]');

    successModalRedirectUrl = typeof redirectUrl === 'string' ? redirectUrl : '';

    if (successModalRedirectTimer) {
        window.clearTimeout(successModalRedirectTimer);
        successModalRedirectTimer = null;
    }
    
    msgEl.textContent = message;
    
    if (courses && courses.length > 0) {
        coursesEl.innerHTML = courses.map(c => `<div class="course-item">${c}</div>`).join('');
        coursesEl.classList.remove('hidden');
    } else {
        coursesEl.classList.add('hidden');
    }
    
    if (warnings && warnings.length > 0) {
        warningsEl.innerHTML = warnings.join('<br>');
        warningsEl.classList.remove('hidden');
    } else {
        warningsEl.classList.add('hidden');
    }
    
    if (closeBtn) {
        closeBtn.textContent = successModalRedirectUrl ? 'View Student Records' : 'Done';
    }

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    if (successModalRedirectUrl) {
        successModalRedirectTimer = window.setTimeout(() => {
            closeSuccessModal();
        }, 1800);
    }
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';

    if (successModalRedirectTimer) {
        window.clearTimeout(successModalRedirectTimer);
        successModalRedirectTimer = null;
    }

    if (successModalRedirectUrl) {
        const targetUrl = successModalRedirectUrl;
        successModalRedirectUrl = '';
        window.location.href = targetUrl;
    }
}

// Close on overlay click
document.getElementById('successModal').addEventListener('click', function(e) {
    if (e.target === this) closeSuccessModal();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSuccessModal();
        closeNotificationModal();
    }
});
</script>

<!-- Notification Modal -->
<div class="notification-modal-overlay" id="notificationModal">
    <div class="notification-modal">
        <div class="notification-modal-header">
            <div class="modal-icon" id="notificationIcon"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></div>
            <h3 id="notificationTitle">Notice</h3>
        </div>
        <div class="notification-modal-body">
            <p id="notificationMessage"></p>
        </div>
        <div class="notification-modal-footer" id="notificationFooter">
            <button type="button" class="btn btn-primary" data-notification-action="close">OK</button>
        </div>
    </div>
</div>

<script>
let notificationCallback = null;
let notificationConfirmCallback = null;

function showNotification(type, title, message, callback) {
    const modal = document.getElementById('notificationModal');
    const iconEl = document.getElementById('notificationIcon');
    const titleEl = document.getElementById('notificationTitle');
    const msgEl = document.getElementById('notificationMessage');
    const footerEl = document.getElementById('notificationFooter');
    
    notificationCallback = callback || null;
    notificationConfirmCallback = null;
    
    // Set icon based on type
    iconEl.className = 'modal-icon ' + type;
    switch(type) {
        case 'error':
            iconEl.innerHTML = '<i class="bi bi-x-octagon-fill" aria-hidden="true"></i>';
            break;
        case 'warning':
            iconEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>';
            break;
        case 'success':
            iconEl.innerHTML = '<i class="bi bi-check-circle-fill" aria-hidden="true"></i>';
            break;
        default:
            iconEl.innerHTML = '<i class="bi bi-info-circle-fill" aria-hidden="true"></i>';
    }
    
    titleEl.textContent = title;
    msgEl.innerHTML = message;
    footerEl.innerHTML = '<button type="button" class="btn btn-primary" data-notification-action="close">OK</button>';
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function showConfirmation(type, title, message, confirmText, onConfirm, onCancel) {
    const modal = document.getElementById('notificationModal');
    const iconEl = document.getElementById('notificationIcon');
    const titleEl = document.getElementById('notificationTitle');
    const msgEl = document.getElementById('notificationMessage');
    const footerEl = document.getElementById('notificationFooter');
    
    notificationConfirmCallback = onConfirm || null;
    notificationCallback = onCancel || null;
    
    iconEl.className = 'modal-icon ' + type;
    if (type === 'warning') {
        iconEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>';
    } else if (type === 'error') {
        iconEl.innerHTML = '<i class="bi bi-x-octagon-fill" aria-hidden="true"></i>';
    } else {
        iconEl.innerHTML = '<i class="bi bi-question-circle-fill" aria-hidden="true"></i>';
    }
    
    titleEl.textContent = title;
    msgEl.innerHTML = message;
    footerEl.innerHTML = `
        <button type="button" class="btn btn-secondary" data-notification-action="cancel">Cancel</button>
        <button type="button" class="btn btn-primary" data-notification-action="confirm">${confirmText || 'Confirm'}</button>
    `;
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeNotificationModal(confirmed) {
    const modal = document.getElementById('notificationModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    
    if (confirmed === true && notificationConfirmCallback) {
        notificationConfirmCallback();
    } else if (confirmed === false && notificationCallback) {
        notificationCallback();
    } else if (notificationCallback && confirmed === undefined) {
        notificationCallback();
    }
    
    notificationCallback = null;
    notificationConfirmCallback = null;
}

// Close notification on overlay click
document.getElementById('notificationModal').addEventListener('click', function(e) {
    if (e.target === this) closeNotificationModal(false);
});

document.addEventListener('click', function(e) {
    const actionTrigger = e.target.closest('[data-enrollment-action], [data-notification-action]');
    if (!actionTrigger) {
        return;
    }

    const enrollmentAction = actionTrigger.getAttribute('data-enrollment-action');
    if (enrollmentAction === 'use-recommended-term') {
        useRecommendedTermCode();
        return;
    }

    if (enrollmentAction === 'close-success-modal') {
        closeSuccessModal();
        return;
    }

    const notificationAction = actionTrigger.getAttribute('data-notification-action');
    if (notificationAction === 'close') {
        closeNotificationModal();
    } else if (notificationAction === 'cancel') {
        closeNotificationModal(false);
    } else if (notificationAction === 'confirm') {
        closeNotificationModal(true);
    }
});
</script>

</body>
</html>
