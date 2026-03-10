<?php
require_once 'config/db_helpers.php';

// Handle notification messages
$notification = '';
$notification_type = '';
$show_added_modal = false;
$added_student = null;

if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'dropped':
            $notification = 'Student "' . htmlspecialchars($_GET['name'] ?? '') . '" has been successfully dropped.';
            $notification_type = 'success';
            break;
        case 'added':
            // Show modal instead of toast for added students
            $show_added_modal = true;
            $added_student = [
                'name' => htmlspecialchars($_GET['name'] ?? ''),
                'student_number' => htmlspecialchars($_GET['student_number'] ?? ''),
                'program' => htmlspecialchars($_GET['program'] ?? ''),
                'student_id' => intval($_GET['student_id'] ?? 0)
            ];
            break;
        case 'notfound':
            $notification = 'Student not found.';
            $notification_type = 'error';
            break;
        case 'error':
            $notification = 'An error occurred. Please try again.';
            $notification_type = 'error';
            break;
    }
}

$view = isset($_GET['view']) ? $_GET['view'] : 'students';

$sort_field = isset($_GET['sort_field']) ? $_GET['sort_field'] : 'last_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'asc';
$allowed_fields = ['last_name', 'student_number', 'year_level', 'program'];
if (!in_array($sort_field, $allowed_fields)) {
    $sort_field = 'last_name';
}
$sort_order = ($sort_order === 'asc' || $sort_order === 'desc') ? $sort_order : 'asc';

// Program filter
$filter_program = isset($_GET['filter_program']) ? intval($_GET['filter_program']) : 0;

// Pagination settings
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_condition = '';
$filter_params = [];
$filter_types = '';

// Build filter conditions
$conditions = [];
if (!empty($search)) {
    $search_like = '%' . $search . '%';
    $conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ? OR s.email LIKE ? OR p.program_code LIKE ?)";
    $filter_params = array_merge($filter_params, [$search_like, $search_like, $search_like, $search_like, $search_like]);
    $filter_types .= 'sssss';
}
if ($filter_program > 0) {
    $conditions[] = "s.program_id = ?";
    $filter_params[] = $filter_program;
    $filter_types .= 'i';
}
if (!empty($conditions)) {
    $filter_condition = " WHERE " . implode(' AND ', $conditions);
}

$conn = getDBConnection();

// --- Count total students for pagination ---
$count_sql = "SELECT COUNT(*) as total FROM students s LEFT JOIN programs p ON s.program_id = p.program_id" . $filter_condition;
if (!empty($filter_params)) {
    $count_result = db_query($conn, $count_sql, $filter_types, $filter_params);
} else {
    $count_result = db_query($conn, $count_sql);
}
$total_students = db_fetch_one($count_result)['total'] ?? 0;
$total_pages = ceil($total_students / $items_per_page);

// --- Prepare students query with pagination ---
$order_by = '';
switch ($sort_field) {
    case 'last_name':
        $order_by = 's.last_name';
        break;
    case 'student_number':
        $order_by = 's.student_number';
        break;
    case 'year_level':
        $order_by = 's.year_level';
        break;
    case 'program':
        $order_by = 'p.program_code, p.program_name';
        break;
    default:
        $order_by = 's.last_name';
}
$students_sql = "SELECT s.*, p.program_name, p.program_code 
        FROM students s 
        LEFT JOIN programs p ON s.program_id = p.program_id" 
        . $filter_condition . "
        ORDER BY $order_by " . ($sort_order === 'asc' ? 'ASC' : 'DESC') . "
        LIMIT $items_per_page OFFSET $offset";
if (!empty($filter_params)) {
    $students_result = db_query($conn, $students_sql, $filter_types, $filter_params);
} else {
    $students_result = db_query($conn, $students_sql);
}

// --- Load programs for selectors (cached for performance) ---
$programs = getCachedPrograms($conn);

// --- Curriculum (courses) filters and pagination ---
$curriculum_program = isset($_GET['curriculum_program']) ? intval($_GET['curriculum_program']) : 0;
$curriculum_year = isset($_GET['curriculum_year']) ? intval($_GET['curriculum_year']) : 0;
$curriculum_semester = isset($_GET['curriculum_semester']) ? intval($_GET['curriculum_semester']) : 0;
$curriculum_page = isset($_GET['cpage']) ? max(1, intval($_GET['cpage'])) : 1;
$curriculum_per_page = 15;
$curriculum_offset = ($curriculum_page - 1) * $curriculum_per_page;

// Count total curriculum items
$curriculum_where_clauses = [];
$curriculum_params = [];
$curriculum_types = '';

if ($curriculum_program > 0) {
    $curriculum_where_clauses[] = "c.program_id = ?";
    $curriculum_params[] = $curriculum_program;
    $curriculum_types .= 'i';
}
if ($curriculum_year > 0) {
    $curriculum_where_clauses[] = "c.year_level = ?";
    $curriculum_params[] = $curriculum_year;
    $curriculum_types .= 'i';
}
if ($curriculum_semester > 0) {
    $curriculum_where_clauses[] = "c.semester = ?";
    $curriculum_params[] = $curriculum_semester;
    $curriculum_types .= 'i';
}

$count_curriculum_sql = "SELECT COUNT(*) as total FROM curriculum c";
if (!empty($curriculum_where_clauses)) {
    $count_curriculum_sql .= " WHERE " . implode(' AND ', $curriculum_where_clauses);
}

$count_curriculum_result = db_query($conn, $count_curriculum_sql, $curriculum_types, $curriculum_params);
$total_curriculum = db_fetch_one($count_curriculum_result)['total'] ?? 0;
$total_curriculum_pages = ceil($total_curriculum / $curriculum_per_page);

$curriculum_sql = "SELECT c.*, p.program_code, p.program_name 
                   FROM curriculum c 
                   LEFT JOIN programs p ON c.program_id = p.program_id";
if (!empty($curriculum_where_clauses)) {
    $curriculum_sql .= " WHERE " . implode(' AND ', $curriculum_where_clauses);
}

$curriculum_sql .= " ORDER BY c.program_id, c.year_level, c.semester, c.course_code";
$curriculum_sql .= " LIMIT $curriculum_per_page OFFSET $curriculum_offset";

// Add result-limiting logic only to the SQL string, but execute with same params
$curriculum_result = db_query($conn, $curriculum_sql, $curriculum_types, $curriculum_params);

// --- Selected program detail ---
$selected_program = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;
if ($selected_program > 0) {
    $sel_sql = "SELECT program_id, program_code, program_name, description FROM programs WHERE program_id = ?";
    $sel_res = db_query($conn, $sel_sql, 'i', [$selected_program]);
    $sel_prog = db_fetch_one($sel_res);
} else {
    $sel_prog = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management System</title>
    <link rel="stylesheet" href="css/common.css">
    <link rel="stylesheet" href="css/enhancements.css">
    <link rel="stylesheet" href="css/index.css">
    <script src="js/app.js" defer></script>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-top">
                <h1>Student Management System</h1>
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
            <?php if ($view === 'students'): ?>
                        <?php if (!isset($students_result) || $students_result === false): ?>
                        <table class="skeleton-table">
                            <?php for ($i = 0; $i < 8; $i++): ?>
                            <tr class="skeleton-row">
                                <?php for ($j = 0; $j < 7; $j++): ?>
                                <td class="skeleton-cell"></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endfor; ?>
                        </table>
                        <?php endif; ?>
            <div class="header-search">
                <form method="get" class="search-form">
                    <input type="hidden" name="view" value="students">
                    <input type="hidden" name="sort_field" value="<?php echo htmlspecialchars($sort_field); ?>">
                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                    <input type="text" name="search" class="search-input" placeholder="Search students..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-search">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="?view=students" class="btn btn-clear">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </header>

        <nav class="mobile-nav" id="mobileNav">
            <a class="nav-link <?php echo $view === 'students' ? 'active' : ''; ?>" href="?view=students">Students</a>
            <a class="nav-link <?php echo ($view === 'programs' || $view === 'curriculum') ? 'active' : ''; ?>" href="?view=programs">Academics</a>
            <a class="nav-link" href="pages/enrollment.php">Enrollment</a>
            <a class="nav-link" href="pages/dashboard.php">Analytics</a>
            <a class="nav-link" href="pages/finance.php">Finance</a>
        </nav>

        <div class="controls card">
            <div class="view-buttons">
                <a class="btn <?php echo $view === 'students' ? 'btn-primary' : ''; ?>" href="?view=students">Students</a>
                <a class="btn <?php echo $view === 'programs' ? 'btn-primary' : ''; ?>" href="?view=programs">Programs</a>
                <a class="btn <?php echo $view === 'curriculum' ? 'btn-primary' : ''; ?>" href="?view=curriculum">Curriculum</a>
                <a class="btn" href="pages/enrollment.php">Enrollment</a>
                <a class="btn" href="pages/dashboard.php">Analytics</a>
                <a class="btn" href="pages/finance.php">Finance</a>
                <?php if ($view === 'students'): ?>
                <a class="btn btn-primary" href="pages/add_student.php">Add Student</a>
                <?php endif; ?>
            </div>

            <?php if ($view === 'students'): ?>
            <div class="inline-filters">
                <select id="filterProgram" class="filter-select" onchange="applyFilters()">
                    <option value="0">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?php echo $p['program_id']; ?>" <?php echo $filter_program == $p['program_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['program_code']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="sortField" class="filter-select" onchange="applyFilters()">
                    <option value="last_name" <?php echo $sort_field === 'last_name' ? 'selected' : ''; ?>>Sort: Name</option>
                    <option value="student_number" <?php echo $sort_field === 'student_number' ? 'selected' : ''; ?>>Sort: Student #</option>
                    <option value="year_level" <?php echo $sort_field === 'year_level' ? 'selected' : ''; ?>>Sort: Year</option>
                    <option value="program" <?php echo $sort_field === 'program' ? 'selected' : ''; ?>>Sort: Program</option>
                </select>
                <button class="filter-order-btn" onclick="toggleSortOrder()" title="Toggle sort order">
                    <?php echo $sort_order === 'asc' ? '↑' : '↓'; ?>
                </button>
                <?php if ($filter_program > 0 || $sort_field !== 'last_name' || $sort_order !== 'asc'): ?>
                <a href="?view=students" class="filter-reset" title="Reset filters">×</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($view === 'curriculum'): ?>
            <div class="inline-filters">
                <form method="get" class="inline-filter-form" id="currFilterForm">
                    <input type="hidden" name="view" value="curriculum">
                    <select name="curriculum_program" class="filter-select" onchange="this.form.submit()">
                        <option value="0">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo $p['program_id']; ?>" <?php echo $curriculum_program == $p['program_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['program_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="curriculum_year" class="filter-select" onchange="this.form.submit()">
                        <option value="0">All Years</option>
                        <option value="1" <?php echo $curriculum_year == 1 ? 'selected' : ''; ?>>Year 1</option>
                        <option value="2" <?php echo $curriculum_year == 2 ? 'selected' : ''; ?>>Year 2</option>
                        <option value="3" <?php echo $curriculum_year == 3 ? 'selected' : ''; ?>>Year 3</option>
                        <option value="4" <?php echo $curriculum_year == 4 ? 'selected' : ''; ?>>Year 4</option>
                    </select>
                    <select name="curriculum_semester" class="filter-select" onchange="this.form.submit()">
                        <option value="0">All Semesters</option>
                        <option value="1" <?php echo $curriculum_semester == 1 ? 'selected' : ''; ?>>1st Sem</option>
                        <option value="2" <?php echo $curriculum_semester == 2 ? 'selected' : ''; ?>>2nd Sem</option>
                    </select>
                    <?php if ($curriculum_program > 0 || $curriculum_year > 0 || $curriculum_semester > 0): ?>
                    <a href="?view=curriculum" class="filter-reset" title="Reset filters">×</a>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($view === 'students'): ?>
            <!-- Removed redundant Students section title -->
            <div id="studentTable" class="table-container card">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Student No.</th>
                            <th>Last Name</th>
                            <th>First Name</th>
                            <th>Program</th>
                            <th>Year</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($students_result && $students_result->num_rows > 0) {
                            while ($row = $students_result->fetch_assoc()) {
                                $firstName = htmlspecialchars($row['first_name'] . ($row['middle_name'] ? ' ' . substr($row['middle_name'], 0, 1) . '.' : ''));
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['student_number']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['last_name']) . "</td>";
                                echo "<td>" . $firstName . "</td>";
                                echo "<td>" . htmlspecialchars($row['program_code'] ?? 'N/A') . "</td>";
                                echo "<td>" . htmlspecialchars($row['year_level']) . "</td>";
                                echo "<td><span class='status-badge status-" . strtolower($row['status']) . "'>" . htmlspecialchars($row['status']) . "</span></td>";
                                echo "<td class='action-buttons'>";
                                echo "<a class='btn btn-action btn-info' href='pages/student_personal.php?id=" . $row['student_id'] . "' title='View Personal Information'>Info</a>";
                                echo "<a class='btn btn-action btn-schedule' href='pages/student_schedule_grades.php?id=" . $row['student_id'] . "&tab=schedule' title='View Schedule & Grades'>Records</a>";
                                echo "<a class='btn btn-action btn-finance' href='pages/student_finance.php?id=" . $row['student_id'] . "' title='View Financial Records'>Finance</a>";

                                echo "<button class='btn btn-action btn-drop' onclick=\"confirmDrop('" . $row['student_id'] . "', '" . htmlspecialchars($row['last_name'] . ", " . $row['first_name'], ENT_QUOTES) . "')\" title='Drop Student'>Drop</button>";
                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='no-data'>No students found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <span class="pagination-info">Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $items_per_page, $total_students); ?> of <?php echo $total_students; ?> students</span>
                    <div class="pagination-controls">
                        <?php 
                        $pagination_params = "view=students&sort_field=$sort_field&sort_order=$sort_order&filter_program=$filter_program" . (!empty($search) ? "&search=" . urlencode($search) : "");
                        ?>
                        <?php if ($current_page > 1): ?>
                            <a href="?<?php echo $pagination_params; ?>&page=1" class="btn btn-page" title="First">&laquo;</a>
                            <a href="?<?php echo $pagination_params; ?>&page=<?php echo $current_page - 1; ?>" class="btn btn-page" title="Previous">&lsaquo;</a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        for ($p = $start_page; $p <= $end_page; $p++):
                        ?>
                            <a href="?<?php echo $pagination_params; ?>&page=<?php echo $p; ?>" class="btn btn-page <?php echo $p === $current_page ? 'btn-page-active' : ''; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?<?php echo $pagination_params; ?>&page=<?php echo $current_page + 1; ?>" class="btn btn-page" title="Next">&rsaquo;</a>
                            <a href="?<?php echo $pagination_params; ?>&page=<?php echo $total_pages; ?>" class="btn btn-page" title="Last">&raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'programs'): ?>
                        <?php if (!isset($programs) || empty($programs)): ?>
                        <table class="skeleton-table">
                            <?php for ($i = 0; $i < 6; $i++): ?>
                            <tr class="skeleton-row">
                                <?php for ($j = 0; $j < 3; $j++): ?>
                                <td class="skeleton-cell"></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endfor; ?>
                        </table>
                        <?php endif; ?>
                        <?php if (!isset($curriculum_result) || $curriculum_result === false): ?>
                        <table class="skeleton-table">
                            <?php for ($i = 0; $i < 8; $i++): ?>
                            <tr class="skeleton-row">
                                <?php for ($j = 0; $j < 7; $j++): ?>
                                <td class="skeleton-cell"></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endfor; ?>
                        </table>
                        <?php endif; ?>
            <!-- Removed redundant Programs section title -->
            <div id="programsTable" class="table-container card">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Program Code</th>
                            <th>Program Name</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($programs)): ?>
                            <?php foreach ($programs as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['program_code']); ?></td>
                                    <td><?php echo htmlspecialchars($p['program_name']); ?></td>
                                    <td><?php echo htmlspecialchars($p['description'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan='3' class='no-data'>No programs found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($view === 'curriculum'): ?>
            <!-- Removed redundant Curriculum section title -->
            <div id="curriculumTable" class="table-container card">
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Program</th>
                            <th>Units</th>
                            <th>Year</th>
                            <th>Semester</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($curriculum_result && $curriculum_result->num_rows > 0): ?>
                            <?php while ($c = $curriculum_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['course_code']); ?></td>
                                    <td><?php echo htmlspecialchars($c['course_name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['program_code'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($c['units']); ?></td>
                                    <td><?php echo htmlspecialchars($c['year_level']); ?></td>
                                    <td><?php echo htmlspecialchars($c['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($c['description'] ?? ''); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan='7' class='no-data'>No courses found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Curriculum Pagination -->
                <?php if ($total_curriculum_pages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $curriculum_offset + 1; ?>-<?php echo min($curriculum_offset + $curriculum_per_page, $total_curriculum); ?> of <?php echo $total_curriculum; ?> courses
                    </div>
                    <div class="pagination-controls">
                        <?php
                        $curriculum_params = $_GET;
                        unset($curriculum_params['cpage']);
                        $curriculum_base_url = '?' . http_build_query($curriculum_params) . '&cpage=';
                        ?>
                        
                        <?php if ($curriculum_page > 1): ?>
                            <a href="<?php echo $curriculum_base_url . '1'; ?>" class="btn-page">&laquo; First</a>
                            <a href="<?php echo $curriculum_base_url . ($curriculum_page - 1); ?>" class="btn-page">&lsaquo; Prev</a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $curriculum_page - 2);
                        $end_page = min($total_curriculum_pages, $curriculum_page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="<?php echo $curriculum_base_url . $i; ?>" class="btn-page <?php echo ($i == $curriculum_page) ? 'btn-page-active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        
                        <?php if ($curriculum_page < $total_curriculum_pages): ?>
                            <a href="<?php echo $curriculum_base_url . ($curriculum_page + 1); ?>" class="btn-page">Next &rsaquo;</a>
                            <a href="<?php echo $curriculum_base_url . $total_curriculum_pages; ?>" class="btn-page">Last &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php $conn->close(); ?>
    </div>

    <!-- Toast Notification -->
    <?php if (!empty($notification)): ?>
    <div id="toast" class="toast toast-<?php echo $notification_type; ?>">
        <span class="toast-icon"><?php echo $notification_type === 'success' ? 'Success' : 'Error'; ?></span>
        <span class="toast-message"><?php echo $notification; ?></span>
        <button class="toast-close" onclick="closeToast()">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p id="modalMessage">Are you sure you want to proceed?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="modalConfirmBtn" onclick="confirmAction()">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <!-- Success Modal for Added Student -->
    <?php if ($show_added_modal && $added_student): ?>
    <div class="modal-overlay active" id="successModal">
        <div class="modal success-modal">
            <div class="modal-header">
                <h2 class="modal-title">Student enrolled successfully</h2>
            </div>
            
            <div class="modal-body">
                <div class="success-details">
                    <div class="success-student-info">
                        <div class="success-avatar">
                            <?php echo strtoupper(substr($added_student['name'], 0, 1)); ?>
                        </div>
                        <div class="success-info">
                            <h3><?php echo $added_student['name']; ?></h3>
                            <p class="student-number"><?php echo $added_student['student_number']; ?></p>
                        </div>
                    </div>
                    
                    <div class="success-meta">
                        <div class="meta-item">
                            <span class="meta-label">Program</span>
                            <span class="meta-value"><?php echo $added_student['program']; ?></span>
                        </div>
                    </div>
                    
                    <p class="success-note">
                        Click anywhere to close this notification.
                    </p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeSuccessModal()">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Inline Filter Functions
        function applyFilters() {
            const program = document.getElementById('filterProgram')?.value || 0;
            const sortField = document.getElementById('sortField')?.value || 'last_name';
            const params = new URLSearchParams(window.location.search);
            const sortOrder = params.get('sort_order') || 'asc';
            const search = params.get('search') || '';
            
            let url = '?view=students';
            if (program > 0) url += '&filter_program=' + program;
            if (sortField !== 'last_name') url += '&sort_field=' + sortField;
            if (sortOrder !== 'asc') url += '&sort_order=' + sortOrder;
            if (search) url += '&search=' + encodeURIComponent(search);
            
            window.location.href = url;
        }
        
        function toggleSortOrder() {
            const params = new URLSearchParams(window.location.search);
            const currentOrder = params.get('sort_order') || 'asc';
            const newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            
            params.set('sort_order', newOrder);
            params.set('view', 'students');
            window.location.href = '?' + params.toString();
        }
        
        // Success Modal - close on click anywhere
        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                // Clean URL
                const url = new URL(window.location);
                url.searchParams.delete('msg');
                url.searchParams.delete('name');
                url.searchParams.delete('student_number');
                url.searchParams.delete('program');
                url.searchParams.delete('student_id');
                window.history.replaceState({}, '', url);
            }
        }
        
        // Click anywhere on overlay or modal to close
        const successModal = document.getElementById('successModal');
        if (successModal) {
            successModal.addEventListener('click', function(e) {
                // Close when clicking overlay background OR inside modal (except buttons)
                if (e.target.tagName !== 'A' && !e.target.classList.contains('btn')) {
                    closeSuccessModal();
                }
            });
            
            // Lock body scroll when modal is open
            document.body.style.overflow = 'hidden';
        }

        // Handle sort field, order, and program filter changes
        const sortField = document.getElementById('sortField');
        const sortOrder = document.getElementById('sortOrder');
        const filterProgram = document.getElementById('filterProgram');
        
        function updateSort() {
            const params = new URLSearchParams(window.location.search);
            if (sortField) params.set('sort_field', sortField.value);
            if (sortOrder) params.set('sort_order', sortOrder.value);
            if (filterProgram) params.set('filter_program', filterProgram.value);
            params.set('view', 'students');
            params.delete('page'); // Reset to first page when filtering
            window.location.search = params.toString();
        }
        
        if (sortField) sortField.addEventListener('change', updateSort);
        if (sortOrder) sortOrder.addEventListener('change', updateSort);
        if (filterProgram) filterProgram.addEventListener('change', updateSort);

        // Toast notification
        function closeToast() {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.classList.add('toast-hide');
                setTimeout(() => toast.remove(), 300);
            }
        }

        // Auto-hide toast after 5 seconds
        const toast = document.getElementById('toast');
        if (toast) {
            // Clean URL query parameters to prevent showing the message again on refresh
            const url = new URL(window.location);
            url.searchParams.delete('msg');
            url.searchParams.delete('name');
            url.searchParams.delete('has_conflicts');
            url.searchParams.delete('conflict_count');
            window.history.replaceState({}, '', url);

            setTimeout(() => {
                toast.classList.add('toast-hide');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Modal functionality
        let pendingAction = null;

        function showModal(title, message, actionUrl, confirmText = 'Confirm') {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalMessage').textContent = message;
            document.getElementById('modalConfirmBtn').textContent = confirmText;
            pendingAction = actionUrl;
            document.getElementById('confirmModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('confirmModal').classList.remove('active');
            document.body.style.overflow = '';
            pendingAction = null;
        }

        function confirmAction() {
            if (pendingAction) {
                showSpinner();
                window.location.href = pendingAction;
            }
        }

        // Close modal on outside click
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeSuccessModal();
            }
        });

        // Loading spinner
        function showSpinner() {
            document.getElementById('loadingSpinner').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function hideSpinner() {
            document.getElementById('loadingSpinner').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Confirm drop action
        function confirmDrop(studentId, studentName) {
            showModal(
                'Drop Student',
                `Are you sure you want to drop ${studentName}? This action cannot be undone.`,
                `pages/drop_student.php?id=${studentId}&confirm=1`,
                'Drop Student'
            );
        }

        // Add loading spinner to forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                showSpinner();
            });
        });

        // Hamburger menu toggle
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const mobileNav = document.getElementById('mobileNav');
        
        if (hamburgerBtn && mobileNav) {
            hamburgerBtn.addEventListener('click', function() {
                this.classList.toggle('active');
                mobileNav.classList.toggle('active');
            });
        }
    </script>
<!-- Dashboard Modal -->
<div id="dashboardModal" class="modal-overlay">
    <div class="modal modal-lg">
        <div class="modal-header flex-between">
            <h2 class="modal-title">Quick Stats</h2>
            <button class="modal-close" onclick="closeDashboard()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="stats-grid">
                <div class="stat-card stat-card-accent">
                    <div class="stat-label">Total Students</div>
                    <div class="stat-value" id="totalStudents">-</div>
                </div>
                <div class="stat-card stat-card-success">
                    <div class="stat-label">Total Courses</div>
                    <div class="stat-value" id="totalCourses">-</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Collection Rate</div>
                    <div class="stat-value" id="collectionEff">-</div>
                </div>
            </div>

            <div class="chart-grid mt-md">
                <div class="chart-box">
                    <h3 class="mb-sm">Enrollment by Program</h3>
                    <div class="chart-container">
                        <canvas id="programChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-box">
                    <h3 class="mb-sm">Financial Status</h3>
                    <div class="chart-container">
                        <canvas id="financeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Dashboard Logic
const dashboardModal = document.getElementById('dashboardModal');

function openDashboard() {
    dashboardModal.classList.add('active'); // Use common.css class
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    loadDashboardData();
}

function closeDashboard() {
    dashboardModal.classList.remove('active');
    document.body.style.overflow = '';
}

window.onclick = function(event) {
    if (event.target == dashboardModal) {
        closeDashboard();
    } else if (event.target == document.getElementById('confirmModal')) { // Fix ID reference
         closeModal();
    }
}

async function loadDashboardData() {
    try {
        const response = await fetch('api/dashboard_stats.php');
        const data = await response.json();
        
        // Update Stats
        document.getElementById('totalStudents').textContent = data.totals.students;
        document.getElementById('totalCourses').textContent = data.totals.courses;
        
        let collected = data.financials.collected || 0;
        let assessed = data.financials.assessed || 0;
        let eff = assessed > 0 ? Math.round((collected / assessed) * 100) : 0;
        document.getElementById('collectionEff').textContent = eff + '%';
        
        // Render Charts
        renderProgramChart(data.programs);
        renderFinanceChart(data.financials);
        
    } catch (e) {
        console.error('Error loading dashboard:', e);
    }
}

let progChartInstance = null;
let finChartInstance = null;

function renderProgramChart(programs) {
    const ctx = document.getElementById('programChart').getContext('2d');
    const labels = programs.map(p => p.program_code);
    const counts = programs.map(p => p.count);
    
    if (progChartInstance) progChartInstance.destroy();
    
    progChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b']
            }]
        },
        options: { maintainAspectRatio: false }
    });
}

function renderFinanceChart(fin) {
    const ctx = document.getElementById('financeChart').getContext('2d');
    
    if (finChartInstance) finChartInstance.destroy();
    
    finChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Assessed', 'Collected', 'Balance'],
            datasets: [{
                label: 'Amount (Php)',
                data: [fin.assessed, fin.collected, fin.balance],
                backgroundColor: ['#4e73df', '#1cc88a', '#e74a3b']
            }]
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}
</script>

</body>
</html>

