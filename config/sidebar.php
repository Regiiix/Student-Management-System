<?php
if (!function_exists('normalizeAppBasePath')) {
    function normalizeAppBasePath(string $basePath): string
    {
        $basePath = trim($basePath);
        if ($basePath === '') {
            return '';
        }

        return rtrim($basePath, '/\\') . '/';
    }
}

if (!function_exists('getAppRouteMap')) {
    /**
     * @return array<string, string>
     */
    function getAppRouteMap(string $basePath = ''): array
    {
        $basePath = normalizeAppBasePath($basePath);

        return [
            'students' => $basePath . 'index.php?view=students',
            'academics' => $basePath . 'index.php?view=programs',
            'curriculum' => $basePath . 'index.php?view=curriculum',
            'enrollment' => $basePath . 'pages/enrollment.php',
            'analytics' => $basePath . 'pages/dashboard.php',
            'finance' => $basePath . 'pages/finance.php',
            'scholarships' => $basePath . 'pages/scholarships.php',
            'add_student' => $basePath . 'pages/add_student.php',
        ];
    }
}

if (!function_exists('getAppRoute')) {
    function getAppRoute(string $routeName, string $basePath = ''): string
    {
        $routes = getAppRouteMap($basePath);
        if (isset($routes[$routeName])) {
            return $routes[$routeName];
        }

        return $routes['students'];
    }
}

if (!function_exists('sanitizeInternalNavigationTarget')) {
    function sanitizeInternalNavigationTarget(string $target, string $fallback): string
    {
        $target = trim($target);
        if ($target === '') {
            return $fallback;
        }

        // Block CRLF/protocol/protocol-relative payloads.
        if (preg_match('/[\r\n]/', $target)) {
            return $fallback;
        }
        if (preg_match('#^(?:[a-z][a-z0-9+\-.]*:)?//#i', $target)) {
            return $fallback;
        }
        if (stripos($target, 'javascript:') === 0) {
            return $fallback;
        }

        return $target;
    }
}

if (!function_exists('getStudentListReturnUrl')) {
    function getStudentListReturnUrl(string $basePath = ''): string
    {
        $fallback = getAppRoute('students', $basePath);
        $returnParam = isset($_GET['return']) ? (string)$_GET['return'] : '';

        return sanitizeInternalNavigationTarget($returnParam, $fallback);
    }
}

if (!function_exists('appendReturnParam')) {
    function appendReturnParam(string $url, string $returnTarget): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . 'return=' . rawurlencode($returnTarget);
    }
}

if (!function_exists('renderPageBreadcrumbs')) {
    /**
     * @param array<int, array<string, string>> $items
     */
    function renderPageBreadcrumbs(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $lastIndex = array_key_last($items);
        ?>
        <nav class="page-breadcrumbs" aria-label="Breadcrumb">
            <ol class="breadcrumbs-list">
                <?php foreach ($items as $index => $item): ?>
                    <?php
                    $label = isset($item['label']) ? trim((string)$item['label']) : '';
                    if ($label === '') {
                        continue;
                    }

                    $href = isset($item['href']) ? trim((string)$item['href']) : '';
                    $isCurrent = ($index === $lastIndex) || $href === '';
                    ?>
                    <li class="breadcrumb-item<?php echo $isCurrent ? ' is-current' : ''; ?>">
                        <?php if ($isCurrent): ?>
                            <span aria-current="page"><?php echo htmlspecialchars($label); ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($label); ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php
    }
}

if (!function_exists('renderAppSidebar')) {
    /**
     * Render shared sidebar navigation.
     *
     * @param array<string, string> $options
     */
    function renderAppSidebar(array $options = []): void
    {
        $active = isset($options['active']) ? (string)$options['active'] : 'students';
        $basePath = isset($options['basePath']) ? (string)$options['basePath'] : '';
        $routes = getAppRouteMap($basePath);

        $links = [
            [
                'id' => 'students',
                'label' => 'Students',
                'icon' => 'bi-people',
                'href' => $routes['students'],
            ],
            [
                'id' => 'academics',
                'label' => 'Academics',
                'icon' => 'bi-journal-richtext',
                'href' => $routes['academics'],
            ],
            [
                'id' => 'enrollment',
                'label' => 'Enrollment',
                'icon' => 'bi-person-plus',
                'href' => $routes['enrollment'],
            ],
            [
                'id' => 'analytics',
                'label' => 'Analytics',
                'icon' => 'bi-graph-up-arrow',
                'href' => $routes['analytics'],
            ],
            [
                'id' => 'finance',
                'label' => 'Finance',
                'icon' => 'bi-wallet2',
                'href' => $routes['finance'],
            ],
            [
                'id' => 'scholarships',
                'label' => 'Scholarships',
                'icon' => 'bi-award',
                'href' => $routes['scholarships'],
            ],
        ];

        $activeLabels = [
            'students' => 'Student records and profiles',
            'academics' => 'Programs and curriculum',
            'enrollment' => 'Term enrollment workflow',
            'analytics' => 'Reports and trend analysis',
            'finance' => 'Balances and payments',
            'scholarships' => 'Grants and discount awards',
        ];

        $activeSummary = $activeLabels[$active] ?? 'Student operations dashboard';
        ?>
        <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-controls="appSidebar" aria-expanded="false">
            <i class="bi bi-list" aria-hidden="true"></i>
            <span>Menu</span>
        </button>

        <div class="sidebar-backdrop" data-sidebar-backdrop></div>

        <aside id="appSidebar" class="app-sidebar" aria-label="Main navigation">
            <div class="sidebar-head">
                <a href="<?php echo htmlspecialchars($routes['students']); ?>" class="sidebar-brand">
                    <span class="sidebar-brand-mark" aria-hidden="true">
                        <i class="bi bi-mortarboard-fill"></i>
                    </span>
                    <span class="sidebar-brand-text">
                        <strong>regiiix</strong>
                        <small>dev</small>
                    </span>
                </a>
                <button type="button" class="sidebar-close" data-sidebar-close aria-label="Close navigation">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>

            <nav class="sidebar-nav" aria-label="Primary links">
                <?php foreach ($links as $link): ?>
                    <?php
                    $isActive = $active === $link['id'];
                    $linkClass = $isActive ? 'sidebar-link is-active' : 'sidebar-link';
                    ?>
                    <a
                        class="<?php echo $linkClass; ?>"
                        href="<?php echo htmlspecialchars($link['href']); ?>"
                        <?php echo $isActive ? 'aria-current="page"' : ''; ?>
                    >
                        <span class="sidebar-link-icon" aria-hidden="true">
                            <i class="bi <?php echo htmlspecialchars($link['icon']); ?>"></i>
                        </span>
                        <span class="sidebar-link-label"><?php echo htmlspecialchars($link['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-quick-actions">
                <a href="<?php echo htmlspecialchars($routes['add_student']); ?>" class="sidebar-quick-link">
                    <i class="bi bi-person-fill-add" aria-hidden="true"></i>
                    <span>New Student</span>
                </a>
                <a href="<?php echo htmlspecialchars($routes['enrollment']); ?>" class="sidebar-quick-link">
                    <i class="bi bi-calendar-check" aria-hidden="true"></i>
                    <span>Open Enrollment</span>
                </a>
            </div>

            <div class="sidebar-meta">
                <span class="sidebar-meta-label">Current Focus</span>
                <strong><?php echo htmlspecialchars($activeSummary); ?></strong>
            </div>
        </aside>
        <?php
    }
}
