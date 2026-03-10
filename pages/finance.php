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

// Calculate Balances for displayed students using program-specific rates
foreach ($students as &$student) {
    $sid = $student['student_id'];
    
    // Use the updated getStudentBalance function which handles program-specific tuition rates
    $student['balance'] = getStudentBalance($conn, $sid);
    
    // Get available credits (from overpayments)
    $student['available_credits'] = getAvailableOverpaymentCredit($conn, $sid);
    
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
    <link rel="stylesheet" href="../css/common.css">
    <link rel="stylesheet" href="../css/enhancements.css">
    <style>
        /* Finance-specific styles */
        .finance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .finance-title-group {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .filter-section {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            min-width: 180px;
            background: white;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
        }
        
        .status-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .status-card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 15px 25px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            text-align: center;
            min-width: 120px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .status-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .status-card.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.2);
        }
        
        .status-card .count {
            font-size: 28px;
            font-weight: 700;
            font-family: var(--font-heading);
        }
        
        .status-card .label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        
        .status-card.unpaid .count { color: var(--status-error); }
        .status-card.clear .count { color: var(--status-success); }
        .status-card.overpaid .count { color: var(--status-info); }
        
        .finance-table-container {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .finance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .finance-table th {
            background: #f8f9fa;
            padding: 14px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .finance-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .finance-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .finance-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .balance-amount {
            font-weight: 600;
            font-family: var(--font-heading);
        }
        
        .balance-positive { color: var(--status-error); }
        .balance-negative { color: var(--status-info); }
        .balance-zero { color: var(--status-success); }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-unpaid {
            background: rgba(239, 68, 68, 0.1);
            color: var(--status-error);
        }
        
        .status-clear {
            background: rgba(16, 185, 129, 0.1);
            color: var(--status-success);
        }
        
        .status-overpaid {
            background: rgba(14, 165, 233, 0.1);
            color: var(--status-info);
        }
        
        .btn-view {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            border: 1px solid var(--primary);
        }
        
        .btn-view:hover {
            background: #3730a3;
            border-color: #3730a3;
            color: white;
        }
        
        .pagination {
            display: flex;
            gap: 8px;
            justify-content: center;
            padding: 20px;
            flex-wrap: wrap;
        }
        
        .pagination a {
            padding: 8px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            text-decoration: none;
            color: var(--text-main);
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .pagination a:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .active-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            border-radius: 20px;
            font-size: 13px;
        }
        
        .filter-tag-remove {
            cursor: pointer;
            font-weight: bold;
            opacity: 0.7;
        }
        
        .filter-tag-remove:hover {
            opacity: 1;
        }
        
        @media (max-width: 768px) {
            .finance-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group input,
            .filter-group select {
                min-width: 100%;
            }
            
            .status-summary {
                justify-content: center;
            }
            
            .finance-table-container {
                overflow-x: auto;
            }
            
            .finance-table {
                min-width: 600px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <header>
        <div class="finance-title-group">
            <a href="../index.php" class="btn btn-secondary">&larr; Back</a>
            <h1>Finance Dashboard</h1>
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
                            <span class="filter-tag-remove" onclick="clearFilter('search')">&times;</span>
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
                            <span class="filter-tag-remove" onclick="clearFilter('program')">&times;</span>
                        </span>
                    <?php endif; ?>
                    <?php if ($filter_status): ?>
                        <span class="filter-tag">
                            Status: <?php echo htmlspecialchars($filter_status); ?>
                            <span class="filter-tag-remove" onclick="clearFilter('status')">&times;</span>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Status Summary Cards -->
    <div class="status-summary">
        <div class="status-card unpaid <?php echo $filter_status === 'Unpaid' ? 'active' : ''; ?>" onclick="filterByStatus('Unpaid')">
            <div class="count"><?php echo $status_counts['Unpaid']; ?></div>
            <div class="label">Unpaid</div>
        </div>
        <div class="status-card clear <?php echo $filter_status === 'Clear' ? 'active' : ''; ?>" onclick="filterByStatus('Clear')">
            <div class="count"><?php echo $status_counts['Clear']; ?></div>
            <div class="label">Clear</div>
        </div>
        <div class="status-card overpaid <?php echo $filter_status === 'Overpaid' ? 'active' : ''; ?>" onclick="filterByStatus('Overpaid')">
            <div class="count"><?php echo $status_counts['Overpaid']; ?></div>
            <div class="label">Overpaid</div>
        </div>
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
                                <div class="empty-state-icon">📋</div>
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
                                <a href="student_finance.php?id=<?php echo $s['student_id']; ?>" class="btn-view">
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
</script>


</body>
</html>
