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
$csrf_scope = 'edit_grades_' . $student_id;

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

// Get filter parameters
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : intval($student['year_level']);
$selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : 1;
if ($selected_year < 0) $selected_year = 0;
if ($selected_year > 4) $selected_year = 4;
if ($selected_semester !== 1 && $selected_semester !== 2) $selected_semester = 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request_token($csrf_scope, 'csrf_token', false)) {
        $message = 'Invalid or expired security token. Please refresh and try again.';
        $message_type = 'error';
    } else {
        $updated = 0;
        $errors = [];

        $conn->begin_transaction();

        try {
            foreach ($_POST['grades'] as $enrollment_id => $grade_data) {
                $enrollment_id = intval($enrollment_id);
                $midterm = trim($grade_data['midterm'] ?? '');
                $final = trim($grade_data['final'] ?? '');
                $status = $grade_data['status'] ?? 'Enrolled';

                // Validate grades (Philippine grading: 1.00 = highest, 5.00 = failed)
                $midterm_value = null;
                $final_value = null;

                if ($midterm !== '') {
                    $midterm_value = floatval($midterm);
                    if ($midterm_value < 1.00 || $midterm_value > 5.00) {
                        $errors[] = "Invalid midterm grade for enrollment #$enrollment_id. Must be between 1.00 and 5.00.";
                        continue;
                    }
                }

                if ($final !== '') {
                    $final_value = floatval($final);
                    if ($final_value < 1.00 || $final_value > 5.00) {
                        $errors[] = "Invalid final grade for enrollment #$enrollment_id. Must be between 1.00 and 5.00.";
                        continue;
                    }
                }

                // Auto-set status based on final grade
                // Philippine grading: 1.00-3.00 = Passed, 3.01-5.00 = Failed (5.00 is outright fail)
                if ($final_value !== null) {
                    if ($final_value <= 3.00) {
                        $status = 'Passed';
                    } else {
                        // Anything above 3.00 (including 3.01-5.00) is Failed
                        $status = 'Failed';
                    }
                } else {
                    // If no final grade, only allow Enrolled
                    $status = 'Enrolled';
                }

                // Update enrollment
                $update_sql = "UPDATE enrollments SET midterm_grade = ?, final_grade = ?, status = ? WHERE enrollment_id = ? AND student_id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param('ddsii', $midterm_value, $final_value, $status, $enrollment_id, $student_id);
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    $updated++;
                }
                $stmt->close();
            }

            if (empty($errors)) {
                $conn->commit();
                $message = "Grades updated successfully! ($updated enrollments processed)";
                $message_type = 'success';
            } else {
                $conn->rollback();
                $message = implode('<br>', $errors);
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error updating grades: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Get enrollments with grades for this student
$grades_sql = "SELECT e.*, c.course_code, c.course_name, c.units, c.year_level, c.semester
               FROM enrollments e
               JOIN curriculum c ON e.curriculum_id = c.curriculum_id
               WHERE e.student_id = ?";
$params = [$student_id];
$types = 'i';

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

$conn->close();

$student_list_url = getStudentListReturnUrl('..');
$records_url = appendReturnParam('student_schedule_grades.php?id=' . $student_id . '&tab=grades', $student_list_url);
$personal_info_url = appendReturnParam('student_personal.php?id=' . $student_id, $student_list_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Grades - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/forms_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-edit-grades">
    <?php renderAppSidebar(['active' => 'students', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Edit Grades</h1>
            <div class="header-actions">
                <a href="<?php echo htmlspecialchars($student_list_url); ?>" class="btn btn-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Student List</a>
                <a href="<?php echo htmlspecialchars($records_url); ?>" class="btn btn-grades"><i class="bi bi-journal-bookmark" aria-hidden="true"></i>View Records</a>
                <a href="<?php echo htmlspecialchars($personal_info_url); ?>" class="btn btn-info"><i class="bi bi-person-vcard" aria-hidden="true"></i>Personal Info</a>
            </div>
        </header>

        <?php renderPageBreadcrumbs([
            ['label' => 'Students', 'href' => $student_list_url],
            ['label' => 'Records', 'href' => $records_url],
            ['label' => 'Edit Grades']
        ]); ?>

        <div class="student-header">
            <div class="student-name"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? '')); ?></div>
            <div class="student-info-summary">
                <span class="info-item"><strong>Student No:</strong> <?php echo htmlspecialchars($student['student_number']); ?></span>
                <span class="info-item"><strong>Program:</strong> <?php echo htmlspecialchars($student['program_code'] . ' - ' . $student['program_name']); ?></span>
                <span class="info-item"><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level']); ?></span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="grade-help">
            <strong>Grade Entry Guide (Philippine Grading System)</strong>
            <div class="grade-scale">
                <span>Select from standardized grades (1.00 - 5.00)</span>
                <span>Leaving blank removes the grade</span>
            </div>
            Status is auto-set based on final grade: ≤3.00 = Passed, >3.00 = Failed
        </div>

        <div class="filter-section">
            <form method="get" class="filter-form">
                <input type="hidden" name="id" value="<?php echo $student_id; ?>">
                <label for="yearFilter">Year Level:</label>
                <select id="yearFilter" name="year" class="sort-select">
                    <option value="0" <?php echo $selected_year === 0 ? 'selected' : ''; ?>>All Years</option>
                    <?php for ($y = 1; $y <= 4; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $selected_year === $y ? 'selected' : ''; ?>>Year <?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <label for="semesterFilter">Semester:</label>
                <select id="semesterFilter" name="semester" class="sort-select">
                    <option value="0" <?php echo $selected_semester === 0 ? 'selected' : ''; ?>>All Semesters</option>
                    <option value="1" <?php echo $selected_semester === 1 ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2" <?php echo $selected_semester === 2 ? 'selected' : ''; ?>>2nd Semester</option>
                </select>
                <button type="submit" class="btn">Filter</button>
            </form>
        </div>

        <div class="section">
            <?php if (empty($grades)): ?>
                <p class="no-data">No enrollments found for this student<?php echo $selected_year > 0 ? ' for the selected filters' : ''; ?>.</p>
            <?php else: ?>
                <form method="post">
                    <?php echo csrf_token_field($csrf_scope); ?>
                    <?php
                    // Group grades by year and semester
                    $grouped_grades = [];
                    foreach ($grades as $grade) {
                        $key = 'Year ' . $grade['year_level'] . ' - Semester ' . $grade['semester'];
                        if (!isset($grouped_grades[$key])) {
                            $grouped_grades[$key] = [];
                        }
                        $grouped_grades[$key][] = $grade;
                    }
                    ?>
                    
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
                                <?php foreach ($group as $grade): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($grade['course_code']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['course_name']); ?></td>
                                        <td class="center"><?php echo htmlspecialchars($grade['units']); ?></td>
                                        <td class="center">
                                            <select name="grades[<?php echo $grade['enrollment_id']; ?>][midterm]" class="grade-input">
                                                <option value="">-</option>
                                                <?php 
                                                $opts = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00'];
                                                $curr = $grade['midterm_grade'] !== null ? number_format($grade['midterm_grade'], 2) : '';
                                                foreach ($opts as $opt): 
                                                ?>
                                                    <option value="<?php echo $opt; ?>" <?php echo $curr === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="center">
                                            <select name="grades[<?php echo $grade['enrollment_id']; ?>][final]" class="grade-input">
                                                <option value="">-</option>
                                                <?php 
                                                $curr_final = $grade['final_grade'] !== null ? number_format($grade['final_grade'], 2) : '';
                                                foreach ($opts as $opt): 
                                                ?>
                                                    <option value="<?php echo $opt; ?>" <?php echo $curr_final === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="center">
                                            <select name="grades[<?php echo $grade['enrollment_id']; ?>][status]" class="status-select">
                                                <option value="Enrolled" <?php echo $grade['status'] === 'Enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                                                <option value="Passed" <?php echo $grade['status'] === 'Passed' ? 'selected' : ''; ?>>Passed</option>
                                                <option value="Failed" <?php echo $grade['status'] === 'Failed' ? 'selected' : ''; ?>>Failed</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>

                    <div class="form-actions">
                        <a href="<?php echo htmlspecialchars($records_url); ?>" class="btn btn-back">Cancel</a>
                        <button type="submit" class="btn btn-add">Save All Grades</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <script>
        // Grade dropdowns (No validation needed for select)

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
    </script>

</body>
</html>
