<?php
require_once '../config/db_helpers.php';
require_once '../config/finance_helpers.php';
require_once '../config/sidebar.php';
require_once '../config/csrf_helpers.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    header('Location: ../index.php');
    exit;
}

$conn = getDBConnection();
$message = '';
$csrf_scope = 'student_finance_payment';

// --- Handle Payment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
    if (!csrf_validate_request_token($csrf_scope)) {
        $message = 'Invalid or expired security token. Please refresh the page and try again.';
    } else {
        $amount = floatval($_POST['amount']);
        $notes = trim($_POST['notes']);
        $sy = $_POST['academic_year']; 
        $sem = intval($_POST['semester']);

        if ($amount > 0) {
            $ins_sql = "INSERT INTO payments (student_id, amount, academic_year, semester, notes, payment_date) VALUES (?, ?, ?, ?, ?, NOW())";
            if (db_query($conn, $ins_sql, 'idsis', [$student_id, $amount, $sy, $sem, $notes])) {
                $message = 'Payment recorded successfully!';
            } else {
                // Include db error for debugging (remove in production if strict security needed, but helpful now)
                $message = 'Error recording payment: ' . $conn->error;
            }
        }
    }
}


// Student Info
$s_sql = "SELECT s.*, p.program_code FROM students s LEFT JOIN programs p ON s.program_id = p.program_id WHERE s.student_id = ?";
$student = db_fetch_one(db_query($conn, $s_sql, 'i', [$student_id]));

// Get program-specific tuition rate
$program_id = $student['program_id'] ?? null;
$rates = getProgramTuitionRate($conn, $program_id);
$tuition_rate = $rates['tuition_per_unit'];
$program_lab_fee = $rates['lab_fee'];

// Fees Config - Fixed fees (excluding LAB since it's program-specific)
$fixed_fees = [];
$total_fixed_fee = 0;

$res = db_query($conn, "SELECT * FROM fees WHERE type = 'fixed' AND code != 'LAB' ORDER BY code");
while($row = $res->fetch_assoc()) {
    $fixed_fees[] = $row;
    $total_fixed_fee += $row['amount'];
}

// Add program-specific lab fee
$fixed_fees[] = [
    'code' => 'LAB',
    'description' => 'Laboratory Fee (Program-specific)',
    'amount' => $program_lab_fee
];
$total_fixed_fee += $program_lab_fee;

// Get available overpayment credits
$available_credits = getAvailableOverpaymentCredit($conn, $student_id);
$overpayment_list = getStudentOverpayments($conn, $student_id, true);

// --- FILTERS ---
// 1. Get List of Enrolled Terms (History) + Current Context using Helper
$terms_options = get_student_term_options($conn, $student_id);

// 2. Handle Selection
$current_sys_ay = date('Y') . '-' . (date('Y') + 1); // Fallback if needed, but helper handles it.
// Default to Current Context if nothing selected
// Helper returns current context as one of the options.
// We need to find the "Current" one to set as default if no GET param?
// Or just replicate logic from before.

// In previous code: 
// $current_key = $student_current_ay . '|' . $student_current_sem;
// $active_terms logic...

// Optimization: We don't need to rebuild options manually.
// Just setting defaults.
$first_opt = reset($terms_options); 
// Note: reset() gives the first element. In helper, we added Current FIRST.
$current_key = key($terms_options); 
// Or better, let's just grab the key from GET or default to first.

// 4. Handle Selection
$selected_key = isset($_GET['term']) ? $_GET['term'] : $current_key;
$is_all_terms = ($selected_key === '||');

if ($is_all_terms) {
    $filter_ay = '';
    $filter_sem = 0;
    $selected_term = ['label' => 'All Terms (Cumulative)', 'ay' => '', 'sem' => 0];
} else {
    if (!isset($terms_options[$selected_key])) {
        $selected_key = $current_key;
    }
    $selected_term = $terms_options[$selected_key];
    $filter_ay = $selected_term['ay'];
    $filter_sem = $selected_term['sem'];
}

// 5. Active Terms
$active_terms = [];
if ($filter_ay === '' && $filter_sem === 0) {
    // "All Terms" for Assessments - get all terms for this student
    $hist_sql = "SELECT DISTINCT e.academic_year, c.semester 
                FROM enrollments e 
                JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                WHERE e.student_id = ? 
                ORDER BY e.academic_year ASC, c.semester ASC";
    $active_terms = db_fetch_all(db_query($conn, $hist_sql, 'i', [$student_id]));
} else {
    $active_terms = [['academic_year' => $filter_ay, 'semester' => $filter_sem]];
}

// --- CALCULATIONS WITH CARRY-FORWARD CREDITS ---
// Use the new function that calculates running balance with carry-forward
$all_terms_data = calculateAllTermsWithCarryForward($conn, $student_id);
$all_terms_calculated = $all_terms_data['terms'];

// Build SOA array based on filter
$grand_total_assessment = 0;
$grand_total_discount = 0;
$grand_total_paid = 0;
$grand_total_credits_applied = 0;
$soa = [];

// Create a lookup for quick access
$terms_lookup = [];
foreach ($all_terms_calculated as $t) {
    $key = $t['ay'] . '|' . $t['sem'];
    $terms_lookup[$key] = $t;
}

foreach ($active_terms as $term) {
    $key = $term['academic_year'] . '|' . $term['semester'];
    
    if (isset($terms_lookup[$key])) {
        $t = $terms_lookup[$key];
        $soa[] = [
            'ay' => $t['ay'],
            'sem' => $t['sem'],
            'units' => $t['units'],
            'tuition' => $t['tuition'],
            'misc' => $t['misc'],
            'discount' => $t['discount'],
            'discounts' => $t['discounts'],
            'total' => $t['net_assessment'],
            'paid' => $t['paid'],
            'credit_applied' => $t['credit_applied'],
            'balance' => $t['balance'],
            'running_credit' => $t['running_credit']
        ];
        $grand_total_assessment += $t['net_assessment'];
        $grand_total_discount += $t['discount'];
        $grand_total_paid += $t['paid'];
        $grand_total_credits_applied += $t['credit_applied'];
    }
}

// Calculate final balance
$balance = $grand_total_assessment - $grand_total_paid - $grand_total_credits_applied;

// Get available credit (from the last term's running credit)
$available_credits = $all_terms_data['grand_totals']['available_credit'];

// Applied credits is the sum of all credits that were applied from previous terms
$applied_credits = $grand_total_credits_applied;

// Total paid for payment history display
$total_paid = $grand_total_paid;

// Check for overpayment (negative balance)
$has_overpayment = $balance < 0;
$overpayment_amount = $has_overpayment ? abs($balance) : 0;

// --- PAYMENT HISTORY ---
$pay_sql = "SELECT * FROM payments WHERE student_id = ? ";
$pay_params = [$student_id];
$pay_types = 'i';

if ($filter_ay !== '' && $filter_sem > 0) {
    $pay_sql .= " AND academic_year = ? AND semester = ?";
    $pay_params[] = $filter_ay;
    $pay_params[] = $filter_sem;
    $pay_types .= 'si';
}
$pay_sql .= " ORDER BY payment_date DESC";

$payments = db_fetch_all(db_query($conn, $pay_sql, $pay_types, $pay_params));

// Total paid is already calculated in grand_total_paid from carry-forward calculation

$finance_page_url = getAppRoute('finance', '..');
$finance_return_url = sanitizeInternalNavigationTarget((string)($_GET['return'] ?? ''), $finance_page_url);
$encoded_return = rawurlencode($finance_return_url);

$conn->close();
// --- AJAX HANDLER ---
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if (!$is_ajax) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Details - <?php echo htmlspecialchars($student['last_name']); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/finance_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-student-finance">
<?php renderAppSidebar(['active' => 'finance', 'basePath' => '..']); ?>

<div class="container finance-container">
    <header class="finance-account-nav">
        <a href="<?php echo htmlspecialchars($finance_return_url); ?>" class="btn btn-secondary finance-account-back"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Finance Page</a>
    </header>
    <?php renderPageBreadcrumbs([
        ['label' => 'Finance', 'href' => $finance_page_url],
        ['label' => 'Account'],
        ['label' => trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? ''))]
    ]); ?>
<?php } ?>

    <!-- Wrapper for AJAX content -->
    <div id="finance-content">

    <div class="student-header">
        <h1><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
        <div class="student-badges">
            <span class="badge badge-info">
                <?php echo htmlspecialchars($student['student_number']); ?> • <?php echo htmlspecialchars($student['program_code']); ?>
            </span>
            <span class="badge badge-term">
                 <?php echo htmlspecialchars($selected_term['label']); ?>
            </span>
            <span class="badge badge-rate">
                ₱<?php echo number_format($tuition_rate, 2); ?>/unit
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- Filter Form -->
    <div class="term-filter">
        <input type="hidden" name="id" value="<?php echo $student_id; ?>">
        <div class="filter-group">
            <label>Select Term</label>
            <select name="term" data-finance-action="filter-term">
                <option value="||" <?php echo ($filter_ay === '' && $filter_sem === 0) ? 'selected' : ''; ?>>All Terms (Cumulative)</option>
                <?php foreach($terms_options as $key => $opt): ?>
                    <option value="<?php echo $key; ?>" <?php echo $selected_key === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($opt['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="grid">
        <!-- Left: Statement -->
        <div>
            <div class="card">
                <h3 class="section-title">Assessment Breakdown</h3>
                <?php if(empty($soa)): ?>
                    <p class="empty-state">No enrollment records found for this term.</p>
                <?php else: ?>
                    <?php foreach ($soa as $term): ?>
                        <div class="assessment-term-card">
                            <h4 class="assessment-term-header"><?php echo $term['ay']; ?> - <?php echo $term['sem'] == 1 ? '1st Semester' : '2nd Semester'; ?></h4>
                            <table class="assessment-table">
                                <!-- Tuition Section -->
                                <tr class="assessment-section-header">
                                    <td colspan="2">Tuition Fees</td>
                                </tr>
                                <tr>
                                    <td style="padding-left: 20px;">Tuition (<?php echo $term['units']; ?> units @ ₱<?php echo number_format($tuition_rate, 2); ?>/unit)</td>
                                    <td style="text-align:right; font-weight: 500;">₱<?php echo number_format($term['tuition'], 2); ?></td>
                                </tr>
                                <tr style="border-bottom: 1px dashed var(--border-color);">
                                    <td style="font-style: italic; color: var(--text-muted); text-align: right; padding-right: 10px;">Subtotal Tuition:</td>
                                    <td style="text-align:right; font-weight: 600;">₱<?php echo number_format($term['tuition'], 2); ?></td>
                                </tr>

                                <!-- Other Fees Section -->
                                <?php if($term['misc'] > 0): ?>
                                    <tr class="assessment-section-header">
                                        <td colspan="2" style="padding-top: 10px;">Other Fees</td>
                                    </tr>
                                    <?php foreach ($fixed_fees as $ff): ?>
                                    <tr>
                                        <td style="padding-left: 20px; color: var(--text-muted);"><?php echo htmlspecialchars($ff['description']); ?></td>
                                        <td style="text-align:right; color: var(--text-muted);">₱<?php echo number_format($ff['amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="border-bottom: 1px solid var(--border-color);">
                                        <td style="font-style: italic; color: var(--text-muted); text-align: right; padding-right: 10px;">Subtotal Other Fees:</td>
                                        <td style="text-align:right; font-weight: 600;">₱<?php echo number_format($term['misc'], 2); ?></td>
                                    </tr>
                                <?php endif; ?>

                                <!-- Scholarship Discounts Section -->
                                <?php if(!empty($term['discounts'])): ?>
                                    <tr class="discount-section-header">
                                        <td colspan="2" style="font-weight: 600; padding-top: 10px;">Scholarship Discounts</td>
                                    </tr>
                                    <?php foreach ($term['discounts'] as $disc): ?>
                                    <tr class="discount-row">
                                        <td style="padding-left: 20px; color: var(--status-success);">
                                            <?php echo htmlspecialchars($disc['name']); ?>
                                            <span style="font-size: 0.85em;">
                                                (<?php echo $disc['type'] === 'percentage' ? $disc['value'] . '% off ' . $disc['applies_to'] : 'Fixed ₱' . number_format($disc['value'], 2); ?>)
                                            </span>
                                        </td>
                                        <td style="text-align:right; color: var(--status-success); font-weight: 500;">-₱<?php echo number_format($disc['discount_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="border-bottom: 1px solid var(--status-success);">
                                        <td style="font-style: italic; color: var(--status-success); text-align: right; padding-right: 10px;">Total Discounts:</td>
                                        <td style="text-align:right; font-weight: 600; color: var(--status-success);">-₱<?php echo number_format($term['discount'], 2); ?></td>
                                    </tr>
                                <?php endif; ?>

                                <!-- Grand Total -->
                                <tr class="total-row">
                                    <td style="padding: 12px;">TOTAL ASSESSMENT</td>
                                    <td style="text-align:right; padding: 12px; font-size: 1.1em;">₱<?php echo number_format($term['total'], 2); ?></td>
                                </tr>
                                
                                <?php if(isset($term['paid']) && $term['paid'] > 0): ?>
                                <tr style="background: rgba(16, 185, 129, 0.05);">
                                    <td style="padding: 8px 12px; color: var(--status-success);">Payments Made</td>
                                    <td style="text-align:right; padding: 8px 12px; color: var(--status-success); font-weight: 500;">-₱<?php echo number_format($term['paid'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if(isset($term['credit_applied']) && $term['credit_applied'] > 0): ?>
                                <tr style="background: rgba(59, 130, 246, 0.08);">
                                    <td style="padding: 8px 12px; color: var(--status-info);">
                                        <strong class="inline-icon-text"><i class="bi bi-piggy-bank-fill" aria-hidden="true"></i>Credit Applied</strong>
                                        <span style="font-size: 0.85em; color: var(--text-muted);"> (from previous term overpayment)</span>
                                    </td>
                                    <td style="text-align:right; padding: 8px 12px; color: var(--status-info); font-weight: 600;">-₱<?php echo number_format($term['credit_applied'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if(isset($term['balance'])): ?>
                                <tr style="background: <?php echo $term['balance'] > 0 ? 'rgba(239, 68, 68, 0.08)' : 'rgba(16, 185, 129, 0.08)'; ?>;">
                                    <td style="padding: 10px 12px; font-weight: 700;">TERM BALANCE</td>
                                    <td style="text-align:right; padding: 10px 12px; font-weight: 700; color: <?php echo $term['balance'] > 0 ? 'var(--status-error)' : 'var(--status-success)'; ?>;">
                                        <?php if($term['balance'] > 0): ?>
                                            ₱<?php echo number_format($term['balance'], 2); ?>
                                        <?php elseif($term['balance'] == 0): ?>
                                            <span class="inline-icon-text"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>PAID</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if(isset($term['running_credit']) && $term['running_credit'] > 0): ?>
                                <tr style="background: rgba(99, 102, 241, 0.1);">
                                    <td style="padding: 8px 12px; color: var(--primary);">
                                        <strong class="inline-icon-text"><i class="bi bi-arrow-right-circle-fill" aria-hidden="true"></i>Credit to Next Term</strong>
                                    </td>
                                    <td style="text-align:right; padding: 8px 12px; color: var(--primary); font-weight: 600;">₱<?php echo number_format($term['running_credit'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="assessment-summary">
                    <div class="assessment-summary-row assessment-summary-row-main">
                        <span>Total Assessment</span>
                        <span>₱ <?php echo number_format($grand_total_assessment, 2); ?></span>
                    </div>
                    <div class="assessment-summary-row assessment-summary-row-paid">
                        <span>Total Paid</span>
                        <span>-₱ <?php echo number_format($grand_total_paid, 2); ?></span>
                    </div>
                    <?php if($grand_total_credits_applied > 0): ?>
                    <div class="assessment-summary-row assessment-summary-row-credit">
                        <span>Credits Applied</span>
                        <span>-₱ <?php echo number_format($grand_total_credits_applied, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="assessment-summary-row assessment-summary-row-total <?php echo $balance > 0 ? 'is-due' : ($balance < 0 ? 'is-credit' : 'is-clear'); ?>">
                        <span><?php echo $balance > 0 ? 'Balance Due' : ($balance < 0 ? 'Overpaid' : 'Fully Paid'); ?></span>
                        <span>₱ <?php echo number_format(abs($balance), 2); ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">Payment History</h3>
                <?php if (empty($payments)): ?>
                    <p>No payments recorded for this term.</p>
                <?php else: ?>
                    <table class="payment-history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ref No.</th>
                                <th>Notes</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($p['reference_no'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($p['notes']); ?></td>
                                    <td style="text-align:right;"><?php echo number_format($p['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:bold;">
                                <td colspan="3" style="text-align:right;">Total Paid</td>
                                <td style="text-align:right;"><?php echo number_format($total_paid, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Actions -->
        <div>
            <div class="card balance-card">
                <h3 class="balance-card-title"><?php echo ($filter_ay === '' && $filter_sem === 0) ? 'Cumulative Balance' : 'Balance (Selected Term)'; ?></h3>
                <?php if ($grand_total_assessment > 0 || $total_paid > 0): ?>
                    <div class="balance-amount <?php echo $balance > 0 ? 'balance-positive' : ($balance < 0 ? 'balance-negative' : 'balance-zero'); ?>">
                        ₱ <?php echo number_format(abs($balance), 2); ?>
                    </div>
                    <div class="balance-card-status">
                        <?php if ($balance > 0): ?>
                            <span class="text-error">Amount Due</span>
                        <?php elseif ($balance < 0): ?>
                            <span style="color: var(--status-info);">Overpaid (Credit for Next Term)</span>
                        <?php else: ?>
                            <span class="text-success">Fully Paid</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="balance-amount balance-empty">No Assessment</div>
                <?php endif; ?>
                <div class="balance-card-period">
                    <?php if ($filter_ay === '' && $filter_sem === 0): ?>
                        All Enrolled Terms
                    <?php else: ?>
                        <?php echo htmlspecialchars($filter_ay); ?> / Semester <?php echo $filter_sem; ?>
                    <?php endif; ?>
                </div>
                
                <?php if ($applied_credits > 0): ?>
                <div class="balance-applied-credit">
                    <div class="balance-applied-credit-text">
                        Applied Credits: <strong style="color: var(--status-success);">₱ <?php echo number_format($applied_credits, 2); ?></strong>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($available_credits > 0): ?>
                <div class="balance-available-credit">
                    <div class="balance-available-credit-text">
                        <span class="inline-icon-text"><i class="bi bi-wallet-fill" aria-hidden="true"></i>Available Credit:</span> <strong>₱ <?php echo number_format($available_credits, 2); ?></strong>
                    </div>
                    <div class="balance-available-credit-note">
                        From previous term overpayments
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="section-title"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i> Add Payment</h3>
                <form method="post" action="?id=<?php echo $student_id; ?>&term=<?php echo urlencode($selected_key); ?>" class="payment-form">
                    <?php echo csrf_token_field($csrf_scope); ?>
                    <input type="hidden" name="action" value="payment">
                    
                    <label>Academic Year</label>
                    <input type="text" name="academic_year" value="<?php echo htmlspecialchars($filter_ay); ?>" readonly>
                    
                    <label>Semester</label>
                    <input type="hidden" name="semester" value="<?php echo $filter_sem; ?>">
                    <input type="text" value="<?php echo $filter_sem == 1 ? '1st Semester' : '2nd Semester'; ?>" readonly>

                    <label>Amount (₱)</label>
                    <input type="number" name="amount" step="0.01" min="1" required placeholder="Enter payment amount">

                    <label>Notes (Optional)</label>
                    <textarea name="notes" rows="3" placeholder="Reference number, bank, payment method, etc."></textarea>

                    <button type="submit" class="btn-pay">Record Payment</button>
                </form>
            </div>
        </div>
    </div>
    </div> <!-- End finance-content -->

    <script>
        function updateFinanceFilter(val) {
             const studentId = '<?php echo $student_id; ?>';
               const returnParam = '<?php echo $encoded_return; ?>';
               const url = `?id=${studentId}&term=${encodeURIComponent(val)}&return=${returnParam}`;
             
             // Opacity indicator
             const container = document.getElementById('finance-content');
             container.style.opacity = '0.6';
             container.style.pointerEvents = 'none';

             fetch(url + '&ajax=1')
                .then(res => res.text())
                .then(html => {
                    window.history.pushState({}, '', url);
                    
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const newContent = tempDiv.querySelector('#finance-content');
                    
                    if (newContent) {
                        container.replaceWith(newContent);
                    } else {
                        container.innerHTML = html;
                        container.style.opacity = '1';
                        container.style.pointerEvents = 'auto';
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    if (window.StudentApp && window.StudentApp.Toast) {
                        window.StudentApp.Toast.show('Filter failed. Reloading page.', 'error', 2200);
                        setTimeout(() => window.location.reload(), 700);
                        return;
                    }

                    alert('Filter failed. Reloading page.');
                    window.location.reload();
                });
        }

        document.addEventListener('change', function(event) {
            const filterSelect = event.target.closest('select[data-finance-action="filter-term"]');
            if (!filterSelect) {
                return;
            }

            updateFinanceFilter(filterSelect.value);
        });
    </script>


<?php if (!$is_ajax): ?>
</div> <!-- End container -->
</body>
</html>
<?php endif; ?>
