<?php
require_once '../config/db_helpers.php';
require_once '../config/finance_helpers.php';
require_once '../config/sidebar.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$return_raw = trim((string)($_GET['return'] ?? ''));
$return_target = sanitizeInternalNavigationTarget($return_raw !== '' ? urldecode($return_raw) : '../index.php?view=students', '../index.php?view=students');

if ($student_id <= 0) {
    header('Location: ' . $return_target . '&msg=promote_failed&reason=' . urlencode('Invalid student reference.'));
    exit;
}

$conn = getDBConnection();

$student_sql = "SELECT s.*, p.program_code, p.program_name
                FROM students s
                LEFT JOIN programs p ON p.program_id = s.program_id
                WHERE s.student_id = ?";
$student_res = db_query($conn, $student_sql, 'i', [$student_id]);
$student = $student_res ? db_fetch_one($student_res) : null;

if (!$student) {
    $conn->close();
    header('Location: ' . $return_target . '&msg=promote_failed&reason=' . urlencode('Student not found.'));
    exit;
}

$current_yl = intval($student['year_level'] ?? 1);
$current_sem = intval($student['current_semester'] ?? 1);
$system_current_ay = (string)getSystemSetting($conn, 'current_academic_year', (date('Y') . '-' . (date('Y') + 1)));

if ($current_sem === 1) {
    $target_yl = $current_yl;
    $target_sem = 2;
} else {
    $target_yl = $current_yl + 1;
    $target_sem = 1;
}

// Resolve source academic year (same preference order used in edit flow).
$source_ay = '';
$status_ay_sql = "SELECT academic_year
                  FROM semester_status
                  WHERE student_id = ?
                    AND year_level = ?
                    AND semester = ?
                    AND status = 'In Progress'
                  ORDER BY academic_year DESC, status_id DESC
                  LIMIT 1";
$status_ay_row = db_fetch_one(db_query($conn, $status_ay_sql, 'iii', [$student_id, $current_yl, $current_sem]));
$source_ay = $status_ay_row['academic_year'] ?? '';

if ($source_ay === '') {
    $enrollment_ay_sql = "SELECT e.academic_year
                          FROM enrollments e
                          JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                          WHERE e.student_id = ? AND c.year_level = ? AND c.semester = ?
                          ORDER BY e.academic_year DESC, e.enrollment_id DESC
                          LIMIT 1";
    $enrollment_ay_row = db_fetch_one(db_query($conn, $enrollment_ay_sql, 'iii', [$student_id, $current_yl, $current_sem]));
    $source_ay = $enrollment_ay_row['academic_year'] ?? '';
}

if ($source_ay === '') {
    $source_ay = $system_current_ay;
}

$errors = [];

// Academic eligibility gate (same rule from edit flow).
$eligibility_sql = "SELECT
                        SUM(CASE WHEN COALESCE(e.status, '') <> 'Passed' THEN 1 ELSE 0 END) AS non_passed_count,
                        SUM(CASE WHEN e.status = 'Failed' OR (e.final_grade IS NOT NULL AND e.final_grade > 3.00) THEN 1 ELSE 0 END) AS failed_count
                    FROM enrollments e
                    JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                    WHERE e.student_id = ?
                      AND e.academic_year = ?
                      AND c.year_level = ?
                      AND c.semester = ?";
$eligibility_row = db_fetch_one(db_query($conn, $eligibility_sql, 'isii', [$student_id, $source_ay, $current_yl, $current_sem]));
$non_passed_count = intval($eligibility_row['non_passed_count'] ?? 0);
$failed_count = intval($eligibility_row['failed_count'] ?? 0);

if ($non_passed_count > 0 || $failed_count > 0) {
    $errors[] = 'Cannot promote: found ' . $non_passed_count . ' non-passed subject(s), including ' . $failed_count . ' failed subject(s).';
}

$term_assessment = getTermAssessment($conn, $student_id, $source_ay, $current_sem);
$term_balance = getTermBalance($conn, $student_id, $source_ay, $current_sem);
$forward_balance_amount = 0.0;

if (empty($errors) && round($term_balance, 2) > 0) {
    $threshold = round($term_assessment * 0.20, 2);
    if (round($term_balance, 2) > $threshold) {
        $errors[] = 'Cannot promote: current term balance (Php ' . number_format($term_balance, 2) . ') exceeds 20% threshold (Php ' . number_format($threshold, 2) . ').';
    } else {
        // Fast promote flow auto-forwards balances that are within threshold.
        $forward_balance_amount = round($term_balance, 2);
    }
}

if (!empty($errors)) {
    $conn->close();
    $reason = implode(' ', $errors);
    $redirect = $return_target . '&msg=promote_failed&name=' . urlencode($student['last_name'] . ', ' . $student['first_name']) . '&reason=' . urlencode($reason);
    header('Location: ' . $redirect);
    exit;
}

if (!$conn->begin_transaction()) {
    $conn->close();
    header('Location: ' . $return_target . '&msg=promote_failed&name=' . urlencode($student['last_name'] . ', ' . $student['first_name']) . '&reason=' . urlencode('Unable to start promotion transaction.'));
    exit;
}

try {
    // Finalize current term status.
    $complete_sql = "UPDATE semester_status
                     SET status = 'Completed', updated_at = NOW()
                     WHERE student_id = ? AND academic_year = ? AND semester = ? AND status = 'In Progress'";
    if (db_query($conn, $complete_sql, 'isi', [$student_id, $source_ay, $current_sem]) === false) {
        throw new Exception('Failed to complete current semester status.');
    }

    $pass_enroll_sql = "UPDATE enrollments e
                        JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                        SET e.status = 'Passed', e.updated_at = NOW()
                        WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? AND e.status = 'Enrolled'";
    if (db_query($conn, $pass_enroll_sql, 'isi', [$student_id, $source_ay, $current_sem]) === false) {
        throw new Exception('Failed to finalize enrolled subjects.');
    }

    // Promote student profile.
    $update_student_sql = "UPDATE students
                           SET year_level = ?, current_semester = ?, updated_at = NOW()
                           WHERE student_id = ?";
    if (db_query($conn, $update_student_sql, 'iii', [$target_yl, $target_sem, $student_id]) === false) {
        throw new Exception('Failed to update student term info.');
    }

    // Compute target academic year used in finance/scholarship records.
    $target_ay = $system_current_ay;
    if ($target_yl > $current_yl || ($current_sem === 2 && $target_sem === 1)) {
        $target_ay = getNextAcademicYear($system_current_ay);
    }

    // Auto-apply merit scholarship for promotion targets.
    $merit_result = applyPromotionMeritScholarship(
        $conn,
        $student_id,
        $source_ay,
        $current_sem,
        $target_ay,
        $target_sem
    );

    if (empty($merit_result['success'])) {
        throw new Exception('Failed to apply merit scholarship: ' . ($merit_result['reason'] ?? 'Unknown error'));
    }

    // Auto-forward within-threshold balance.
    if ($forward_balance_amount > 0) {
        $credit_note = 'Balance forwarded to Year ' . $target_yl . ' Sem ' . $target_sem;
        $insert_payment_sql = "INSERT INTO payments (student_id, amount, academic_year, semester, notes) VALUES (?, ?, ?, ?, ?)";
        if (db_query($conn, $insert_payment_sql, 'idiss', [$student_id, $forward_balance_amount, $source_ay, $current_sem, $credit_note]) === false) {
            throw new Exception('Failed recording source-term forward credit.');
        }

        $debit_note = 'Balance forwarded from Year ' . $current_yl . ' Sem ' . $current_sem;
        if (db_query($conn, $insert_payment_sql, 'idiss', [$student_id, -$forward_balance_amount, $target_ay, $target_sem, $debit_note]) === false) {
            throw new Exception('Failed recording target-term forward debit.');
        }
    }

    $conn->commit();
    $conn->close();

    $success_url = $return_target
        . '&msg=promoted'
        . '&name=' . urlencode($student['last_name'] . ', ' . $student['first_name'])
        . '&student_number=' . urlencode((string)($student['student_number'] ?? ''))
        . '&program=' . urlencode((string)($student['program_code'] ?? ''));

    header('Location: ' . $success_url);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();

    $failure_url = $return_target
        . '&msg=promote_failed'
        . '&name=' . urlencode($student['last_name'] . ', ' . $student['first_name'])
        . '&reason=' . urlencode($e->getMessage());

    header('Location: ' . $failure_url);
    exit;
}
