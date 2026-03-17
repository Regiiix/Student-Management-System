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
$csrf_scope = 'edit_student_' . $student_id;
$system_current_ay = (string)getSystemSetting($conn, 'current_academic_year', (date('Y') . '-' . (date('Y') + 1)));

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
    if ($current_semester < 1 || $current_semester > 2) $errors[] = 'Invalid semester';
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
    $confirm_forward = isset($_POST['confirm_forward']) && $_POST['confirm_forward'] == '1';
    
    if (empty($errors)) {
        // Check if attempting to change Year or Semester (Promotion/Regression)
        $current_yl = intval($student['year_level']);
        $current_sem = intval($student['current_semester']);
        
        if ($year_level != $current_yl || $current_semester != $current_sem) {
            require_once '../config/finance_helpers.php';
            
            // 1. Resolve Source Academic Year (Latest AY for their CURRENT YL/Sem)
            $ay_sql = "SELECT e.academic_year 
                       FROM enrollments e 
                       JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                       WHERE e.student_id = ? AND c.semester = ? AND c.year_level = ?
                       ORDER BY e.enrollment_id DESC LIMIT 1";
            $ay_res = db_query($conn, $ay_sql, 'iii', [$student_id, $current_sem, $current_yl]);
            $student_ay = db_fetch_one($ay_res)['academic_year'] ?? '';

            if (!$student_ay) {
                // Fallback to System AY if no enrollment found
                $student_ay = $system_current_ay;
            }
            
            $source_term = ['ay' => $student_ay, 'sem' => $current_sem, 'yl' => $current_yl];
            $term_assessment = getTermAssessment($conn, $student_id, $student_ay, $current_sem);
            $term_balance = getTermBalance($conn, $student_id, $student_ay, $current_sem);
            
            if (round($term_balance, 2) > 0) {
                $threshold = round($term_assessment * 0.20, 2);
                if (round($term_balance, 2) > $threshold) {
                    $errors[] = "Cannot promote student: Current term balance (Php " . number_format($term_balance, 2) . ") exceeds 20% threshold (Php " . number_format($threshold, 2) . ").";
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

            // 2. Mark current/previous term status as Completed if we are promoting/moving forward
            // (Only if no errors so far)
            if (empty($errors) && !$requires_confirmation) {
                $complete_sql = "UPDATE semester_status SET status = 'Completed', updated_at = NOW() 
                                WHERE student_id = ? AND academic_year = ? AND semester = ? AND status = 'In Progress'";
                db_query($conn, $complete_sql, 'isi', [$student_id, $student_ay, $current_sem]);
                
                // Also update Enrollments for that term to 'Passed' if they don't have a failing grade?
                // Conservative approach: Just update 'Enrolled' to 'Passed' for subjects in that term
                // if they are being promoted, assuming they passed (or system will handle grades later).
                // For now, let's at least clear the 'Enrolled' status so they don't show up in classmate lists.
                $pass_enroll_sql = "UPDATE enrollments e
                                   JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                                   SET e.status = 'Passed', e.updated_at = NOW()
                                   WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? AND e.status = 'Enrolled'";
                db_query($conn, $pass_enroll_sql, 'isi', [$student_id, $student_ay, $current_sem]);
            }
        }
    }
    
        if (empty($errors) && !$requires_confirmation) {
            $update_sql = "UPDATE students SET 
                           first_name = ?, middle_name = ?, last_name = ?, email = ?, 
                           date_of_birth = ?, gender = ?, address = ?, phone = ?, 
                           program_id = ?, year_level = ?, current_semester = ?, status = ?
                           WHERE student_id = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param('ssssssssiiisi', $first_name, $middle_name, $last_name, $email, 
                                  $date_of_birth, $gender, $address, $phone, 
                                  $program_id, $year_level, $current_semester, $status, $student_id);
                if ($stmt->execute()) {
                    $message = 'Student information updated successfully!';
                    $message_type = 'success';

                    // Handle Balance Forwarding if applicable
                    if ($forward_balance_amount > 0 && !empty($source_term)) {
                        // Get New Academic Year (re-fetch if needed, or assume sys_ay)
                        $sys_ay = $system_current_ay;
                        $target_ay = $sys_ay;

                        $target_yl = intval($_POST['year_level']);
                        $target_sem = intval($_POST['current_semester']);
                        
                        // Smart AY Promotion: If moving to next year or resetting from sem 2 to 1
                        if ($target_yl > intval($student['year_level']) || (intval($student['current_semester'] ?? 1) == 2 && $target_sem == 1)) {
                            $target_ay = getNextAcademicYear($sys_ay);
                        }
                        
                        // 1. Credit old term
                        $target_yl = intval($_POST['year_level']);
                        $target_sem = intval($_POST['current_semester']);
                        
                        $credit_note = "Balance forwarded to Year " . $target_yl . " Sem " . $target_sem;
                        $ins_credit = "INSERT INTO payments (student_id, amount, academic_year, semester, notes) VALUES (?, ?, ?, ?, ?)";
                        db_query($conn, $ins_credit, 'idiss', [$student_id, $forward_balance_amount, $source_term['ay'], $source_term['sem'], $credit_note]);
                        
                        // 2. Debit new term
                        $debit_note = "Balance forwarded from Year " . $source_term['yl'] . " Sem " . $source_term['sem'];
                        db_query($conn, $ins_credit, 'idiss', [$student_id, -$forward_balance_amount, $target_ay, $target_sem, $debit_note]);
                        
                        $message .= " (Forwarded Php " . number_format($forward_balance_amount, 2) . ")";
                    }

                    // Refresh student data
                    $result = db_query($conn, $sql, 'i', [$student_id]);
                    $student = db_fetch_one($result);
                } else {
                    $message = 'Error updating student: ' . $stmt->error;
                    $message_type = 'error';
                }
                $stmt->close();
            } else {
                $message = 'Error preparing update: ' . $conn->error;
                $message_type = 'error';
            }
        } elseif (!empty($errors)) {
            $message = implode('<br>', $errors);
            $message_type = 'error';
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

        // Add loading spinner to forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                showSpinner();
            });
        });

        function confirmPromotion() {
            closePromoModal();
            showSpinner();
            // Add hidden input to form and submit
            const form = document.querySelector('.edit-student-form');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'confirm_forward';
            input.value = '1';
            form.appendChild(input);
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
