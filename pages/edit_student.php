<?php
require_once '../config/db_helpers.php';
require_once '../config/sidebar.php';
require_once '../config/csrf_helpers.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$conn = getDBConnection();
$message = '';
$message_type = '';
$merit_notice = '';
$merit_notice_type = 'info';
$csrf_scope = 'edit_student_' . $student_id;
csrf_ensure_session();
$system_current_ay = (string)getSystemSetting($conn, 'current_academic_year', (date('Y') . '-' . (date('Y') + 1)));
$submission_token_key = 'edit_student_submission_tokens';
$submission_token_ttl_seconds = 3600;

if (!isset($_SESSION[$submission_token_key]) || !is_array($_SESSION[$submission_token_key])) {
    $_SESSION[$submission_token_key] = [];
}

$submission_token_now = time();
foreach ($_SESSION[$submission_token_key] as $token_value => $token_created_at) {
    if (!is_int($token_created_at) || ($submission_token_now - $token_created_at) > $submission_token_ttl_seconds) {
        unset($_SESSION[$submission_token_key][$token_value]);
    }
}

try {
    $edit_student_submission_token = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $edit_student_submission_token = hash('sha256', uniqid('edit_student_', true));
}
$_SESSION[$submission_token_key][$edit_student_submission_token] = $submission_token_now;

// Get student data
$sql = "SELECT s.*, p.program_name, p.program_code 
        FROM students s 
        LEFT JOIN programs p ON s.program_id = p.program_id 
        WHERE s.student_id = ?";
$result = db_query($conn, $sql, 'i', [$student_id]);

if (!$result || $result->num_rows === 0) {
    $conn->close();
    header('Location: ../index.php?msg=notfound');
    exit;
}

$student = db_fetch_one($result);

// Get all programs for dropdown (cached for performance)
$programs = getCachedPrograms($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_submission_token = trim((string)($_POST['submission_token'] ?? ''));
    if ($submitted_submission_token === '' || !isset($_SESSION[$submission_token_key][$submitted_submission_token])) {
        $message = 'This edit request was already submitted or expired. Please try again.';
        $message_type = 'error';
    } else {
        unset($_SESSION[$submission_token_key][$submitted_submission_token]);

        if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
            $message = 'Invalid or expired security token. Please refresh and try again.';
            $message_type = 'error';
        } else {
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            $gender = $_POST['gender'] ?? '';
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $program_id = intval($_POST['program_id'] ?? 0);
            $year_level = intval($_POST['year_level'] ?? 1);
            $current_semester = intval($_POST['current_semester'] ?? 1);
            $status = $_POST['status'] ?? 'Active';

            // Validation
            $errors = [];
            if (empty($first_name)) $errors[] = 'First name is required';
            if (empty($last_name)) $errors[] = 'Last name is required';
            if (empty($email)) $errors[] = 'Email is required';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
            if (empty($date_of_birth)) $errors[] = 'Date of birth is required';
            if (empty($gender)) $errors[] = 'Gender is required';
            if ($program_id <= 0) $errors[] = 'Please select a program';
            if ($year_level < 1 || $year_level > 4) $errors[] = 'Invalid year level';
            if ($current_semester < 0 || $current_semester > 2) $errors[] = 'Invalid semester';
            if (!in_array($status, ['Active', 'Inactive', 'Graduated'])) $errors[] = 'Invalid status';

            // Check for duplicate email (excluding current student)
            if (empty($errors)) {
                $check_email = db_query($conn, "SELECT student_id FROM students WHERE email = ? AND student_id != ?", 'si', [$email, $student_id]);
                if ($check_email && $check_email->num_rows > 0) {
                    $errors[] = 'Email already exists for another student';
                }
            }

            // FINANCIAL CONSTRAINT: PREVENT PROMOTION IF HAS BALANCE (with 20% threshold for "Force Promote")
            $forward_balance_amount = 0;
            $requires_confirmation = false;
            $source_term = [];
            $should_finalize_current_term = false;
            $confirm_forward = isset($_POST['confirm_forward']) && $_POST['confirm_forward'] == '1';
            $is_advancing = false;

            if (empty($errors)) {
                // Check if attempting to change Year or Semester (Promotion/Regression)
                $current_yl = intval($student['year_level']);
                $current_sem = intval($student['current_semester']);

                if ($year_level != $current_yl || $current_semester != $current_sem) {
                    require_once '../config/finance_helpers.php';

                    $is_advancing = ($year_level > $current_yl)
                        || ($year_level === $current_yl && $current_semester > $current_sem);

                    // 1. Resolve Source Academic Year for CURRENT YL/Sem using active semester status first.
                    $student_ay = '';

                    $status_ay_sql = "SELECT academic_year
                                      FROM semester_status
                                      WHERE student_id = ?
                                        AND year_level = ?
                                        AND semester = ?
                                        AND status = 'In Progress'
                                      ORDER BY academic_year DESC, status_id DESC
                                      LIMIT 1";
                    $status_ay_row = db_fetch_one(db_query($conn, $status_ay_sql, 'iii', [$student_id, $current_yl, $current_sem]));
                    $student_ay = $status_ay_row['academic_year'] ?? '';

                    if (!$student_ay) {
                        $ay_sql = "SELECT e.academic_year
                                   FROM enrollments e
                                   JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                   WHERE e.student_id = ? AND c.semester = ? AND c.year_level = ?
                                   ORDER BY e.academic_year DESC, e.enrollment_id DESC LIMIT 1";
                        $ay_res = db_query($conn, $ay_sql, 'iii', [$student_id, $current_sem, $current_yl]);
                        $student_ay = db_fetch_one($ay_res)['academic_year'] ?? '';
                    }

                    if (!$student_ay) {
                        // Fallback to latest payment-backed AY for this semester, then system AY.
                        $pay_ay_sql = "SELECT academic_year
                                       FROM payments
                                       WHERE student_id = ? AND semester = ?
                                       ORDER BY academic_year DESC, payment_id DESC
                                       LIMIT 1";
                        $pay_ay_row = db_fetch_one(db_query($conn, $pay_ay_sql, 'ii', [$student_id, $current_sem]));
                        $student_ay = $pay_ay_row['academic_year'] ?? $system_current_ay;
                    }

                    $source_term = ['ay' => $student_ay, 'sem' => $current_sem, 'yl' => $current_yl];
                    $term_assessment = getTermAssessment($conn, $student_id, $student_ay, $current_sem);
                    $term_balance = getTermBalance($conn, $student_id, $student_ay, $current_sem);

                    if ($is_advancing) {
                        $eligibility_sql = "SELECT
                                                SUM(CASE WHEN COALESCE(e.status, '') <> 'Passed' THEN 1 ELSE 0 END) AS non_passed_count,
                                                SUM(CASE WHEN e.status = 'Failed' OR (e.final_grade IS NOT NULL AND e.final_grade > 3.00) THEN 1 ELSE 0 END) AS failed_count
                                            FROM enrollments e
                                            JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                            WHERE e.student_id = ?
                                              AND e.academic_year = ?
                                              AND c.year_level = ?
                                              AND c.semester = ?";
                        $eligibility_row = db_fetch_one(db_query($conn, $eligibility_sql, 'isii', [$student_id, $student_ay, $current_yl, $current_sem]));

                        $non_passed_count = intval($eligibility_row['non_passed_count'] ?? 0);
                        $failed_count = intval($eligibility_row['failed_count'] ?? 0);

                        if ($non_passed_count > 0 || $failed_count > 0) {
                            $eligibility_detail_sql = "SELECT
                                                          GROUP_CONCAT(DISTINCT CASE WHEN COALESCE(e.status, '') <> 'Passed' THEN c.course_code END ORDER BY c.course_code SEPARATOR ', ') AS non_passed_courses,
                                                          GROUP_CONCAT(DISTINCT CASE WHEN e.status = 'Failed' OR (e.final_grade IS NOT NULL AND e.final_grade > 3.00) THEN c.course_code END ORDER BY c.course_code SEPARATOR ', ') AS failed_courses
                                                      FROM enrollments e
                                                      JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                                      WHERE e.student_id = ?
                                                        AND e.academic_year = ?
                                                        AND c.year_level = ?
                                                        AND c.semester = ?";
                            $eligibility_detail = db_fetch_one(db_query($conn, $eligibility_detail_sql, 'isii', [$student_id, $student_ay, $current_yl, $current_sem]));

                            $format_course_list = function ($course_csv) {
                                $course_csv = trim((string)$course_csv);
                                if ($course_csv === '') {
                                    return 'None';
                                }

                                $courses = array_values(array_filter(array_map('trim', explode(',', $course_csv))));
                                $courses = array_map(static function ($course_code) {
                                    return htmlspecialchars($course_code, ENT_QUOTES, 'UTF-8');
                                }, $courses);
                                $max_items = 8;

                                if (count($courses) <= $max_items) {
                                    return implode(', ', $courses);
                                }

                                $visible = array_slice($courses, 0, $max_items);
                                return implode(', ', $visible) . ' +' . (count($courses) - $max_items) . ' more';
                            };

                            $non_passed_courses = $format_course_list($eligibility_detail['non_passed_courses'] ?? '');
                            $failed_courses = $format_course_list($eligibility_detail['failed_courses'] ?? '');

                            $errors[] = 'Cannot proceed to next semester/year: found ' . $non_passed_count . ' non-passed subject(s), including ' . $failed_count . ' failed subject(s). All current-term subjects must be Passed.<br>Non-passed courses: ' . $non_passed_courses . '<br>Failed courses: ' . $failed_courses;
                        }
                    }

                    if (empty($errors) && round($term_balance, 2) > 0) {
                        $threshold = round($term_assessment * 0.20, 2);
                        if (round($term_balance, 2) > $threshold) {
                            $errors[] = "Cannot promote student: Current term balance (Php " . number_format($term_balance, 2) . ") exceeds 20% threshold (Php " . number_format($threshold, 2) . "). Checked term: " . htmlspecialchars($student_ay, ENT_QUOTES, 'UTF-8') . " Sem " . intval($current_sem) . " (Year " . intval($current_yl) . "), net assessment Php " . number_format($term_assessment, 2) . ".";
                        } else {
                            // Within threshold, check if confirmed
                            if (!$confirm_forward) {
                                $requires_confirmation = true;
                                $message = "Student has an outstanding balance of <strong>Php " . number_format($term_balance, 2) . "</strong> for the current term. Proceed with promotion and forward the balance?";
                                $message_type = 'warning';
                            } else {
                                $forward_balance_amount = $term_balance;
                            }
                        }
                    }

                    if (empty($errors) && !$requires_confirmation) {
                        $should_finalize_current_term = true;
                    }
                }
            }

            if (empty($errors) && !$requires_confirmation) {
                if (!$conn->begin_transaction()) {
                    $message = 'Unable to start update transaction. Please try again.';
                    $message_type = 'error';
                } else {
                    try {
                        if ($should_finalize_current_term && !empty($source_term)) {
                            $complete_sql = "UPDATE semester_status SET status = 'Completed', updated_at = NOW()
                                            WHERE student_id = ? AND academic_year = ? AND semester = ? AND status = 'In Progress'";
                            if (db_query($conn, $complete_sql, 'isi', [$student_id, $source_term['ay'], $source_term['sem']]) === false) {
                                throw new Exception('Error updating semester status: ' . $conn->error);
                            }

                            $pass_enroll_sql = "UPDATE enrollments e
                                               JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                               SET e.status = 'Passed', e.updated_at = NOW()
                                               WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? AND e.status = 'Enrolled'";
                            if (db_query($conn, $pass_enroll_sql, 'isi', [$student_id, $source_term['ay'], $source_term['sem']]) === false) {
                                throw new Exception('Error updating enrollment statuses: ' . $conn->error);
                            }
                        }

                        $update_sql = "UPDATE students SET
                                       first_name = ?, middle_name = ?, last_name = ?, email = ?,
                                       date_of_birth = ?, gender = ?, address = ?, phone = ?,
                                       program_id = ?, year_level = ?, current_semester = ?, status = ?
                                       WHERE student_id = ?";
                        $stmt = $conn->prepare($update_sql);
                        if (!$stmt) {
                            throw new Exception('Error preparing update: ' . $conn->error);
                        }

                        $stmt->bind_param(
                            'ssssssssiiisi',
                            $first_name,
                            $middle_name,
                            $last_name,
                            $email,
                            $date_of_birth,
                            $gender,
                            $address,
                            $phone,
                            $program_id,
                            $year_level,
                            $current_semester,
                            $status,
                            $student_id
                        );

                        if (!$stmt->execute()) {
                            $stmt_error = $stmt->error;
                            $stmt->close();
                            throw new Exception('Error updating student: ' . $stmt_error);
                        }
                        $stmt->close();

                        $message = 'Student information updated successfully!';

                        $target_ay = $system_current_ay;
                        if (
                            $year_level > intval($student['year_level'])
                            || (intval($student['current_semester'] ?? 1) === 2 && $current_semester === 1)
                        ) {
                            $target_ay = getNextAcademicYear($system_current_ay);
                        }

                        // Auto-apply merit scholarship for promotion targets.
                        if ($is_advancing && !empty($source_term)) {
                            $merit_result = applyPromotionMeritScholarship(
                                $conn,
                                $student_id,
                                $source_term['ay'],
                                intval($source_term['sem']),
                                $target_ay,
                                $current_semester
                            );

                            if (empty($merit_result['success'])) {
                                throw new Exception('Error applying merit scholarship: ' . ($merit_result['reason'] ?? 'Unknown error'));
                            }

                            $merit_grade_summary = '';
                            if (isset($merit_result['best_grade'], $merit_result['highest_grade'])
                                && $merit_result['best_grade'] !== null
                                && $merit_result['highest_grade'] !== null) {
                                $merit_grade_summary = ' Lowest passed: ' . number_format(floatval($merit_result['best_grade']), 2)
                                    . '; Highest passed: ' . number_format(floatval($merit_result['highest_grade']), 2) . '.';
                            }

                            if (!empty($merit_result['applied'])) {
                                $merit_notice = 'Auto-applied ' . $merit_result['scholarship_name'] . ' for ' . $target_ay . ' Sem ' . $current_semester . '.' . $merit_grade_summary;
                                $merit_notice_type = 'success';
                            } elseif (!empty($merit_result['reason'])) {
                                $merit_notice = 'No merit scholarship auto-applied: ' . $merit_result['reason'] . $merit_grade_summary;
                                $merit_notice_type = 'info';
                            }
                        }

                        // Handle balance forwarding if applicable.
                        if ($forward_balance_amount > 0 && !empty($source_term)) {

                            $credit_note = 'Balance forwarded to Year ' . $year_level . ' Sem ' . $current_semester;
                            $insert_payment_sql = "INSERT INTO payments (student_id, amount, academic_year, semester, notes) VALUES (?, ?, ?, ?, ?)";
                            if (db_query($conn, $insert_payment_sql, 'idiss', [$student_id, $forward_balance_amount, $source_term['ay'], $source_term['sem'], $credit_note]) === false) {
                                throw new Exception('Error recording source-term credit: ' . $conn->error);
                            }

                            $debit_note = 'Balance forwarded from Year ' . $source_term['yl'] . ' Sem ' . $source_term['sem'];
                            if (db_query($conn, $insert_payment_sql, 'idiss', [$student_id, -$forward_balance_amount, $target_ay, $current_semester, $debit_note]) === false) {
                                throw new Exception('Error recording target-term debit: ' . $conn->error);
                            }

                            $message .= ' (Forwarded Php ' . number_format($forward_balance_amount, 2) . ')';
                        }

                        $conn->commit();
                        $message_type = 'success';

                        // Refresh student data
                        $result = db_query($conn, $sql, 'i', [$student_id]);
                        if ($result && $result->num_rows > 0) {
                            $student = db_fetch_one($result);
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = $e->getMessage();
                        $message_type = 'error';
                    }
                }
            } elseif (!empty($errors)) {
                $message = implode('<br>', $errors);
                $message_type = 'error';
            }

            if ($message_type !== 'success') {
                // Keep submitted values visible so retries/confirmations preserve user input.
                $student['first_name'] = $first_name;
                $student['middle_name'] = $middle_name;
                $student['last_name'] = $last_name;
                $student['email'] = $email;
                $student['date_of_birth'] = $date_of_birth;
                $student['gender'] = $gender;
                $student['address'] = $address;
                $student['phone'] = $phone;
                $student['program_id'] = $program_id;
                $student['year_level'] = $year_level;
                $student['current_semester'] = $current_semester;
                $student['status'] = $status;
            }
        }
    }
}

$conn->close();

$student_list_url = getStudentListReturnUrl('..');
$view_info_url = appendReturnParam('student_personal.php?id=' . $student_id, $student_list_url);
$grades_url = appendReturnParam('student_schedule_grades.php?id=' . $student_id . '&tab=grades', $student_list_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/forms_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-edit-student">
    <?php renderAppSidebar(['active' => 'students', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Edit Student</h1>
            <div class="header-actions">
                <a href="<?php echo htmlspecialchars($student_list_url); ?>" class="btn btn-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Student List</a>
                <a href="<?php echo htmlspecialchars($view_info_url); ?>" class="btn btn-info"><i class="bi bi-person-vcard" aria-hidden="true"></i>View Info</a>
                <a href="<?php echo htmlspecialchars($grades_url); ?>" class="btn btn-grades"><i class="bi bi-journal-check" aria-hidden="true"></i>Grades</a>
            </div>
        </header>

        <?php renderPageBreadcrumbs([
            ['label' => 'Students', 'href' => $student_list_url],
            ['label' => 'Student Profile', 'href' => $view_info_url],
            ['label' => 'Edit Student']
        ]); ?>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>

            <?php if ($merit_notice !== ''): ?>
                <div class="message <?php echo htmlspecialchars($merit_notice_type, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($merit_notice, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($requires_confirmation) && $requires_confirmation): ?>
                <div id="promotionModal" class="modal-overlay active">
                    <div class="modal-container">
                        <div class="modal-header">
                            <h3>Force Promotion Confirmation</h3>
                        </div>
                        <div class="modal-body">
                            <?php echo $message; ?>
                            <p style="margin-top: 15px; font-size: 13px; color: #666; font-style: italic;">
                                Note: This will create an offset payment entry to forward this balance to the next term.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" data-promo-action="cancel" class="btn-cancel">Cancel</button>
                            <button type="button" data-promo-action="confirm" class="btn-confirm">Proceed & Forward Balance</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="student-details">
            <form method="post" class="edit-student-form">
                <?php echo csrf_token_field($csrf_scope); ?>
                <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($edit_student_submission_token); ?>">
                <!-- Student Number (Read-only) -->
                <div class="form-section">
                    <h3>Student Identification</h3>
                    <div class="form-group">
                        <label>Student Number</label>
                        <div class="student-number-display"><?php echo htmlspecialchars($student['student_number']); ?></div>
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="form-section">
                    <h3>Personal Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($student['last_name']); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth <span class="required">*</span></label>
                            <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($student['date_of_birth']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender <span class="required">*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo $student['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $student['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo $student['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="form-section">
                    <h3>Academic Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="program_id">Program <span class="required">*</span></label>
                            <select id="program_id" name="program_id" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['program_id']; ?>" <?php echo $student['program_id'] == $prog['program_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="year_level">Year Level <span class="required">*</span></label>
                            <select id="year_level" name="year_level" required>
                                <option value="1" <?php echo $student['year_level'] == 1 ? 'selected' : ''; ?>>1st Year</option>
                                <option value="2" <?php echo $student['year_level'] == 2 ? 'selected' : ''; ?>>2nd Year</option>
                                <option value="3" <?php echo $student['year_level'] == 3 ? 'selected' : ''; ?>>3rd Year</option>
                                <option value="4" <?php echo $student['year_level'] == 4 ? 'selected' : ''; ?>>4th Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                         <div class="form-group">
                            <label for="current_semester">Current Semester <span class="required">*</span></label>
                            <select id="current_semester" name="current_semester" required>
                                <option value="0" <?php echo intval($student['current_semester'] ?? 1) === 0 ? 'selected' : ''; ?>>Summer Term</option>
                                <option value="1" <?php echo ($student['current_semester'] ?? 1) == 1 ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2" <?php echo ($student['current_semester'] ?? 1) == 2 ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Status <span class="required">*</span></label>
                            <select id="status" name="status" required>
                                <option value="Active" <?php echo $student['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $student['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Graduated" <?php echo $student['status'] === 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="<?php echo htmlspecialchars($view_info_url); ?>" class="btn btn-back">Cancel</a>
                    <button type="submit" class="btn btn-add">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <script>
        // Loading spinner
        function showSpinner() {
            document.getElementById('loadingSpinner').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Prevent duplicate submissions while the request is in-flight.
        const editStudentForm = document.querySelector('.edit-student-form');
        if (editStudentForm) {
            editStudentForm.addEventListener('submit', function(event) {
                if (editStudentForm.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                editStudentForm.dataset.submitting = '1';

                const submitter = event.submitter || editStudentForm.querySelector('button[type="submit"], input[type="submit"]');
                if (submitter) {
                    submitter.disabled = true;
                    if (submitter.tagName === 'BUTTON') {
                        submitter.textContent = 'Saving...';
                    } else {
                        submitter.value = 'Saving...';
                    }
                }

                showSpinner();
            });
        }

        function confirmPromotion() {
            closePromoModal();

            // Add hidden input to form and submit.
            const form = document.querySelector('.edit-student-form');
            if (!form) {
                return;
            }

            let input = form.querySelector('input[name="confirm_forward"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'confirm_forward';
                form.appendChild(input);
            }
            input.value = '1';

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            // Fallback for older browsers without requestSubmit support.
            if (form.dataset.submitting === '1') {
                return;
            }
            form.dataset.submitting = '1';
            showSpinner();
            form.submit();
        }

        function closePromoModal() {
            const modal = document.getElementById('promotionModal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => modal.style.display = 'none', 300);
            }
        }

        document.addEventListener('click', function(event) {
            const actionButton = event.target.closest('[data-promo-action]');
            if (!actionButton) {
                return;
            }

            const action = actionButton.getAttribute('data-promo-action');
            if (action === 'confirm') {
                confirmPromotion();
            } else if (action === 'cancel') {
                closePromoModal();
            }
        });
    </script>

</body>
</html>
