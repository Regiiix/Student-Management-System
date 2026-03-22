<?php
require_once '../config/db_helpers.php';
require_once '../config/csrf_helpers.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : intval($_POST['student_id'] ?? 0);

if ($student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$conn = getDBConnection();
$message = '';
$message_type = '';
$csrf_scope = 'drop_student_' . $student_id;
csrf_ensure_session();

$submission_token_key = 'drop_student_submission_tokens';
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
    $drop_student_submission_token = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $drop_student_submission_token = hash('sha256', uniqid('drop_student_', true));
}
$_SESSION[$submission_token_key][$drop_student_submission_token] = $submission_now;

// Get student info
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
$student_name = $student['first_name'] . ' ' . $student['last_name'];
$student_number = $student['student_number'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_submission_token = trim((string)($_POST['submission_token'] ?? ''));
    if ($submitted_submission_token === '' || !isset($_SESSION[$submission_token_key][$submitted_submission_token])) {
        $message = 'This drop request was already submitted or expired. Please reload and try again.';
        $message_type = 'error';
    } else {
        unset($_SESSION[$submission_token_key][$submitted_submission_token]);

        if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
            $message = 'Invalid or expired security token. Please refresh and try again.';
            $message_type = 'error';
        } elseif (trim((string)($_POST['confirm_drop'] ?? '')) !== 'yes') {
            $message = 'Drop confirmation was not provided.';
            $message_type = 'error';
        } elseif (intval($_POST['student_id'] ?? 0) !== $student_id) {
            $message = 'Invalid student reference in request.';
            $message_type = 'error';
        } elseif (!$conn->begin_transaction()) {
            $message = 'Unable to start drop transaction. Please try again.';
            $message_type = 'error';
        } else {
            try {
                // Delete dependent records first (to satisfy FK constraints).
                if (db_query($conn, "DELETE FROM payments WHERE student_id = ?", 'i', [$student_id]) === false) {
                    throw new Exception('Failed deleting payments: ' . $conn->error);
                }
                if (db_query($conn, "DELETE FROM semester_status WHERE student_id = ?", 'i', [$student_id]) === false) {
                    throw new Exception('Failed deleting semester statuses: ' . $conn->error);
                }

                // Decrement schedule enrolled counts before deleting enrollments.
                $enrolled_courses_sql = "SELECT curriculum_id FROM enrollments WHERE student_id = ?";
                $courses_res = db_query($conn, $enrolled_courses_sql, 'i', [$student_id]);
                if ($courses_res === false) {
                    throw new Exception('Failed reading enrolled courses: ' . $conn->error);
                }

                $course_ids = [];
                while ($row = $courses_res->fetch_assoc()) {
                    $course_ids[] = intval($row['curriculum_id']);
                }

                if (!empty($course_ids)) {
                    $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
                    $types = str_repeat('i', count($course_ids));
                    if (db_query($conn, "UPDATE schedules SET enrolled_count = GREATEST(0, enrolled_count - 1) WHERE curriculum_id IN ($placeholders)", $types, $course_ids) === false) {
                        throw new Exception('Failed updating schedule counts: ' . $conn->error);
                    }
                }

                if (db_query($conn, "DELETE FROM student_scholarships WHERE student_id = ?", 'i', [$student_id]) === false) {
                    throw new Exception('Failed deleting scholarships: ' . $conn->error);
                }
                if (db_query($conn, "DELETE FROM academic_standings WHERE student_id = ?", 'i', [$student_id]) === false) {
                    throw new Exception('Failed deleting academic standings: ' . $conn->error);
                }
                if (db_query($conn, "DELETE FROM enrollments WHERE student_id = ?", 'i', [$student_id]) === false) {
                    throw new Exception('Failed deleting enrollments: ' . $conn->error);
                }
                if (db_query($conn, "DELETE FROM students WHERE student_id = ?", 'i', [$student_id]) === false) {
                    throw new Exception('Failed deleting student: ' . $conn->error);
                }

                $conn->commit();
                $conn->close();

                header('Location: ../index.php?msg=dropped&name=' . urlencode($student_name));
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error dropping student: ' . $e->getMessage();
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
    <title>Drop Student - Confirmation</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/forms_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-drop-student">
    <?php require_once '../config/sidebar.php'; ?>
    <?php renderAppSidebar(['active' => 'students', 'basePath' => '..']); ?>
    <div class="container">
        <div class="confirm-container">
            <div class="confirm-title">Drop Student?</div>
            <div class="confirm-message">
                Are you sure you want to drop this student from the system?
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="student-info-box">
                <p><strong>Student Number:</strong> <?php echo htmlspecialchars($student_number); ?></p>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($student_name); ?></p>
                <p><strong>Program:</strong> <?php echo htmlspecialchars($student['program_code'] . ' - ' . $student['program_name']); ?></p>
                <p><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level']); ?></p>
            </div>
            
            <div class="warning-text">
                This action cannot be undone. All enrollments and records for this student will be permanently deleted.
            </div>
            
            <div class="confirm-buttons">
                <a href="../index.php?view=students" class="btn btn-cancel">Cancel</a>
                <form method="post" action="drop_student.php?id=<?php echo $student_id; ?>" class="drop-student-form">
                    <?php echo csrf_token_field($csrf_scope); ?>
                    <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($drop_student_submission_token); ?>">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="confirm_drop" value="yes">
                    <button type="submit" class="btn btn-drop">Drop Student</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const dropStudentForm = document.querySelector('.drop-student-form');
        if (dropStudentForm) {
            dropStudentForm.addEventListener('submit', function(event) {
                if (dropStudentForm.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                dropStudentForm.dataset.submitting = '1';
                const submitter = event.submitter || dropStudentForm.querySelector('button[type="submit"], input[type="submit"]');
                if (submitter) {
                    submitter.disabled = true;
                    if (submitter.tagName === 'BUTTON') {
                        submitter.textContent = 'Dropping...';
                    } else {
                        submitter.value = 'Dropping...';
                    }
                }
            });
        }
    </script>

</body>
</html>
