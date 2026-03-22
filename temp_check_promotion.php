<?php
require 'config/db_helpers.php';
require 'config/finance_helpers.php';
$conn = getDBConnection();
$student_id = 56;
$ay='2027-2028';
$sem=2;
$assessment = getTermAssessment($conn, $student_id, $ay, $sem);
$balance = getTermBalance($conn, $student_id, $ay, $sem);
$threshold = round($assessment * 0.20, 2);
echo "assessment=" . number_format($assessment, 2) . "\n";
echo "balance=" . number_format($balance, 2) . "\n";
echo "threshold=" . number_format($threshold, 2) . "\n";
echo "exceeds_threshold=" . (($balance > $threshold) ? 'YES' : 'NO') . "\n";
?>
