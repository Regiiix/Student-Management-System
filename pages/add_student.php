<?php
require_once '../config/db_helpers.php';
require_once '../config/csrf_helpers.php';

$conn = getDBConnection();
$message = '';
$message_type = '';
$csrf_scope = 'add_student';
csrf_ensure_session();

$submission_token_key = 'add_student_submission_tokens';
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
    $add_student_submission_token = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $add_student_submission_token = hash('sha256', uniqid('add_student_', true));
}
$_SESSION[$submission_token_key][$add_student_submission_token] = $submission_now;

$first_name = '';
$middle_name = '';
$last_name = '';
$email = '';
$date_of_birth = '';
$gender = '';
$address = '';
$phone = '';
$year_level = 1;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 1);

    $submitted_submission_token = trim((string)($_POST['submission_token'] ?? ''));
    if ($submitted_submission_token === '' || !isset($_SESSION[$submission_token_key][$submitted_submission_token])) {
        $message = 'This add-student request was already submitted or expired. Please try again.';
        $message_type = 'error';
    } else {
        unset($_SESSION[$submission_token_key][$submitted_submission_token]);

        if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
            $message = 'Invalid or expired security token. Please refresh and try again.';
            $message_type = 'error';
        } else {
            // Validation
            $errors = [];
            if (empty($first_name)) $errors[] = 'First name is required';
            if (empty($last_name)) $errors[] = 'Last name is required';
            if (empty($email)) $errors[] = 'Email is required';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
            if (empty($date_of_birth)) $errors[] = 'Date of birth is required';
            if (empty($gender)) $errors[] = 'Gender is required';
            if ($year_level < 1 || $year_level > 4) $errors[] = 'Invalid year level';

            // Check for duplicate email
            if (empty($errors)) {
                $check_email = db_query($conn, "SELECT student_id FROM students WHERE email = ?", 's', [$email]);
                if ($check_email && $check_email->num_rows > 0) {
                    $errors[] = 'Email already exists';
                }
            }

            if (empty($errors)) {
                // Start transaction
                if (!$conn->begin_transaction()) {
                    $message = 'Unable to start create transaction. Please try again.';
                    $message_type = 'error';
                } else {
                    try {
                        // Generate student number (format: YYYY-XXXXX) using MAX to prevent reuse/duplicates
                        $year_prefix = date('Y');
                        $max_result = db_query($conn, "SELECT student_number FROM students WHERE student_number LIKE ? ORDER BY student_number DESC LIMIT 1", 's', [$year_prefix . '%']);
                        $max_row = db_fetch_one($max_result);

                        if ($max_row && isset($max_row['student_number'])) {
                            // Extract the sequence number
                            $parts = explode('-', $max_row['student_number']);
                            $last_seq = intval(end($parts));
                            $new_seq = $last_seq + 1;
                        } else {
                            $new_seq = 1;
                        }

                        $student_number = $year_prefix . '-' . str_pad($new_seq, 5, '0', STR_PAD_LEFT);

                        // Insert student
                        $insert_sql = "INSERT INTO students (student_number, first_name, middle_name, last_name, email, date_of_birth, gender, address, phone, year_level, status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')";
                        $stmt = $conn->prepare($insert_sql);
                        if (!$stmt) {
                            throw new Exception('Prepare failed: ' . $conn->error);
                        }

                        $stmt->bind_param('sssssssssi', $student_number, $first_name, $middle_name, $last_name, $email, $date_of_birth, $gender, $address, $phone, $year_level);
                        if (!$stmt->execute()) {
                            $stmt_error = $stmt->error;
                            $stmt->close();
                            throw new Exception('Insert failed: ' . $stmt_error);
                        }
                        $new_student_id = $conn->insert_id;
                        $stmt->close();

                        $conn->commit();

                        // Redirect to index with success message for modal
                        $conn->close();
                        $redirect_url = '../index.php?msg=added&name=' . urlencode($first_name . ' ' . $last_name)
                                      . '&student_number=' . urlencode($student_number)
                                      . '&student_id=' . $new_student_id;
                        header('Location: ' . $redirect_url);
                        exit;

                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = 'Error adding student: ' . $e->getMessage();
                        $message_type = 'error';
                    }
                }
            } else {
                $message = implode('<br>', $errors);
                $message_type = 'error';
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Student</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/forms_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-add-student">
    <?php require_once '../config/sidebar.php'; ?>
    <?php renderAppSidebar(['active' => 'students', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Add New Student</h1>
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="student-details">
            <form method="post" class="add-student-form">
                <?php echo csrf_token_field($csrf_scope); ?>
                <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($add_student_submission_token); ?>">
                <!-- Personal Information -->
                <div class="form-section">
                    <h3>Personal Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($middle_name ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth <span class="required">*</span></label>
                            <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($date_of_birth ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender <span class="required">*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ($gender ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($gender ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address"><?php echo htmlspecialchars($address ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="form-section">
                    <h3>Academic Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="year_level">Year Level <span class="required">*</span></label>
                            <select id="year_level" name="year_level" required>
                                <option value="1" <?php echo ($year_level ?? 1) == 1 ? 'selected' : ''; ?>>1st Year</option>
                                <option value="2" <?php echo ($year_level ?? 1) == 2 ? 'selected' : ''; ?>>2nd Year</option>
                                <option value="3" <?php echo ($year_level ?? 1) == 3 ? 'selected' : ''; ?>>3rd Year</option>
                                <option value="4" <?php echo ($year_level ?? 1) == 4 ? 'selected' : ''; ?>>4th Year</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="../index.php?view=students" class="btn btn-back">Cancel</a>
                    <button type="submit" class="btn btn-add">Add Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <script>
        function showSpinner() {
            const spinner = document.getElementById('loadingSpinner');
            if (spinner) {
                spinner.classList.add('active');
            }
        }

        const addStudentForm = document.querySelector('.add-student-form');
        if (addStudentForm) {
            addStudentForm.addEventListener('submit', function(event) {
                if (addStudentForm.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                if (!addStudentForm.checkValidity()) {
                    return;
                }

                addStudentForm.dataset.submitting = '1';

                const submitter = event.submitter || addStudentForm.querySelector('button[type="submit"], input[type="submit"]');
                if (submitter) {
                    submitter.disabled = true;
                    if (submitter.tagName === 'BUTTON') {
                        submitter.textContent = 'Adding...';
                    } else {
                        submitter.value = 'Adding...';
                    }
                }

                showSpinner();
            });
        }
    </script>

</body>
</html>
