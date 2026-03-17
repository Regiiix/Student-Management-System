<?php
require_once 'config/db_helpers.php';
require_once 'config/sidebar.php';

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

if (empty($_GET)) {
    header('Location: landing.php', true, 302);
    exit;
}

$view = isset($_GET['view']) ? $_GET['view'] : 'students';
$sidebar_active = in_array($view, ['programs', 'curriculum'], true) ? 'academics' : 'students';

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
$allowed_page_sizes = [10, 25, 50, 100];
$requested_page_size = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
$items_per_page = in_array($requested_page_size, $allowed_page_sizes, true) ? $requested_page_size : 10;
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
$total_pages = (int)ceil($total_students / $items_per_page);
if ($total_pages < 1) {
    $total_pages = 1;
}

if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $items_per_page;
}

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
$allowed_curriculum_page_sizes = [15, 30, 60, 100];
$requested_curriculum_page_size = isset($_GET['cper_page']) ? intval($_GET['cper_page']) : 15;
$curriculum_per_page = in_array($requested_curriculum_page_size, $allowed_curriculum_page_sizes, true)
    ? $requested_curriculum_page_size
    : 15;
$curriculum_page = isset($_GET['cpage']) ? max(1, intval($_GET['cpage'])) : 1;
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
$total_curriculum_pages = (int)ceil($total_curriculum / $curriculum_per_page);
if ($total_curriculum_pages < 1) {
    $total_curriculum_pages = 1;
}

if ($curriculum_page > $total_curriculum_pages) {
    $curriculum_page = $total_curriculum_pages;
    $curriculum_offset = ($curriculum_page - 1) * $curriculum_per_page;
}

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
    <link rel="icon" href="<?php echo htmlspecialchars(app_asset('favicon.ico')); ?>" type="image/x-icon">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/index.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js')); ?>" defer></script>
</head>
<body class="has-sidebar">
    <?php renderAppSidebar(['active' => $sidebar_active]); ?>
    <div class="container">
        <header>
            <div class="header-top">
                <h1>Student Management System</h1>
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
            <?php endif; ?>
        </header>

        <div class="controls card">
            <?php if ($view !== 'students'): ?>
            <div class="controls-main">
                <div class="view-buttons" role="navigation" aria-label="Index views">
                    <a class="btn <?php echo $view === 'programs' ? 'btn-primary' : ''; ?>" href="?view=programs"><i class="bi bi-grid-1x2" aria-hidden="true"></i><span class="btn-label">Programs</span></a>
                    <a class="btn <?php echo $view === 'curriculum' ? 'btn-primary' : ''; ?>" href="?view=curriculum"><i class="bi bi-book" aria-hidden="true"></i><span class="btn-label">Curriculum</span></a>
                </div>

                <div class="controls-actions">
                    <button type="button" class="btn btn-quick-stats" data-index-action="open-dashboard"><i class="bi bi-bar-chart-line" aria-hidden="true"></i><span class="btn-label">Quick Stats</span></button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($view === 'students'): ?>
            <?php
                $active_program_code = '';
                if ($filter_program > 0) {
                    foreach ($programs as $program_item) {
                        if ((int)$program_item['program_id'] === (int)$filter_program) {
                            $active_program_code = $program_item['program_code'];
                            break;
                        }
                    }
                }

                $sort_label_map = [
                    'last_name' => 'Name',
                    'student_number' => 'Student #',
                    'year_level' => 'Year',
                    'program' => 'Program',
                ];
                $active_sort_label = $sort_label_map[$sort_field] ?? 'Name';

                $clear_search_url = '?view=students';
                if ($items_per_page !== 10) {
                    $clear_search_url .= '&per_page=' . $items_per_page;
                }
                if ($filter_program > 0) {
                    $clear_search_url .= '&filter_program=' . $filter_program;
                }
                if ($sort_field !== 'last_name') {
                    $clear_search_url .= '&sort_field=' . urlencode($sort_field);
                }
                if ($sort_order !== 'asc') {
                    $clear_search_url .= '&sort_order=' . urlencode($sort_order);
                }
            ?>

            <div class="filter-toolbar filter-toolbar-students">
                <form method="get" class="search-form filter-search-form">
                    <input type="hidden" name="view" value="students">
                    <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
                    <input type="hidden" name="sort_field" value="<?php echo htmlspecialchars($sort_field); ?>">
                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                    <?php if ($filter_program > 0): ?>
                    <input type="hidden" name="filter_program" value="<?php echo $filter_program; ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="search-input" placeholder="Search students by name or student number" value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-search">Search</button>
                    <?php if (!empty($search)): ?>
                    <a href="<?php echo htmlspecialchars($clear_search_url); ?>" class="btn btn-clear">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="inline-filters-students">
                    <select id="filterProgram" class="filter-select" data-index-change="apply-filters">
                        <option value="0">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo $p['program_id']; ?>" <?php echo $filter_program == $p['program_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['program_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="sortField" class="filter-select" data-index-change="apply-filters">
                        <option value="last_name" <?php echo $sort_field === 'last_name' ? 'selected' : ''; ?>>Sort: Name</option>
                        <option value="student_number" <?php echo $sort_field === 'student_number' ? 'selected' : ''; ?>>Sort: Student #</option>
                        <option value="year_level" <?php echo $sort_field === 'year_level' ? 'selected' : ''; ?>>Sort: Year</option>
                        <option value="program" <?php echo $sort_field === 'program' ? 'selected' : ''; ?>>Sort: Program</option>
                    </select>
                    <button type="button" class="filter-order-btn" data-index-action="toggle-sort-order" title="Toggle sort order" aria-label="Toggle sort order">
                        <span class="order-label"><?php echo $sort_order === 'asc' ? 'A-Z' : 'Z-A'; ?></span>
                    </button>
                    <?php if ($filter_program > 0 || $sort_field !== 'last_name' || $sort_order !== 'asc' || !empty($search)): ?>
                    <a href="?view=students&per_page=<?php echo $items_per_page; ?>" class="filter-reset" title="Reset filters"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="sr-only">Reset filters</span></a>
                    <?php endif; ?>
                </div>

                <a class="btn btn-primary btn-add-student btn-add-student-inline" href="pages/add_student.php"><i class="bi bi-person-fill-add" aria-hidden="true"></i><span class="btn-label">Add Student</span></a>
            </div>

            <?php if (!empty($search) || $filter_program > 0 || $sort_field !== 'last_name' || $sort_order !== 'asc'): ?>
            <div class="active-filter-chips" aria-live="polite">
                <?php if (!empty($search)): ?>
                <span class="filter-chip"><i class="bi bi-search" aria-hidden="true"></i>Search: <?php echo htmlspecialchars($search); ?></span>
                <?php endif; ?>
                <?php if ($filter_program > 0 && $active_program_code !== ''): ?>
                <span class="filter-chip"><i class="bi bi-mortarboard" aria-hidden="true"></i>Program: <?php echo htmlspecialchars($active_program_code); ?></span>
                <?php endif; ?>
                <?php if ($sort_field !== 'last_name' || $sort_order !== 'asc'): ?>
                <span class="filter-chip"><i class="bi bi-sort-alpha-down" aria-hidden="true"></i>Sort: <?php echo htmlspecialchars($active_sort_label); ?> (<?php echo $sort_order === 'asc' ? 'A-Z' : 'Z-A'; ?>)</span>
                <?php endif; ?>
                <a href="?view=students&per_page=<?php echo $items_per_page; ?>" class="chip-clear-all">Clear all</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($view === 'curriculum'): ?>
            <div class="inline-filters">
                <form method="get" class="inline-filter-form" id="currFilterForm">
                    <input type="hidden" name="view" value="curriculum">
                    <input type="hidden" name="cper_page" value="<?php echo $curriculum_per_page; ?>">
                    <select name="curriculum_program" class="filter-select" data-index-change="submit-form">
                        <option value="0">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo $p['program_id']; ?>" <?php echo $curriculum_program == $p['program_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['program_code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="curriculum_year" class="filter-select" data-index-change="submit-form">
                        <option value="0">All Years</option>
                        <option value="1" <?php echo $curriculum_year == 1 ? 'selected' : ''; ?>>Year 1</option>
                        <option value="2" <?php echo $curriculum_year == 2 ? 'selected' : ''; ?>>Year 2</option>
                        <option value="3" <?php echo $curriculum_year == 3 ? 'selected' : ''; ?>>Year 3</option>
                        <option value="4" <?php echo $curriculum_year == 4 ? 'selected' : ''; ?>>Year 4</option>
                    </select>
                    <select name="curriculum_semester" class="filter-select" data-index-change="submit-form">
                        <option value="0">All Semesters</option>
                        <option value="1" <?php echo $curriculum_semester == 1 ? 'selected' : ''; ?>>1st Sem</option>
                        <option value="2" <?php echo $curriculum_semester == 2 ? 'selected' : ''; ?>>2nd Sem</option>
                    </select>
                    <?php if ($curriculum_program > 0 || $curriculum_year > 0 || $curriculum_semester > 0): ?>
                    <a href="?view=curriculum&cper_page=<?php echo $curriculum_per_page; ?>" class="filter-reset" title="Reset filters"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="sr-only">Reset filters</span></a>
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
                        $return_query = $_SERVER['QUERY_STRING'] ?? '';
                        if ($return_query === '') {
                            $return_query = 'view=students';
                        }
                        $student_return_url = rawurlencode('../index.php?' . $return_query);

                        if ($students_result && $students_result->num_rows > 0) {
                            while ($row = $students_result->fetch_assoc()) {
                                $firstName = htmlspecialchars($row['first_name'] . ($row['middle_name'] ? ' ' . substr($row['middle_name'], 0, 1) . '.' : ''));
                                echo "<tr>";
                                echo "<td data-label='Student No.'>" . htmlspecialchars($row['student_number']) . "</td>";
                                echo "<td data-label='Last Name'>" . htmlspecialchars($row['last_name']) . "</td>";
                                echo "<td data-label='First Name'>" . $firstName . "</td>";
                                echo "<td data-label='Program'>" . htmlspecialchars($row['program_code'] ?? 'N/A') . "</td>";
                                echo "<td data-label='Year'>" . htmlspecialchars($row['year_level']) . "</td>";
                                echo "<td data-label='Status'><span class='status-badge status-" . strtolower($row['status']) . "'>" . htmlspecialchars($row['status']) . "</span></td>";
                                $student_drop_name = htmlspecialchars($row['last_name'] . ", " . $row['first_name'], ENT_QUOTES);
                                echo "<td data-label='Actions' class='action-buttons-cell'>";
                                echo "<div class='action-buttons'>";
                                echo "<a class='btn btn-action btn-primary-action' href='pages/student_personal.php?id=" . $row['student_id'] . "&return=" . $student_return_url . "' title='View Student Information'><i class='bi bi-person-vcard'></i>View</a>";
                                echo "<details class='row-actions-menu'>";
                                echo "<summary class='btn btn-action btn-more' title='More actions'><i class='bi bi-three-dots' aria-hidden='true'></i><span>More</span></summary>";
                                echo "<div class='row-actions-dropdown'>";
                                echo "<a class='row-action-link' href='pages/student_schedule_grades.php?id=" . $row['student_id'] . "&tab=schedule&return=" . $student_return_url . "' title='View Schedule & Grades'><i class='bi bi-journal-text' aria-hidden='true'></i>Records</a>";
                                echo "<a class='row-action-link' href='pages/student_finance.php?id=" . $row['student_id'] . "&return=" . $student_return_url . "' title='View Financial Records'><i class='bi bi-wallet2' aria-hidden='true'></i>Finance</a>";
                                echo "<button type='button' class='row-action-link row-action-danger' data-student-action='drop' data-student-id='" . (int)$row['student_id'] . "' data-student-name='" . $student_drop_name . "' title='Drop Student'><i class='bi bi-person-dash' aria-hidden='true'></i>Drop</button>";
                                echo "</div>";
                                echo "</details>";
                                echo "</div>";
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

            <?php if ($total_students > 0): ?>
                <div class="pagination">
                    <div class="pagination-summary">
                        <span class="pagination-info">Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $items_per_page, $total_students); ?> of <?php echo $total_students; ?> students</span>

                        <div class="pagination-tools">
                            <form method="get" class="pagination-tool-form">
                                <input type="hidden" name="view" value="students">
                                <input type="hidden" name="page" value="1">
                                <?php if ($filter_program > 0): ?>
                                    <input type="hidden" name="filter_program" value="<?php echo $filter_program; ?>">
                                <?php endif; ?>
                                <?php if ($sort_field !== 'last_name'): ?>
                                    <input type="hidden" name="sort_field" value="<?php echo htmlspecialchars($sort_field); ?>">
                                <?php endif; ?>
                                <?php if ($sort_order !== 'asc'): ?>
                                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                                <?php endif; ?>
                                <?php if (!empty($search)): ?>
                                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <?php endif; ?>

                                <label for="itemsPerPage" class="pagination-tool-label">Rows</label>
                                <select id="itemsPerPage" name="per_page" class="pagination-select" data-index-change="submit-form">
                                    <?php foreach ($allowed_page_sizes as $page_size_option): ?>
                                        <option value="<?php echo $page_size_option; ?>" <?php echo $items_per_page === $page_size_option ? 'selected' : ''; ?>><?php echo $page_size_option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>

                            <?php if ($total_pages > 1): ?>
                            <form method="get" class="pagination-tool-form pagination-jump-form">
                                <input type="hidden" name="view" value="students">
                                <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
                                <?php if ($filter_program > 0): ?>
                                    <input type="hidden" name="filter_program" value="<?php echo $filter_program; ?>">
                                <?php endif; ?>
                                <?php if ($sort_field !== 'last_name'): ?>
                                    <input type="hidden" name="sort_field" value="<?php echo htmlspecialchars($sort_field); ?>">
                                <?php endif; ?>
                                <?php if ($sort_order !== 'asc'): ?>
                                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
                                <?php endif; ?>
                                <?php if (!empty($search)): ?>
                                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <?php endif; ?>

                                <label for="jumpPage" class="pagination-tool-label">Page</label>
                                <input id="jumpPage" name="page" type="number" class="pagination-jump-input" min="1" max="<?php echo $total_pages; ?>" value="<?php echo $current_page; ?>">
                                <button type="submit" class="btn btn-page">Go</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-controls">
                        <?php 
                        $pagination_params = "view=students&per_page=$items_per_page&sort_field=$sort_field&sort_order=$sort_order&filter_program=$filter_program" . (!empty($search) ? "&search=" . urlencode($search) : "");
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
                    <?php endif; ?>
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
                <?php if ($total_curriculum > 0): ?>
                <div class="pagination">
                    <div class="pagination-summary">
                        <span class="pagination-info">Showing <?php echo $curriculum_offset + 1; ?>-<?php echo min($curriculum_offset + $curriculum_per_page, $total_curriculum); ?> of <?php echo $total_curriculum; ?> courses</span>

                        <div class="pagination-tools">
                            <form method="get" class="pagination-tool-form">
                                <input type="hidden" name="view" value="curriculum">
                                <input type="hidden" name="cpage" value="1">
                                <?php if ($curriculum_program > 0): ?>
                                    <input type="hidden" name="curriculum_program" value="<?php echo $curriculum_program; ?>">
                                <?php endif; ?>
                                <?php if ($curriculum_year > 0): ?>
                                    <input type="hidden" name="curriculum_year" value="<?php echo $curriculum_year; ?>">
                                <?php endif; ?>
                                <?php if ($curriculum_semester > 0): ?>
                                    <input type="hidden" name="curriculum_semester" value="<?php echo $curriculum_semester; ?>">
                                <?php endif; ?>

                                <label for="curriculumRowsPerPage" class="pagination-tool-label">Rows</label>
                                <select id="curriculumRowsPerPage" name="cper_page" class="pagination-select" data-index-change="submit-form">
                                    <?php foreach ($allowed_curriculum_page_sizes as $curriculum_page_size_option): ?>
                                        <option value="<?php echo $curriculum_page_size_option; ?>" <?php echo $curriculum_per_page === $curriculum_page_size_option ? 'selected' : ''; ?>><?php echo $curriculum_page_size_option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>

                            <?php if ($total_curriculum_pages > 1): ?>
                            <form method="get" class="pagination-tool-form pagination-jump-form">
                                <input type="hidden" name="view" value="curriculum">
                                <input type="hidden" name="cper_page" value="<?php echo $curriculum_per_page; ?>">
                                <?php if ($curriculum_program > 0): ?>
                                    <input type="hidden" name="curriculum_program" value="<?php echo $curriculum_program; ?>">
                                <?php endif; ?>
                                <?php if ($curriculum_year > 0): ?>
                                    <input type="hidden" name="curriculum_year" value="<?php echo $curriculum_year; ?>">
                                <?php endif; ?>
                                <?php if ($curriculum_semester > 0): ?>
                                    <input type="hidden" name="curriculum_semester" value="<?php echo $curriculum_semester; ?>">
                                <?php endif; ?>

                                <label for="curriculumJumpPage" class="pagination-tool-label">Page</label>
                                <input id="curriculumJumpPage" name="cpage" type="number" class="pagination-jump-input" min="1" max="<?php echo $total_curriculum_pages; ?>" value="<?php echo $curriculum_page; ?>">
                                <button type="submit" class="btn btn-page">Go</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($total_curriculum_pages > 1): ?>
                    <div class="pagination-controls">
                        <?php
                        $curriculum_query_params = $_GET;
                        unset($curriculum_query_params['cpage']);
                        $curriculum_base_url = '?' . http_build_query($curriculum_query_params) . '&cpage=';
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
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php $conn->close(); ?>
    </div>

    <!-- Toast Notification -->
    <?php if (!empty($notification)): ?>
    <div id="toast" class="toast toast-<?php echo $notification_type; ?>">
        <span class="toast-icon" aria-hidden="true">
            <i class="bi <?php echo $notification_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-octagon-fill'; ?>"></i>
        </span>
        <span class="toast-message"><?php echo $notification; ?></span>
        <button type="button" class="toast-close" data-index-action="close-toast"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="sr-only">Close notification</span></button>
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
                <button type="button" class="btn btn-secondary" data-index-action="close-confirm-modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="modalConfirmBtn" data-index-action="confirm-modal-action">Confirm</button>
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
                <button type="button" class="btn btn-secondary" data-index-action="close-success-modal">Close</button>
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
            const perPage = params.get('per_page') || '10';
            const searchInput = document.querySelector('.filter-search-form input[name="search"]');
            const search = searchInput ? searchInput.value.trim() : (params.get('search') || '');
            
            let url = '?view=students';
            if (perPage !== '10') url += '&per_page=' + encodeURIComponent(perPage);
            if (program > 0) url += '&filter_program=' + program;
            if (sortField !== 'last_name') url += '&sort_field=' + sortField;
            if (sortOrder !== 'asc') url += '&sort_order=' + sortOrder;
            if (search !== '') url += '&search=' + encodeURIComponent(search);
            
            window.location.href = url;
        }
        
        function toggleSortOrder() {
            const params = new URLSearchParams(window.location.search);
            const currentOrder = params.get('sort_order') || 'asc';
            const newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            
            params.set('sort_order', newOrder);
            params.set('view', 'students');
            params.delete('page');
            window.location.href = '?' + params.toString();
        }

        document.addEventListener('change', function(event) {
            const changeAction = event.target && event.target.getAttribute
                ? event.target.getAttribute('data-index-change')
                : null;

            if (!changeAction) {
                return;
            }

            if (changeAction === 'apply-filters') {
                applyFilters();
                return;
            }

            if (changeAction === 'submit-form') {
                const form = event.target.closest('form');
                if (form) {
                    form.submit();
                }
            }
        });

        document.addEventListener('click', function(event) {
            const trigger = event.target.closest('[data-index-action], [data-student-action]');
            if (!trigger) {
                return;
            }

            const studentAction = trigger.getAttribute('data-student-action');
            if (studentAction === 'drop') {
                const studentId = trigger.getAttribute('data-student-id');
                const studentName = trigger.getAttribute('data-student-name') || 'this student';
                confirmDrop(studentId, studentName);
                return;
            }

            const action = trigger.getAttribute('data-index-action');
            if (!action) {
                return;
            }

            if (action === 'open-dashboard') {
                openDashboard();
            } else if (action === 'toggle-sort-order') {
                toggleSortOrder();
            } else if (action === 'close-toast') {
                closeToast();
            } else if (action === 'close-confirm-modal') {
                closeModal();
            } else if (action === 'confirm-modal-action') {
                confirmAction();
            } else if (action === 'close-success-modal') {
                closeSuccessModal();
            } else if (action === 'close-dashboard') {
                closeDashboard();
            }
        });
        
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
                closeDashboard();
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

        // Row action menu behavior
        const rowActionMenus = document.querySelectorAll('.row-actions-menu');
        if (rowActionMenus.length > 0) {
            const studentTable = document.getElementById('studentTable');

            const syncRowActionsState = function() {
                if (!studentTable) {
                    return;
                }

                const hasOpenMenu = Array.from(rowActionMenus).some(menu => menu.hasAttribute('open'));
                studentTable.classList.toggle('row-actions-active', hasOpenMenu);
            };

            rowActionMenus.forEach(menu => {
                const summary = menu.querySelector('summary');
                if (!summary) {
                    return;
                }

                menu.addEventListener('toggle', function() {
                    const row = menu.closest('tr');
                    if (!row) {
                        return;
                    }

                    if (menu.open) {
                        row.classList.add('row-actions-open');
                    } else {
                        row.classList.remove('row-actions-open');
                    }

                    syncRowActionsState();
                });

                summary.addEventListener('click', function() {
                    rowActionMenus.forEach(otherMenu => {
                        if (otherMenu !== menu) {
                            otherMenu.removeAttribute('open');
                        }
                    });

                    setTimeout(syncRowActionsState, 0);
                });
            });

            document.addEventListener('click', function(event) {
                rowActionMenus.forEach(menu => {
                    if (!menu.contains(event.target)) {
                        menu.removeAttribute('open');
                    }
                });

                syncRowActionsState();
            });

            document.addEventListener('keydown', function(event) {
                if (event.key !== 'Escape') {
                    return;
                }

                rowActionMenus.forEach(menu => menu.removeAttribute('open'));
                syncRowActionsState();
            });
        }

        // Add loading spinner to forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                showSpinner();
            });
        });

    </script>
<!-- Dashboard Modal -->
<div id="dashboardModal" class="modal-overlay">
    <div class="modal modal-lg">
        <div class="modal-header flex-between">
            <div class="dashboard-modal-title-group">
                <h2 class="modal-title">Quick Stats</h2>
                <p id="dashboardModalLastUpdated" class="dashboard-modal-meta" aria-live="polite">Last updated: --</p>
                <p id="dashboardModalCacheAge" class="dashboard-modal-meta" aria-live="polite">Cache age: --</p>
            </div>
            <div class="dashboard-modal-actions">
                <button type="button" class="btn btn-sm" id="dashboardSoftRefreshBtn">Refresh</button>
                <button type="button" class="btn btn-sm btn-primary" id="dashboardHardRefreshBtn">Hard Refresh</button>
                <button type="button" class="modal-close" data-index-action="close-dashboard" aria-label="Close quick stats">&times;</button>
            </div>
        </div>
        
        <div class="modal-body">
            <div id="dashboardModalError" class="dashboard-modal-error hidden" role="alert" aria-live="polite"></div>
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
const dashboardModalError = document.getElementById('dashboardModalError');
const dashboardModalLastUpdated = document.getElementById('dashboardModalLastUpdated');
const dashboardModalCacheAge = document.getElementById('dashboardModalCacheAge');
const dashboardSoftRefreshBtn = document.getElementById('dashboardSoftRefreshBtn');
const dashboardHardRefreshBtn = document.getElementById('dashboardHardRefreshBtn');

const DASHBOARD_MODAL_CACHE_TTL_MS = 5 * 60 * 1000;
let dashboardModalCache = null;
let dashboardModalFetchPromise = null;
let dashboardModalMetaTimer = null;

function isDashboardModalCacheFresh() {
    if (!dashboardModalCache || !dashboardModalCache.fetchedAt) {
        return false;
    }

    return (Date.now() - dashboardModalCache.fetchedAt) <= DASHBOARD_MODAL_CACHE_TTL_MS;
}

function formatDashboardModalAge(ageMs) {
    if (ageMs < 10000) {
        return 'just now';
    }

    if (ageMs < 60000) {
        return `${Math.floor(ageMs / 1000)}s old`;
    }

    return `${Math.floor(ageMs / 60000)}m old`;
}

function updateDashboardModalMeta(fetchedAtMs) {
    if (dashboardModalLastUpdated) {
        const timestamp = new Date(fetchedAtMs || Date.now());
        dashboardModalLastUpdated.textContent = 'Last updated: ' + timestamp.toLocaleString('en-PH', {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    if (!dashboardModalCacheAge) {
        return;
    }

    if (!dashboardModalCache || !dashboardModalCache.fetchedAt) {
        dashboardModalCacheAge.textContent = 'Cache age: --';
        return;
    }

    const ageMs = Math.max(0, Date.now() - dashboardModalCache.fetchedAt);
    let ageLabel = formatDashboardModalAge(ageMs);
    if (ageMs > DASHBOARD_MODAL_CACHE_TTL_MS) {
        ageLabel += ' (stale)';
    }

    dashboardModalCacheAge.textContent = 'Cache age: ' + ageLabel;
}

function startDashboardModalMetaTimer() {
    if (dashboardModalMetaTimer) {
        window.clearInterval(dashboardModalMetaTimer);
    }

    dashboardModalMetaTimer = window.setInterval(() => {
        if (!dashboardModalCache || !dashboardModalCache.fetchedAt) {
            return;
        }

        updateDashboardModalMeta(dashboardModalCache.fetchedAt);
    }, 15000);
}

function stopDashboardModalMetaTimer() {
    if (!dashboardModalMetaTimer) {
        return;
    }

    window.clearInterval(dashboardModalMetaTimer);
    dashboardModalMetaTimer = null;
}

function setDashboardModalRefreshState(isBusy, mode = 'soft') {
    if (dashboardSoftRefreshBtn) {
        dashboardSoftRefreshBtn.disabled = isBusy;
        dashboardSoftRefreshBtn.textContent = isBusy && mode !== 'hard' ? 'Refreshing...' : 'Refresh';
    }

    if (dashboardHardRefreshBtn) {
        dashboardHardRefreshBtn.disabled = isBusy;
        dashboardHardRefreshBtn.textContent = isBusy && mode === 'hard' ? 'Hard Refreshing...' : 'Hard Refresh';
    }
}

function clearDashboardModalError() {
    clearApiErrorNotice(dashboardModalError);
}

function showDashboardModalError(message) {
    showApiErrorNotice(
        dashboardModalError,
        message,
        loadDashboardData,
        { fallbackMessage: 'Unable to load dashboard data right now. Please try again.' }
    );
}

function openDashboard() {
    dashboardModal.classList.add('active'); // Use common.css class
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    clearDashboardModalError();
    loadDashboardData({ forceRefresh: false });
}

function closeDashboard() {
    dashboardModal.classList.remove('active');
    document.body.style.overflow = '';
    stopDashboardModalMetaTimer();
}

if (dashboardSoftRefreshBtn) {
    dashboardSoftRefreshBtn.addEventListener('click', function() {
        loadDashboardData({ forceRefresh: false });
    });
}

if (dashboardHardRefreshBtn) {
    dashboardHardRefreshBtn.addEventListener('click', function() {
        loadDashboardData({ forceRefresh: true });
    });
}

window.addEventListener('click', function(event) {
    if (event.target == dashboardModal) {
        closeDashboard();
    }
});

async function requestDashboardPayload(forceRefresh = false) {
    if (!forceRefresh && dashboardModalFetchPromise) {
        return dashboardModalFetchPromise;
    }

    const endpoint = forceRefresh ? 'api/dashboard_stats.php?refresh=1' : 'api/dashboard_stats.php';
    const request = fetch(endpoint)
        .then(async (response) => {
            const payload = await response.json();
            const fallbackError = 'Failed to load dashboard stats.';

            if (!response.ok || !payload || payload.success !== true) {
                const errMsg = payload && payload.error
                    ? (typeof payload.error === 'string' ? payload.error : (payload.error.message || fallbackError))
                    : fallbackError;
                throw new Error(errMsg);
            }

            return payload.data || {};
        });

    if (forceRefresh) {
        return request;
    }

    dashboardModalFetchPromise = request.finally(() => {
        dashboardModalFetchPromise = null;
    });

    return dashboardModalFetchPromise;
}

function renderDashboardData(data) {
    const totals = data.totals || {};
    const financials = data.financials || {};
    const programs = data.programs || [];

    document.getElementById('totalStudents').textContent = totals.students || 0;
    document.getElementById('totalCourses').textContent = totals.courses || 0;

    const collected = financials.collected || 0;
    const assessed = financials.assessed || 0;
    const eff = assessed > 0 ? Math.round((collected / assessed) * 100) : 0;
    document.getElementById('collectionEff').textContent = eff + '%';

    // Render Charts
    renderProgramChart(programs);
    renderFinanceChart(financials);
}

async function loadDashboardData(options = {}) {
    const forceRefresh = options && options.forceRefresh === true;

    try {
        clearDashboardModalError();
        if (!forceRefresh && isDashboardModalCacheFresh()) {
            renderDashboardData(dashboardModalCache.data || {});
            updateDashboardModalMeta(dashboardModalCache.fetchedAt);
            startDashboardModalMetaTimer();
            return;
        }

        setDashboardModalRefreshState(true, forceRefresh ? 'hard' : 'soft');
        const data = await requestDashboardPayload(forceRefresh);
        dashboardModalCache = {
            data,
            fetchedAt: Date.now()
        };

        renderDashboardData(data);
        updateDashboardModalMeta(dashboardModalCache.fetchedAt);
        startDashboardModalMetaTimer();
        
    } catch (e) {
        console.error('Error loading dashboard:', e);
        showDashboardModalError(e && e.message ? e.message : 'Unable to load dashboard data right now. Please try again.');
    } finally {
        setDashboardModalRefreshState(false);
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

