<?php
require 'config/db_helpers.php';
require 'config/finance_helpers.php';
$conn = getDBConnection();
$sid = 56;
$ay = '2027-2028';
$sem = 2;
$program_id = getStudentProgramId($conn, $sid);
$rates = getProgramTuitionRate($conn, $program_id);
$u = db_fetch_one(db_query($conn, "SELECT COALESCE(SUM(c.units),0) AS units FROM enrollments e JOIN curriculum c ON e.curriculum_id=c.curriculum_id WHERE e.student_id=? AND e.academic_year=? AND c.semester=? AND e.status IN ('Enrolled','Passed','Failed')", 'isi', [$sid, $ay, $sem]));
$units = floatval($u['units'] ?? 0);
$fixed = 0.0;
$res = db_query($conn, "SELECT amount FROM fees WHERE type='fixed' AND code!='LAB'");
while($row = $res->fetch_assoc()) { $fixed += floatval($row['amount']); }
$fixed += floatval($rates['lab_fee']);
$tuition = $units * floatval($rates['tuition_per_unit']);
$disc = calculateScholarshipDiscount($conn, $sid, $ay, $sem, $tuition, $fixed);
$assessment = getTermAssessment($conn, $sid, $ay, $sem);
$balance = getTermBalance($conn, $sid, $ay, $sem);
echo "units={$units}\n";
echo "tuition=" . number_format($tuition,2) . "\n";
echo "discount_total=" . number_format(floatval($disc['total_discount']),2) . "\n";
echo "discount_rows=" . count($disc['discounts']) . "\n";
foreach($disc['discounts'] as $d){ echo "- {$d['name']} ({$d['value']}%) => " . number_format($d['discount_amount'],2) . "\n"; }
echo "assessment=" . number_format($assessment,2) . "\n";
echo "balance=" . number_format($balance,2) . "\n";
?>
