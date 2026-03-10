<?php
require_once '../config/db_helpers.php';

$conn = getDBConnection();
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 1);
    $enroll_semester = intval($_POST['enroll_semester'] ?? 1);
    
    // Validation
    $errors = [];
    if (empty($first_name)) $errors[] = 'First name is required';
    if (empty($last_name)) $errors[] = 'Last name is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
    if (empty($date_of_birth)) $errors[] = 'Date of birth is required';
    if (empty($gender)) $errors[] = 'Gender is required';
    if ($year_level < 1 || $year_level > 4) $errors[] = 'Invalid year level';
    if ($enroll_semester < 1 || $enroll_semester > 2) $errors[] = 'Invalid semester';
    
    // Check for duplicate email
    if (empty($errors)) {
        $check_email = db_query($conn, "SELECT student_id FROM students WHERE email = ?", 's', [$email]);
        if ($check_email && $check_email->num_rows > 0) {
            $errors[] = 'Email already exists';
        }
    }
    
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
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
            
            // Insert student (middle_name is optional)
            $middle_name = trim($_POST['middle_name'] ?? '');
            $insert_sql = "INSERT INTO students (student_number, first_name, middle_name, last_name, email, date_of_birth, gender, address, phone, year_level, current_semester, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param('ssssssssiii', $student_number, $first_name, $middle_name, $last_name, $email, $date_of_birth, $gender, $address, $phone, $year_level, $enroll_semester);
            $stmt->execute();
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
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
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
    <link rel="stylesheet" href="../css/common.css">
    <link rel="stylesheet" href="../css/enhancements.css">
    <link rel="stylesheet" href="../css/details.css">
    <script src="../js/app.js" defer></script>
    <style>
        .add-student-form {
            max-width: 700px;
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
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
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
        .form-actions .btn {
            padding: 12px 30px;
            font-size: 15px;
        }
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        .required {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Add New Student</h1>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-back">← Back to Student List</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="student-details">
            <form method="post" class="add-student-form">
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
                        <div class="form-group">
                            <label for="enroll_semester">Current Semester <span class="required">*</span></label>
                            <select id="enroll_semester" name="enroll_semester" required>
                                <option value="1" <?php echo ($enroll_semester ?? 1) == 1 ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2" <?php echo ($enroll_semester ?? 1) == 2 ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="../index.php" class="btn btn-back">Cancel</a>
                    <button type="submit" class="btn btn-add" onclick="showSpinner()">Add Student</button>
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
            const form = document.querySelector('form');
            if (form.checkValidity()) {
                document.getElementById('loadingSpinner').classList.add('active');
            }
        }
    </script>

</body>
</html>
