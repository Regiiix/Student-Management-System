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

$semester_value = intval($student['current_semester'] ?? 0);
if ($semester_value === 1) {
    $semester_label = '1st Sem';
} elseif ($semester_value === 2) {
    $semester_label = '2nd Sem';
} elseif ($semester_value === 0) {
    $semester_label = 'Summer';
} else {
    $semester_label = 'Sem ' . $semester_value;
}

$term_summary = '';
$term_options = get_student_term_options($conn, $student_id);
$current_year_level = intval($student['year_level'] ?? 0);
$current_ay = '';

foreach ($term_options as $option) {
    if (intval($option['yl']) === $current_year_level && intval($option['sem']) === $semester_value) {
        $current_ay = (string)($option['ay'] ?? '');
        if (strpos((string)($option['label'] ?? ''), '- Current') !== false) {
            break;
        }
    }
}

if ($current_ay === '') {
    $settings = getSystemSettings($conn);
    $current_ay = (string)($settings['current_academic_year'] ?? '');
}

if ($current_ay !== '') {
    $term_summary = $semester_label . ' | A.Y. ' . $current_ay;
}

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
                                <?php echo htmlspecialchars($student['year_level']); ?>
                                <?php if ($term_summary !== ''): ?>
                                <span style="color: #666; font-size: 0.9em;">
                                    (<?php echo htmlspecialchars($term_summary); ?>)
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Current Semester</th>
                            <td><?php echo htmlspecialchars($semester_label); ?></td>
                            <th>Current Academic Year</th>
                            <td><?php echo htmlspecialchars($current_ay !== '' ? $current_ay : 'N/A'); ?></td>
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
