<?php
require_once '../config/db_helpers.php';
require_once '../config/finance_helpers.php';

$conn = getDBConnection();

// Get programs for filter dropdown
$programs = db_fetch_all(db_query($conn, "SELECT program_id, program_code, program_name FROM programs ORDER BY program_code"));

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_program = isset($_GET['program']) ? intval($_GET['program']) : 0;

$finance_query = $_SERVER['QUERY_STRING'] ?? '';
$finance_return_url = 'finance.php' . ($finance_query !== '' ? ('?' . $finance_query) : '');
$finance_return_param = rawurlencode($finance_return_url);

$where = "WHERE 1=1";
$params = [];
$types = "";

// Search filter
if ($search) {
    $where .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
    $types .= "sss";
}

// Program filter
if ($filter_program > 0) {
    $where .= " AND s.program_id = ?";
    $params[] = $filter_program;
    $types .= "i";
}

// Count total
$count_sql = "SELECT COUNT(*) as total FROM students s $where";
$total_rows = db_fetch_one(db_query($conn, $count_sql, $types, $params))['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch Students
$sql = "SELECT s.student_id, s.student_number, s.first_name, s.last_name, s.program_id, p.program_code 
        FROM students s 
        LEFT JOIN programs p ON s.program_id = p.program_id
        $where 
        ORDER BY s.last_name ASC 
        LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $limit;
$params[] = $offset;

$result = db_query($conn, $sql, $types, $params);
$students = $result ? db_fetch_all($result) : [];

// Calculate balances and credits for displayed students in batch.
$student_program_map = [];
foreach ($students as $student_row) {
    $sid = intval($student_row['student_id']);
    if ($sid <= 0) {
        continue;
    }
    $student_program_map[$sid] = isset($student_row['program_id']) ? intval($student_row['program_id']) : null;
}

$balances_by_student = getStudentBalancesBatch($conn, $student_program_map);
$credits_by_student = getAvailableOverpaymentCreditsBatch($conn, array_keys($student_program_map));

foreach ($students as &$student) {
    $sid = intval($student['student_id']);
    $student['balance'] = $balances_by_student[$sid] ?? 0.0;
    $student['available_credits'] = $credits_by_student[$sid] ?? 0.0;

    // Determine status
    if ($student['balance'] > 0) {
        $student['status'] = 'Unpaid';
    } elseif ($student['balance'] < 0 || $student['available_credits'] > 0) {
        $student['status'] = 'Overpaid';
    } else {
        $student['status'] = 'Clear';
    }
}
unset($student); // Break reference

// Filter by status (after balance calculation)
if ($filter_status) {
    $students = array_filter($students, function($s) use ($filter_status) {
        return $s['status'] === $filter_status;
    });
    $students = array_values($students); // Re-index
}

// Count by status for summary badges
$status_counts = ['Unpaid' => 0, 'Clear' => 0, 'Overpaid' => 0];
foreach ($students as $s) {
    if (isset($status_counts[$s['status']])) {
        $status_counts[$s['status']]++;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/finance_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-finance">
<?php require_once '../config/sidebar.php'; ?>
<?php renderAppSidebar(['active' => 'finance', 'basePath' => '..']); ?>

<div class="container">
    <!-- Header -->
    <header>
        <div class="finance-title-group">
            <div class="finance-title-text">
                <h1>Finance Dashboard</h1>
                <p class="finance-subtitle">Monitor balances and payment status for enrolled students.</p>
            </div>
        </div>
    </header>
    
    <!-- Filter Section -->
    <div class="filter-section">
        <form method="get" id="filterForm">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="search">Search Student</label>
                    <input type="text" id="search" name="search" placeholder="Name or Student No." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="program">Program</label>
                    <select id="program" name="program">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog['program_id']; ?>" <?php echo $filter_program == $prog['program_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prog['program_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="status">Payment Status</label>
                    <select id="status" name="status">
                        <option value="">All Status</option>
                        <option value="Unpaid" <?php echo $filter_status === 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="Clear" <?php echo $filter_status === 'Clear' ? 'selected' : ''; ?>>Clear</option>
                        <option value="Overpaid" <?php echo $filter_status === 'Overpaid' ? 'selected' : ''; ?>>Overpaid</option>
                    </select>
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        Search
                    </button>
                    <?php if ($search || $filter_program || $filter_status): ?>
                        <a href="finance.php" class="btn btn-secondary">Clear All</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($search || $filter_program || $filter_status): ?>
                <div class="active-filters">
                    <?php if ($search): ?>
                        <span class="filter-tag">
                            Search: "<?php echo htmlspecialchars($search); ?>"
                            <button type="button" class="filter-tag-remove" aria-label="Remove search filter" data-filter="search">&times;</button>
                        </span>
                    <?php endif; ?>
                    <?php if ($filter_program): ?>
                        <?php 
                        $prog_name = '';
                        foreach ($programs as $prog) {
                            if ($prog['program_id'] == $filter_program) {
                                $prog_name = $prog['program_code'];
                                break;
                            }
                        }
                        ?>
                        <span class="filter-tag">
                            Program: <?php echo htmlspecialchars($prog_name); ?>
                            <button type="button" class="filter-tag-remove" aria-label="Remove program filter" data-filter="program">&times;</button>
                        </span>
                    <?php endif; ?>
                    <?php if ($filter_status): ?>
                        <span class="filter-tag">
                            Status: <?php echo htmlspecialchars($filter_status); ?>
                            <button type="button" class="filter-tag-remove" aria-label="Remove status filter" data-filter="status">&times;</button>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Status Summary Cards -->
    <div class="status-summary">
        <button type="button" class="status-card unpaid <?php echo $filter_status === 'Unpaid' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_status === 'Unpaid' ? 'true' : 'false'; ?>" data-status="Unpaid">
            <div class="count"><?php echo $status_counts['Unpaid']; ?></div>
            <div class="label">Unpaid</div>
        </button>
        <button type="button" class="status-card clear <?php echo $filter_status === 'Clear' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_status === 'Clear' ? 'true' : 'false'; ?>" data-status="Clear">
            <div class="count"><?php echo $status_counts['Clear']; ?></div>
            <div class="label">Clear</div>
        </button>
        <button type="button" class="status-card overpaid <?php echo $filter_status === 'Overpaid' ? 'active' : ''; ?>" aria-pressed="<?php echo $filter_status === 'Overpaid' ? 'true' : 'false'; ?>" data-status="Overpaid">
            <div class="count"><?php echo $status_counts['Overpaid']; ?></div>
            <div class="label">Overpaid</div>
        </button>
    </div>
    
    <!-- Finance Table -->
    <div class="finance-table-container">
        <table class="finance-table">
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Outstanding Balance</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="bi bi-card-list" aria-hidden="true"></i></div>
                                <p>No students found matching your criteria.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['student_number']); ?></td>
                            <td><strong><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($s['program_code']); ?></td>
                            <td class="balance-amount <?php 
                                if ($s['balance'] > 0) echo 'balance-positive';
                                elseif ($s['balance'] < 0) echo 'balance-negative';
                                else echo 'balance-zero';
                            ?>">
                                ₱ <?php echo number_format(abs($s['balance']), 2); ?>
                            </td>
                            <td>
                                <span class="status-badge <?php 
                                    if ($s['status'] === 'Unpaid') echo 'status-unpaid';
                                    elseif ($s['status'] === 'Clear') echo 'status-clear';
                                    else echo 'status-overpaid';
                                ?>">
                                    <?php echo htmlspecialchars($s['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="student_finance.php?id=<?php echo $s['student_id']; ?>&return=<?php echo htmlspecialchars($finance_return_param); ?>" class="btn-view">
                                    View Account
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&program=<?php echo $filter_program; ?>&status=<?php echo urlencode($filter_status); ?>">&laquo; Prev</a>
                <?php endif; ?>
                
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1): ?>
                    <a href="?page=1&search=<?php echo urlencode($search); ?>&program=<?php echo $filter_program; ?>&status=<?php echo urlencode($filter_status); ?>">1</a>
                    <?php if ($start_page > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&program=<?php echo $filter_program; ?>&status=<?php echo urlencode($filter_status); ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                    <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&program=<?php echo $filter_program; ?>&status=<?php echo urlencode($filter_status); ?>"><?php echo $total_pages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&program=<?php echo $filter_program; ?>&status=<?php echo urlencode($filter_status); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function filterByStatus(status) {
    const url = new URL(window.location.href);
    const currentStatus = url.searchParams.get('status');
    
    if (currentStatus === status) {
        url.searchParams.delete('status');
    } else {
        url.searchParams.set('status', status);
    }
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

function clearFilter(filterName) {
    const url = new URL(window.location.href);
    url.searchParams.delete(filterName);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', () => {
    const statusSummary = document.querySelector('.status-summary');
    if (statusSummary) {
        statusSummary.addEventListener('click', (event) => {
            const trigger = event.target.closest('.status-card[data-status]');
            if (!trigger) {
                return;
            }

            filterByStatus(trigger.getAttribute('data-status'));
        });
    }

    const activeFilters = document.querySelector('.active-filters');
    if (activeFilters) {
        activeFilters.addEventListener('click', (event) => {
            const trigger = event.target.closest('.filter-tag-remove[data-filter]');
            if (!trigger) {
                return;
            }

            clearFilter(trigger.getAttribute('data-filter'));
        });
    }
});
</script>


</body>
</html>
