<?php
require_once '../config/db_helpers.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$conn = getDBConnection();

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

// If confirmed, delete the student
if ($confirm === 'yes' || $confirm === '1') {
    $conn->begin_transaction();
    
    try {
        // Delete dependent records first (to satisfy FK constraints)
        db_query($conn, "DELETE FROM payments WHERE student_id = ?", 'i', [$student_id]);
        db_query($conn, "DELETE FROM semester_status WHERE student_id = ?", 'i', [$student_id]);
        
        // Decrement schedule enrolled counts
        // We need to do this BEFORE deleting enrollments so we know which courses to update
        $enrolled_courses_sql = "SELECT curriculum_id FROM enrollments WHERE student_id = ?";
        $courses_res = db_query($conn, $enrolled_courses_sql, 'i', [$student_id]);
        $course_ids = [];
        while($row = $courses_res->fetch_assoc()) {
            $course_ids[] = $row['curriculum_id'];
        }
        
        if (!empty($course_ids)) {
            // Decrement count using prepared statement, ensuring it doesn't drop below zero
            $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
            $types = str_repeat('i', count($course_ids));
            db_query($conn, "UPDATE schedules SET enrolled_count = GREATEST(0, enrolled_count - 1) WHERE curriculum_id IN ($placeholders)", $types, $course_ids);
        }
        
        // Delete student scholarships
        db_query($conn, "DELETE FROM student_scholarships WHERE student_id = ?", 'i', [$student_id]);
        
        // Delete academic standings
        db_query($conn, "DELETE FROM academic_standings WHERE student_id = ?", 'i', [$student_id]);
        
        // Delete enrollments
        $delete_enrollments = db_query($conn, "DELETE FROM enrollments WHERE student_id = ?", 'i', [$student_id]);
        
        // Delete student
        $delete_student = db_query($conn, "DELETE FROM students WHERE student_id = ?", 'i', [$student_id]);
        
        $conn->commit();
        $conn->close();
        
        // Redirect with success message
        header('Location: ../index.php?msg=dropped&name=' . urlencode($student_name));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->close();
        header('Location: ../index.php?msg=error');
        exit;
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
    <link rel="stylesheet" href="../css/common.css">
    <link rel="stylesheet" href="../css/enhancements.css">
    <link rel="stylesheet" href="../css/details.css">
    <script src="../js/app.js" defer></script>
    <style>
        .confirm-container {
            max-width: 500px;
            margin: 50px auto;
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
        }
        .confirm-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .confirm-title {
            font-size: 24px;
            font-weight: 600;
            color: #343a40;
            margin-bottom: 15px;
        }
        .confirm-message {
            font-size: 16px;
            color: #6c757d;
            margin-bottom: 10px;
            line-height: 1.6;
        }
        .student-info-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            text-align: left;
        }
        .student-info-box p {
            margin: 8px 0;
            font-size: 14px;
        }
        .student-info-box strong {
            color: #495057;
        }
        .warning-text {
            color: #dc3545;
            font-weight: 500;
            margin-top: 15px;
            font-size: 14px;
        }
        .confirm-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }
        .confirm-buttons .btn {
            padding: 12px 30px;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="confirm-container">
            <div class="confirm-title">Drop Student?</div>
            <div class="confirm-message">
                Are you sure you want to drop this student from the system?
            </div>
            
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
                <a href="../index.php" class="btn btn-cancel">Cancel</a>
                <a href="drop_student.php?id=<?php echo $student_id; ?>&confirm=yes" class="btn btn-drop">Drop Student</a>
            </div>
        </div>
    </div>

</body>
</html>
