<?php
/**
 * Scholarship Management Page
 * Manage student scholarships and discounts
 */
require_once '../config/db_helpers.php';
require_once '../config/finance_helpers.php';
require_once '../config/csrf_helpers.php';

$conn = getDBConnection();
$message = '';
$message_type = '';
$csrf_scope = 'scholarships_management';

// Get current academic year from settings
$settings = getSystemSettings($conn);
$current_ay = $settings['current_academic_year'] ?? (date('Y') . '-' . (date('Y') + 1));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request_token($csrf_scope)) {
        $message = 'Invalid or expired security token. Please refresh and try again.';
        $message_type = 'error';
    } elseif (isset($_POST['action'])) {
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/details.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/reports_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-scholarships">
    <?php require_once '../config/sidebar.php'; ?>
    <?php renderAppSidebar(['active' => 'scholarships', 'basePath' => '..']); ?>
    <div class="container">
        <header>
            <h1>Scholarship Management</h1>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Dashboard</a>
                <a href="finance.php" class="btn btn-primary"><i class="bi bi-cash-stack" aria-hidden="true"></i>Finance Dashboard</a>
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
            <button type="button" class="tab active" data-tab="awards">Awarded Scholarships</button>
            <button type="button" class="tab" data-tab="award-new">Award New</button>
            <button type="button" class="tab" data-tab="scholarship-types">Scholarship Types</button>
        </div>

        <!-- Tab: Awarded Scholarships -->
        <div id="tab-awards" class="tab-content active">
            <div class="card">
                <form method="get" class="filter-form">
                    <div class="filter-field">
                        <label for="filter-ay">Academic Year</label>
                        <input id="filter-ay" type="text" name="ay" value="<?php echo htmlspecialchars($filter_ay); ?>" placeholder="e.g., 2025-2026" class="form-control">
                    </div>

                    <div class="filter-field">
                        <label for="filter-sem">Semester</label>
                        <select id="filter-sem" name="sem" class="form-control">
                            <option value="0">All</option>
                            <option value="1" <?php echo $filter_sem == 1 ? 'selected' : ''; ?>>1st</option>
                            <option value="2" <?php echo $filter_sem == 2 ? 'selected' : ''; ?>>2nd</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="filter-scholarship">Scholarship</label>
                        <select id="filter-scholarship" name="scholarship" class="form-control">
                            <option value="0">All Types</option>
                            <?php foreach ($scholarships as $s): ?>
                                <option value="<?php echo $s['scholarship_id']; ?>" <?php echo $filter_scholarship == $s['scholarship_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="scholarships.php" class="btn">Clear</a>
                    </div>
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
                                            <form method="post" class="inline-form" data-confirm="Revoke this scholarship?" data-confirm-title="Revoke Scholarship" data-confirm-text="Revoke" data-confirm-style="danger">
                                                <?php echo csrf_token_field($csrf_scope); ?>
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
                    <?php echo csrf_token_field($csrf_scope); ?>
                    <input type="hidden" name="action" value="award">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="student_id">Student *</label>
                            <select name="student_id" id="student_id" class="form-control" required>
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
        function showTab(tabName, triggerEl = null) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            
            // Show selected tab
            document.getElementById('tab-' + tabName).classList.add('active');

            const activeTrigger = triggerEl || document.querySelector(`.tab[data-tab="${tabName}"]`);
            if (activeTrigger) {
                activeTrigger.classList.add('active');
            }
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

        document.addEventListener('DOMContentLoaded', () => {
            const tabsContainer = document.querySelector('.tabs');
            if (tabsContainer) {
                tabsContainer.addEventListener('click', (event) => {
                    const trigger = event.target.closest('.tab[data-tab]');
                    if (!trigger) {
                        return;
                    }

                    showTab(trigger.getAttribute('data-tab'), trigger);
                });
            }

            const studentSelect = document.getElementById('student_id');
            if (studentSelect) {
                studentSelect.addEventListener('change', autofillStudentTerm);
            }
        });
    </script>

</body>
</html>
