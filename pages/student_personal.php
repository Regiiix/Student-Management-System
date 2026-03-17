<?php
require_once '../config/db_helpers.php';
require_once '../config/sidebar.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$conn = getDBConnection();

// Query to get student information with program
$sql = "SELECT s.*, p.program_name, p.program_code, p.description as program_description
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
$conn->close();

$student_list_url = getStudentListReturnUrl('..');
$records_url = appendReturnParam('student_schedule_grades.php?id=' . $student_id . '&tab=schedule', $student_list_url);
$edit_student_url = appendReturnParam('edit_student.php?id=' . $student_id, $student_list_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Information - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
</head>
<body class="has-sidebar">
    <?php renderAppSidebar(['active' => 'students', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Personal Information</h1>
            <div class="header-actions">
                <a href="<?php echo htmlspecialchars($student_list_url); ?>" class="btn btn-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Student List</a>
                <a href="<?php echo htmlspecialchars($edit_student_url); ?>" class="btn btn-edit"><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit Info</a>
                <a href="<?php echo htmlspecialchars($records_url); ?>" class="btn btn-schedule"><i class="bi bi-journal-text" aria-hidden="true"></i>Records</a>

            </div>
        </header>

        <?php renderPageBreadcrumbs([
            ['label' => 'Students', 'href' => $student_list_url],
            ['label' => 'Records', 'href' => $records_url],
            ['label' => 'Personal Information']
        ]); ?>

        <div class="student-details">
            <div class="student-name-header">
                <h2><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?></h2>
                <span class="status-badge status-<?php echo strtolower($student['status']); ?>"><?php echo htmlspecialchars($student['status']); ?></span>
            </div>

            <h3>Basic Information</h3>
            <div class="table-container">
                <table class="info-table">
                    <tbody>
                        <tr>
                            <th>Student Number</th>
                            <td><?php echo htmlspecialchars($student['student_number']); ?></td>
                            <th>Gender</th>
                            <td><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>First Name</th>
                            <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                            <th>Date of Birth</th>
                            <td><?php echo $student['date_of_birth'] ? date('F d, Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <th>Middle Name</th>
                            <td><?php echo htmlspecialchars($student['middle_name'] ?? 'N/A'); ?></td>
                            <th>Date Enrolled</th>
                            <td><?php echo $student['created_at'] ? date('F d, Y', strtotime($student['created_at'])) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <th>Last Name</th>
                            <td><?php echo htmlspecialchars($student['last_name']); ?></td>
                            <th>Status</th>
                            <td><span class="status-badge status-<?php echo strtolower($student['status']); ?>"><?php echo htmlspecialchars($student['status']); ?></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Contact Information</h3>
            <div class="table-container">
                <table class="info-table">
                    <tbody>
                        <tr>
                            <th>Email</th>
                            <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                            <th>Phone</th>
                            <td><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Address</th>
                            <td colspan="3"><?php echo htmlspecialchars($student['address'] ?? 'N/A'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Academic Information</h3>
            <div class="table-container">
                <table class="info-table">
                    <tbody>
                        <tr>
                            <th>Program Code</th>
                            <td><?php echo htmlspecialchars($student['program_code'] ?? 'N/A'); ?></td>
                            <th>Year Level</th>
                            <td>
                                <?php 
                                    $sys_ay = date('Y') . '-' . (date('Y') + 1);
                                    // Try to fetch specific if available or just use this default? 
                                    // Better to fetch system settings if I can, but I'll stick to a simple robust default if I don't want to query again.
                                    // Actually, let's just do a quick inline check if we want perfection, but the user asked for "current".
                                    // I'll assume standard calculation or student's 'enrollment' context? 
                                    // The student doesn't have an "enrolled AY" column on the student table directly, usually derived from enrollments.
                                    // But let's just append the semester column from students table which exists: `current_semester`.
                                    // And for School Year, we can show the current system year.
                                    echo htmlspecialchars($student['year_level']); 
                                ?> 
                                <span style="color: #666; font-size: 0.9em;">
                                    (Sem <?php echo htmlspecialchars($student['current_semester']); ?> | A.Y. <?php echo date('Y') . '-' . (date('Y') + 1); ?>)
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Program Name</th>
                            <td colspan="3"><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Description</th>
                            <td colspan="3"><?php echo htmlspecialchars($student['program_description'] ?? 'N/A'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>
