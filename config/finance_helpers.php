<?php
require_once 'db_helpers.php';

/**
 * Get program-specific tuition rate for a student
 * Falls back to base rate from fees table if no program-specific rate exists
 * 
 * @param mysqli $conn Database connection
 * @param int $program_id Program ID
 * @return array ['tuition_per_unit' => float, 'lab_fee' => float]
 */
function getProgramTuitionRate($conn, $program_id) {
    // Check for program-specific rate
    $sql = "SELECT tuition_per_unit, lab_fee FROM program_tuition_rates 
            WHERE program_id = ? AND is_active = 1 
            ORDER BY effective_date DESC LIMIT 1";
    $result = db_query($conn, $sql, 'i', [$program_id]);
    $row = $result ? db_fetch_one($result) : null;
    
    if ($row) {
        return [
            'tuition_per_unit' => floatval($row['tuition_per_unit']),
            'lab_fee' => floatval($row['lab_fee'])
        ];
    }
    
    // Fallback to base rate from fees table
    $base_sql = "SELECT amount FROM fees WHERE code = 'TUITION' AND type = 'per_unit' LIMIT 1";
    $base_result = db_query($conn, $base_sql);
    $base = $base_result ? db_fetch_one($base_result) : null;
    
    $lab_sql = "SELECT amount FROM fees WHERE code = 'LAB' LIMIT 1";
    $lab_result = db_query($conn, $lab_sql);
    $lab = $lab_result ? db_fetch_one($lab_result) : null;
    
    return [
        'tuition_per_unit' => $base ? floatval($base['amount']) : 800.00,
        'lab_fee' => $lab ? floatval($lab['amount']) : 2000.00
    ];
}

/**
 * Get student's program ID
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @return int|null Program ID or null
 */
function getStudentProgramId($conn, $student_id) {
    $sql = "SELECT program_id FROM students WHERE student_id = ?";
    $result = db_query($conn, $sql, 'i', [$student_id]);
    $row = $result ? db_fetch_one($result) : null;
    return $row ? intval($row['program_id']) : null;
}

/**
 * Get available overpayment credit for a student from previous terms
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @return float Total available overpayment credit
 */
function getAvailableOverpaymentCredit($conn, $student_id) {
    $sql = "SELECT SUM(amount) as total FROM term_overpayments 
            WHERE student_id = ? AND is_applied = 0";
    $result = db_query($conn, $sql, 'i', [$student_id]);
    $row = $result ? db_fetch_one($result) : null;
    return $row && $row['total'] ? floatval($row['total']) : 0;
}

/**
 * Get available overpayment credits for multiple students in one query.
 *
 * @param mysqli $conn Database connection
 * @param array $student_ids Student IDs
 * @return array Map of student_id => available credit
 */
function getAvailableOverpaymentCreditsBatch($conn, $student_ids) {
    $student_ids = array_values(array_filter(array_map('intval', $student_ids), function($id) {
        return $id > 0;
    }));

    if (empty($student_ids)) {
        return [];
    }

    $credits = array_fill_keys($student_ids, 0.0);
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $types = str_repeat('i', count($student_ids));

    $sql = "SELECT student_id, SUM(amount) as total
            FROM term_overpayments
            WHERE is_applied = 0 AND student_id IN ($placeholders)
            GROUP BY student_id";

    $result = db_query($conn, $sql, $types, $student_ids);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $sid = intval($row['student_id']);
            $credits[$sid] = $row['total'] ? floatval($row['total']) : 0.0;
        }
    }

    return $credits;
}

/**
 * Calculate balances for multiple students in batch.
 * Mirrors getStudentBalance() logic but avoids per-student N+1 queries.
 *
 * @param mysqli $conn Database connection
 * @param array $student_program_map Map of student_id => program_id
 * @return array Map of student_id => balance
 */
function getStudentBalancesBatch($conn, $student_program_map) {
    if (!is_array($student_program_map) || empty($student_program_map)) {
        return [];
    }

    $normalized_program_map = [];
    foreach ($student_program_map as $student_id => $program_id) {
        $sid = intval($student_id);
        if ($sid <= 0) {
            continue;
        }
        $normalized_program_map[$sid] = $program_id !== null ? intval($program_id) : null;
    }

    if (empty($normalized_program_map)) {
        return [];
    }

    $student_ids = array_keys($normalized_program_map);
    $balances = array_fill_keys($student_ids, 0.0);

    // Fetch base fee configuration once.
    $base_tuition_rate = 800.00;
    $base_lab_fee = 2000.00;
    $fixed_fees_excluding_lab = 0.0;

    $fee_sql = "SELECT code, type, amount FROM fees WHERE type = 'fixed' OR code IN ('TUITION', 'LAB')";
    $fees_result = db_query($conn, $fee_sql);
    if ($fees_result) {
        while ($row = $fees_result->fetch_assoc()) {
            $amount = floatval($row['amount']);
            if ($row['code'] === 'TUITION' && $row['type'] === 'per_unit') {
                $base_tuition_rate = $amount;
            }
            if ($row['code'] === 'LAB') {
                $base_lab_fee = $amount;
            }
            if ($row['type'] === 'fixed' && $row['code'] !== 'LAB') {
                $fixed_fees_excluding_lab += $amount;
            }
        }
    }

    // Build program-specific rate map in one query, falling back to base rates.
    $program_ids = array_values(array_unique(array_filter(array_map('intval', array_values($normalized_program_map)), function($id) {
        return $id > 0;
    })));

    $rates_by_program = [];
    foreach ($program_ids as $pid) {
        $rates_by_program[$pid] = [
            'tuition_per_unit' => $base_tuition_rate,
            'lab_fee' => $base_lab_fee
        ];
    }

    if (!empty($program_ids)) {
        $program_placeholders = implode(',', array_fill(0, count($program_ids), '?'));
        $program_types = str_repeat('i', count($program_ids));

        $rate_sql = "SELECT ptr.program_id, ptr.tuition_per_unit, ptr.lab_fee
                     FROM program_tuition_rates ptr
                     INNER JOIN (
                        SELECT program_id, MAX(effective_date) AS latest_effective
                        FROM program_tuition_rates
                        WHERE is_active = 1 AND program_id IN ($program_placeholders)
                        GROUP BY program_id
                     ) latest
                        ON latest.program_id = ptr.program_id
                        AND latest.latest_effective = ptr.effective_date
                     WHERE ptr.is_active = 1";

        $rate_result = db_query($conn, $rate_sql, $program_types, $program_ids);
        if ($rate_result) {
            while ($row = $rate_result->fetch_assoc()) {
                $pid = intval($row['program_id']);
                $rates_by_program[$pid] = [
                    'tuition_per_unit' => floatval($row['tuition_per_unit']),
                    'lab_fee' => floatval($row['lab_fee'])
                ];
            }
        }
    }

    // Aggregate all enrolled units per student per term.
    $student_placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $included_statuses = ['Enrolled', 'Passed', 'Failed'];
    $status_placeholders = implode(',', array_fill(0, count($included_statuses), '?'));
    $term_types = str_repeat('i', count($student_ids)) . str_repeat('s', count($included_statuses));
    $term_params = array_merge($student_ids, $included_statuses);

    $term_sql = "SELECT e.student_id, e.academic_year, c.semester, SUM(c.units) as total_units
                 FROM enrollments e
                 JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                 WHERE e.student_id IN ($student_placeholders)
                 AND e.status IN ($status_placeholders)
                 GROUP BY e.student_id, e.academic_year, c.semester";

    $assessment_by_student = array_fill_keys($student_ids, 0.0);
    $term_result = db_query($conn, $term_sql, $term_types, $term_params);
    if ($term_result) {
        while ($row = $term_result->fetch_assoc()) {
            $sid = intval($row['student_id']);
            $units = $row['total_units'] ? floatval($row['total_units']) : 0.0;
            if ($units <= 0 || !isset($assessment_by_student[$sid])) {
                continue;
            }

            $program_id = isset($normalized_program_map[$sid]) ? intval($normalized_program_map[$sid]) : 0;
            $rates = ($program_id > 0 && isset($rates_by_program[$program_id]))
                ? $rates_by_program[$program_id]
                : ['tuition_per_unit' => $base_tuition_rate, 'lab_fee' => $base_lab_fee];

            $term_tuition = $units * $rates['tuition_per_unit'];
            $term_misc = $fixed_fees_excluding_lab + $rates['lab_fee'];
            $term_scholarship = calculateScholarshipDiscount(
                $conn,
                $sid,
                (string)$row['academic_year'],
                intval($row['semester']),
                $term_tuition,
                $term_misc
            );
            $term_discount = floatval($term_scholarship['total_discount'] ?? 0);

            $assessment_by_student[$sid] += (($term_tuition + $term_misc) - $term_discount);
        }
    }

    // Aggregate total payments once for all students.
    $payment_sql = "SELECT student_id, SUM(amount) as total_paid
                    FROM payments
                    WHERE student_id IN ($student_placeholders)
                    GROUP BY student_id";
    $payment_result = db_query($conn, $payment_sql, str_repeat('i', count($student_ids)), $student_ids);
    $payments_by_student = array_fill_keys($student_ids, 0.0);
    if ($payment_result) {
        while ($row = $payment_result->fetch_assoc()) {
            $sid = intval($row['student_id']);
            if (isset($payments_by_student[$sid])) {
                $payments_by_student[$sid] = $row['total_paid'] ? floatval($row['total_paid']) : 0.0;
            }
        }
    }

    foreach ($student_ids as $sid) {
        $balances[$sid] = $assessment_by_student[$sid] - $payments_by_student[$sid];
    }

    return $balances;
}

/**
 * Get overpayment details for display
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param bool $unapplied_only Only get unapplied overpayments
 * @return array List of overpayments
 */
function getStudentOverpayments($conn, $student_id, $unapplied_only = true) {
    $sql = "SELECT * FROM term_overpayments WHERE student_id = ?";
    if ($unapplied_only) {
        $sql .= " AND is_applied = 0";
    }
    $sql .= " ORDER BY created_at DESC";
    $result = db_query($conn, $sql, 'i', [$student_id]);
    return $result ? db_fetch_all($result) : [];
}

/**
 * Record an overpayment from a term
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic Year
 * @param int $sem Semester
 * @param float $amount Overpayment amount
 * @return bool Success
 */
function recordOverpayment($conn, $student_id, $ay, $sem, $amount) {
    // Check if overpayment already exists for this term
    $exists_sql = "SELECT overpayment_id, amount FROM term_overpayments 
                   WHERE student_id = ? AND source_academic_year = ? AND source_semester = ? AND is_applied = 0";
    $exists = db_fetch_one(db_query($conn, $exists_sql, 'isi', [$student_id, $ay, $sem]));
    
    if ($exists) {
        // Update existing overpayment
        $update_sql = "UPDATE term_overpayments SET amount = ?, updated_at = NOW() WHERE overpayment_id = ?";
        return db_query($conn, $update_sql, 'di', [$amount, $exists['overpayment_id']]) !== false;
    }
    
    $sql = "INSERT INTO term_overpayments (student_id, source_academic_year, source_semester, amount) 
            VALUES (?, ?, ?, ?)";
    return db_query($conn, $sql, 'isid', [$student_id, $ay, $sem, $amount]) !== false;
}

/**
 * Apply overpayment credit to a term
 * 
 * @param mysqli $conn Database connection
 * @param int $overpayment_id Overpayment record ID
 * @param string $ay Target Academic Year
 * @param int $sem Target Semester
 * @return bool Success
 */
function applyOverpaymentCredit($conn, $overpayment_id, $ay, $sem) {
    $sql = "UPDATE term_overpayments 
            SET is_applied = 1, applied_academic_year = ?, applied_semester = ?, applied_date = NOW(), updated_at = NOW() 
            WHERE overpayment_id = ?";
    return db_query($conn, $sql, 'sii', [$ay, $sem, $overpayment_id]) !== false;
}

/**
 * Calculate all terms with automatic carry-forward of overpayments
 * This gives a complete financial picture with running balance
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @return array ['terms' => array, 'grand_totals' => array]
 */
function calculateAllTermsWithCarryForward($conn, $student_id) {
    // Get student's program for tuition rate
    $program_id = getStudentProgramId($conn, $student_id);
    $rates = getProgramTuitionRate($conn, $program_id);
    $tuition_rate = $rates['tuition_per_unit'];
    $program_lab_fee = $rates['lab_fee'];
    
    // Get fixed fees (excluding LAB since it's program-specific now)
    $total_fixed_fee = 0;
    $res = db_query($conn, "SELECT * FROM fees WHERE type = 'fixed' AND code != 'LAB'");
    while($row = $res->fetch_assoc()) {
        $total_fixed_fee += floatval($row['amount']);
    }
    $total_fixed_fee += $program_lab_fee;
    
    // Get all enrolled terms ordered chronologically
    $terms_sql = "SELECT DISTINCT e.academic_year, c.semester 
                  FROM enrollments e 
                  JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                  WHERE e.student_id = ? 
                  ORDER BY e.academic_year ASC, c.semester ASC";
    $terms = db_fetch_all(db_query($conn, $terms_sql, 'i', [$student_id]));
    
    $result_terms = [];
    $running_credit = 0; // Carry-forward credit from previous terms
    $grand_total_assessment = 0;
    $grand_total_paid = 0;
    $grand_total_discount = 0;
    
    foreach ($terms as $term) {
        $ay = $term['academic_year'];
        $sem = $term['semester'];
        
        // Get units for this term
        $u_sql = "SELECT SUM(c.units) as total_units 
                  FROM enrollments e 
                  JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                  WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? 
                  AND e.status IN ('Enrolled', 'Passed', 'Failed')";
        $units_row = db_fetch_one(db_query($conn, $u_sql, 'isi', [$student_id, $ay, $sem]));
        $units = $units_row ? floatval($units_row['total_units']) : 0;
        
        $tuition = $units * $tuition_rate;
        $misc = ($units > 0) ? $total_fixed_fee : 0;
        
        // Get scholarship discounts
        $scholarship_data = calculateScholarshipDiscount($conn, $student_id, $ay, $sem, $tuition, $misc);
        $term_discount = $scholarship_data['total_discount'];
        
        $gross_assessment = $tuition + $misc;
        $net_assessment = $gross_assessment - $term_discount;
        
        // Get payments for this term
        $pay_sql = "SELECT SUM(amount) as total_paid FROM payments 
                    WHERE student_id = ? AND academic_year = ? AND semester = ?";
        $pay_row = db_fetch_one(db_query($conn, $pay_sql, 'isi', [$student_id, $ay, $sem]));
        $term_paid = $pay_row && $pay_row['total_paid'] ? floatval($pay_row['total_paid']) : 0;
        
        // Apply carry-forward credit from previous terms
        $credit_applied = 0;
        if ($running_credit > 0 && $net_assessment > 0) {
            $credit_applied = min($running_credit, $net_assessment);
            $running_credit -= $credit_applied;
        }
        
        // Calculate term balance
        $term_balance = $net_assessment - $term_paid - $credit_applied;
        
        // If overpaid this term, add to carry-forward credit
        if ($term_balance < 0) {
            $running_credit += abs($term_balance);
            $term_balance = 0; // This term is fully paid
        }
        
        $result_terms[] = [
            'ay' => $ay,
            'sem' => $sem,
            'units' => $units,
            'tuition' => $tuition,
            'tuition_rate' => $tuition_rate,
            'misc' => $misc,
            'gross_assessment' => $gross_assessment,
            'discount' => $term_discount,
            'discounts' => $scholarship_data['discounts'],
            'net_assessment' => $net_assessment,
            'paid' => $term_paid,
            'credit_applied' => $credit_applied,
            'balance' => $term_balance,
            'running_credit' => $running_credit
        ];
        
        $grand_total_assessment += $net_assessment;
        $grand_total_paid += $term_paid;
        $grand_total_discount += $term_discount;
    }
    
    return [
        'terms' => $result_terms,
        'grand_totals' => [
            'assessment' => $grand_total_assessment,
            'paid' => $grand_total_paid,
            'discount' => $grand_total_discount,
            'available_credit' => $running_credit,
            'balance' => $grand_total_assessment - $grand_total_paid
        ],
        'tuition_rate' => $tuition_rate,
        'lab_fee' => $program_lab_fee
    ];
}

/**
 * Calculate the total outstanding balance for a student.
 * Now uses program-specific tuition rates and handles overpayment credits.
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @return float The total balance (Assessment - Payments - Overpayment Credits)
 */
function getStudentBalance($conn, $student_id) {
    // Get student's program for tuition rate
    $program_id = getStudentProgramId($conn, $student_id);
    $rates = getProgramTuitionRate($conn, $program_id);
    $tuition_rate = $rates['tuition_per_unit'];
    $program_lab_fee = $rates['lab_fee'];
    
    // Get fixed fees (excluding LAB since it's program-specific now)
    $total_fixed_fee = 0;
    $res = db_query($conn, "SELECT * FROM fees WHERE type = 'fixed' AND code != 'LAB'");
    while($row = $res->fetch_assoc()) {
        $total_fixed_fee += floatval($row['amount']);
    }
    
    // Add program-specific lab fee
    $total_fixed_fee += $program_lab_fee;

    // Get all enrolled terms
    $sem_sql = "SELECT DISTINCT e.academic_year, c.semester 
                FROM enrollments e 
                JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                WHERE e.student_id = ? 
                AND e.status IN ('Enrolled', 'Passed', 'Failed')";
                
    $terms_res = db_query($conn, $sem_sql, 'i', [$student_id]);
    $terms = $terms_res ? db_fetch_all($terms_res) : [];
    
    $total_assessment = 0;
    
    foreach ($terms as $term) {
        $ay = $term['academic_year'];
        $sem = $term['semester'];
        
        // Get total units for this term
        $u_sql = "SELECT SUM(c.units) as total_units 
                  FROM enrollments e 
                  JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
                  WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? 
                  AND e.status IN ('Enrolled', 'Passed', 'Failed')";
                  
        $units_row = db_fetch_one(db_query($conn, $u_sql, 'isi', [$student_id, $ay, $sem]));
        $units = $units_row ? floatval($units_row['total_units']) : 0;
        
        if ($units > 0) {
            $term_tuition = $units * $tuition_rate;
            $term_misc = $total_fixed_fee;
            $total_assessment += ($term_tuition + $term_misc);
        }
    }

    // Get Total Payments
    $pay_sql = "SELECT SUM(amount) as total_paid FROM payments WHERE student_id = ?";
    $pay_row = db_fetch_one(db_query($conn, $pay_sql, 'i', [$student_id]));
    $total_paid = $pay_row ? floatval($pay_row['total_paid']) : 0;

    // Return Balance (Note: overpayment credits are tracked separately and auto-applied)
    return $total_assessment - $total_paid;
}

/**
 * Calculate the net assessment for a specific term (after scholarship discounts).
 * Uses program-specific tuition rates.
 */
function getTermAssessment($conn, $student_id, $ay, $sem) {
    // Get student's program for tuition rate
    $program_id = getStudentProgramId($conn, $student_id);
    $rates = getProgramTuitionRate($conn, $program_id);
    $tuition_rate = $rates['tuition_per_unit'];
    $program_lab_fee = $rates['lab_fee'];
    
    // Get fixed fees (excluding LAB since it's program-specific now)
    $total_fixed_fee = 0;
    $res = db_query($conn, "SELECT * FROM fees WHERE type = 'fixed' AND code != 'LAB'");
    while($row = $res->fetch_assoc()) {
        $total_fixed_fee += floatval($row['amount']);
    }
    
    // Add program-specific lab fee
    $total_fixed_fee += $program_lab_fee;

    $u_sql = "SELECT SUM(c.units) as total_units 
              FROM enrollments e 
              JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
              WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? 
              AND e.status IN ('Enrolled', 'Passed', 'Failed')";
              
    $units_row = db_fetch_one(db_query($conn, $u_sql, 'isi', [$student_id, $ay, $sem]));
    $units = $units_row ? floatval($units_row['total_units']) : 0;
    
    if ($units > 0) {
        $tuition_amount = $units * $tuition_rate;
        $gross_assessment = $tuition_amount + $total_fixed_fee;
        $scholarship_data = calculateScholarshipDiscount($conn, $student_id, $ay, $sem, $tuition_amount, $total_fixed_fee);
        $total_discount = floatval($scholarship_data['total_discount'] ?? 0);

        return $gross_assessment - $total_discount;
    }
    return 0;
}

/**
 * Calculate the balance for a specific term.
 * Now includes overpayment credits from previous terms.
 */
function getTermBalance($conn, $student_id, $ay, $sem) {
    $assessment = getTermAssessment($conn, $student_id, $ay, $sem);
    
    $pay_sql = "SELECT SUM(amount) as total_paid FROM payments WHERE student_id = ? AND academic_year = ? AND semester = ?";
    $pay_row = db_fetch_one(db_query($conn, $pay_sql, 'isi', [$student_id, $ay, $sem]));
    $total_paid = $pay_row ? floatval($pay_row['total_paid']) : 0;

    // Check for applied overpayment credits to this term
    $credit_sql = "SELECT SUM(amount) as applied_credit FROM term_overpayments 
                   WHERE student_id = ? AND applied_academic_year = ? AND applied_semester = ? AND is_applied = 1";
    $credit_row = db_fetch_one(db_query($conn, $credit_sql, 'isi', [$student_id, $ay, $sem]));
    $applied_credit = $credit_row && $credit_row['applied_credit'] ? floatval($credit_row['applied_credit']) : 0;

    $raw_balance = $assessment - $total_paid - $applied_credit;
    if ($raw_balance <= 0) {
        return $raw_balance;
    }

    // Also consider unapplied credits from earlier terms for consistency with carry-forward views.
    $available_credit_sql = "SELECT SUM(amount) AS available_credit
                             FROM term_overpayments
                             WHERE student_id = ?
                               AND is_applied = 0
                               AND NOT (source_academic_year = ? AND source_semester = ?)";
    $available_credit_row = db_fetch_one(db_query($conn, $available_credit_sql, 'isi', [$student_id, $ay, $sem]));
    $available_credit = $available_credit_row && $available_credit_row['available_credit']
        ? floatval($available_credit_row['available_credit'])
        : 0;

    $auto_credit = min($available_credit, $raw_balance);

    return $raw_balance - $auto_credit;
}

/**
 * Process term completion and handle overpayments
 * Call this when a term is fully paid to check for overpayment
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic Year
 * @param int $sem Semester
 * @return array ['has_overpayment' => bool, 'overpayment_amount' => float]
 */
function processTermOverpayment($conn, $student_id, $ay, $sem) {
    $balance = getTermBalance($conn, $student_id, $ay, $sem);
    
    if ($balance < 0) {
        // Negative balance means overpayment
        $overpayment = abs($balance);
        recordOverpayment($conn, $student_id, $ay, $sem, $overpayment);
        return ['has_overpayment' => true, 'overpayment_amount' => $overpayment];
    }
    
    return ['has_overpayment' => false, 'overpayment_amount' => 0];
}

/**
 * Apply available overpayment credit to a term's balance
 * 
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $target_ay Target Academic Year
 * @param int $target_sem Target Semester
 * @return array ['applied_amount' => float, 'remaining_balance' => float]
 */
function applyAvailableCredits($conn, $student_id, $target_ay, $target_sem) {
    // Get current term balance
    $current_balance = getTermBalance($conn, $student_id, $target_ay, $target_sem);
    
    if ($current_balance <= 0) {
        return ['applied_amount' => 0, 'remaining_balance' => $current_balance];
    }
    
    // Get unapplied overpayments (oldest first)
    $sql = "SELECT * FROM term_overpayments 
            WHERE student_id = ? AND is_applied = 0 
            ORDER BY source_academic_year ASC, source_semester ASC";
    $result = db_query($conn, $sql, 'i', [$student_id]);
    $overpayments = $result ? db_fetch_all($result) : [];
    
    $total_applied = 0;
    $remaining = $current_balance;
    
    foreach ($overpayments as $op) {
        if ($remaining <= 0) break;
        
        $apply_amount = min($op['amount'], $remaining);
        
        if ($apply_amount == $op['amount']) {
            // Apply full overpayment
            applyOverpaymentCredit($conn, $op['overpayment_id'], $target_ay, $target_sem);
        } else {
            // Partial application - update the remaining amount
            $new_amount = $op['amount'] - $apply_amount;
            $update_sql = "UPDATE term_overpayments SET amount = ?, updated_at = NOW() WHERE overpayment_id = ?";
            db_query($conn, $update_sql, 'di', [$new_amount, $op['overpayment_id']]);
            
            // Record the applied portion
            $insert_sql = "INSERT INTO term_overpayments 
                          (student_id, source_academic_year, source_semester, amount, applied_academic_year, applied_semester, is_applied, applied_date) 
                          VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";
            db_query($conn, $insert_sql, 'isidsi', [$student_id, $op['source_academic_year'], $op['source_semester'], $apply_amount, $target_ay, $target_sem]);
        }
        
        $total_applied += $apply_amount;
        $remaining -= $apply_amount;
    }
    
    return ['applied_amount' => $total_applied, 'remaining_balance' => $remaining];
}

// ============================================================
// SCHOLARSHIP FUNCTIONS
// ============================================================

/**
 * Get all available scholarships
 * @param mysqli $conn Database connection
 * @param bool $active_only Only return active scholarships
 * @return array List of scholarships
 */
function getAllScholarships($conn, $active_only = true) {
    $sql = "SELECT * FROM scholarships";
    if ($active_only) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name";
    $result = db_query($conn, $sql);
    return $result ? db_fetch_all($result) : [];
}

/**
 * Ensure required merit scholarship definitions exist in scholarships table.
 *
 * @param mysqli $conn Database connection
 * @return array Map of scholarship code => scholarship_id
 */
function ensurePromotionMeritScholarships($conn) {
    $definitions = [
        [
            'code' => 'MERIT_75',
            'name' => 'Merit Scholarship 75%',
            'description' => 'Auto-awarded on promotion when all passed final grades in source term are 1.25 or better.',
            'discount' => 75.00
        ],
        [
            'code' => 'MERIT_25',
            'name' => 'Merit Scholarship 25%',
            'description' => 'Auto-awarded on promotion when best final grade is 1.75 or better and no passed final grade is 2.00 or higher.',
            'discount' => 25.00
        ],
        [
            'code' => 'MERIT_50',
            'name' => 'Merit Scholarship 50%',
            'description' => 'Auto-awarded on promotion when all passed final grades in source term are 1.50 or better.',
            'discount' => 50.00
        ]
    ];

    $ids = [];
    foreach ($definitions as $def) {
        $upsert_sql = "INSERT INTO scholarships
                       (code, name, description, discount_type, discount_value, applies_to, is_active)
                       VALUES (?, ?, ?, 'percentage', ?, 'tuition', 1)
                       ON DUPLICATE KEY UPDATE
                           name = VALUES(name),
                           description = VALUES(description),
                           discount_type = 'percentage',
                           discount_value = VALUES(discount_value),
                           applies_to = 'tuition',
                           is_active = 1,
                           updated_at = NOW()";
        if (db_query($conn, $upsert_sql, 'sssd', [$def['code'], $def['name'], $def['description'], $def['discount']]) === false) {
            return [];
        }

        $id_row = db_fetch_one(db_query($conn, "SELECT scholarship_id FROM scholarships WHERE code = ? LIMIT 1", 's', [$def['code']]));
        if (!$id_row) {
            return [];
        }

        $ids[$def['code']] = intval($id_row['scholarship_id']);
    }

    return $ids;
}

/**
 * Apply auto merit scholarship for target term based on best final grade in source term.
 *
 * Rules:
 * - min grade <= 1.25 AND max grade <= 1.25 => 75% tuition scholarship
 * - min grade <= 1.50 AND max grade <= 1.50 => 50% tuition scholarship
 * - min grade <= 1.75 AND max grade < 2.00 => 25% tuition scholarship
 *
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $source_ay Source academic year
 * @param int $source_sem Source semester
 * @param string $target_ay Target academic year
 * @param int $target_sem Target semester
 * @return array ['success'=>bool, 'applied'=>bool, 'scholarship_name'=>string, 'best_grade'=>float|null, 'highest_grade'=>float|null, 'reason'=>string]
 */
function applyPromotionMeritScholarship($conn, $student_id, $source_ay, $source_sem, $target_ay, $target_sem) {
    $student_id = intval($student_id);
    $source_sem = intval($source_sem);
    $target_sem = intval($target_sem);
    $source_ay = trim((string)$source_ay);
    $target_ay = trim((string)$target_ay);

    if ($student_id <= 0 || $source_ay === '' || $target_ay === '' || $source_sem <= 0 || $target_sem <= 0) {
        return ['success' => false, 'applied' => false, 'scholarship_name' => '', 'best_grade' => null, 'highest_grade' => null, 'reason' => 'Invalid merit scholarship parameters.'];
    }

    $ids = ensurePromotionMeritScholarships($conn);
    if (empty($ids['MERIT_25']) || empty($ids['MERIT_50']) || empty($ids['MERIT_75'])) {
        return ['success' => false, 'applied' => false, 'scholarship_name' => '', 'best_grade' => null, 'highest_grade' => null, 'reason' => 'Unable to initialize merit scholarship definitions.'];
    }

    $grade_sql = "SELECT MIN(e.final_grade) AS best_grade,
                         MAX(e.final_grade) AS highest_grade
                  FROM enrollments e
                  JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                  WHERE e.student_id = ?
                    AND e.academic_year = ?
                    AND c.semester = ?
                    AND e.final_grade IS NOT NULL
                    AND e.final_grade > 0
                    AND e.status = 'Passed'";
    $grade_row = db_fetch_one(db_query($conn, $grade_sql, 'isi', [$student_id, $source_ay, $source_sem]));
    $best_grade = isset($grade_row['best_grade']) ? floatval($grade_row['best_grade']) : 0;
    $highest_grade = isset($grade_row['highest_grade']) ? floatval($grade_row['highest_grade']) : 0;

    if ($best_grade <= 0) {
        return ['success' => true, 'applied' => false, 'scholarship_name' => '', 'best_grade' => null, 'highest_grade' => null, 'reason' => 'No finalized passed grades found in source term.'];
    }

    $chosen_code = '';
    $chosen_name = '';
    if ($best_grade <= 1.25 && $highest_grade <= 1.25) {
        $chosen_code = 'MERIT_75';
        $chosen_name = 'Merit Scholarship 75%';
    } elseif ($best_grade <= 1.50 && $highest_grade <= 1.50) {
        $chosen_code = 'MERIT_50';
        $chosen_name = 'Merit Scholarship 50%';
    } elseif ($best_grade <= 1.75 && $highest_grade < 2.00) {
        $chosen_code = 'MERIT_25';
        $chosen_name = 'Merit Scholarship 25%';
    }

    if ($chosen_code === '') {
        $reason = 'Lowest passed grade is ' . number_format($best_grade, 2) . ', which is above the 1.75 merit limit.';

        if ($best_grade <= 1.25 && $highest_grade > 1.25) {
            $reason = '75% not qualified: found passed grade ' . number_format($highest_grade, 2) . ' higher than 1.25.';
        } elseif ($best_grade <= 1.50 && $highest_grade > 1.50) {
            $reason = '50% not qualified: found passed grade ' . number_format($highest_grade, 2) . ' higher than 1.50.';
        } elseif ($best_grade <= 1.75 && $highest_grade >= 2.00) {
            $reason = '25% not qualified: found passed grade ' . number_format($highest_grade, 2) . ' at or above 2.00.';
        }

        return ['success' => true, 'applied' => false, 'scholarship_name' => '', 'best_grade' => $best_grade, 'highest_grade' => $highest_grade, 'reason' => $reason];
    }

    $revoke_sql = "UPDATE student_scholarships ss
                   JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
                   SET ss.status = 'Revoked', ss.updated_at = NOW()
                   WHERE ss.student_id = ?
                     AND ss.status = 'Active'
                                         AND (ss.academic_year <> ? OR ss.semester <> ?)
                     AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75')";
    if (db_query($conn, $revoke_sql, 'isi', [$student_id, $target_ay, $target_sem]) === false) {
        return ['success' => false, 'applied' => false, 'scholarship_name' => '', 'best_grade' => $best_grade, 'highest_grade' => $highest_grade, 'reason' => 'Unable to clear existing merit scholarships for target term.'];
    }

    $award_note = 'Auto-awarded from promotion ('
        . $source_ay . ' Sem ' . intval($source_sem)
        . ') based on passed grades: lowest '
        . number_format($best_grade, 2)
        . ', highest '
        . number_format($highest_grade, 2);
    $upsert_award_sql = "INSERT INTO student_scholarships
                         (student_id, scholarship_id, academic_year, semester, status, awarded_date, notes)
                         VALUES (?, ?, ?, ?, 'Active', CURDATE(), ?)
                         ON DUPLICATE KEY UPDATE
                            status = 'Active',
                            awarded_date = CURDATE(),
                            notes = VALUES(notes),
                            updated_at = NOW()";
    if (db_query($conn, $upsert_award_sql, 'iisis', [$student_id, $ids[$chosen_code], $target_ay, $target_sem, $award_note]) === false) {
        return ['success' => false, 'applied' => false, 'scholarship_name' => '', 'best_grade' => $best_grade, 'highest_grade' => $highest_grade, 'reason' => 'Unable to award merit scholarship for target term.'];
    }

    return ['success' => true, 'applied' => true, 'scholarship_name' => $chosen_name, 'best_grade' => $best_grade, 'highest_grade' => $highest_grade, 'reason' => ''];
}

/**
 * Resolve fallback merit discount percent for a term when no explicit merit award exists yet.
 * Mirrors promotion merit thresholds using previous-term passed grades.
 *
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $target_ay Target academic year
 * @param int $target_sem Target semester
 * @return float Discount percent (0, 25, 50, 75)
 */
function getFallbackPromotionMeritDiscountPercent($conn, $student_id, $target_ay, $target_sem) {
    $student_id = intval($student_id);
    $target_ay = trim((string)$target_ay);
    $target_sem = intval($target_sem);

    if ($student_id <= 0 || $target_ay === '' || ($target_sem !== 1 && $target_sem !== 2)) {
        return 0.0;
    }

    $existing_merit_sql = "SELECT 1
                           FROM student_scholarships ss
                           JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
                           WHERE ss.student_id = ?
                             AND ss.academic_year = ?
                             AND ss.semester = ?
                             AND ss.status = 'Active'
                             AND s.code IN ('MERIT_25', 'MERIT_50', 'MERIT_75')
                           LIMIT 1";
    $existing_merit = db_fetch_one(db_query($conn, $existing_merit_sql, 'isi', [$student_id, $target_ay, $target_sem]));
    if ($existing_merit) {
        return 0.0;
    }

    $source_ay = '';
    $source_sem = 0;

    if ($target_sem === 2) {
        $source_ay = $target_ay;
        $source_sem = 1;
    } else {
        if (preg_match('/^(\d{4})-(\d{4})$/', $target_ay, $matches)) {
            $start_year = intval($matches[1]) - 1;
            $end_year = intval($matches[2]) - 1;
            $source_ay = $start_year . '-' . $end_year;
        }
        $source_sem = 2;
    }

    if ($source_ay === '' || $source_sem <= 0) {
        return 0.0;
    }

    $grade_sql = "SELECT MIN(e.final_grade) AS best_grade,
                         MAX(e.final_grade) AS highest_grade
                  FROM enrollments e
                  JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                  WHERE e.student_id = ?
                    AND e.academic_year = ?
                    AND c.semester = ?
                    AND e.final_grade IS NOT NULL
                    AND e.final_grade > 0
                    AND e.status = 'Passed'";
    $grade_row = db_fetch_one(db_query($conn, $grade_sql, 'isi', [$student_id, $source_ay, $source_sem]));
    $best_grade = isset($grade_row['best_grade']) ? floatval($grade_row['best_grade']) : 0.0;
    $highest_grade = isset($grade_row['highest_grade']) ? floatval($grade_row['highest_grade']) : 0.0;

    if ($best_grade <= 0) {
        return 0.0;
    }

    if ($best_grade <= 1.25 && $highest_grade <= 1.25) {
        return 75.0;
    }
    if ($best_grade <= 1.50 && $highest_grade <= 1.50) {
        return 50.0;
    }
    if ($best_grade <= 1.75 && $highest_grade < 2.00) {
        return 25.0;
    }

    return 0.0;
}

/**
 * Get scholarships for a student in a specific term
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic year
 * @param int $sem Semester
 * @return array List of student scholarships with details
 */
function getStudentScholarships($conn, $student_id, $ay = null, $sem = null) {
    $sql = "SELECT ss.*, s.code, s.name, s.discount_type, s.discount_value, s.applies_to
            FROM student_scholarships ss
            JOIN scholarships s ON ss.scholarship_id = s.scholarship_id
            WHERE ss.student_id = ?";
    
    $types = 'i';
    $params = [$student_id];
    
    if ($ay !== null) {
        $sql .= " AND ss.academic_year = ?";
        $types .= 's';
        $params[] = $ay;
    }
    if ($sem !== null) {
        $sql .= " AND ss.semester = ?";
        $types .= 'i';
        $params[] = $sem;
    }
    
    $sql .= " AND ss.status = 'Active'";
    $sql .= " ORDER BY ss.academic_year DESC, ss.semester DESC";
    
    $result = db_query($conn, $sql, $types, $params);
    return $result ? db_fetch_all($result) : [];
}

/**
 * Calculate scholarship discount for a term
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic year
 * @param int $sem Semester
 * @param float $tuition_amount Tuition amount before discount
 * @param float $misc_amount Misc fees amount before discount
 * @return array ['total_discount' => float, 'discounts' => array]
 */
function calculateScholarshipDiscount($conn, $student_id, $ay, $sem, $tuition_amount, $misc_amount) {
    $scholarships = getStudentScholarships($conn, $student_id, $ay, $sem);
    
    $total_discount = 0;
    $discounts = [];
    
    foreach ($scholarships as $s) {
        $discount = 0;
        $base_amount = 0;
        
        // Determine base amount based on what the scholarship applies to
        switch ($s['applies_to']) {
            case 'tuition':
                $base_amount = $tuition_amount;
                break;
            case 'misc':
                $base_amount = $misc_amount;
                break;
            case 'all':
                $base_amount = $tuition_amount + $misc_amount;
                break;
        }
        
        // Calculate discount based on type
        if ($s['discount_type'] === 'percentage') {
            $discount = $base_amount * ($s['discount_value'] / 100);
        } else {
            $discount = min($s['discount_value'], $base_amount);
        }
        
        $discounts[] = [
            'name' => $s['name'],
            'code' => $s['code'],
            'type' => $s['discount_type'],
            'value' => $s['discount_value'],
            'applies_to' => $s['applies_to'],
            'discount_amount' => $discount,
            'notes' => trim((string)($s['notes'] ?? '')),
            'awarded_date' => $s['awarded_date'] ?? null
        ];
        
        $total_discount += $discount;
    }

    // Safety fallback: if no explicit scholarship row exists for the term,
    // derive merit discount from previous-term grades and expose it in UI output.
    if ($total_discount <= 0) {
        $fallback_percent = getFallbackPromotionMeritDiscountPercent($conn, $student_id, $ay, intval($sem));
        if ($fallback_percent > 0) {
            $fallback_discount = round($tuition_amount * ($fallback_percent / 100), 2);
            $discounts[] = [
                'name' => 'Merit Scholarship (Auto Applied)',
                'code' => 'MERIT_AUTO',
                'type' => 'percentage',
                'value' => $fallback_percent,
                'applies_to' => 'tuition',
                'discount_amount' => $fallback_discount,
                'notes' => 'Derived from previous-term passed grades; no explicit scholarship row for this term yet.',
                'awarded_date' => null
            ];
            $total_discount += $fallback_discount;
        }
    }
    
    return [
        'total_discount' => $total_discount,
        'discounts' => $discounts
    ];
}

/**
 * Award a scholarship to a student
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param int $scholarship_id Scholarship ID
 * @param string $ay Academic year
 * @param int $sem Semester
 * @param string $notes Optional notes
 * @return bool Success status
 */
function awardScholarship($conn, $student_id, $scholarship_id, $ay, $sem, $notes = '') {
    // Check if already awarded
    $exists = db_exists($conn, 'student_scholarships',
        'student_id = ? AND scholarship_id = ? AND academic_year = ? AND semester = ?',
        'iisi', [$student_id, $scholarship_id, $ay, $sem]);
    
    if ($exists) {
        return false; // Already awarded
    }
    
    $sql = "INSERT INTO student_scholarships 
            (student_id, scholarship_id, academic_year, semester, status, awarded_date, notes)
            VALUES (?, ?, ?, ?, 'Active', CURDATE(), ?)";
    
    return db_query($conn, $sql, 'iisis', [$student_id, $scholarship_id, $ay, $sem, $notes]) !== false;
}

/**
 * Revoke a scholarship from a student
 * @param mysqli $conn Database connection
 * @param int $student_scholarship_id The student_scholarship record ID
 * @return bool Success status
 */
function revokeScholarship($conn, $student_scholarship_id) {
    $sql = "UPDATE student_scholarships SET status = 'Revoked', updated_at = NOW() 
            WHERE student_scholarship_id = ?";
    return db_query($conn, $sql, 'i', [$student_scholarship_id]) !== false;
}

// ============================================================
// LATE FEE FUNCTIONS
// ============================================================

/**
 * Get late fee configuration
 * @param mysqli $conn Database connection
 * @return array Late fee config or defaults
 */
function getLateFeeConfig($conn) {
    $sql = "SELECT * FROM late_fee_config WHERE is_active = 1 LIMIT 1";
    $result = db_query($conn, $sql);
    $config = $result ? db_fetch_one($result) : null;
    
    if (!$config) {
        // Return defaults
        return [
            'fee_type' => 'percentage',
            'fee_value' => 5.00,
            'grace_period_days' => 30,
            'max_penalty_percent' => 25.00,
            'apply_per' => 'month'
        ];
    }
    
    return $config;
}

/**
 * Calculate late fees for a student's term
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic year
 * @param int $sem Semester
 * @param string $due_date The payment due date (Y-m-d format)
 * @return array ['late_fee' => float, 'days_overdue' => int, 'periods_overdue' => int]
 */
function calculateLateFee($conn, $student_id, $ay, $sem, $due_date = null) {
    $config = getLateFeeConfig($conn);
    
    // Get current balance
    $balance = getTermBalance($conn, $student_id, $ay, $sem);
    
    if ($balance <= 0) {
        return ['late_fee' => 0, 'days_overdue' => 0, 'periods_overdue' => 0, 'message' => 'No balance due'];
    }
    
    // Determine due date (if not provided, assume 30 days from enrollment start)
    if ($due_date === null) {
        // Get earliest enrollment date for this term
        $enroll_sql = "SELECT MIN(enrolled_at) as first_enrolled 
                       FROM enrollments e
                       JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                       WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ?";
        $enroll_row = db_fetch_one(db_query($conn, $enroll_sql, 'isi', [$student_id, $ay, $sem]));
        
        if ($enroll_row && $enroll_row['first_enrolled']) {
            $enrolled_date = new DateTime($enroll_row['first_enrolled']);
            $enrolled_date->modify('+' . $config['grace_period_days'] . ' days');
            $due_date = $enrolled_date->format('Y-m-d');
        } else {
            return ['late_fee' => 0, 'days_overdue' => 0, 'periods_overdue' => 0, 'message' => 'No enrollment found'];
        }
    }
    
    $today = new DateTime();
    $due = new DateTime($due_date);
    
    if ($today <= $due) {
        return ['late_fee' => 0, 'days_overdue' => 0, 'periods_overdue' => 0, 'message' => 'Not yet overdue'];
    }
    
    $days_overdue = $today->diff($due)->days;
    
    // Calculate periods overdue
    $periods_overdue = 1;
    if ($config['apply_per'] === 'month') {
        $periods_overdue = ceil($days_overdue / 30);
    } elseif ($config['apply_per'] === 'week') {
        $periods_overdue = ceil($days_overdue / 7);
    }
    
    // Calculate the fee
    $late_fee = 0;
    if ($config['fee_type'] === 'percentage') {
        $late_fee = $balance * ($config['fee_value'] / 100) * $periods_overdue;
    } else {
        $late_fee = $config['fee_value'] * $periods_overdue;
    }
    
    // Apply maximum cap
    $max_fee = $balance * ($config['max_penalty_percent'] / 100);
    $late_fee = min($late_fee, $max_fee);
    
    return [
        'late_fee' => round($late_fee, 2),
        'days_overdue' => $days_overdue,
        'periods_overdue' => $periods_overdue,
        'max_fee' => round($max_fee, 2),
        'balance' => $balance,
        'message' => 'Overdue by ' . $days_overdue . ' days'
    ];
}

/**
 * Apply late fee to student's account
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic year
 * @param int $sem Semester
 * @param float $amount Late fee amount
 * @param string $reason Reason for late fee
 * @return bool Success status
 */
function applyLateFee($conn, $student_id, $ay, $sem, $amount, $reason = 'Late payment penalty') {
    $sql = "INSERT INTO student_late_fees 
            (student_id, academic_year, semester, amount, applied_date, reason)
            VALUES (?, ?, ?, ?, CURDATE(), ?)";
    
    return db_query($conn, $sql, 'isids', [$student_id, $ay, $sem, $amount, $reason]) !== false;
}

/**
 * Get applied late fees for a student
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic year (optional)
 * @param int $sem Semester (optional)
 * @return array List of late fees
 */
function getStudentLateFees($conn, $student_id, $ay = null, $sem = null) {
    $sql = "SELECT * FROM student_late_fees WHERE student_id = ?";
    $types = 'i';
    $params = [$student_id];
    
    if ($ay !== null) {
        $sql .= " AND academic_year = ?";
        $types .= 's';
        $params[] = $ay;
    }
    if ($sem !== null) {
        $sql .= " AND semester = ?";
        $types .= 'i';
        $params[] = $sem;
    }
    
    $sql .= " ORDER BY applied_date DESC";
    $result = db_query($conn, $sql, $types, $params);
    return $result ? db_fetch_all($result) : [];
}

/**
 * Waive a late fee
 * @param mysqli $conn Database connection
 * @param int $late_fee_id Late fee record ID
 * @param string $waived_by Who waived it
 * @return bool Success status
 */
function waiveLateFee($conn, $late_fee_id, $waived_by = 'Admin') {
    $sql = "UPDATE student_late_fees 
            SET is_waived = 1, waived_by = ?, waived_date = CURDATE() 
            WHERE late_fee_id = ?";
    return db_query($conn, $sql, 'si', [$waived_by, $late_fee_id]) !== false;
}

/**
 * Get comprehensive financial summary for a student term
 * Includes: Assessment, Scholarships, Late Fees, Payments, Overpayment Credits, Balance
 * Now uses program-specific tuition rates.
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param string $ay Academic year
 * @param int $sem Semester
 * @return array Complete financial breakdown
 */
function getComprehensiveFinancialSummary($conn, $student_id, $ay, $sem) {
    // Get student's program for tuition rate
    $program_id = getStudentProgramId($conn, $student_id);
    $rates = getProgramTuitionRate($conn, $program_id);
    $tuition_rate = $rates['tuition_per_unit'];
    $program_lab_fee = $rates['lab_fee'];
    
    // Get fixed fees (excluding LAB since it's program-specific now)
    $total_fixed_fee = 0;
    $fee_breakdown = [];
    
    $res = db_query($conn, "SELECT * FROM fees WHERE type = 'fixed' AND code != 'LAB'");
    while($row = $res->fetch_assoc()) {
        $total_fixed_fee += floatval($row['amount']);
        $fee_breakdown[] = [
            'code' => $row['code'],
            'description' => $row['description'],
            'amount' => floatval($row['amount'])
        ];
    }
    
    // Add program-specific lab fee
    $total_fixed_fee += $program_lab_fee;
    $fee_breakdown[] = [
        'code' => 'LAB',
        'description' => 'Laboratory Fee (Program-specific)',
        'amount' => $program_lab_fee
    ];
    
    // Get units enrolled
    $u_sql = "SELECT SUM(c.units) as total_units 
              FROM enrollments e 
              JOIN curriculum c ON e.curriculum_id = c.curriculum_id 
              WHERE e.student_id = ? AND e.academic_year = ? AND c.semester = ? 
              AND e.status IN ('Enrolled', 'Passed', 'Failed')";
    $units_row = db_fetch_one(db_query($conn, $u_sql, 'isi', [$student_id, $ay, $sem]));
    $units = $units_row ? floatval($units_row['total_units']) : 0;
    
    $tuition_amount = $units * $tuition_rate;
    $misc_amount = $total_fixed_fee;
    $gross_assessment = $tuition_amount + $misc_amount;
    
    // Get scholarship discounts
    $scholarship_data = calculateScholarshipDiscount($conn, $student_id, $ay, $sem, $tuition_amount, $misc_amount);
    $total_discount = $scholarship_data['total_discount'];
    
    $net_assessment = $gross_assessment - $total_discount;
    
    // Get payments
    $pay_sql = "SELECT SUM(amount) as total_paid FROM payments WHERE student_id = ? AND academic_year = ? AND semester = ?";
    $pay_row = db_fetch_one(db_query($conn, $pay_sql, 'isi', [$student_id, $ay, $sem]));
    $total_paid = $pay_row ? floatval($pay_row['total_paid']) : 0;
    
    // Get applied overpayment credits
    $credit_sql = "SELECT SUM(amount) as applied_credit FROM term_overpayments 
                   WHERE student_id = ? AND applied_academic_year = ? AND applied_semester = ? AND is_applied = 1";
    $credit_row = db_fetch_one(db_query($conn, $credit_sql, 'isi', [$student_id, $ay, $sem]));
    $applied_credits = $credit_row && $credit_row['applied_credit'] ? floatval($credit_row['applied_credit']) : 0;
    
    // Get available overpayment credits (for display)
    $available_credits = getAvailableOverpaymentCredit($conn, $student_id);
    
    // Get late fees (not waived)
    $late_sql = "SELECT SUM(amount) as total_late FROM student_late_fees 
                 WHERE student_id = ? AND academic_year = ? AND semester = ? AND is_waived = 0";
    $late_row = db_fetch_one(db_query($conn, $late_sql, 'isi', [$student_id, $ay, $sem]));
    $total_late_fees = $late_row ? floatval($late_row['total_late']) : 0;
    
    // Calculate pending late fee (not yet applied)
    $pending_late = calculateLateFee($conn, $student_id, $ay, $sem);
    
    // Final balance (after payments and applied credits)
    $balance = $net_assessment + $total_late_fees - $total_paid - $applied_credits;
    
    return [
        'units' => $units,
        'tuition_rate' => $tuition_rate,
        'tuition_amount' => $tuition_amount,
        'misc_amount' => $misc_amount,
        'misc_breakdown' => $fee_breakdown,
        'gross_assessment' => $gross_assessment,
        'scholarships' => $scholarship_data['discounts'],
        'total_discount' => $total_discount,
        'net_assessment' => $net_assessment,
        'total_paid' => $total_paid,
        'applied_credits' => $applied_credits,
        'available_credits' => $available_credits,
        'applied_late_fees' => $total_late_fees,
        'pending_late_fee' => $pending_late['late_fee'],
        'balance' => $balance,
        'total_due' => max(0, $balance + $pending_late['late_fee']),
        'has_overpayment' => $balance < 0,
        'overpayment_amount' => $balance < 0 ? abs($balance) : 0,
        'status' => $balance <= 0 ? 'Paid' : ($pending_late['days_overdue'] > 0 ? 'Overdue' : 'Unpaid')
    ];
}
