<?php
require 'config/db_helpers.php';
$conn = getDBConnection();
$sid = 56;
$student = db_fetch_one(db_query($conn, "SELECT student_id, student_number, first_name, last_name, year_level, current_semester FROM students WHERE student_id=?", 'i', [$sid]));
$options = get_student_term_options($conn, $sid);
$current = null;
foreach ($options as $opt) {
    if (strpos($opt['label'], ' - Current') !== false) {
        $current = $opt;
        break;
    }
}
echo 'student=' . $student['student_number'] . ' ' . $student['last_name'] . ', ' . $student['first_name'] . "\n";
echo 'student_year_sem=' . $student['year_level'] . '-' . $student['current_semester'] . "\n";
if ($current) {
    echo 'computed_current_ay=' . $current['ay'] . "\n";
    echo 'computed_current_sem=' . $current['sem'] . "\n";
    echo 'computed_current_yl=' . $current['yl'] . "\n";
    echo 'label=' . $current['label'] . "\n";
} else {
    echo "computed_current=NONE\n";
}
?>
