<?php
require_once '../config/db_helpers.php';
require_once '../config/csrf_helpers.php';
require_once '../config/academic_helpers.php';
require_once '../config/api_auth_helpers.php';
require_once '../config/sidebar.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['schedule', 'grades', 'academic']) ? $_GET['tab'] : 'schedule';

$conn = getDBConnection();
$message = '';
$message_type = '';
$csrf_scope = 'student_schedule_grades_' . $student_id;
csrf_ensure_session();
$api_access_token = api_auth_issue_token();

$submission_token_key = 'student_schedule_grades_submission_tokens';
$submission_token_ttl_seconds = 3600;
if (!isset($_SESSION[$submission_token_key]) || !is_array($_SESSION[$submission_token_key])) {
    $_SESSION[$submission_token_key] = [];
}

$submission_now = time();
foreach ($_SESSION[$submission_token_key] as $token_value => $token_created_at) {
    if (!is_int($token_created_at) || ($submission_now - $token_created_at) > $submission_token_ttl_seconds) {
        unset($_SESSION[$submission_token_key][$token_value]);
    }
}

try {
    $student_records_submission_token = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $student_records_submission_token = hash('sha256', uniqid('student_records_', true));
}
$_SESSION[$submission_token_key][$student_records_submission_token] = $submission_now;

// --- HANDLE ENROLLMENT ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $post_student_id = intval($_POST['student_id']);
    $submitted_submission_token = trim((string)($_POST['submission_token'] ?? ''));
    
    if ($submitted_submission_token === '' || !isset($_SESSION[$submission_token_key][$submitted_submission_token])) {
        $message = 'This records update request was already submitted or expired. Please reload and try again.';
        $message_type = 'error';
    } else {
        unset($_SESSION[$submission_token_key][$submitted_submission_token]);

    if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
        $message = 'Invalid or expired security token. Please refresh and try again.';
        $message_type = 'error';
    } elseif ($post_student_id === $student_id) { // Security check
        if ($action === 'drop' && isset($_POST['curriculum_id'])) {
            $curriculum_id_to_drop = intval($_POST['curriculum_id']);

            if (!$conn->begin_transaction()) {
                $message = 'Unable to start drop transaction. Please try again.';
                $message_type = 'error';
            } else {
                try {
                    if (!db_query($conn, "UPDATE schedules SET enrolled_count = GREATEST(0, enrolled_count - 1) WHERE curriculum_id = ?", 'i', [$curriculum_id_to_drop])) {
                        throw new Exception('Failed to decrement schedule count');
                    }

                    $del_sql = "DELETE FROM enrollments WHERE student_id = ? AND curriculum_id = ?";
                    if (!db_query($conn, $del_sql, 'ii', [$student_id, $curriculum_id_to_drop])) {
                        throw new Exception('Failed to delete enrollment');
                    }

                    $conn->commit();
                    $message = 'Course dropped successfully.';
                    $message_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to drop course.';
                    $message_type = 'error';
                }
            }
        } elseif ($action === 'enroll' && isset($_POST['curriculum_id'])) {
            $curriculum_id_to_add = intval($_POST['curriculum_id']);
            $academic_year_add = $_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
            
            // Check if already enrolled
            $check_sql = "SELECT enrollment_id FROM enrollments WHERE student_id = ? AND curriculum_id = ?";
            $res = db_query($conn, $check_sql, 'ii', [$student_id, $curriculum_id_to_add]);
            
            if ($res && $res->num_rows > 0) {
                $message = 'Already enrolled in this course.';
                $message_type = 'error';
            } else {
                // Check conflicts & Capacity
                // 1. Get schedule of new course
                $new_sched_sql = "SELECT schedule_id, day_of_week, start_time, end_time, capacity, enrolled_count FROM schedules WHERE curriculum_id = ?";
                $new_scheds = db_fetch_all(db_query($conn, $new_sched_sql, 'i', [$curriculum_id_to_add]));
                
                // --- VALIDATION: Year Level & Prerequisites ---
                     
                // Get Course Details (include semester for schedule conflict checking)
                $c_info_sql = "SELECT year_level, course_code, prerequisite_id, semester FROM curriculum WHERE curriculum_id = ?";
                $c_info = db_fetch_one(db_query($conn, $c_info_sql, 'i', [$curriculum_id_to_add]));
                
                $block_enrollment = false;
                
                // 1. Year Level Check
                // Get Student Year Level (Refresh from DB to be safe)
                $s_info = db_fetch_one(db_query($conn, "SELECT year_level FROM students WHERE student_id = ?", 'i', [$student_id]));
                $student_year = intval($s_info['year_level']);
                
                if ($c_info['year_level'] > $student_year) {
                    $block_enrollment = true;
                    $message = "Cannot enroll in " . $c_info['course_code'] . " (Year " . $c_info['year_level'] . "). You are Year $student_year.";
                    $message_type = 'error';
                }
                
                // 2. Prerequisite Check
                if (!$block_enrollment && !empty($c_info['prerequisite_id'])) {
                    $prereq_id = $c_info['prerequisite_id'];
                    // Check if passed
                    $pre_sql = "SELECT status, final_grade FROM enrollments WHERE student_id = ? AND curriculum_id = ?";
                    $pre_chk = db_fetch_one(db_query($conn, $pre_sql, 'ii', [$student_id, $prereq_id]));
                    
                    // Logic: Must exist AND (Status='Passed' OR (Grade <= 3.0 and Grade > 0))
                    // Simplified: Just Status='Passed' if system maintains it correctly.
                    // If system relies on Grade: 
                    $passed = false;
                    if ($pre_chk) {
                        if ($pre_chk['status'] === 'Passed') $passed = true;
                        elseif ($pre_chk['final_grade'] > 0 && $pre_chk['final_grade'] <= 3.0) $passed = true;
                    }
                    
                    if (!$passed) {
                        // Get Prereq Name
                        $p_name = db_fetch_one(db_query($conn, "SELECT course_code FROM curriculum WHERE curriculum_id = ?", 'i', [$prereq_id]))['course_code'];
                        $block_enrollment = true;
                        $message = "Prerequisite required: You must pass $p_name before enrolling in " . $c_info['course_code'];
                        $message_type = 'error';
                    }
                }

                $conflict = false;
                if (!$block_enrollment) {
                    // Check Capacity
                    foreach ($new_scheds as $ns) {
                        if ($ns['enrolled_count'] >= $ns['capacity']) {
                            $conflict = true;
                            $message = "Course is full (" . $ns['enrolled_count'] . "/" . $ns['capacity'] . ").";
                            $message_type = 'error';
                            break;
                        }
                    }

                    if (!$conflict) {
                        // 2. Get current schedules (Restricted to same Academic Year AND Semester)
                        $curr_sched_sql = "SELECT s.day_of_week, s.start_time, s.end_time 
                                       FROM enrollments e 
                                       JOIN schedules s ON e.curriculum_id = s.curriculum_id 
                                       JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                       WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? AND e.status = 'Enrolled'";
                        $target_sem = $c_info['semester'];
                        $curr_scheds = db_fetch_all(db_query($conn, $curr_sched_sql, 'isi', [$student_id, $academic_year_add, $target_sem]));
    
                        foreach ($new_scheds as $ns) {
                            foreach ($curr_scheds as $cs) {
                                if ($ns['day_of_week'] === $cs['day_of_week']) {
                                    if ($ns['start_time'] < $cs['end_time'] && $cs['start_time'] < $ns['end_time']) {
                                        $conflict = true;
                                        $message = "Schedule conflict detected on " . $ns['day_of_week'];
                                        $message_type = 'error';
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $conflict = true; // Use conflict flag to skip insertion
                }
                
                if (!$conflict) {
                    if (!$conn->begin_transaction()) {
                        $message = 'Unable to start enrollment transaction. Please try again.';
                        $message_type = 'error';
                    } else {
                        try {
                            $ins_sql = "INSERT INTO enrollments (student_id, curriculum_id, academic_year, status) VALUES (?, ?, ?, 'Enrolled')";
                            if (!db_query($conn, $ins_sql, 'iis', [$student_id, $curriculum_id_to_add, $academic_year_add])) {
                                throw new Exception('Failed to insert enrollment');
                            }

                            if (!db_query($conn, "UPDATE schedules SET enrolled_count = enrolled_count + 1 WHERE curriculum_id = ?", 'i', [$curriculum_id_to_add])) {
                                throw new Exception('Failed to increment schedule count');
                            }

                            $target_yl = intval($c_info['year_level']);
                            $target_sem = intval($c_info['semester']);
                            $chk_status = db_query($conn, "SELECT status_id FROM semester_status WHERE student_id = ? AND academic_year = ? AND semester = ?", 'isi', [$student_id, $academic_year_add, $target_sem]);
                            if ($chk_status === false) {
                                throw new Exception('Failed to check semester status');
                            }

                            if ($chk_status->num_rows == 0) {
                                $ins_status = "INSERT INTO semester_status (student_id, year_level, semester, academic_year, status) VALUES (?, ?, ?, ?, 'In Progress')";
                                if (!db_query($conn, $ins_status, 'iiis', [$student_id, $target_yl, $target_sem, $academic_year_add])) {
                                    throw new Exception('Failed to create semester status');
                                }
                            } else {
                                if (!db_query($conn, "UPDATE semester_status SET status = 'In Progress', updated_at = NOW() WHERE student_id = ? AND academic_year = ? AND semester = ? AND status != 'In Progress'", 'isi', [$student_id, $academic_year_add, $target_sem])) {
                                    throw new Exception('Failed to update semester status');
                                }
                            }

                            $conn->commit();
                            $message = 'Enrolled successfully.';
                            $message_type = 'success';
                        } catch (Exception $e) {
                            $conn->rollback();
                            $message = 'Failed to enroll.';
                            $message_type = 'error';
                        }
                    }
                }
            }
        } elseif ($action === 'enroll_all' && isset($_POST['academic_year'])) {
            $academic_year_add = $_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
            $selected_year_all = isset($_POST['year']) ? intval($_POST['year']) : 0;
            $selected_sem_all = isset($_POST['semester']) ? intval($_POST['semester']) : 0;
            $program_id_all = intval($_POST['program_id']);

            if ($selected_year_all > 0 && $selected_sem_all > 0 && $program_id_all > 0) {
                 // Fetch available courses (re-query for safety)
                $avail_sql = "SELECT c.* 
                              FROM curriculum c
                              WHERE c.program_id = ? 
                              AND c.year_level = ? 
                              AND c.semester = ?
                              AND c.curriculum_id NOT IN (
                                  SELECT curriculum_id FROM enrollments 
                                  WHERE student_id = ?
                              )
                              ORDER BY c.course_code";
                $avail_courses = db_fetch_all(db_query($conn, $avail_sql, 'iiii', [$program_id_all, $selected_year_all, $selected_sem_all, $student_id]));
                
                $enrolled_count = 0;
                $failed_count = 0;
                $reasons = [];
                $db_error = false;
                $existing_sched_by_sem = [];

                if (!$conn->begin_transaction()) {
                    $message = 'Unable to start batch enrollment transaction. Please try again.';
                    $message_type = 'error';
                } else {
                    foreach ($avail_courses as $ac) {
                        $cid = $ac['curriculum_id'];
                        $block_enrollment = false;
                        $fail_reason = "";

                        // 1. Check Prerequisite
                        if (!empty($ac['prerequisite_id'])) {
                            $pre_sql = "SELECT status, final_grade FROM enrollments WHERE student_id = ? AND curriculum_id = ?";
                            $pre_res = db_query($conn, $pre_sql, 'ii', [$student_id, $ac['prerequisite_id']]);
                            if ($pre_res === false) {
                                $db_error = true;
                                break;
                            }
                            $pre_chk = db_fetch_one($pre_res);
                            $passed = false;
                            if ($pre_chk && ($pre_chk['status'] === 'Passed' || ($pre_chk['final_grade'] > 0 && $pre_chk['final_grade'] <= 3.0))) {
                                 $passed = true;
                            }
                            if (!$passed) {
                                $block_enrollment = true;
                                $fail_reason = "Prereq missing";
                            }
                        }

                        // 2. Check Capacity & Conflict (Simplified for bulk: skip checks? No, must check)
                        // If we want to be strict, we check collisions. 
                        // For now, let's assume we proceed unless Schedule Full or Prereq fail.
                        // Conflict checking in bulk is complex because enrolling in Course A might conflict with Course B in the same batch.
                        // Simple approach: Check against *currently enrolled*, and as we iterate, we don't check against *batch items* (limitation)
                        // Or precise approach: Update scheds list as we go.
                        
                        if (!$block_enrollment) {
                            $new_sched_sql = "SELECT schedule_id, day_of_week, start_time, end_time, capacity, enrolled_count FROM schedules WHERE curriculum_id = ?";
                            $new_sched_res = db_query($conn, $new_sched_sql, 'i', [$cid]);
                            if ($new_sched_res === false) {
                                $db_error = true;
                                break;
                            }
                            $new_scheds = db_fetch_all($new_sched_res);
                            
                            // Check Full
                            foreach ($new_scheds as $ns) {
                                if ($ns['enrolled_count'] >= $ns['capacity']) {
                                    $block_enrollment = true;
                                    $fail_reason = "Full";
                                    break;
                                }
                            }
                            
                            // Check Conflict (against DB enrollments only)
                            if (!$block_enrollment) {
                                $target_sem = intval($ac['semester']);
                                if (!isset($existing_sched_by_sem[$target_sem])) {
                                    $curr_sched_sql = "SELECT s.day_of_week, s.start_time, s.end_time 
                                           FROM enrollments e 
                                           JOIN schedules s ON e.curriculum_id = s.curriculum_id 
                                           JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                           WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? AND e.status = 'Enrolled'";
                                    $curr_sched_res = db_query($conn, $curr_sched_sql, 'isi', [$student_id, $academic_year_add, $target_sem]);
                                    if ($curr_sched_res === false) {
                                        $db_error = true;
                                        break;
                                    }
                                    $existing_sched_by_sem[$target_sem] = db_fetch_all($curr_sched_res);
                                }
                                $curr_scheds = $existing_sched_by_sem[$target_sem];
                                
                                foreach ($new_scheds as $ns) {
                                    foreach ($curr_scheds as $cs) {
                                        if ($ns['day_of_week'] === $cs['day_of_week']) {
                                            if ($ns['start_time'] < $cs['end_time'] && $cs['start_time'] < $ns['end_time']) {
                                                $block_enrollment = true;
                                                $fail_reason = "Conflict";
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        if ($db_error) {
                            break;
                        }

                        if (!$block_enrollment) {
                            $ins_sql = "INSERT INTO enrollments (student_id, curriculum_id, academic_year, status) VALUES (?, ?, ?, 'Enrolled')";
                            if (db_query($conn, $ins_sql, 'iis', [$student_id, $cid, $academic_year_add])) {
                                if (!db_query($conn, "UPDATE schedules SET enrolled_count = enrolled_count + 1 WHERE curriculum_id = ?", 'i', [$cid])) {
                                    $db_error = true;
                                    break;
                                }
                                $enrolled_count++;

                                // Ensure semester_status exists
                                 $chk_status = db_query($conn, "SELECT status_id FROM semester_status WHERE student_id = ? AND academic_year = ? AND semester = ?", 'isi', [$student_id, $academic_year_add, $selected_sem_all]);
                                if ($chk_status === false) {
                                    $db_error = true;
                                    break;
                                }
                                if ($chk_status->num_rows == 0) {
                                     $ins_status = "INSERT INTO semester_status (student_id, year_level, semester, academic_year, status) VALUES (?, ?, ?, ?, 'In Progress')";
                                     if (!db_query($conn, $ins_status, 'iiis', [$student_id, $selected_year_all, $selected_sem_all, $academic_year_add])) {
                                        $db_error = true;
                                        break;
                                     }
                                } else {
                                     if (!db_query($conn, "UPDATE semester_status SET status = 'In Progress', updated_at = NOW() WHERE student_id = ? AND academic_year = ? AND semester = ? AND status != 'In Progress'", 'isi', [$student_id, $academic_year_add, $selected_sem_all])) {
                                        $db_error = true;
                                        break;
                                     }
                                }

                                foreach ($new_scheds as $ns) {
                                    $existing_sched_by_sem[intval($ac['semester'])][] = [
                                        'day_of_week' => $ns['day_of_week'],
                                        'start_time' => $ns['start_time'],
                                        'end_time' => $ns['end_time'],
                                    ];
                                }
                            } else {
                                $failed_count++;
                            }
                        } else {
                            $failed_count++;
                            $reasons[] = $ac['course_code'] . " ($fail_reason)";
                        }
                    }
                    
                    if ($db_error) {
                        $conn->rollback();
                        $message = 'Batch enrollment failed due to a database error. No changes were saved.';
                        $message_type = 'error';
                    } else {
                        $conn->commit();

                        if ($enrolled_count > 0) {
                            $message = "Batch Enrollment: Successfully enrolled in $enrolled_count courses.";
                            $message_type = 'success';
                            if ($failed_count > 0) {
                                $message .= " ($failed_count failed: " . implode(', ', array_slice($reasons, 0, 3)) . (count($reasons)>3 ? '...' : '') . ")";
                            }
                        } else {
                            $message = "No courses enrolled. " . implode(', ', $reasons);
                            $message_type = 'error';
                        }
                    }
                }

            } else {
                $message = "Invalid parameters for batch enrollment.";
                $message_type = 'error';
            }
        }
        elseif ($action === 'drop_all' && isset($_POST['year']) && isset($_POST['semester'])) {
            $target_year = intval($_POST['year']);
            $target_sem = intval($_POST['semester']);
            $target_ay = $_POST['academic_year'] ?? '';

            if ($target_year > 0 && $target_sem > 0 && !empty($target_ay)) {
                // Fetch all enrolled courses for this student in this term
                $enrolled_sql = "SELECT e.curriculum_id, c.course_code 
                                 FROM enrollments e
                                 JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                 WHERE e.student_id = ? AND e.academic_year = ? 
                                 AND c.year_level = ? AND c.semester = ? AND e.status = 'Enrolled'";
                $enrolled_courses = db_fetch_all(db_query($conn, $enrolled_sql, 'isii', [$student_id, $target_ay, $target_year, $target_sem]));

                if (!empty($enrolled_courses)) {
                    $cids = array_values(array_unique(array_map('intval', array_column($enrolled_courses, 'curriculum_id'))));
                    $drop_count = count($cids);

                    if (!$conn->begin_transaction()) {
                        $message = 'Unable to start drop-all transaction. Please try again.';
                        $message_type = 'error';
                    } else {
                        try {
                            $placeholders = implode(',', array_fill(0, count($cids), '?'));
                            $types = str_repeat('i', count($cids));

                            $decrement_sql = "UPDATE schedules SET enrolled_count = GREATEST(0, enrolled_count - 1) WHERE curriculum_id IN ($placeholders)";
                            if (!db_query($conn, $decrement_sql, $types, $cids)) {
                                throw new Exception('Failed to update schedule counts');
                            }

                            $delete_sql = "DELETE FROM enrollments WHERE student_id = ? AND academic_year = ? AND curriculum_id IN ($placeholders)";
                            $delete_types = 'is' . $types;
                            $delete_params = array_merge([$student_id, $target_ay], $cids);
                            if (!db_query($conn, $delete_sql, $delete_types, $delete_params)) {
                                throw new Exception('Failed to remove enrollments');
                            }

                            $conn->commit();
                            $message = "Successfully dropped all $drop_count courses.";
                            $message_type = 'success';
                        } catch (Exception $e) {
                            $conn->rollback();
                            $message = 'Failed to drop all courses for this term.';
                            $message_type = 'error';
                        }
                    }
                } else {
                    $message = "No enrolled courses found to drop for this term.";
                    $message_type = 'warning';
                }
            } else {
                 $message = "Invalid term selected for Drop All.";
                 $message_type = 'error';
            }
        }
    } else {
        $message = 'Invalid student context for this action.';
        $message_type = 'error';
    }
    }
}

// Query to get student information with program
$sql = "SELECT s.*, p.program_name, p.program_code
        FROM students s 
        LEFT JOIN programs p ON s.program_id = p.program_id 
        WHERE s.student_id = ?";
$result = db_query($conn, $sql, 'i', [$student_id]);
if (!$result || $result->num_rows === 0) {
    $conn->close();
    header('Location: ../index.php');
    exit;
}
$student = db_fetch_one($result);

// --- SHARED DATA --- (like academic years)
$terms_options = get_student_term_options($conn, $student_id);

// --- TAB SPECIFIC LOGIC ---
// Default to Current Context if nothing selected
// Or default to 'All' if we prefer? User asked for "apply the same filter", 
// finance defaults to "Current". But maybe schedule/grades should default to "Current" too?
// Let's use the first option (Current) as default if empty.
$first_opt = reset($terms_options);
$default_ay = $first_opt['ay'];
$default_sem = $first_opt['sem'];
$default_yl = $first_opt['yl'];

$selected_ay = isset($_GET['academic_year']) ? $_GET['academic_year'] : $default_ay;
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $default_yl;
$selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : $default_sem;

// Robust handling of "All Terms" state
$is_all_terms = false;
if (isset($_GET['academic_year']) && $_GET['academic_year'] === '') {
    $selected_ay = '';
    $selected_year = 0;
    $selected_semester = 0;
    $is_all_terms = true;
}
if (isset($_GET['year']) && $_GET['year'] === '0') $selected_year = 0;
if (isset($_GET['semester']) && $_GET['semester'] === '0') $selected_semester = 0;

$selected_key = $is_all_terms ? '||' : ($selected_ay . '|' . $selected_year . '|' . $selected_semester);

// Just in case selected AY/Sem combination isn't in options, we still allow filtering by it? 
// No, stick to the robust options provided or fallback.
// But we should allow 'All' options.

// Map back for view variables
// Implementation Note: The unified filter works best if we use the consolidated key. 
// But this page uses separate GET params. Let's keep separate params for now but populate dropdown from options.


// Variables for views
$grouped_schedules = [];
$courses = [];
$total_units = 0;
$conflicts = [];
$grouped_grades = [];
$completed_count = 0;
$gwa = null;


if ($active_tab === 'schedule') {
    // --- SCHEDULE LOGIC ---
    $student_year_level = intval($student['year_level']);

    $schedules_sql = "SELECT sc.*, c.course_code, c.course_name, c.units, c.year_level, c.semester, 
                      c.description as course_description, e.status as enrollment_status, e.academic_year as enrolled_ay,
                      t.first_name as teacher_first_name, t.last_name as teacher_last_name, t.title as teacher_title,
                      CASE 
                          WHEN c.year_level <= ? THEN CONCAT(COALESCE(t.title, ''), ' ', COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, ''))
                          ELSE 'TBA'
                      END as instructor
                      FROM enrollments e
                      JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                      JOIN schedules sc ON sc.curriculum_id = c.curriculum_id
                      LEFT JOIN teachers t ON sc.teacher_id = t.teacher_id
                      WHERE e.student_id = ?";
    $params = [$student_year_level, $student_id];
    $types = 'ii';
    
    if (!$is_all_terms) {
        $schedules_sql .= " AND e.academic_year = ?";
        $params[] = $selected_ay;
        $types .= 's';
    }
    
    if ($selected_year > 0) {
        $schedules_sql .= " AND c.year_level = ?";
        $params[] = $selected_year;
        $types .= 'i';
    }
    if ($selected_semester > 0) {
        $schedules_sql .= " AND c.semester = ?";
        $params[] = $selected_semester;
        $types .= 'i';
    }
    $schedules_sql .= " ORDER BY c.year_level, c.semester, sc.day_of_week, sc.start_time";
    $schedules_result = db_query($conn, $schedules_sql, $types, $params);
    $schedules = $schedules_result ? db_fetch_all($schedules_result) : [];

    // Fallback/Grades view queries... (unchanged)
    $curriculum_sql = "SELECT c.*, e.status as enrollment_status 
                       FROM enrollments e
                       JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                       WHERE e.student_id = ?";
    $curr_params = [$student_id];
    $curr_types = 'i';
    if (!$is_all_terms) {
        $curriculum_sql .= " AND e.academic_year = ?";
        $curr_params[] = $selected_ay;
        $curr_types .= 's';
    }
    if ($selected_year > 0) {
        $curriculum_sql .= " AND c.year_level = ?";
        $curr_params[] = $selected_year;
        $curr_types .= 'i';
    }
    if ($selected_semester > 0) {
        $curriculum_sql .= " AND c.semester = ?";
        $curr_params[] = $selected_semester;
        $curr_types .= 'i';
    }
    $curriculum_sql .= " ORDER BY c.year_level, c.semester, c.course_code";
    $curriculum_result = db_query($conn, $curriculum_sql, $curr_types, $curr_params);
    $courses = $curriculum_result ? db_fetch_all($curriculum_result) : [];

    // --- AVAILABLE COURSES LOGIC ---
    // Only fetch if we have a valid year/semester filter (to match Edit Enrollment logic)
    // Constraint: Only courses of corresponding program
    $available_courses = [];
    if ($selected_year > 0 && $selected_semester > 0) {
        $avail_sql = "SELECT c.* 
                      FROM curriculum c
                      WHERE c.program_id = ? 
                      AND c.year_level = ? 
                      AND c.semester = ?
                      AND c.curriculum_id NOT IN (
                          SELECT curriculum_id FROM enrollments 
                          WHERE student_id = ?
                      )
                      ORDER BY c.course_code";
        $avail_result = db_query($conn, $avail_sql, 'iiii', [$student['program_id'], $selected_year, $selected_semester, $student_id]);
        $available_courses = $avail_result ? db_fetch_all($avail_result) : [];
    }

    // Processing schedules
    $unique_courses = [];
    foreach ($schedules as $sched) {
        $cid = $sched['curriculum_id'];
        if (!isset($unique_courses[$cid])) {
            $unique_courses[$cid] = $sched['units'];
            $total_units += $sched['units'];
            $grouped_schedules[$cid] = [
                'course_code' => $sched['course_code'],
                'course_name' => $sched['course_name'],
                'units' => $sched['units'],
                'year_level' => $sched['year_level'],
                'semester' => $sched['semester'],
                'academic_year' => $sched['enrolled_ay'],
                'meetings' => []
            ];
        }
        $instructor = trim($sched['instructor'] ?? 'TBA');
        if (empty($instructor) || $instructor === '  ') $instructor = 'TBA';
        
        $grouped_schedules[$cid]['meetings'][] = [
            'day' => $sched['day_of_week'],
            'start_time' => $sched['start_time'],
            'end_time' => $sched['end_time'],
            'room' => $sched['room'],
            'instructor' => $instructor
        ];
    }
    if (empty($schedules) && !empty($courses)) {
        foreach ($courses as $course) {
            $total_units += $course['units'];
        }
    }

    // Conflicts (Refined: Only check overlaps within the SAME term)
    foreach ($grouped_schedules as $cid1 => $course1) {
        foreach ($course1['meetings'] as $m1) {
            foreach ($grouped_schedules as $cid2 => $course2) {
                if ($cid1 >= $cid2) continue; 
                
                // ONLY conflict if same Academic Year and Semester
                if ($course1['academic_year'] !== $course2['academic_year'] || 
                    $course1['semester'] !== $course2['semester']) {
                    continue;
                }

                foreach ($course2['meetings'] as $m2) {
                    if ($m1['day'] === $m2['day']) {
                        if ($m1['start_time'] < $m2['end_time'] && $m2['start_time'] < $m1['end_time']) {
                            $conflicts[] = [
                                'course1' => $course1['course_code'] . ' - ' . $course1['course_name'],
                                'course2' => $course2['course_code'] . ' - ' . $course2['course_name'],
                                'day' => formatDay($m1['day']),
                                'time1' => formatTime($m1['start_time']) . ' - ' . formatTime($m1['end_time']),
                                'time2' => formatTime($m2['start_time']) . ' - ' . formatTime($m2['end_time'])
                            ];
                        }
                    }
                }
            }
        }
    }

} elseif ($active_tab === 'grades') {
    // --- GRADES LOGIC ---
    $grades_sql = "SELECT e.*, c.course_code, c.course_name, c.units, c.year_level, c.semester
                   FROM enrollments e
                   JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                   WHERE e.student_id = ?";
    $params = [$student_id];
    $types = 'i';
    
    // Optional: Also filter grades by academic year? 
    // The previous student_grades.php didn't have AY filter, just Year Level/Sem. 
    // But since we have it, let's keep it consistent or allow "All" to show full history.
    // If user selects specific AY, we filter. If they select "All Years" (AY) maybe we show all?
    // Let's reuse the $selected_ay logic but handle 'N/A' or empty if we want full history.
    // Assuming for grades, seeing "All" is common. 
    // Let's stick to what student_grades.php had: Year Level and Semester filters. 
    // But maybe we add AY filter too if it makes sense? The prompt asked to combine pages.
    // I will add the AY filter to grades too as it is useful.

    // If selected_ay is passed (which it is by default logic above), we filter by it.
    // But wait, grades are usually cumulative. 
    // Let's check if the previous grades page had AY filter. No, it didn't.
    // I'll add it as an option, but default to showing all history? 
    // Actually, to match the schedule filter UI, I should probably apply it.
    // But GWA is usually cumulative or per sem.
    // Let's APPLY the AY filter if selected_ay is not empty/latest.
    // Actually, let's allow "All" in the dropdown for AY.
    
    // But wait, the default $selected_ay is $latest_ay. 
    // If I enforce that on grades, I only see this year's grades.
    // That might be annoying if I want to see previous grades.
    // Let's make the default for grades be "All" if not explicitly set? 
    // Or just let it share the state?
    // Let's share state for consistency, but enable "All" option in dropdown.
    
    if ($selected_ay && $active_tab !== 'grades') {
         $grades_sql .= " AND e.academic_year = ?";
         $params[] = $selected_ay;
         $types .= 's';
    } elseif ($selected_ay && $active_tab === 'grades') {
        // If specific AY is selected for grades, apply it. If empty (All), skip.
        $grades_sql .= " AND e.academic_year = ?";
        $params[] = $selected_ay;
        $types .= 's';
    }

    if ($selected_year > 0) {
        $grades_sql .= " AND c.year_level = ?";
        $params[] = $selected_year;
        $types .= 'i';
    }
    if ($selected_semester > 0) {
        $grades_sql .= " AND c.semester = ?";
        $params[] = $selected_semester;
        $types .= 'i';
    }
    $grades_sql .= " ORDER BY c.year_level, c.semester, c.course_code";
    $grades_result = db_query($conn, $grades_sql, $types, $params);
    $grades = $grades_result ? db_fetch_all($grades_result) : [];

    // Calculate GWA (General Weighted Average) based on FILTERED view? 
    // Usually GWA is cumulative. The previous page calculated GWA based on $grades result.
    // So if filtered, it's Semester WA, if all, it's GWA.
    $total_units_gwa = 0;
    $total_grade_points = 0;
    foreach ($grades as $grade) {
        if ($grade['status'] === 'Passed' && $grade['final_grade'] !== null) {
            $total_units_gwa += $grade['units'];
            $total_grade_points += ($grade['final_grade'] * $grade['units']);
            $completed_count++;
        }
        $key = 'Year ' . $grade['year_level'] . ' - Semester ' . $grade['semester'];
        if (!isset($grouped_grades[$key])) {
            $grouped_grades[$key] = [
                'year_level' => $grade['year_level'],
                'semester' => $grade['semester'],
                'courses' => []
            ];
        }
        $grouped_grades[$key]['courses'][] = $grade;
    }
    $gwa = $total_units_gwa > 0 ? round($total_grade_points / $total_units_gwa, 2) : null;
} elseif ($active_tab === 'academic') {
    // --- ACADEMIC STANDING LOGIC ---
    // Get cumulative GPA data (returns array with cumulative_gpa, total_units, etc.)
    $gpa_data = calculateCumulativeGPA($conn, $student_id);
    $cumulative_gpa = $gpa_data['cumulative_gpa'] ?? null;
    $total_completed_units = $gpa_data['total_units'] ?? 0;
    $passed_units = $gpa_data['passed_units'] ?? 0;
    $completion_rate = $gpa_data['completion_rate'] ?? 0;
    
    // Get current academic standing
    $standing_result = determineAcademicStanding($conn, $cumulative_gpa, $total_completed_units);
    $current_standing = $standing_result['standing'] ?? 'Good Standing';
    $standing_description = $standing_result['description'] ?? '';
    
    // Get term-by-term GPA history
    $term_gpa_sql = "SELECT e.academic_year, c.year_level as enrolled_year, c.semester,
                            SUM(CASE WHEN e.status = 'Passed' AND e.final_grade IS NOT NULL THEN e.final_grade * c.units ELSE 0 END) as grade_points,
                            SUM(CASE WHEN e.status = 'Passed' AND e.final_grade IS NOT NULL THEN c.units ELSE 0 END) as total_units,
                            SUM(CASE WHEN e.status = 'Enrolled' OR e.final_grade IS NOT NULL THEN 1 ELSE 0 END) as course_count,
                            SUM(CASE WHEN e.status = 'Passed' AND e.final_grade IS NOT NULL THEN 1 ELSE 0 END) as passed_count,
                            SUM(CASE WHEN e.status = 'Failed' AND e.final_grade IS NOT NULL THEN 1 ELSE 0 END) as failed_count,
                            SUM(CASE WHEN e.final_grade IS NOT NULL THEN 1 ELSE 0 END) as graded_count,
                            SUM(CASE WHEN e.status = 'Enrolled' THEN 1 ELSE 0 END) as in_progress_count
                     FROM enrollments e
                     JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                     WHERE e.student_id = ?";
    $term_params = [$student_id];
    $term_types = 'i';

    if (!$is_all_terms) {
        $term_gpa_sql .= " AND e.academic_year = ?";
        $term_params[] = $selected_ay;
        $term_types .= 's';
    }

    if ($selected_year > 0) {
        $term_gpa_sql .= " AND c.year_level = ?";
        $term_params[] = $selected_year;
        $term_types .= 'i';
    }

    if ($selected_semester > 0) {
        $term_gpa_sql .= " AND c.semester = ?";
        $term_params[] = $selected_semester;
        $term_types .= 'i';
    }

    $term_gpa_sql .= " GROUP BY e.academic_year, c.year_level, c.semester
                       ORDER BY e.academic_year, c.year_level, c.semester";
    $term_result = db_query($conn, $term_gpa_sql, $term_types, $term_params);
    $term_gpa_history = $term_result ? db_fetch_all($term_result) : [];
    
    // Calculate GPA for each term
    $normalized_term_history = [];
    foreach ($term_gpa_history as $term) {
        $term['course_count'] = (int)($term['course_count'] ?? 0);
        $term['passed_count'] = (int)($term['passed_count'] ?? 0);
        $term['failed_count'] = (int)($term['failed_count'] ?? 0);
        $term['graded_count'] = (int)($term['graded_count'] ?? 0);
        $term['in_progress_count'] = (int)($term['in_progress_count'] ?? 0);
        $term['total_units'] = (int)($term['total_units'] ?? 0);
        $term['grade_points'] = (float)($term['grade_points'] ?? 0);

        // Ignore orphaned term rows where no active enrollment and no graded record exists.
        if ($term['course_count'] <= 0) {
            continue;
        }

        $term['term_gpa'] = $term['total_units'] > 0
            ? round($term['grade_points'] / $term['total_units'], 2)
            : null;

        $normalized_term_history[] = $term;
    }
    $term_gpa_history = $normalized_term_history;
    
    // Get academic standing history
    $standing_history_sql = "SELECT * FROM academic_standings 
                             WHERE student_id = ? 
                             ORDER BY academic_year DESC, semester DESC LIMIT 10";
    $standing_result = db_query($conn, $standing_history_sql, 'i', [$student_id]);
    $standing_history = $standing_result ? db_fetch_all($standing_result) : [];
    
    // Get total curriculum progress
    $progress_sql = "SELECT 
                        (SELECT COUNT(*) FROM curriculum WHERE program_id = ?) as total_courses,
                        (SELECT SUM(units) FROM curriculum WHERE program_id = ?) as total_units,
                        (SELECT COUNT(*) FROM enrollments e JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                         WHERE e.student_id = ? AND e.status = 'Passed' AND e.final_grade IS NOT NULL) as completed_courses,
                        (SELECT SUM(c.units) FROM enrollments e JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                         WHERE e.student_id = ? AND e.status = 'Passed' AND e.final_grade IS NOT NULL) as completed_units";
    $progress_result = db_query($conn, $progress_sql, 'iiii', [$student['program_id'], $student['program_id'], $student_id, $student_id]);
    $progress = $progress_result ? db_fetch_one($progress_result) : null;

    // --- PREPARE DISPLAY VARIABLES (Dynamic switching) ---
    // Default to Cumulative
    $display_label_gpa = 'Cumulative GPA';
    $display_val_gpa = $cumulative_gpa;
    $display_desc_gpa = ($cumulative_gpa !== null) ? ($gpa_interpretation['rating'] ?? '') : '';
    
    $display_label_standing = 'Academic Standing';
    $display_val_standing = $current_standing;
    $display_desc_standing = match($current_standing) {
        "Dean's List" => 'Outstanding Academic Performance',
        'Good Standing' => 'Meets Academic Requirements',
        'Warning' => 'Needs Improvement',
        'Probation' => 'At Risk - Immediate Action Needed',
        'Dismissed' => 'Subject to Academic Review',
        default => 'Not yet determined'
    };
    
    $display_label_progress = 'Curriculum Progress';
    $display_val_progress_completed = $progress['completed_units'] ?? 0;
    $display_val_progress_total = $progress['total_units'] ?? 0;

    // Override if Specific Term Selected
    if (!$is_all_terms && $selected_year > 0 && $selected_semester > 0) {
        $display_label_gpa = "Term GPA (Year $selected_year, Sem $selected_semester)";
        $display_label_standing = "Term Standing";
        $display_label_progress = "Term Progress";
        
        // 1. Term GPA (Find in history)
        $found_term = null;
        foreach ($term_gpa_history as $t) {
            if ($t['academic_year'] == $selected_ay && 
                $t['enrolled_year'] == $selected_year && 
                $t['semester'] == $selected_semester) {
                $found_term = $t;
                break;
            }
        }
        
        $display_val_gpa = $found_term['term_gpa'] ?? null;
        if ($display_val_gpa !== null) {
             $term_interp = getGPAInterpretation($display_val_gpa);
             $display_desc_gpa = $term_interp['rating'];
        } else {
             $display_desc_gpa = 'No GPA calculated';
        }

        // 2. Term Standing
        // Logic: Calculate based on Term GPA first (Dean's Lister if <= 2.00)
        // Check if GPA Is Null (In Progress) -> N/A
        // Then fallback to DB record or defaults.
        if ($display_val_gpa === null) {
             $display_val_standing = 'N/A';
             $display_desc_standing = 'Grades Incomplete';
        } elseif ($display_val_gpa <= 2.00) {
            $display_val_standing = "Dean's Lister";
            $display_desc_standing = "Term Honor (GPA <= 2.00)";
        } else {
            $term_standing_sql = "SELECT * FROM academic_standings 
                                  WHERE student_id = ? AND academic_year = ? AND semester = ?";
            $term_standing_res = db_query($conn, $term_standing_sql, 'isi', [$student_id, $selected_ay, $selected_semester]);
            $term_standing_row = $term_standing_res ? db_fetch_one($term_standing_res) : null;
            
            if ($term_standing_row) {
                $display_val_standing = $term_standing_row['standing'];
                $display_desc_standing = match($display_val_standing) {
                    "Dean's List" => 'Term Honor',
                    'Good Standing' => 'Passed Requirements',
                    'Warning' => 'Low Term Performance',
                    'Probation' => 'Critical Term Performance',
                    'Dismissed' => 'Failed Term Requirements',
                    default => $term_standing_row['remarks'] ?? 'Computed for term'
                };
            } else {
                // Fallback: If no official record, maybe infer? Or just say N/A
                $display_val_standing = 'Good Standing';
                $display_desc_standing = 'Meets Requirements';
            }
        }

        // 3. Term Progress (Completed vs Curriculum for that term)
        $term_curr_sql = "SELECT SUM(units) as total FROM curriculum 
                          WHERE program_id = ? AND year_level = ? AND semester = ?";
        $term_curr_res = db_query($conn, $term_curr_sql, 'iii', [$student['program_id'], $selected_year, $selected_semester]);
        $term_curr_row = db_fetch_one($term_curr_res);
        
        $display_val_progress_total = $term_curr_row['total'] ?? 0;
        $display_val_progress_completed = $found_term['total_units'] ?? 0; // 'total_units' in term history query is actually passed units
    }
}

$conn->close();

function formatDay($day) {
    $days = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];
    return $days[$day] ?? $day;
}

function formatTime($time) {
    return date('g:i A', strtotime($time));
}

function getGradeClass($grade) {
    if ($grade === null) return '';
    if ($grade <= 1.25) return 'grade-excellent';
    if ($grade <= 1.75) return 'grade-good';
    if ($grade <= 2.50) return 'grade-average';
    if ($grade <= 3.00) return 'grade-pass';
    return 'grade-fail';
}

$student_list_url = getStudentListReturnUrl('..');
$records_home_url = appendReturnParam('student_schedule_grades.php?id=' . $student_id . '&tab=schedule', $student_list_url);
$personal_info_url = appendReturnParam('student_personal.php?id=' . $student_id, $student_list_url);
$edit_grades_url = appendReturnParam('edit_grades.php?id=' . $student_id, $student_list_url);
$encoded_return = rawurlencode($student_list_url);

// --- AJAX HANDLER ---
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if (!$is_ajax) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/forms_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-student-schedule-grades">
    <?php renderAppSidebar(['active' => 'students', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Student Records</h1>
            <div class="header-actions">
                <a href="<?php echo htmlspecialchars($student_list_url); ?>" class="btn btn-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Student List</a>
                <a href="<?php echo htmlspecialchars($personal_info_url); ?>" class="btn btn-info"><i class="bi bi-person-vcard" aria-hidden="true"></i>Personal Info</a>
                <a href="<?php echo htmlspecialchars($edit_grades_url); ?>" class="btn btn-edit js-edit-grades-btn" style="display: <?php echo $active_tab === 'grades' ? 'inline-flex' : 'none'; ?>;"><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit Grades</a>
            </div>
        </header>

        <?php
        $tab_label_map = [
            'schedule' => 'Class Schedule',
            'grades' => 'Grades',
            'academic' => 'Academic Standing',
        ];
        $active_tab_label = $tab_label_map[$active_tab] ?? 'Records';
        renderPageBreadcrumbs([
            ['label' => 'Students', 'href' => $student_list_url],
            ['label' => 'Student Records', 'href' => $records_home_url],
            ['label' => $active_tab_label]
        ]);
        ?>

<?php } ?>

        <div id="schedule-content" class="student-details">
            <div class="student-name-header">
                <h2><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                <div class="badges">
                     <span class="program-badge"><?php echo htmlspecialchars($student['program_code'] ?? 'N/A'); ?> - Year <?php echo $student['year_level']; ?> / Semester <?php echo $student['current_semester']; ?></span>
                     <?php if ($active_tab === 'grades' && $gwa !== null): ?>
                         <span class="info-item ml-sm"><strong>GWA:</strong> <span class="gwa-value"><?php echo number_format($gwa, 2); ?></span></span>
                     <?php endif; ?>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <a href="?id=<?php echo $student_id; ?>&tab=schedule&academic_year=<?php echo urlencode($selected_ay); ?>&year=<?php echo $selected_year; ?>&semester=<?php echo $selected_semester; ?>&return=<?php echo $encoded_return; ?>" class="tab-link <?php echo $active_tab === 'schedule' ? 'active' : ''; ?>">Class Schedule</a>
                <a href="?id=<?php echo $student_id; ?>&tab=grades&academic_year=<?php echo urlencode($selected_ay); ?>&year=<?php echo $selected_year; ?>&semester=<?php echo $selected_semester; ?>&return=<?php echo $encoded_return; ?>" class="tab-link <?php echo $active_tab === 'grades' ? 'active' : ''; ?>">Grades</a>
                <a href="?id=<?php echo $student_id; ?>&tab=academic&academic_year=<?php echo urlencode($selected_ay); ?>&year=<?php echo $selected_year; ?>&semester=<?php echo $selected_semester; ?>&return=<?php echo $encoded_return; ?>" class="tab-link <?php echo $active_tab === 'academic' ? 'active' : ''; ?>">Academic Standing</a>
            </div>

            <!-- Filter Section (Shared but preserves state) -->
            <div class="filter-section">
                <form method="get" class="schedule-filter">
                    <input type="hidden" name="id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    
                    <label for="termSelect">Academic Term (Year/Sem):</label>
                    <select id="termSelect" class="sort-select">
                         <option value="||" <?php echo $is_all_terms ? 'selected' : ''; ?>>All Terms</option>
                        <?php foreach($terms_options as $key => $opt): ?>
                            <?php 
                                $opt_val = $opt['ay'] . '|' . $opt['yl'] . '|' . $opt['sem'];
                                $is_selected = (!$is_all_terms && $opt['ay'] == $selected_ay && $opt['yl'] == $selected_year && $opt['sem'] == $selected_semester);
                            ?>
                            <option value="<?php echo $opt_val; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($opt['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Hidden inputs to maintain separate GET params structure for compatibility -->
                    <input type="hidden" id="h_ay" name="academic_year" value="<?php echo htmlspecialchars($selected_ay); ?>">
                    <input type="hidden" id="h_year" name="year" value="<?php echo $selected_year; ?>">
                    <input type="hidden" id="h_sem" name="semester" value="<?php echo $selected_semester; ?>">

                    <script>
                        function applyTermFilter(val) {
                            let ay = '', yr = 0, sem = 0;
                            if (val && val !== '||') {
                                const parts = val.split('|');
                                ay = parts[0];
                                yr = parts[1];
                                sem = parts[2];
                            }
                            
                            // Update hidden fields just in case
                            document.getElementById('h_ay').value = ay;
                            document.getElementById('h_year').value = yr;
                            document.getElementById('h_sem').value = sem;

                            // Construct URL
                            const studentId = '<?php echo $student_id; ?>';
                            const returnParam = '<?php echo $encoded_return; ?>';
                            
                            // Get current tab from URL to avoid resetting to initial tab
                            const urlParams = new URLSearchParams(window.location.search);
                            // Default to 'schedule' if not set, or preserve current
                            const activeTab = urlParams.get('tab') || 'schedule';
                            
                            const url = `?id=${studentId}&tab=${activeTab}&academic_year=${encodeURIComponent(ay)}&year=${yr}&semester=${sem}&return=${returnParam}`;
                            
                            loadContent(url);
                        }

                        function loadContent(url) {
                            // Add opacity to indicate loading
                            const container = document.getElementById('schedule-content');
                            container.style.opacity = '0.6';
                            container.style.pointerEvents = 'none';

                            fetch(url + '&ajax=1')
                                .then(response => response.text())
                                .then(html => {
                                    // Update history
                                    window.history.pushState({}, '', url);
                                    
                                    // Replace content
                                    // We need to extract the content just in case, but since backend sends partial, direct replace is fine
                                    // However, replacing the container itself ('outerHTML') might break event listeners attached to it if we had any.
                                    // Best to replace innerHTML. But our wrapper ID is inside the response?
                                    // Actually, the PHP outputs `<div id="schedule-content"...>` as the root of the AJAX response.
                                    // So we should replace the current element with the new one.
                                    
                                    // Wait, if we replace outerHTML, we need to re-find it for next time.
                                    const tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = html;
                                    
                                    // If strict replacement:
                                    const newContent = tempDiv.querySelector('#schedule-content');
                                    if(newContent) {
                                        container.replaceWith(newContent);
                                    } else {
                                        // Fallback if structure mismatches (shouldnt happen)
                                        container.innerHTML = html; 
                                        container.style.opacity = '1';
                                        container.style.pointerEvents = 'auto';
                                    }

                                    // Toggle header button visibility
                                    const editBtn = document.querySelector('.js-edit-grades-btn');
                                    if(editBtn) {
                                        if (url.includes('tab=grades')) {
                                            editBtn.style.display = 'inline-flex';
                                        } else {
                                            editBtn.style.display = 'none';
                                        }
                                    }
                                })
                                .catch(err => {
                                    console.error('Error loading content:', err);
                                    if (window.StudentApp && window.StudentApp.Toast) {
                                        window.StudentApp.Toast.show('Failed to load data. Please refresh the page.', 'error', 2800);
                                    } else {
                                        alert('Failed to load data. Please refresh the page.');
                                    }
                                    container.style.opacity = '1';
                                    container.style.pointerEvents = 'auto';
                                });
                        }

                        document.addEventListener('change', (event) => {
                            if (event.target && event.target.id === 'termSelect') {
                                applyTermFilter(event.target.value);
                            }
                        });

                        document.addEventListener('submit', (event) => {
                            if (event.defaultPrevented) {
                                return;
                            }

                            const form = event.target;
                            if (!(form instanceof HTMLFormElement)) {
                                return;
                            }

                            const method = (form.getAttribute('method') || '').toLowerCase();
                            if (method !== 'post') {
                                return;
                            }

                            if (form.dataset.submitting === '1') {
                                event.preventDefault();
                                return;
                            }

                            form.dataset.submitting = '1';
                            const submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
                            if (!submitter) {
                                return;
                            }

                            submitter.disabled = true;
                            if (submitter.tagName === 'BUTTON') {
                                const originalText = submitter.textContent;
                                submitter.dataset.originalText = originalText || '';
                                submitter.textContent = 'Processing...';
                            }
                        });

                        document.addEventListener('click', (event) => {
                            const tabLink = event.target.closest('.tabs .tab-link');
                            if (tabLink) {
                                event.preventDefault();
                                loadContent(tabLink.href);
                                return;
                            }

                            const scheduleAction = event.target.closest('[data-schedule-action]');
                            if (scheduleAction) {
                                const action = scheduleAction.getAttribute('data-schedule-action');
                                if (action === 'check-conflicts') {
                                    checkStudentConflicts();
                                    return;
                                }

                                if (action === 'close-conflict-modal') {
                                    closeConflictModal();
                                    return;
                                }

                                if (action === 'close-class-modal') {
                                    closeClassModal();
                                    return;
                                }
                            }

                            const clickableRow = event.target.closest('tr.clickable-row[data-curriculum-id]');
                            if (!clickableRow) {
                                return;
                            }

                            if (event.target.closest('.row-action-cell, .inline-form, button, a, input, select, textarea')) {
                                return;
                            }

                            openClassModal(
                                clickableRow.getAttribute('data-curriculum-id'),
                                clickableRow.getAttribute('data-academic-year') || ''
                            );
                        });
                    </script>

                    
                    <?php if ($active_tab === 'schedule'): ?>
                        <span class="total-units-badge">Total Units: <?php echo $total_units; ?></span>
                    <?php endif; ?>
                </form>
            </div>

            <!-- TAB CONTENT: SCHEDULE -->
            <?php if ($active_tab === 'schedule'): ?>
                <div id="scheduleTab" class="tab-content active">
                    <?php if (!empty($grouped_schedules)): ?>
                         <div class="section-header mb-sm">
                            <h3>Class Schedule - <?php echo $selected_ay ? htmlspecialchars($selected_ay) . ' / ' : ''; ?>Year <?php echo $selected_year; ?><?php echo $selected_semester > 0 ? ' (Semester ' . $selected_semester . ')' : ''; ?></h3>
                            <div class="actions-group">
                                <button type="button" data-schedule-action="check-conflicts" class="btn btn-conflict-check">Check Conflicts</button>
                                <?php if (!$is_all_terms && $selected_year > 0 && $selected_semester > 0): ?>
                                    <form method="post" action="" style="display:inline-block;" data-confirm="Are you sure you want to DROP ALL subjects for this semester? This action cannot be undone." data-confirm-title="Drop All Subjects" data-confirm-text="Drop All" data-confirm-style="danger">
                                        <?php echo csrf_token_field($csrf_scope); ?>
                                        <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($student_records_submission_token); ?>">
                                        <input type="hidden" name="action" value="drop_all">
                                        <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_ay); ?>">
                                        <input type="hidden" name="year" value="<?php echo $selected_year; ?>">
                                        <input type="hidden" name="semester" value="<?php echo $selected_semester; ?>">
                                        <button type="submit" class="btn btn-danger">Drop All Subjects</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-container">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Schedule</th>
                                        <th>Room</th>
                                        <th>Instructor</th>
                                        <th>Units</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grouped_schedules as $cid => $course): ?>
                                        <tr class="clickable-row" data-curriculum-id="<?php echo $cid; ?>" data-academic-year="<?php echo htmlspecialchars($course['academic_year']); ?>">
                                            <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                            <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                            <td>
                                                <?php foreach ($course['meetings'] as $i => $meeting): ?>
                                                    <?php echo formatDay($meeting['day']); ?> <?php echo formatTime($meeting['start_time']) . ' - ' . formatTime($meeting['end_time']); ?><?php echo $i < count($course['meetings']) - 1 ? '<br>' : ''; ?>
                                                <?php endforeach; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($course['meetings'][0]['room'] ?? 'TBA'); ?></td>
                                            <td><?php echo htmlspecialchars($course['meetings'][0]['instructor'] ?? 'TBA'); ?></td>
                                            <td><?php echo htmlspecialchars($course['units']); ?></td>
                                            <td class="row-action-cell">
                                                <form method="post" class="inline-form" data-confirm="Drop <?php echo htmlspecialchars($course['course_code']); ?>?" data-confirm-title="Drop Course" data-confirm-text="Drop" data-confirm-style="danger">
                                                    <?php echo csrf_token_field($csrf_scope); ?>
                                                    <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($student_records_submission_token); ?>">
                                                    <input type="hidden" name="action" value="drop">
                                                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                    <input type="hidden" name="curriculum_id" value="<?php echo $cid; ?>">
                                                    <button type="submit" class="btn-action btn-drop">Drop</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="5" class="text-right text-bold">Total Units:</td>
                                        <td class="text-bold"><?php echo $total_units; ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php elseif (!empty($courses)): ?>
                        <h3>Courses - <?php echo $selected_ay ? htmlspecialchars($selected_ay) . ' / ' : ''; ?>Year <?php echo $selected_year; ?><?php echo $selected_semester > 0 ? ' (Semester ' . $selected_semester . ')' : ''; ?></h3>
                        <div class="table-container">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Semester</th>
                                        <th>Units</th>
                                        <th>Description</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courses as $course): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                            <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                            <td><?php echo htmlspecialchars($course['semester']); ?></td>
                                            <td><?php echo htmlspecialchars($course['units']); ?></td>
                                            <td><?php echo htmlspecialchars($course['description'] ?? ''); ?></td>
                                            <td>
                                                 <form method="post" class="inline-form" data-confirm="Drop <?php echo htmlspecialchars($course['course_code']); ?>?" data-confirm-title="Drop Course" data-confirm-text="Drop" data-confirm-style="danger">
                                                    <?php echo csrf_token_field($csrf_scope); ?>
                                                    <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($student_records_submission_token); ?>">
                                                    <input type="hidden" name="action" value="drop">
                                                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                    <input type="hidden" name="curriculum_id" value="<?php echo $course['curriculum_id']; ?>">
                                                    <button type="submit" class="btn-link btn-link-danger">Drop</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="no-data">No courses or schedules found for the selected filters.</p>
                    <?php endif; ?>

                    <?php if (!empty($available_courses)): ?>
                        <div class="available-section">
                            <div class="section-header mb-sm">
                                <h3>Available Courses to Enroll (Year <?php echo $selected_year; ?>, Sem <?php echo $selected_semester; ?>)</h3>
                                <form method="post" class="inline-form" data-confirm="Enroll in ALL available courses?" data-confirm-title="Enroll All Available" data-confirm-text="Enroll All" data-confirm-style="primary">
                                    <?php echo csrf_token_field($csrf_scope); ?>
                                    <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($student_records_submission_token); ?>">
                                    <input type="hidden" name="action" value="enroll_all">
                                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_ay); ?>">
                                    <input type="hidden" name="year" value="<?php echo $selected_year; ?>">
                                    <input type="hidden" name="semester" value="<?php echo $selected_semester; ?>">
                                    <input type="hidden" name="program_id" value="<?php echo $student['program_id']; ?>">
                                    <button type="submit" class="btn btn-primary">
                                        Enroll All Available
                                    </button>
                                </form>
                            </div>
                            <div class="table-container">
                                <table class="schedule-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Units</th>
                                            <th>Semester</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($available_courses as $ac): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($ac['course_code']); ?></td>
                                                <td><?php echo htmlspecialchars($ac['course_name']); ?></td>
                                                <td><?php echo htmlspecialchars($ac['units']); ?></td>
                                                <td><?php echo htmlspecialchars($ac['semester']); ?></td>
                                                <td>
                                                    <form method="post" class="inline-form">
                                                        <?php echo csrf_token_field($csrf_scope); ?>
                                                        <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($student_records_submission_token); ?>">
                                                        <input type="hidden" name="action" value="enroll">
                                                        <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                        <input type="hidden" name="curriculum_id" value="<?php echo $ac['curriculum_id']; ?>">
                                                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($selected_ay); ?>">
                                                        <button type="submit" class="btn-link btn-link-success">Enroll</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($conflicts)): ?>
                        <div class="conflict-warning">
                            <strong class="conflict-warning-title"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Schedule Conflict Detected!</strong>
                            <ul>
                                <?php foreach ($conflicts as $conf): ?>
                                    <li>
                                        <span class="conflict-course"><?php echo htmlspecialchars($conf['course1']); ?></span> and
                                        <span class="conflict-course"><?php echo htmlspecialchars($conf['course2']); ?></span>
                                        on <strong><?php echo htmlspecialchars($conf['day']); ?></strong>
                                        (<span class="conflict-time"><?php echo htmlspecialchars($conf['time1']); ?></span> vs <span class="conflict-time"><?php echo htmlspecialchars($conf['time2']); ?></span>)
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

            <!-- TAB CONTENT: GRADES -->
            <?php elseif ($active_tab === 'grades'): ?>
                <div id="gradesTab" class="tab-content active">
                    <?php if (empty($grades)): ?>
                        <p class="no-data">No grades found for this student for the selected filters.</p>
                    <?php else: ?>
                        <?php foreach ($grouped_grades as $group_name => $group): ?>
                            <h3 class="section-title"><?php echo htmlspecialchars($group_name); ?></h3>
                            <table class="info-table grades-table">
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Units</th>
                                        <th>Midterm</th>
                                        <th>Final</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['courses'] as $grade): ?>
                                        <tr class="grade-row <?php echo strtolower($grade['status']); ?>">
                                            <td><?php echo htmlspecialchars($grade['course_code']); ?></td>
                                            <td><?php echo htmlspecialchars($grade['course_name']); ?></td>
                                            <td class="center"><?php echo htmlspecialchars($grade['units']); ?></td>
                                            <td class="center grade-cell <?php echo getGradeClass($grade['midterm_grade']); ?>">
                                                <?php echo $grade['midterm_grade'] !== null ? number_format($grade['midterm_grade'], 2) : '-'; ?>
                                            </td>
                                            <td class="center grade-cell <?php echo getGradeClass($grade['final_grade']); ?>">
                                                <?php echo $grade['final_grade'] !== null ? number_format($grade['final_grade'], 2) : '-'; ?>
                                            </td>
                                            <td class="center">
                                                <span class="status-badge status-<?php echo strtolower($grade['status']); ?>">
                                                    <?php echo htmlspecialchars($grade['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <?php
                                    $sem_units = 0;
                                    $sem_grade_points = 0;
                                    foreach ($group['courses'] as $g) {
                                        if ($g['status'] === 'Passed' && $g['final_grade'] !== null) {
                                            $sem_units += $g['units'];
                                            $sem_grade_points += ($g['final_grade'] * $g['units']);
                                        }
                                    }
                                    $sem_gwa = $sem_units > 0 ? round($sem_grade_points / $sem_units, 2) : null;
                                    ?>
                                    <tr class="semester-summary">
                                        <td colspan="2" class="right"><strong>Semester Total:</strong></td>
                                        <td class="center"><strong><?php echo array_sum(array_column($group['courses'], 'units')); ?></strong></td>
                                        <td class="center">-</td>
                                        <td class="center">
                                            <?php if ($sem_gwa !== null): ?>
                                                <strong><?php echo number_format($sem_gwa, 2); ?></strong>
                                            <?php else: ?>
                                                <em>-</em>
                                            <?php endif; ?>
                                        </td>
                                        <td class="center">
                                            <?php if ($sem_gwa !== null): ?>
                                                <span class="gwa-label">GWA</span>
                                            <?php else: ?>
                                                <em>In Progress</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <!-- TAB CONTENT: ACADEMIC STANDING -->
            <?php elseif ($active_tab === 'academic'): ?>
                <div id="academicTab" class="tab-content active">
                    <!-- Academic Summary Cards -->
                    <div class="stats-grid">
                        <!-- GPA Card -->
                        <div class="stat-card stat-card-accent">
                            <div class="stat-label"><?php echo htmlspecialchars($display_label_gpa); ?></div>
                            <div class="stat-value">
                                <?php echo $display_val_gpa !== null ? number_format($display_val_gpa, 2) : 'N/A'; ?>
                            </div>
                            <?php if ($display_val_gpa !== null): ?>
                                <div class="stat-subtitle">
                                    <?php echo htmlspecialchars($display_desc_gpa); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Academic Standing Card -->
                        <div class="stat-card stat-card-accent">
                            <div class="stat-label"><?php echo htmlspecialchars($display_label_standing); ?></div>
                            <div class="stat-value" style="font-size: 22px;">
                                <?php echo htmlspecialchars($display_val_standing); ?>
                            </div>
                            <div class="stat-subtitle">
                                <?php echo htmlspecialchars($display_desc_standing); ?>
                            </div>
                        </div>

                        <!-- Progress Card -->
                        <div class="stat-card stat-card-success">
                            <div class="stat-label"><?php echo htmlspecialchars($display_label_progress); ?></div>
                            <?php 
                                $progress_pct = $display_val_progress_total > 0 
                                    ? round(($display_val_progress_completed) / $display_val_progress_total * 100) 
                                    : 0;
                            ?>
                            <div class="stat-value">
                                <?php echo $progress_pct; ?>%
                            </div>
                            <div class="stat-subtitle">
                                <?php echo $display_val_progress_completed; ?> / <?php echo $display_val_progress_total; ?> units
                            </div>
                        </div>
                    </div>

                    <!-- Term-by-Term GPA History -->
                    <h3 class="section-title">GPA History by Term</h3>
                    <?php if (!empty($term_gpa_history)): ?>
                        <table class="info-table">
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Year Level</th>
                                    <th>Semester</th>
                                    <th>Courses</th>
                                    <th>Units Earned</th>
                                    <th>Term GPA</th>
                                    <th>Standing</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($term_gpa_history as $term): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($term['academic_year']); ?></td>
                                        <td class="center">Year <?php echo $term['enrolled_year']; ?></td>
                                        <td class="center">Sem <?php echo $term['semester']; ?></td>
                                        <td class="center">
                                            <?php if ($term['graded_count'] > 0): ?>
                                                <?php echo $term['passed_count']; ?>/<?php echo $term['graded_count']; ?> passed
                                            <?php else: ?>
                                                0/<?php echo $term['course_count']; ?> graded
                                            <?php endif; ?>
                                        </td>
                                        <td class="center"><?php echo $term['total_units'] ?? 0; ?> units</td>
                                        <td class="center">
                                            <?php if ($term['term_gpa'] !== null): ?>
                                                <span class="grade-badge <?php echo $term['term_gpa'] <= 1.75 ? 'grade-excellent' : ($term['term_gpa'] <= 2.50 ? 'grade-good' : ($term['term_gpa'] <= 3.00 ? 'grade-pass' : 'grade-fail')); ?>">
                                                    <?php echo number_format($term['term_gpa'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <em>In Progress</em>
                                            <?php endif; ?>
                                        </td>
                                        <td class="center">
                                            <?php 
                                                // Calculate Standing for History Row
                                                if ($term['term_gpa'] !== null) {
                                                    if ($term['term_gpa'] <= 2.00) {
                                                        echo '<span class="status-badge status-success">Dean\'s Lister</span>';
                                                    } else {
                                                        echo 'Good Standing';
                                                    }
                                                } else {
                                                    echo '-';
                                                }
                                            ?>
                                        </td>
                                        <td class="center">
                                            <?php if ($term['graded_count'] <= 0): ?>
                                                <em>In Progress</em>
                                            <?php elseif ($term['failed_count'] > 0): ?>
                                                <span class="term-status-failed"><?php echo $term['failed_count']; ?> failed</span>
                                            <?php elseif ($term['in_progress_count'] > 0): ?>
                                                <em>In Progress</em>
                                            <?php else: ?>
                                                <span class="term-status-passed">All passed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No academic history found for this student.</p>
                    <?php endif; ?>

                    <!-- Academic Standing History -->
                    <?php if (!empty($standing_history)): ?>
                        <h3 class="section-title mt-lg">Standing History</h3>
                        <table class="info-table">
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>GPA at Time</th>
                                    <th>Standing</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($standing_history as $record): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['academic_year']); ?></td>
                                        <td class="center">Semester <?php echo $record['semester']; ?></td>
                                        <td class="center"><?php echo number_format($record['gpa_at_time'], 2); ?></td>
                                        <td class="center">
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', str_replace("'", '', $record['standing']))); ?>">
                                                <?php echo htmlspecialchars($record['standing']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['remarks'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <!-- GPA Scale Reference -->
                    <div class="gpa-reference">
                        <h4>GPA Scale Reference (Philippine System)</h4>
                        <div class="gpa-scale-grid">
                            <div class="gpa-scale-item gpa-scale-excellent">
                                <strong>1.00 - 1.25</strong><small>Excellent</small>
                            </div>
                            <div class="gpa-scale-item gpa-scale-very-good">
                                <strong>1.26 - 1.75</strong><small>Very Good</small>
                            </div>
                            <div class="gpa-scale-item gpa-scale-good">
                                <strong>1.76 - 2.50</strong><small>Good</small>
                            </div>
                            <div class="gpa-scale-item gpa-scale-pass">
                                <strong>2.51 - 3.00</strong><small>Passing</small>
                            </div>
                            <div class="gpa-scale-item gpa-scale-fail">
                                <strong>3.01 - 5.00</strong><small>Failed</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
<?php if (!$is_ajax): ?>
    </div>
</body>
<!-- Conflict Modal -->
<div id="conflictModal" class="modal-overlay">
    <div class="modal">
        <h3>Schedule Conflicts</h3>
        <div id="conflictContent" class="conflict-modal-content">
            <p>Scanning...</p>
        </div>
        <div class="modal-footer">
            <button type="button" data-schedule-action="close-conflict-modal" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<!-- Class Details Modal -->
<div id="classModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3 id="classModalTitle">Class Details</h3>
            <button type="button" data-schedule-action="close-class-modal" class="modal-close">&times;</button>
        </div>
        
        <div id="classModalContent">
            <p>Loading...</p>
        </div>
        
        <div class="modal-footer">
            <button type="button" data-schedule-action="close-class-modal" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<script>
    const STUDENT_SYSTEM_API_TOKEN = <?php echo json_encode($api_access_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const conflictModal = document.getElementById('conflictModal');
    const classModal = document.getElementById('classModal');
    
    function closeConflictModal() {
        conflictModal.classList.remove('active');
    }
    
    function closeClassModal() {
        classModal.classList.remove('active');
    }
    
    async function openClassModal(curriculumId, ay) {
        classModal.classList.add('active');
        const content = document.getElementById('classModalContent');
        const title = document.getElementById('classModalTitle');
        content.innerHTML = '<p style="text-align:center; padding:20px;">Loading class details...</p>';
        title.innerText = 'Class Details';

        try {
            const response = await fetch(`../api/get_class_details.php?curriculum_id=${curriculumId}&ay=${ay}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Api-Token': STUDENT_SYSTEM_API_TOKEN
                }
            });
            const payload = await response.json();
            const fallbackError = 'Unable to load class details.';

            if (!response.ok || !payload || payload.success !== true) {
                const errMsg = payload && payload.error
                    ? (typeof payload.error === 'string' ? payload.error : (payload.error.message || fallbackError))
                    : fallbackError;
                content.innerHTML = `<p style="color:red">${errMsg}</p>`;
                return;
            }

            const data = payload.data || {};
            
            title.innerText = `${data.course_code} - ${data.course_name}`;
            
            let html = `
                <div style="background:#f8f9fa; padding:15px; border-radius:6px; margin-bottom:20px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:14px;">
                        <div><strong>Instructor:</strong> ${data.instructor}</div>
                        <div><strong>Room:</strong> ${data.schedules[0] ? data.schedules[0].room : 'TBA'}</div>
                        <div><strong>Term:</strong> Year ${data.year_level} / ${data.semester == 1 ? '1st' : '2nd'} Sem</div>
                        <div><strong>Capacity:</strong> ${data.enrolled} / ${data.capacity} Enrolled</div>
                        <div><strong>Schedule:</strong><br>
                            ${data.schedules.map(s => `${formatDay(s.day)} ${formatTime(s.start)} - ${formatTime(s.end)}`).join('<br>')}
                        </div>
                    </div>
                </div>
                
                <h4 style="margin-bottom:10px; border-bottom:2px solid #ddd; padding-bottom:5px;">Class List</h4>
            `;
            
            if (data.students && data.students.length > 0) {
                html += `
                    <div style="max-height:300px; overflow-y:auto; border:1px solid #dee2e6; border-radius:4px;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead style="background:#e9ecef; position:sticky; top:0;">
                                <tr>
                                    <th style="padding:8px; text-align:left;">#</th>
                                    <th style="padding:8px; text-align:left;">Student Name</th>
                                    <th style="padding:8px; text-align:left;">Program</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.students.forEach((s, index) => {
                    const isMe = s.student_id == <?php echo $student_id; ?>;
                    const rowStyle = isMe ? 'background-color:#fff3cd; font-weight:bold;' : (index % 2 === 0 ? 'background-color:#fff;' : 'background-color:#f8f9fa;');
                    html += `
                        <tr style="${rowStyle}">
                            <td style="padding:8px; border-bottom:1px solid #eee;">${index + 1}</td>
                            <td style="padding:8px; border-bottom:1px solid #eee;">
                                ${s.last_name}, ${s.first_name} ${isMe ? '(You)' : ''}
                                <div style="font-size:11px; color:#666;">${s.student_number}</div>
                            </td>
                            <td style="padding:8px; border-bottom:1px solid #eee;">${s.program_code} - Year ${s.yr_at_enrollment}</td>
                        </tr>
                    `;
                });
                
                html += `</tbody></table></div>`;
            } else {
                html += `<p style="color:#666; font-style:italic;">No students enrolled.</p>`;
            }
            
            content.innerHTML = html;
            
        } catch (e) {
            console.error(e);
            content.innerHTML = '<p style="color:red">Error fetching details.</p>';
        }
    }
    
    // Helper formatters matching PHP
    function formatDay(day) {
        const days = {'Mon':'Monday', 'Tue':'Tuesday', 'Wed':'Wednesday', 'Thu':'Thursday', 'Fri':'Friday', 'Sat':'Saturday', 'Sun':'Sunday'};
        return days[day] || day;
    }
    
    function formatTime(timeStr) {
        if(!timeStr) return '';
        const [h, m] = timeStr.split(':');
        let hours = parseInt(h);
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        return `${hours}:${m} ${ampm}`;
    }

    async function checkStudentConflicts() {
        conflictModal.classList.add('active'); 
        const content = document.getElementById('conflictContent');
        content.innerHTML = '<p>Scanning...</p>';
        
        try {
            const studentId = <?php echo $student_id; ?>;
            const ay = "<?php echo $selected_ay; ?>";
            
            const response = await fetch(`../api/check_student_conflicts.php?student_id=${studentId}&ay=${ay}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Api-Token': STUDENT_SYSTEM_API_TOKEN
                }
            });
            const payload = await response.json();
            const fallbackError = 'Unable to check schedule conflicts.';

            if (!response.ok || !payload || payload.success !== true) {
                const errMsg = payload && payload.error
                    ? (typeof payload.error === 'string' ? payload.error : (payload.error.message || fallbackError))
                    : fallbackError;
                throw new Error(errMsg);
            }

            const conflicts = Array.isArray(payload.data && payload.data.conflicts) ? payload.data.conflicts : [];

            if (!conflicts || conflicts.length === 0) {
                content.innerHTML = '<div class="message success" style="padding:10px; background:#d4edda; color:#155724; border-radius:4px;">No conflicts detected in this schedule.</div>';
            } else {
                let html = `<div class="message error" style="padding:10px; background:#f8d7da; color:#721c24; border-radius:4px; margin-bottom:15px;">Found ${conflicts.length} overlap(s).</div>`;
                html += '<ul style="padding-left:20px;">';
                conflicts.forEach(c => {
                    html += `<li style="margin-bottom:5px;"><strong>${c.day}</strong>: ${c.s1} (${c.t1}) <br> overlaps with ${c.s2} (${c.t2})</li>`;
                });
                html += '</ul>';
                content.innerHTML = html;
            }
        } catch (e) {
            console.error(e);
            content.innerHTML = '<p style="color:red">Error checking conflicts.</p>';
        }
    }
    
    // Close modal on outside click
    window.addEventListener('click', function(event) {
        if (event.target == conflictModal) closeConflictModal();
        if (event.target == classModal) closeClassModal();
    });
</script>

</body>
</html>
<?php endif; ?>
