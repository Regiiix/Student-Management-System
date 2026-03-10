<?php
/**
 * Scholarship Management Page
 * Manage student scholarships and discounts
 */
require_once '../config/db_helpers.php';
require_once '../config/finance_helpers.php';

$conn = getDBConnection();
$message = '';
$message_type = '';

// Get current academic year from settings
$settings = [];
$set_res = db_query($conn, "SELECT setting_key, setting_value FROM system_settings");
while ($row = $set_res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$current_ay = $settings['current_academic_year'] ?? (date('Y') . '-' . (date('Y') + 1));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'award':
                $student_id = intval($_POST['student_id'] ?? 0);
                $scholarship_id = intval($_POST['scholarship_id'] ?? 0);
                $ay = $_POST['academic_year'] ?? $current_ay;
                $sem = intval($_POST['semester'] ?? 1);
                $notes = trim($_POST['notes'] ?? '');
                
                if ($student_id > 0 && $scholarship_id > 0) {
                    if (awardScholarship($conn, $student_id, $scholarship_id, $ay, $sem, $notes)) {
                        $message = 'Scholarship awarded successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Scholarship already awarded or error occurred.';
                        $message_type = 'error';
                    }
                } else {
                    $message = 'Please select a student and scholarship.';
                    $message_type = 'error';
                }
                break;
                
            case 'revoke':
                $ss_id = intval($_POST['student_scholarship_id'] ?? 0);
                if ($ss_id > 0) {
                    if (revokeScholarship($conn, $ss_id)) {
                        $message = 'Scholarship revoked successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Error revoking scholarship.';
                        $message_type = 'error';
                    }
                }
                break;
        }
    }
}

// Get all scholarships
$scholarships = getAllScholarships($conn);

// Get all students for dropdown (include current_semester for autofill)
$students_sql = "SELECT student_id, student_number, first_name, last_name, s.current_semester, p.program_code
                 FROM students s 
                 LEFT JOIN programs p ON s.program_id = p.program_id
                 WHERE s.status = 'Active'
                 ORDER BY s.last_name, s.first_name";
$students = db_fetch_all(db_query($conn, $students_sql));

// Create lookup array for JavaScript
$students_data = [];
foreach ($students as $st) {
    $students_data[$st['student_id']] = [
        'semester' => $st['current_semester']
    ];
}

// Get filter parameters
$filter_ay = isset($_GET['ay']) ? $_GET['ay'] : $current_ay;
$filter_sem = isset($_GET['sem']) ? intval($_GET['sem']) : 0;
$filter_scholarship = isset($_GET['scholarship']) ? intval($_GET['scholarship']) : 0;

// Get awarded scholarships with filters
$awards_sql = "SELECT ss.*, s.code, s.name as scholarship_name, s.discount_type, s.discount_value, s.applies_to,
               st.student_id, st.student_number, st.first_name, st.last_name, p.program_code
               FROM student_scholarships ss
               JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
               JOIN students st ON ss.student_id = st.student_id
               LEFT JOIN programs p ON st.program_id = p.program_id
               WHERE 1=1";

$award_params = [];
$award_types = '';

if ($filter_ay) {
    $awards_sql .= " AND ss.academic_year = ?";
    $award_params[] = $filter_ay;
    $award_types .= 's';
}
if ($filter_sem > 0) {
    $awards_sql .= " AND ss.semester = ?";
    $award_params[] = $filter_sem;
    $award_types .= 'i';
}
if ($filter_scholarship > 0) {
    $awards_sql .= " AND ss.scholarship_id = ?";
    $award_params[] = $filter_scholarship;
    $award_types .= 'i';
}

$awards_sql .= " ORDER BY ss.academic_year DESC, ss.semester DESC, st.last_name";

$awards_result = db_query($conn, $awards_sql, $award_types, $award_params);
$awards = $awards_result ? db_fetch_all($awards_result) : [];

// Get scholarship statistics
$stats_sql = "SELECT 
    COUNT(*) as total_awards,
    COUNT(DISTINCT ss.student_id) as unique_students,
    SUM(CASE WHEN ss.status = 'Active' THEN 1 ELSE 0 END) as active_awards,
    s.discount_type,
    SUM(CASE WHEN s.discount_type = 'percentage' THEN s.discount_value ELSE 0 END) as total_percent
FROM student_scholarships ss
JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
WHERE ss.academic_year = ?";
$stats = db_fetch_one(db_query($conn, $stats_sql, 's', [$current_ay]));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Management</title>
    <link rel="stylesheet" href="../css/common.css">
    <link rel="stylesheet" href="../css/enhancements.css">
    <link rel="stylesheet" href="../css/details.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-card h3 {
            font-size: 2em;
            margin: 0;
        }
        .stat-card p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 0.9em;
        }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #4e73df 0%, #36b9cc 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .scholarship-table {
            width: 100%;
            border-collapse: collapse;
        }
        .scholarship-table th, .scholarship-table td {
            padding: 12px;
            border: 1px solid #eee;
            text-align: left;
        }
        .scholarship-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .scholarship-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-active { background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 4px; }
        .status-revoked { background: #f8d7da; color: #721c24; padding: 3px 8px; border-radius: 4px; }
        .status-expired { background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 4px; }
        
        .discount-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85em;
        }
        
        .filter-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            color: #666;
        }
        .tab.active {
            border-bottom-color: #4e73df;
            color: #4e73df;
            font-weight: 600;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Scholarship Management</h1>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-back">← Back to Dashboard</a>
                <a href="finance.php" class="btn btn-primary">Finance Dashboard</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo $stats['total_awards'] ?? 0; ?></h3>
                <p>Total Awards</p>
            </div>
            <div class="stat-card green">
                <h3><?php echo $stats['unique_students'] ?? 0; ?></h3>
                <p>Scholars</p>
            </div>
            <div class="stat-card blue">
                <h3><?php echo $stats['active_awards'] ?? 0; ?></h3>
                <p>Active This Term</p>
            </div>
            <div class="stat-card orange">
                <h3><?php echo count($scholarships); ?></h3>
                <p>Scholarship Types</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('awards')">Awarded Scholarships</div>
            <div class="tab" onclick="showTab('award-new')">Award New</div>
            <div class="tab" onclick="showTab('scholarship-types')">Scholarship Types</div>
        </div>

        <!-- Tab: Awarded Scholarships -->
        <div id="tab-awards" class="tab-content active">
            <div class="card">
                <form method="get" class="filter-form">
                    <label>Academic Year:</label>
                    <input type="text" name="ay" value="<?php echo htmlspecialchars($filter_ay); ?>" placeholder="e.g., 2025-2026" class="form-control w-120">
                    
                    <label>Semester:</label>
                    <select name="sem" class="form-control w-120">
                        <option value="0">All</option>
                        <option value="1" <?php echo $filter_sem == 1 ? 'selected' : ''; ?>>1st</option>
                        <option value="2" <?php echo $filter_sem == 2 ? 'selected' : ''; ?>>2nd</option>
                    </select>
                    
                    <label>Scholarship:</label>
                    <select name="scholarship" class="form-control w-180">
                        <option value="0">All Types</option>
                        <?php foreach ($scholarships as $s): ?>
                            <option value="<?php echo $s['scholarship_id']; ?>" <?php echo $filter_scholarship == $s['scholarship_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="scholarships.php" class="btn">Clear</a>
                </form>

                <table class="scholarship-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Scholarship</th>
                            <th>Discount</th>
                            <th>Term</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($awards)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No scholarships found matching filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($awards as $a): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($a['last_name'] . ', ' . $a['first_name']); ?></strong><br>
                                        <small class="text-secondary"><?php echo htmlspecialchars($a['student_number']); ?> • <?php echo htmlspecialchars($a['program_code']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($a['scholarship_name']); ?><br>
                                        <small class="text-secondary"><?php echo htmlspecialchars($a['code']); ?></small>
                                    </td>
                                    <td>
                                        <span class="discount-badge">
                                            <?php 
                                            if ($a['discount_type'] === 'percentage') {
                                                echo $a['discount_value'] . '% off ' . $a['applies_to'];
                                            } else {
                                                echo '₱' . number_format($a['discount_value'], 2) . ' off ' . $a['applies_to'];
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo $a['academic_year'] . ' Sem ' . $a['semester']; ?></td>
                                    <td>
                                        <span class="status-<?php echo strtolower($a['status']); ?>">
                                            <?php echo $a['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($a['status'] === 'Active'): ?>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Revoke this scholarship?');">
                                                <input type="hidden" name="action" value="revoke">
                                                <input type="hidden" name="student_scholarship_id" value="<?php echo $a['student_scholarship_id']; ?>">
                                                <button type="submit" class="btn btn-danger">Revoke</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab: Award New Scholarship -->
        <div id="tab-award-new" class="tab-content">
            <div class="card">
                <h3>Award Scholarship to Student</h3>
                <form method="post">
                    <input type="hidden" name="action" value="award">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="student_id">Student *</label>
                            <select name="student_id" id="student_id" class="form-control" required onchange="autofillStudentTerm()">
                                <option value="">-- Select Student --</option>
                                <?php foreach ($students as $st): ?>
                                    <option value="<?php echo $st['student_id']; ?>" data-semester="<?php echo $st['current_semester']; ?>">
                                        <?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name'] . ' (' . $st['student_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="scholarship_id">Scholarship *</label>
                            <select name="scholarship_id" id="scholarship_id" class="form-control" required>
                                <option value="">-- Select Scholarship --</option>
                                <?php foreach ($scholarships as $s): ?>
                                    <option value="<?php echo $s['scholarship_id']; ?>">
                                        <?php echo htmlspecialchars($s['name'] . ' (' . ($s['discount_type'] === 'percentage' ? $s['discount_value'] . '%' : '₱' . $s['discount_value']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <input type="text" name="academic_year" id="academic_year" class="form-control" 
                                   value="<?php echo htmlspecialchars($current_ay); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="semester">Semester *</label>
                            <select name="semester" id="semester" class="form-control" required>
                                <option value="1">1st Semester</option>
                                <option value="2">2nd Semester</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group mt-md">
                        <label for="notes">Notes (Optional)</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Any additional notes..."></textarea>
                    </div>
                    
                    <div class="form-actions mt-md">
                        <button type="submit" class="btn btn-primary">Award Scholarship</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tab: Scholarship Types -->
        <div id="tab-scholarship-types" class="tab-content">
            <div class="card">
                <h3>Available Scholarship Types</h3>
                <table class="scholarship-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Discount</th>
                            <th>Applies To</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scholarships as $s): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($s['code']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['description'] ?? '-'); ?></td>
                                <td>
                                    <span class="discount-badge">
                                        <?php echo $s['discount_type'] === 'percentage' ? $s['discount_value'] . '%' : '₱' . number_format($s['discount_value'], 2); ?>
                                    </span>
                                </td>
                                <td><?php echo ucfirst($s['applies_to']); ?></td>
                                <td>
                                    <span class="status-<?php echo $s['is_active'] ? 'active' : 'revoked'; ?>">
                                        <?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            
            // Show selected tab
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Autofill academic year and semester based on student's current term
        function autofillStudentTerm() {
            const studentSelect = document.getElementById('student_id');
            const selectedOption = studentSelect.options[studentSelect.selectedIndex];
            const semesterSelect = document.getElementById('semester');
            
            if (selectedOption && selectedOption.value) {
                // Get student's current semester from data attribute
                const semester = selectedOption.getAttribute('data-semester');
                if (semester) {
                    semesterSelect.value = semester;
                }
            }
        }
    </script>

</body>
</html>
