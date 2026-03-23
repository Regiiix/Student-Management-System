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
    'label' => 'Student Services Focus',
    'text' => 'Keep enrollment, records, and finance coordinated so students complete requirements on time.',
    'icon' => 'bi-mortarboard',
];

$hero_actions = [
    [
        'label' => 'Student Records',
        'href' => $landing_routes['students'],
        'icon' => 'bi-people-fill',
        'primary' => true,
    ],
    [
        'label' => 'Enroll for Current Term',
        'href' => $landing_routes['enrollment'],
        'icon' => 'bi-calendar-check',
    ],
];

if ($current_semester === '1') {
    $landing_focus = [
        'label' => 'Enrollment Opening Focus',
        'text' => 'Prioritize admissions processing, section planning, and initial billing setup for incoming students.',
        'icon' => 'bi-calendar2-check',
    ];

    $hero_actions = [
        [
            'label' => 'Start Enrollment',
            'href' => $landing_routes['enrollment'],
            'icon' => 'bi-calendar-check',
            'primary' => true,
        ],
        [
            'label' => 'Finance Assessment',
            'href' => $landing_routes['finance'],
            'icon' => 'bi-wallet2',
        ],
    ];

} elseif ($current_semester === '2') {
    $landing_focus = [
        'label' => 'Retention and Completion Focus',
        'text' => 'Prioritize student progress checks, enrollment adjustments, and payment follow-up.',
        'icon' => 'bi-speedometer2',
    ];

    $hero_actions = [
        [
            'label' => 'Academic Analytics',
            'href' => $landing_routes['analytics'],
            'icon' => 'bi-graph-up-arrow',
            'primary' => true,
        ],
        [
            'label' => 'Enrollment Adjustments',
            'href' => $landing_routes['enrollment'],
            'icon' => 'bi-pencil-square',
        ],
    ];

}

$operation_lanes = [
    [
        'eyebrow' => 'Registrar Office',
        'title' => 'Student Records and Profiles',
        'description' => 'Manage personal details, year level, and official student records.',
        'href' => $landing_routes['students'],
        'cta' => 'Open Records',
    ],
    [
        'eyebrow' => 'Admissions and Enrollment',
        'title' => 'Enrollment and Scheduling',
        'description' => 'Process admissions, section loads, and term registration requests.',
        'href' => $landing_routes['enrollment'],
        'cta' => 'Open Enrollment',
    ],
    [
        'eyebrow' => 'Student Finance Office',
        'title' => 'Billing and Scholarship Verification',
        'description' => 'Monitor balances, review scholarships, and track payment completion.',
        'href' => $landing_routes['finance'],
        'cta' => 'Open Finance',
    ],
];

$conn->close();

$landing_cards = [
    [
        'title' => 'Programs and Curriculum',
        'description' => 'Review program offerings, course flow, and academic structure.',
        'href' => $landing_routes['academics'],
        'icon' => 'bi-journal-richtext',
        'accent' => 'academics',
    ],
    [
        'title' => 'Academic Analytics',
        'description' => 'Track enrollment trends, completion rates, and school performance.',
        'href' => $landing_routes['analytics'],
        'icon' => 'bi-graph-up-arrow',
        'accent' => 'analytics',
    ],
    [
        'title' => 'Scholarships and Grants',
        'description' => 'Manage scholarship awards, discounts, and recipient eligibility.',
        'href' => $landing_routes['scholarships'],
        'icon' => 'bi-award-fill',
        'accent' => 'scholarships',
    ],
];

$landing_announcements = [
    [
        'icon' => 'bi-megaphone-fill',
        'title' => 'Enrollment Window',
        'text' => 'Complete all enrollment steps before term cutoff to secure schedules.',
    ],
    [
        'icon' => 'bi-receipt-cutoff',
        'title' => 'Payment Reminder',
        'text' => 'Students with outstanding balances should coordinate with the Finance Office.',
    ],
    [
        'icon' => 'bi-patch-check-fill',
        'title' => 'Academic Verification',
        'text' => 'Review records and curriculum alignment before finalizing promotions.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Student Services Portal</title>
    <link rel="icon" href="<?php echo htmlspecialchars(app_asset('images/site-favicon.svg')); ?>" type="image/svg+xml">
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
                <span class="landing-brand-text">Student Services Portal</span>
            </a>

            <div class="landing-topbar-meta">
                <p class="landing-topbar-title">Official School Landing Page</p>
                <p class="landing-topbar-note">Student Management System</p>
            </div>
        </section>

        <section class="card landing-hero">
            <div class="landing-hero-layout">
                <div class="landing-hero-copy">
                    <p class="landing-kicker">School Administration and Student Services</p>
                    <h1>Welcome to the School Student Services Portal</h1>
                    <p class="landing-summary">Support enrollment, records, finance, and scholarship workflows in one school-focused platform designed for students, faculty, and staff.</p>
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

        <section class="card landing-lanes" aria-label="Daily operation lanes">
            <div class="landing-lanes-head">
                <h2>Core Student Services</h2>
                <p>Select a service area to continue official school transactions.</p>
            </div>
            <div class="landing-lanes-grid">
                <?php foreach ($operation_lanes as $lane): ?>
                    <article class="landing-lane-card">
                        <p class="landing-lane-eyebrow"><?php echo htmlspecialchars($lane['eyebrow']); ?></p>
                        <h3><?php echo htmlspecialchars($lane['title']); ?></h3>
                        <p><?php echo htmlspecialchars($lane['description']); ?></p>
                        <a class="btn" href="<?php echo htmlspecialchars($lane['href']); ?>">
                            <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($lane['cta']); ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card landing-announcements" aria-label="School announcements">
            <div class="landing-lanes-head">
                <h2>Important School Reminders</h2>
                <p>Keep these reminders visible while processing term activities.</p>
            </div>
            <div class="landing-announcements-grid">
                <?php foreach ($landing_announcements as $announcement): ?>
                    <article class="landing-announcement-item">
                        <span class="landing-announcement-icon" aria-hidden="true"><i class="bi <?php echo htmlspecialchars($announcement['icon']); ?>"></i></span>
                        <h3><?php echo htmlspecialchars($announcement['title']); ?></h3>
                        <p><?php echo htmlspecialchars($announcement['text']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <details class="card landing-collapsible" aria-label="Support modules">
            <summary class="landing-collapsible-summary">
                <span><i class="bi bi-grid-1x2" aria-hidden="true"></i> Administrative Tools</span>
                <span class="landing-collapsible-hint">Expand</span>
            </summary>
            <div class="landing-support">
                <div class="landing-grid">
                    <?php foreach ($landing_cards as $card): ?>
                        <a class="landing-card landing-card-<?php echo htmlspecialchars($card['accent']); ?>" href="<?php echo htmlspecialchars($card['href']); ?>">
                            <span class="landing-card-icon" aria-hidden="true"><i class="bi <?php echo htmlspecialchars($card['icon']); ?>"></i></span>
                            <span class="landing-card-title"><?php echo htmlspecialchars($card['title']); ?></span>
                            <span class="landing-card-cta">Open module <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>

    </main>
</body>
</html>
