<?php
require_once '../config/db_helpers.php';

$conn = getDBConnection();
$message = '';
$message_type = '';

// Current date context: February 24, 2026
// Current academic year is 2025-2026
$current_year = intval(date('Y')); // 2026
$current_month = intval(date('n')); // 2 (February)

// Determine the current academic year start
// Academic years typically start in August, so if we're before August, we're still in the previous academic year
if ($current_month >= 8) {
    $current_academic_year_start = $current_year;
} else {
    $current_academic_year_start = $current_year - 1;
}

// Allow 3 years advance from current academic year
$max_allowed_year_start = $current_academic_year_start + 3;

// Handle AJAX request for recent students
if (isset($_GET['action']) && $_GET['action'] === 'get_recent_students') {
    header('Content-Type: application/json');
    
    $recent_sql = "SELECT s.student_id, s.student_number, s.first_name, s.last_name, 
                          p.program_code
                   FROM students s 
                   LEFT JOIN programs p ON s.program_id = p.program_id 
                   ORDER BY s.created_at DESC 
                   LIMIT 10";
    $recent_result = db_query($conn, $recent_sql);
    $recent_students = db_fetch_all($recent_result);
    
    echo json_encode([
        'success' => true,
        'students' => $recent_students
    ]);
    $conn->close();
    exit;
}

// Handle AJAX request for student lookup
if (isset($_GET['action']) && $_GET['action'] === 'lookup_student') {
    header('Content-Type: application/json');
    $student_number = trim($_GET['student_number'] ?? '');
    
    if (empty($student_number)) {
        echo json_encode(['success' => false, 'error' => 'Student number is required']);
        exit;
    }
    
    $student_sql = "SELECT s.student_id, s.student_number, s.first_name, s.middle_name, s.last_name, 
                           s.program_id, s.year_level, s.current_semester,
                           p.program_code, p.program_name
                    FROM students s 
                    LEFT JOIN programs p ON s.program_id = p.program_id 
                    WHERE s.student_number = ?";
    $student_result = db_query($conn, $student_sql, 's', [$student_number]);
    $student = db_fetch_one($student_result);
    
    if ($student) {
        $full_name = $student['first_name'];
        if (!empty($student['middle_name'])) {
            $full_name .= ' ' . $student['middle_name'];
        }
        $full_name .= ' ' . $student['last_name'];
        
        echo json_encode([
            'success' => true,
            'student' => [
                'id' => $student['student_id'],
                'student_number' => $student['student_number'],
                'name' => $full_name,
                'program_id' => $student['program_id'],
                'program_code' => $student['program_code'],
                'program_name' => $student['program_name'],
                'year_level' => $student['year_level'],
                'current_semester' => $student['current_semester']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
    }
    $conn->close();
    exit;
}

// Handle AJAX request for schedule lookup
if (isset($_GET['action']) && $_GET['action'] === 'lookup_schedule') {
    header('Content-Type: application/json');
    $schedule_id = intval($_GET['schedule_id'] ?? 0);
    
    if ($schedule_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid schedule ID']);
        exit;
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
    $schedule = db_fetch_one($schedule_result);
    
    if ($schedule) {
        echo json_encode([
            'success' => true,
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
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Schedule not found']);
    }
    $conn->close();
    exit;
}

// Handle AJAX request for filtered schedule suggestions
if (isset($_GET['action']) && $_GET['action'] === 'get_schedule_suggestions') {
    header('Content-Type: application/json');
    $program_id = intval($_GET['program_id'] ?? 0);
    $year_level = intval($_GET['year_level'] ?? 0);
    $semester = intval($_GET['semester'] ?? 0);
    $search = trim($_GET['search'] ?? '');
    $student_id = intval($_GET['student_id'] ?? 0);
    $academic_year = trim($_GET['academic_year'] ?? '');
    
    if ($program_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Program is required']);
        exit;
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
            WHERE c.program_id = ? AND c.year_level = ? AND c.semester = ?";
    
    $params = [$program_id, $year_level, $semester];
    $types = 'iii';
    
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
    $schedules = db_fetch_all($result);
    
    // Check enrollment status for each course if student_id is provided
    if ($student_id > 0) {
        // Get ALL courses student is currently enrolled in (any academic year with status 'Enrolled')
        $enrolled_sql = "SELECT DISTINCT e.curriculum_id 
                         FROM enrollments e
                         WHERE e.student_id = ? AND e.status = 'Enrolled'";
        $enrolled_result = db_query($conn, $enrolled_sql, 'i', [$student_id]);
        $enrolled_courses = [];
        if ($enrolled_result && $enrolled_result !== true) {
            while ($row = mysqli_fetch_assoc($enrolled_result)) {
                $enrolled_courses[] = $row['curriculum_id'];
            }
        }
        
        // Get courses already passed (status = 'Passed')
        $passed_sql = "SELECT DISTINCT e.curriculum_id 
                       FROM enrollments e
                       WHERE e.student_id = ? AND e.status = 'Passed'";
        $passed_result = db_query($conn, $passed_sql, 'i', [$student_id]);
        $passed_courses = [];
        if ($passed_result && $passed_result !== true) {
            while ($row = mysqli_fetch_assoc($passed_result)) {
                $passed_courses[] = $row['curriculum_id'];
            }
        }
        
        // Get courses that were failed (status = 'Failed') - needs retake
        $failed_sql = "SELECT DISTINCT e.curriculum_id 
                       FROM enrollments e
                       WHERE e.student_id = ? AND e.status = 'Failed'";
        $failed_result = db_query($conn, $failed_sql, 'i', [$student_id]);
        $failed_courses = [];
        if ($failed_result && $failed_result !== true) {
            while ($row = mysqli_fetch_assoc($failed_result)) {
                $failed_courses[] = $row['curriculum_id'];
            }
        }
        
        // Add status to each schedule
        foreach ($schedules as &$schedule) {
            $curriculum_id = $schedule['curriculum_id'];
            if (in_array($curriculum_id, $enrolled_courses)) {
                $schedule['enrollment_status'] = 'enrolled';
            } elseif (in_array($curriculum_id, $passed_courses)) {
                $schedule['enrollment_status'] = 'passed';
            } elseif (in_array($curriculum_id, $failed_courses)) {
                $schedule['enrollment_status'] = 'failed';
            } else {
                $schedule['enrollment_status'] = 'available';
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'schedules' => $schedules
    ]);
    $conn->close();
    exit;
}

// Handle AJAX request to enroll a subject
if (isset($_GET['action']) && $_GET['action'] === 'enroll_subject') {
    header('Content-Type: application/json');
    $student_id = intval($_GET['student_id'] ?? 0);
    $schedule_id = intval($_GET['schedule_id'] ?? 0);
    $academic_year = trim($_GET['academic_year'] ?? '');
    
    if ($student_id <= 0 || $schedule_id <= 0 || empty($academic_year)) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit;
    }
    
    // Get curriculum_id from schedule
    $sched_info = db_fetch_one(db_query($conn, "SELECT curriculum_id, capacity, enrolled_count FROM schedules WHERE schedule_id = ?", 'i', [$schedule_id]));
    
    if (!$sched_info) {
        echo json_encode(['success' => false, 'error' => 'Schedule not found']);
        exit;
    }
    
    $curriculum_id = $sched_info['curriculum_id'];
    
    // Check capacity
    if ($sched_info['enrolled_count'] >= $sched_info['capacity']) {
        echo json_encode(['success' => false, 'error' => 'This schedule is full']);
        exit;
    }
    
    // Check if already enrolled
    $check_sql = "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND curriculum_id = ? AND academic_year = ?";
    $existing = db_fetch_one(db_query($conn, $check_sql, 'iis', [$student_id, $curriculum_id, $academic_year]));
    
    if ($existing) {
        echo json_encode(['success' => false, 'error' => 'Already enrolled in this course']);
        exit;
    }
    
    // Insert enrollment
    $ins_sql = "INSERT INTO enrollments (student_id, curriculum_id, academic_year, status) VALUES (?, ?, ?, 'Enrolled')";
    if (db_query($conn, $ins_sql, 'iis', [$student_id, $curriculum_id, $academic_year])) {
        // Increment enrolled count
        db_query($conn, "UPDATE schedules SET enrolled_count = enrolled_count + 1 WHERE schedule_id = ?", 'i', [$schedule_id]);
        
        // Get course info for response
        $course_info = db_fetch_one(db_query($conn, "SELECT course_code, course_name FROM curriculum WHERE curriculum_id = ?", 'i', [$curriculum_id]));
        
        echo json_encode([
            'success' => true,
            'message' => 'Successfully enrolled in ' . $course_info['course_code'] . ' - ' . $course_info['course_name']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to enroll']);
    }
    $conn->close();
    exit;
}

// Handle AJAX request for bulk enrollment
if (isset($_GET['action']) && $_GET['action'] === 'bulk_enroll') {
    header('Content-Type: application/json');
    
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $student_id = intval($input['student_id'] ?? 0);
    $program_id = intval($input['program_id'] ?? 0);
    $academic_year = trim($input['academic_year'] ?? '');
    $semester = intval($input['semester'] ?? 0);
    $schedule_ids = $input['schedule_ids'] ?? [];
    
    if ($student_id <= 0 || $program_id <= 0 || empty($academic_year) || empty($schedule_ids)) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit;
    }
    
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
            $sched_info = db_fetch_one(db_query($conn, "SELECT s.curriculum_id, s.capacity, s.enrolled_count, c.course_code, c.course_name FROM schedules s JOIN curriculum c ON s.curriculum_id = c.curriculum_id WHERE s.schedule_id = ?", 'i', [$schedule_id]));
            
            if (!$sched_info) {
                $errors[] = "Schedule $schedule_id not found";
                continue;
            }
            
            // Check capacity
            if ($sched_info['enrolled_count'] >= $sched_info['capacity']) {
                $errors[] = "{$sched_info['course_code']} is full";
                continue;
            }
            
            // Check if already enrolled
            $check_sql = "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND curriculum_id = ? AND academic_year = ?";
            $existing = db_fetch_one(db_query($conn, $check_sql, 'iis', [$student_id, $sched_info['curriculum_id'], $academic_year]));
            
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
            echo json_encode([
                'success' => true,
                'message' => 'Successfully enrolled in ' . count($enrolled_courses) . ' course(s)',
                'enrolled' => $enrolled_courses,
                'errors' => $errors
            ]);
        } else {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'No courses enrolled. ' . implode(', ', $errors)]);
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment</title>
    <link rel="stylesheet" href="../css/common.css">
    <link rel="stylesheet" href="../css/enhancements.css">
    <link rel="stylesheet" href="../css/details.css">
    <script src="../js/app.js" defer></script>
    <style>
        .enrollment-form {
            max-width: 800px;
            margin: 0 auto;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            flex: 1;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #495057;
            font-size: 14px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        .form-group input:disabled,
        .form-group select:disabled {
            background: #e9ecef;
            cursor: not-allowed;
        }
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .form-section h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #343a40;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
        }
        .required { color: #dc3545; }
        .help-text {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        .current-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .hidden { display: none !important; }
        .student-info-section {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
        }
        .student-info-section h4 {
            margin: 0 0 10px 0;
            color: #155724;
            font-size: 14px;
        }
        .inline-error {
            color: #721c24;
            font-size: 13px;
            margin-top: 10px;
            padding: 12px 15px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
        }
        .term-display {
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 13px;
            color: #084298;
        }
        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: #fff;
            padding: 25px;
            border-radius: 10px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #343a40;
        }
        .modal-body {
            margin-bottom: 20px;
            color: #495057;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        /* Schedule table */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }
        .schedule-table th, .schedule-table td {
            padding: 10px 12px;
            text-align: left;
            border: 1px solid #dee2e6;
        }
        .schedule-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        .schedule-table tr:hover {
            background: #f8f9fa;
        }
        /* Pending subjects list */
        .pending-list {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
        }
        .pending-list h4 {
            margin: 0 0 10px 0;
            color: #856404;
            font-size: 14px;
        }
        .pending-item {
            padding: 8px 12px;
            border-bottom: 1px solid #ffe69c;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        .pending-item:last-child { margin-bottom: 0; }
        .pending-item .remove-btn {
            background: #dc3545;
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .pending-item .remove-btn:hover {
            background: #c82333;
        }
        .enroll-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        .btn-enroll-student {
            background: #007bff;
            color: #fff;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
        }
        .btn-enroll-student:hover {
            background: #0056b3;
        }
        .btn-enroll-student:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        /* Confirmation modal */
        .confirm-modal {
            max-width: 600px;
        }
        .confirm-section {
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .confirm-section label {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 5px;
        }
        .confirm-section .value {
            font-size: 16px;
            color: #212529;
        }
        .confirm-subjects {
            max-height: 200px;
            overflow-y: auto;
        }
        .confirm-subject-item {
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .confirm-subject-item:last-child { border-bottom: none; }
        .schedule-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .btn-add-subject {
            background: #28a745;
            color: #fff;
        }
        .btn-add-subject:hover {
            background: #218838;
        }
        .btn-toggle {
            background: #6c757d;
            color: #fff;
        }
        .btn-toggle:hover {
            background: #5a6268;
        }
        /* Autocomplete dropdown */
        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .autocomplete-item:hover {
            background: #e7f3ff;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item .schedule-id {
            font-weight: 600;
            color: #0066cc;
        }
        .autocomplete-item .course-info {
            font-size: 13px;
            color: #495057;
        }
        .autocomplete-item .schedule-details {
            font-size: 12px;
            color: #6c757d;
            margin-top: 3px;
        }
        /* Schedule suggestions section */
        .schedule-suggestions-section {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .schedules-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .schedule-item {
            padding: 12px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .schedule-item:hover {
            background: #e7f3ff;
            border-color: #b6d4fe;
        }
        .schedule-item .schedule-id {
            font-weight: 600;
            color: #0066cc;
            font-size: 14px;
        }
        .schedule-item .course-info {
            font-size: 14px;
            color: #212529;
            margin: 4px 0;
        }
        .schedule-item .schedule-details {
            font-size: 12px;
            color: #6c757d;
        }
        .schedule-item .slots {
            font-size: 12px;
            color: #28a745;
            font-weight: 500;
        }
        .schedule-item .slots.full {
            color: #dc3545;
        }
        
        /* Enrollment Status Labels */
        .schedule-item .enrollment-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .schedule-item .enrollment-status.enrolled {
            background: #cce5ff;
            color: #004085;
        }
        .schedule-item .enrollment-status.passed {
            background: #d4edda;
            color: #155724;
        }
        .schedule-item .enrollment-status.failed {
            background: #f8d7da;
            color: #721c24;
        }
        .schedule-item.is-enrolled {
            opacity: 0.7;
            border-left: 3px solid #007bff;
        }
        .schedule-item.is-passed {
            opacity: 0.6;
            border-left: 3px solid #28a745;
        }
        .schedule-item.is-failed {
            border-left: 3px solid #dc3545;
        }
        
        /* Notification Modal */
        .notification-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }
        .notification-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .notification-modal {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-width: 380px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            transform: translateY(-20px) scale(0.95);
            transition: transform 0.2s ease;
        }
        .notification-modal-overlay.active .notification-modal {
            transform: translateY(0) scale(1);
        }
        .notification-modal-header {
            padding: 20px 20px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .notification-modal-header .modal-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .notification-modal-header .modal-icon.info {
            background: #cce5ff;
            color: #004085;
        }
        .notification-modal-header .modal-icon.warning {
            background: #fff3cd;
            color: #856404;
        }
        .notification-modal-header .modal-icon.error {
            background: #f8d7da;
            color: #721c24;
        }
        .notification-modal-header .modal-icon.success {
            background: #d4edda;
            color: #155724;
        }
        .notification-modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #212529;
        }
        .notification-modal-body {
            padding: 16px 20px 20px;
        }
        .notification-modal-body p {
            margin: 0;
            font-size: 14px;
            color: #495057;
            line-height: 1.6;
        }
        .notification-modal-footer {
            padding: 0 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .notification-modal-footer .btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
        }
        .notification-modal-footer .btn-secondary {
            background: #e9ecef;
            color: #495057;
        }
        .notification-modal-footer .btn-secondary:hover {
            background: #dee2e6;
        }
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
        }
        .program-mismatch-warning {
            background: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 13px;
        }
        
        /* Term Mismatch Warning */
        .term-mismatch-warning {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
        }
        .term-mismatch-warning .warning-title {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .term-mismatch-warning .warning-details {
            margin-bottom: 10px;
            line-height: 1.6;
        }
        .term-mismatch-warning .warning-recommendation {
            background: #fff;
            padding: 10px 12px;
            border-radius: 4px;
            margin-top: 8px;
        }
        .term-mismatch-warning .recommended-code {
            font-weight: 600;
            color: #155724;
            font-family: monospace;
            font-size: 16px;
        }
        .term-mismatch-warning .btn-use-recommended {
            margin-top: 10px;
            background: #155724;
            color: #fff;
            padding: 8px 16px;
            font-size: 13px;
        }
        .term-mismatch-warning .btn-use-recommended:hover {
            background: #0d3d17;
        }
        /* Recent students */
        .recent-students-section {
            margin-top: 15px;
            padding: 15px;
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            border-radius: 6px;
        }
        .recent-students-section label {
            display: block;
            font-size: 13px;
            color: #084298;
            margin-bottom: 10px;
            font-weight: 500;
        }
        .recent-students-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .recent-student-item {
            padding: 8px 12px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .recent-student-item:hover {
            background: #0066cc;
            color: #fff;
            border-color: #0066cc;
        }
        .recent-student-item .sn {
            font-weight: 600;
        }
        .recent-student-item .name {
            color: #6c757d;
            font-size: 12px;
        }
        .recent-student-item:hover .name {
            color: #e7f3ff;
        }
        /* Programs section */
        .programs-section {
            margin-top: 15px;
            padding: 15px;
            background: #f0fff4;
            border: 1px solid #c3e6cb;
            border-radius: 6px;
        }
        .programs-section label {
            display: block;
            font-size: 13px;
            color: #155724;
            margin-bottom: 10px;
            font-weight: 500;
        }
        .programs-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .program-item {
            padding: 10px 12px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .program-item:hover {
            background: #28a745;
            color: #fff;
            border-color: #28a745;
        }
        
        /* Success Modal */
        .success-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }
        .success-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .success-modal {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            transform: translateY(-20px) scale(0.95);
            transition: transform 0.2s ease;
        }
        .success-modal-overlay.active .success-modal {
            transform: translateY(0) scale(1);
        }
        .success-modal-header {
            padding: 24px 24px 0;
        }
        .success-modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #155724;
        }
        .success-modal-body {
            padding: 16px 24px 24px;
        }
        .success-modal-body p {
            margin: 0 0 12px;
            font-size: 15px;
            color: #495057;
            line-height: 1.5;
        }
        .success-modal-body p:last-child {
            margin-bottom: 0;
        }
        .success-modal-courses {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
            max-height: 150px;
            overflow-y: auto;
        }
        .success-modal-courses .course-item {
            padding: 6px 0;
            font-size: 14px;
            color: #212529;
            border-bottom: 1px solid #e9ecef;
        }
        .success-modal-courses .course-item:last-child {
            border-bottom: none;
        }
        .success-modal-warnings {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
            font-size: 13px;
            color: #856404;
        }
        .success-modal-footer {
            padding: 16px 24px 24px;
            display: flex;
            justify-content: flex-end;
        }
        .success-modal-footer .btn {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Enrollment</h1>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-back">← Back to Student List</a>
            </div>
        </header>

        <div class="student-details">
            <div class="enrollment-form" id="enrollmentForm">
                <div class="current-info">
                    <strong>Current Academic Year:</strong> <?php echo $current_academic_year_start . '-' . ($current_academic_year_start + 1); ?> | 
                    <strong>Allowed Range:</strong> <?php echo $current_academic_year_start . '-' . ($current_academic_year_start + 1); ?> to <?php echo $max_allowed_year_start . '-' . ($max_allowed_year_start + 1); ?>
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
                                <button type="button" class="btn btn-use-recommended" onclick="useRecommendedTermCode()">Use Recommended Code</button>
                            </div>
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
        const maxAllowedYearStart = <?php echo $max_allowed_year_start; ?>;
        
        let validatedTermCode = null;
        let currentStudent = null;
        let confirmedProgramId = null;
        let currentSchedule = null;
        // Rename to pendingSubjects for clarity - subjects queued for enrollment
        let pendingSubjects = [];
        
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
        
        let autocompleteTimeout = null;
        
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
            
            filterYearLevel.textContent = currentStudent.year_level;
            filterSemester.textContent = validatedTermCode.semester === 0 ? 'Summer' : (validatedTermCode.semester === 1 ? '1st' : '2nd');
            schedulesList.innerHTML = '<span style="color: #6c757d;">Loading...</span>';
            
            const apiUrl = `?action=get_schedule_suggestions&program_id=${confirmedProgramId}&year_level=${currentStudent.year_level}&semester=${validatedTermCode.semester}&student_id=${currentStudent.id}&academic_year=${encodeURIComponent(validatedTermCode.academicYear)}`;
            
            fetch(apiUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.schedules.length > 0) {
                        schedulesList.innerHTML = data.schedules.map(s => {
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
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.schedules.length > 0) {
                            scheduleAutocomplete.innerHTML = data.schedules.slice(0, 8).map(s => {
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
            
            if (semesterPart < 0 || semesterPart > 2) {
                showTermError('Invalid semester digit. Use 0 for summer, 1 for 1st semester, 2 for 2nd semester');
                return;
            }
            
            if (academicYearStart < currentAcademicYearStart) {
                const yearsBehind = currentAcademicYearStart - academicYearStart;
                showTermError(`Academic year ${academicYear} is ${yearsBehind} year(s) behind current.`);
                return;
            }
            
            if (academicYearStart > maxAllowedYearStart) {
                const yearsAhead = academicYearStart - currentAcademicYearStart;
                showTermError(`Academic year ${academicYear} is ${yearsAhead} years ahead. Max 3 years advance.`);
                return;
            }
            
            validatedTermCode = { code: termCode, academicYear: academicYear, semester: semesterPart };
            const semesterNames = {0: 'Summer Term', 1: '1st Semester', 2: '2nd Semester'};
            termDisplay.innerHTML = `<strong>Academic Year:</strong> ${academicYear} | <strong>Term:</strong> ${semesterNames[semesterPart]}`;
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
            studentError.classList.add('hidden');
            studentInfoDisplay.classList.add('hidden');
            
            if (!studentNumber) {
                showStudentError('Student number is required');
                return;
            }
            
            fetch(`?action=lookup_student&student_number=${encodeURIComponent(studentNumber)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentStudent = data.student;
                        displayStudentInfo(data.student);
                    } else {
                        showStudentError(data.error || 'Student not found');
                    }
                })
                .catch(() => showStudentError('Error looking up student'));
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
            document.getElementById('year_level').value = yearLevelNames[student.year_level] || student.year_level;
            document.getElementById('semester').value = semesterNames[student.current_semester];
            
            studentInfoDisplay.classList.remove('hidden');
            
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
            
            const termSemester = validatedTermCode.semester;
            const studentSemester = parseInt(student.current_semester);
            const studentYearLevel = parseInt(student.year_level);
            const termCode = validatedTermCode.code;
            const termYearPart = termCode.substring(0, 2);
            
            const semesterNames = {0: 'Summer', 1: '1st Semester', 2: '2nd Semester'};
            
            let issues = [];
            let recommendations = [];
            
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
            scheduleError.classList.add('hidden');
            scheduleDisplay.classList.add('hidden');
            
            if (!scheduleId) {
                showScheduleError('Schedule code is required');
                return;
            }
            
            fetch(`?action=lookup_schedule&schedule_id=${encodeURIComponent(scheduleId)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentSchedule = data.schedule;
                        displaySchedule(data.schedule);
                    } else {
                        showScheduleError(data.error || 'Schedule not found');
                    }
                })
                .catch(() => showScheduleError('Error looking up schedule'));
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
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(enrollData)
            })
                .then(r => {
                    console.log('Response status:', r.status);
                    if (!r.ok) throw new Error('Server returned ' + r.status);
                    return r.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                    }
                })
                .then(data => {
                    console.log('Parsed data:', data);
                    if (data.success) {
                        // Get enrolled course names for display
                        const enrolledCourses = pendingSubjects.map(s => `${s.course_code} - ${s.course_name}`);
                        const warnings = data.errors && data.errors.length > 0 ? data.errors : null;
                        
                        showSuccessModal(data.message, enrolledCourses, warnings);
                        
                        // Reset form
                        pendingSubjects = [];
                        updatePendingList();
                        scheduleCodeInput.value = '';
                        scheduleDisplay.classList.add('hidden');
                    } else {
                        showNotification('error', 'Enrollment Failed', data.error || 'Unknown error occurred.');
                    }
                })
                .catch(e => {
                    console.error('Enrollment error:', e);
                    showNotification('error', 'Enrollment Error', 'Error processing enrollment: ' + e.message);
                })
                .finally(() => {
                    enrollStudentBtn.disabled = false;
                    enrollStudentBtn.textContent = 'Enroll Student';
                });
        }
        
        // Load recent students
        function loadRecentStudents() {
            const recentList = document.getElementById('recentStudentsList');
            
            fetch('?action=get_recent_students')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.students.length > 0) {
                        recentList.innerHTML = data.students.map(s => `
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
            <button type="button" class="btn btn-primary" onclick="closeSuccessModal()">Done</button>
        </div>
    </div>
</div>

<script>
function showSuccessModal(message, courses, warnings) {
    const modal = document.getElementById('successModal');
    const msgEl = document.getElementById('successModalMessage');
    const coursesEl = document.getElementById('successModalCourses');
    const warningsEl = document.getElementById('successModalWarnings');
    
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
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
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
            <div class="modal-icon" id="notificationIcon">!</div>
            <h3 id="notificationTitle">Notice</h3>
        </div>
        <div class="notification-modal-body">
            <p id="notificationMessage"></p>
        </div>
        <div class="notification-modal-footer" id="notificationFooter">
            <button type="button" class="btn btn-primary" onclick="closeNotificationModal()">OK</button>
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
            iconEl.textContent = '✕';
            break;
        case 'warning':
            iconEl.textContent = '!';
            break;
        case 'success':
            iconEl.textContent = '✓';
            break;
        default:
            iconEl.textContent = 'i';
    }
    
    titleEl.textContent = title;
    msgEl.innerHTML = message;
    footerEl.innerHTML = '<button type="button" class="btn btn-primary" onclick="closeNotificationModal()">OK</button>';
    
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
    iconEl.textContent = type === 'warning' ? '?' : '!';
    
    titleEl.textContent = title;
    msgEl.innerHTML = message;
    footerEl.innerHTML = `
        <button type="button" class="btn btn-secondary" onclick="closeNotificationModal(false)">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="closeNotificationModal(true)">${confirmText || 'Confirm'}</button>
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
</script>

</body>
</html>
