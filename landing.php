<?php
require_once 'config/db_helpers.php';
require_once 'config/sidebar.php';

$landing_routes = getAppRouteMap();
$conn = getDBConnection();

$settings = getSystemSettings($conn, ['current_academic_year', 'current_semester']);

$current_ay = $settings['current_academic_year'] ?? (date('Y') . '-' . ((int)date('Y') + 1));
$current_semester = isset($settings['current_semester']) ? (string)$settings['current_semester'] : '';
$semester_label = $current_semester === '1'
    ? '1st Semester'
    : ($current_semester === '2' ? '2nd Semester' : 'Current Semester');

$landing_background_path = is_file(__DIR__ . '/images/background-optimized.jpg')
    ? 'images/background-optimized.jpg'
    : 'images/background.jpg';
$landing_background_image = app_asset($landing_background_path);
$script_name = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '/landing.php';
$app_base_path = rtrim(str_replace('\\', '/', dirname($script_name)), '/.');
if ($app_base_path === '/' || $app_base_path === '\\' || $app_base_path === '.') {
    $app_base_path = '';
}
$landing_background_image_url = ($app_base_path !== '' ? $app_base_path . '/' : '/') . ltrim($landing_background_image, '/');

$landing_focus = [
    'label' => 'Balanced Operations Focus',
    'text' => 'Keep records, enrollment, and finance aligned to avoid end-of-term bottlenecks.',
    'icon' => 'bi-bullseye',
];

$hero_actions = [
    [
        'label' => 'Open Student Records',
        'href' => $landing_routes['students'],
        'icon' => 'bi-people-fill',
        'primary' => true,
    ],
    [
        'label' => 'Continue Enrollment',
        'href' => $landing_routes['enrollment'],
        'icon' => 'bi-calendar-check',
    ],
];

$workflow_intro = 'Follow this path to keep enrollment and records updated each term.';

$workflow_steps = [
    [
        'number' => '01',
        'title' => 'Review Student Records',
        'text' => 'Check student profiles and status before processing term activity.',
        'href' => $landing_routes['students'],
    ],
    [
        'number' => '02',
        'title' => 'Process Enrollment',
        'text' => 'Assign classes and confirm enrolled students for the active term.',
        'href' => $landing_routes['enrollment'],
    ],
    [
        'number' => '03',
        'title' => 'Reconcile Finance',
        'text' => 'Track balances, payments, and scholarship impact in one view.',
        'href' => $landing_routes['finance'],
    ],
];

if ($current_semester === '1') {
    $landing_focus = [
        'label' => 'Enrollment Intake Focus',
        'text' => 'Prioritize roster cleanup, enrollment completion, and billing setup for the new term.',
        'icon' => 'bi-calendar2-check',
    ];

    $hero_actions = [
        [
            'label' => 'Continue Enrollment',
            'href' => $landing_routes['enrollment'],
            'icon' => 'bi-calendar-check',
            'primary' => true,
        ],
        [
            'label' => 'Open Finance Queue',
            'href' => $landing_routes['finance'],
            'icon' => 'bi-wallet2',
        ],
    ];

    $workflow_intro = 'Use this intake sequence to keep the first semester launch clean and fast.';

    $workflow_steps = [
        [
            'number' => '01',
            'title' => 'Validate Student Roster',
            'text' => 'Confirm active status, year level, and student profile completeness.',
            'href' => $landing_routes['students'],
        ],
        [
            'number' => '02',
            'title' => 'Finalize Enrollment',
            'text' => 'Process registrations and class assignments for the opening weeks.',
            'href' => $landing_routes['enrollment'],
        ],
        [
            'number' => '03',
            'title' => 'Confirm Billing and Aid',
            'text' => 'Review tuition balances and scholarship coverage after enrollment posting.',
            'href' => $landing_routes['finance'],
        ],
    ];
} elseif ($current_semester === '2') {
    $landing_focus = [
        'label' => 'Progress and Retention Focus',
        'text' => 'Prioritize monitoring, enrollment adjustments, and payment follow-up to keep students on track.',
        'icon' => 'bi-speedometer2',
    ];

    $hero_actions = [
        [
            'label' => 'View Analytics',
            'href' => $landing_routes['analytics'],
            'icon' => 'bi-graph-up-arrow',
            'primary' => true,
        ],
        [
            'label' => 'Continue Enrollment Adjustments',
            'href' => $landing_routes['enrollment'],
            'icon' => 'bi-pencil-square',
        ],
    ];

    $workflow_intro = 'Use this retention sequence to maintain momentum in the second semester.';

    $workflow_steps = [
        [
            'number' => '01',
            'title' => 'Track Performance Signals',
            'text' => 'Check analytics trends and identify students needing follow-up.',
            'href' => $landing_routes['analytics'],
        ],
        [
            'number' => '02',
            'title' => 'Process Adds and Drops',
            'text' => 'Update enrollment changes and class assignments without delay.',
            'href' => $landing_routes['enrollment'],
        ],
        [
            'number' => '03',
            'title' => 'Reconcile Balances and Aid',
            'text' => 'Follow up unpaid balances and confirm scholarship application.',
            'href' => $landing_routes['finance'],
        ],
    ];
}

$operation_lanes = [
    [
        'eyebrow' => 'Registrar Desk',
        'title' => 'Student Records Desk',
        'description' => 'For profile updates, active status checks, and student information cleanup.',
        'checks' => [
            'Update student status and profile changes',
            'Review year level and section assignments',
        ],
        'href' => $landing_routes['students'],
        'cta' => 'Start with Student Records',
    ],
    [
        'eyebrow' => 'Enrollment Desk',
        'title' => 'Term Enrollment Queue',
        'description' => 'For registration processing, class load updates, and term finalization.',
        'checks' => [
            'Process enrollments and section changes',
            'Validate subject load and schedule alignment',
        ],
        'href' => $landing_routes['enrollment'],
        'cta' => 'Open Enrollment',
    ],
    [
        'eyebrow' => 'Finance Desk',
        'title' => 'Collections and Scholarship Review',
        'description' => 'For payment follow-up, balance monitoring, and aid confirmation.',
        'checks' => [
            'Review outstanding balances and receipts',
            'Validate scholarship coverage and discount rules',
        ],
        'href' => $landing_routes['finance'],
        'cta' => 'Open Finance',
    ],
];

$landing_metric_counts = [
    'students' => 0,
    'programs' => 0,
    'courses' => 0,
    'enrollments' => 0,
];

$metrics_result = db_query(
    $conn,
    "SELECT
        (SELECT COUNT(*) FROM students) AS students_count,
        (SELECT COUNT(*) FROM programs) AS programs_count,
        (SELECT COUNT(*) FROM curriculum) AS courses_count,
        (SELECT COUNT(*) FROM enrollments WHERE academic_year = ?) AS enrollments_count",
    's',
    [$current_ay]
);

if ($metrics_result) {
    $metrics_row = db_fetch_one($metrics_result);
    if ($metrics_row) {
        $landing_metric_counts['students'] = (int)($metrics_row['students_count'] ?? 0);
        $landing_metric_counts['programs'] = (int)($metrics_row['programs_count'] ?? 0);
        $landing_metric_counts['courses'] = (int)($metrics_row['courses_count'] ?? 0);
        $landing_metric_counts['enrollments'] = (int)($metrics_row['enrollments_count'] ?? 0);
    }
}

$landing_metrics = [
    [
        'label' => 'Students',
        'value' => $landing_metric_counts['students'],
        'icon' => 'bi-people-fill',
    ],
    [
        'label' => 'Programs',
        'value' => $landing_metric_counts['programs'],
        'icon' => 'bi-journal-richtext',
    ],
    [
        'label' => 'Courses',
        'value' => $landing_metric_counts['courses'],
        'icon' => 'bi-book-half',
    ],
    [
        'label' => 'Enrollments (AY)',
        'value' => $landing_metric_counts['enrollments'],
        'icon' => 'bi-person-check-fill',
    ],
];

$conn->close();

$landing_cards = [
    [
        'title' => 'Academics',
        'description' => 'Review programs and curriculum flow for academic planning.',
        'href' => $landing_routes['academics'],
        'icon' => 'bi-journal-richtext',
        'accent' => 'academics',
    ],
    [
        'title' => 'Analytics',
        'description' => 'Track trends, outcomes, and key performance indicators.',
        'href' => $landing_routes['analytics'],
        'icon' => 'bi-graph-up-arrow',
        'accent' => 'analytics',
    ],
    [
        'title' => 'Scholarships',
        'description' => 'Manage grants, discount rules, and recipient coverage.',
        'href' => $landing_routes['scholarships'],
        'icon' => 'bi-award-fill',
        'accent' => 'scholarships',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management System</title>
    <link rel="icon" href="<?php echo htmlspecialchars(app_asset('favicon.ico')); ?>" type="image/x-icon">
    <link rel="preload" as="image" href="<?php echo htmlspecialchars($landing_background_image_url, ENT_QUOTES); ?>" fetchpriority="high">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/index.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js')); ?>" defer></script>
</head>
<body class="page-landing" style="--landing-bg-image: url('<?php echo htmlspecialchars($landing_background_image_url, ENT_QUOTES); ?>');">

    <main id="main-content" class="container landing-shell">
        <section class="card landing-topbar" aria-label="Landing header">
            <a href="landing.php" class="landing-brand" aria-label="Student Management home">
                <span class="landing-brand-mark" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
                <span class="landing-brand-text">Student Management</span>
            </a>

            <div class="landing-topbar-meta">
                <p class="landing-topbar-title">Unified Campus Workspace</p>
                <p class="landing-topbar-note">Start with Daily Start Lanes, then move to support modules only when needed.</p>
            </div>
        </section>

        <section class="card landing-hero">
            <div class="landing-hero-layout">
                <div class="landing-hero-copy">
                    <p class="landing-kicker">Campus Operations Hub</p>
                    <h1>Student Management System</h1>
                    <p class="landing-summary">Run daily registrar, enrollment, and finance work in one connected system built for term-based operations.</p>
                    <div class="landing-context" aria-label="Current term">
                        <span class="landing-pill"><i class="bi bi-calendar-event" aria-hidden="true"></i><?php echo htmlspecialchars($current_ay); ?></span>
                        <span class="landing-pill"><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo htmlspecialchars($semester_label); ?></span>
                        <span class="landing-pill landing-pill-focus"><i class="bi <?php echo htmlspecialchars($landing_focus['icon']); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($landing_focus['label']); ?></span>
                    </div>
                    <p class="landing-focus-copy"><?php echo htmlspecialchars($landing_focus['text']); ?></p>
                    <div class="landing-hero-actions">
                        <?php foreach ($hero_actions as $action): ?>
                            <a class="btn <?php echo !empty($action['primary']) ? 'btn-primary' : ''; ?>" href="<?php echo htmlspecialchars($action['href']); ?>">
                                <i class="bi <?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($action['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="landing-metrics-grid" aria-label="System snapshot">
            <?php foreach ($landing_metrics as $metric): ?>
                <article class="card landing-metric-card">
                    <div class="landing-metric-icon" aria-hidden="true"><i class="bi <?php echo htmlspecialchars($metric['icon']); ?>"></i></div>
                    <p class="landing-metric-value"><?php echo number_format((int)$metric['value']); ?></p>
                    <p class="landing-metric-label"><?php echo htmlspecialchars($metric['label']); ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="card landing-lanes" aria-label="Daily operation lanes">
            <div class="landing-lanes-head">
                <h2>Daily Start Lanes</h2>
                <p>Choose the lane that matches your desk and continue with focused actions.</p>
            </div>
            <div class="landing-lanes-grid">
                <?php foreach ($operation_lanes as $lane): ?>
                    <article class="landing-lane-card">
                        <p class="landing-lane-eyebrow"><?php echo htmlspecialchars($lane['eyebrow']); ?></p>
                        <h3><?php echo htmlspecialchars($lane['title']); ?></h3>
                        <p><?php echo htmlspecialchars($lane['description']); ?></p>
                        <ul class="landing-lane-checks" aria-label="Lane checklist">
                            <?php foreach ($lane['checks'] as $check): ?>
                                <li><i class="bi bi-check2-circle" aria-hidden="true"></i><?php echo htmlspecialchars($check); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a class="btn" href="<?php echo htmlspecialchars($lane['href']); ?>">
                            <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($lane['cta']); ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card landing-support" aria-label="Support modules">
            <div class="landing-support-head">
                <h2>Support Modules</h2>
                <p>Use these modules when you need academic planning, trend monitoring, or scholarship updates.</p>
            </div>
            <div class="landing-grid">
                <?php foreach ($landing_cards as $card): ?>
                    <a class="landing-card landing-card-<?php echo htmlspecialchars($card['accent']); ?>" href="<?php echo htmlspecialchars($card['href']); ?>">
                        <span class="landing-card-icon" aria-hidden="true"><i class="bi <?php echo htmlspecialchars($card['icon']); ?>"></i></span>
                        <span class="landing-card-title"><?php echo htmlspecialchars($card['title']); ?></span>
                        <span class="landing-card-text"><?php echo htmlspecialchars($card['description']); ?></span>
                        <span class="landing-card-cta">Open support module <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card landing-workflow" aria-label="Recommended flow">
            <div class="landing-workflow-head">
                <h2>Recommended Workflow</h2>
                <p><?php echo htmlspecialchars($workflow_intro); ?></p>
            </div>
            <div class="landing-workflow-grid">
                <?php foreach ($workflow_steps as $step): ?>
                    <a class="landing-step" href="<?php echo htmlspecialchars($step['href']); ?>">
                        <span class="landing-step-number"><?php echo htmlspecialchars($step['number']); ?></span>
                        <span class="landing-step-title"><?php echo htmlspecialchars($step['title']); ?></span>
                        <span class="landing-step-text"><?php echo htmlspecialchars($step['text']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card landing-meta-strip" aria-label="System notes">
            <p><strong>Tip:</strong> After entering the system, use the in-app sidebar to move between modules without losing workflow context.</p>
        </section>
    </main>
</body>
</html>
