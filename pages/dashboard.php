<?php
/**
 * Analytics Dashboard
 * Comprehensive reporting and analytics for the student management system
 */
require_once '../config/db_helpers.php';
require_once '../config/api_auth_helpers.php';

$conn = getDBConnection();

// Get system settings
$settings = getSystemSettings($conn);
$current_ay = $settings['current_academic_year'] ?? (date('Y') . '-' . (date('Y') + 1));
$api_access_token = api_auth_issue_token();

// Get available academic years
$ay_sql = "SELECT DISTINCT academic_year FROM enrollments ORDER BY academic_year DESC";
$academic_years = db_fetch_all(db_query($conn, $ay_sql));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Student Management System</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/common.css', '../')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="<?php echo htmlspecialchars(app_asset('js/app.js', '../')); ?>" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_asset('css/reports_bundle.css', '../')); ?>">
</head>
<body class="has-sidebar page-dashboard">
    <?php require_once '../config/sidebar.php'; ?>
    <?php renderAppSidebar(['active' => 'analytics', 'basePath' => '..']); ?>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <header class="dashboard-header">
        <div>
            <h1>Analytics Dashboard</h1>
            <p class="dashboard-subtitle">Comprehensive reporting and analytics</p>
            <p id="dashboardLastUpdated" class="dashboard-last-updated" aria-live="polite">Last updated: --</p>
            <p id="dashboardCacheAge" class="dashboard-last-updated" aria-live="polite">Cache age: --</p>
            <div class="quick-links">
                <a href="../index.php?view=students" class="quick-link"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Students</a>
                <a href="finance.php" class="quick-link"><i class="bi bi-wallet2" aria-hidden="true"></i>Finance</a>
                <a href="scholarships.php" class="quick-link"><i class="bi bi-award" aria-hidden="true"></i>Scholarships</a>
            </div>
        </div>
        <div class="header-controls" role="group" aria-label="Dashboard filters">
            <div class="filter-control">
                <label for="academicYear">Academic Year</label>
                <select id="academicYear">
                    <?php foreach ($academic_years as $ay): ?>
                        <option value="<?php echo $ay['academic_year']; ?>" <?php echo $ay['academic_year'] === $current_ay ? 'selected' : ''; ?>>
                            <?php echo $ay['academic_year']; ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($academic_years)): ?>
                        <option value="<?php echo $current_ay; ?>"><?php echo $current_ay; ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="filter-control">
                <label for="semester">Semester</label>
                <select id="semester">
                    <option value="">All Semesters</option>
                    <option value="1">1st Semester</option>
                    <option value="2">2nd Semester</option>
                </select>
            </div>
            <button id="refreshDashboardBtn" type="button" class="btn btn-refresh">Refresh</button>
            <button id="hardRefreshDashboardBtn" type="button" class="btn btn-refresh btn-refresh-secondary">Hard Refresh</button>
        </div>
    </header>

    <main id="main-content" class="dashboard-container">
        <div id="dashboardErrorNotice" class="dashboard-error-notice hidden" role="alert" aria-live="polite"></div>
        <div id="refreshIndicator" class="refresh-indicator hidden" role="status" aria-live="polite">Refreshing dashboard data...</div>
        <!-- Key Stats -->
        <div class="stats-row" id="statsRow">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></div>
                <h3>Total Students</h3>
                <div class="value" id="statStudents">-</div>
                <div class="subtext" id="statStudentsSub">Active students</div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon"><i class="bi bi-person-check-fill" aria-hidden="true"></i></div>
                <h3>Enrolled This Term</h3>
                <div class="value" id="statEnrolled">-</div>
                <div class="subtext">Currently enrolled</div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></div>
                <h3>Collection Rate</h3>
                <div class="value" id="statCollection">-</div>
                <div class="subtext" id="statCollectionSub">of assessed fees</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon"><i class="bi bi-trophy-fill" aria-hidden="true"></i></div>
                <h3>Dean's Listers</h3>
                <div class="value" id="statDeans">-</div>
                <div class="subtext">Academic excellence</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-icon"><i class="bi bi-exclamation-diamond-fill" aria-hidden="true"></i></div>
                <h3>At Risk Students</h3>
                <div class="value" id="statRisk">-</div>
                <div class="subtext">Probation/Warning</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-icon"><i class="bi bi-award-fill" aria-hidden="true"></i></div>
                <h3>Active Scholarships</h3>
                <div class="value" id="statScholarships">-</div>
                <div class="subtext">This term</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="dashboard-tabs">
            <button type="button" class="dashboard-tab active" data-tab="overview">Overview</button>
            <button type="button" class="dashboard-tab" data-tab="enrollment">Enrollment</button>
            <button type="button" class="dashboard-tab" data-tab="grades">Grade Distribution</button>
            <button type="button" class="dashboard-tab" data-tab="retention">Retention</button>
            <button type="button" class="dashboard-tab" data-tab="revenue">Revenue</button>
        </div>

        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-content active">
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Students by Program</h3>
                    <div class="chart-container">
                        <canvas id="chartPrograms"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Students by Year Level</h3>
                    <div class="chart-container">
                        <canvas id="chartYearLevels"></canvas>
                    </div>
                </div>
                <div class="chart-card full-width">
                    <h3>Financial Overview <span class="badge" id="financialTerm">-</span></h3>
                    <div class="chart-container small">
                        <canvas id="chartFinancial"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Enrollment -->
        <div id="tab-enrollment" class="tab-content">
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Enrollment by Program</h3>
                    <div class="chart-container">
                        <canvas id="chartEnrollProgram"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Enrollment Trend</h3>
                    <div class="chart-container">
                        <canvas id="chartEnrollTrend"></canvas>
                    </div>
                </div>
                <div class="chart-card full-width">
                    <h3>Most Popular Courses</h3>
                    <div class="table-scroll">
                        <table class="data-table" id="tablePopularCourses">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Enrollments</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Grade Distribution -->
        <div id="tab-grades" class="tab-content">
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Overall Grade Distribution</h3>
                    <div class="chart-container">
                        <canvas id="chartGrades"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Pass/Fail Rate by Program</h3>
                    <div class="chart-container">
                        <canvas id="chartPassFail"></canvas>
                    </div>
                </div>
                <div class="chart-card full-width">
                    <h3>Top Performing Courses (Lowest Average Grade = Better)</h3>
                    <div class="table-scroll">
                        <table class="data-table" id="tableTopCourses">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Average Grade</th>
                                    <th>Students</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Retention -->
        <div id="tab-retention" class="tab-content">
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Student Status Distribution</h3>
                    <div class="chart-container">
                        <canvas id="chartStatus"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Retention Rate by Cohort</h3>
                    <div class="chart-container">
                        <canvas id="chartRetention"></canvas>
                    </div>
                </div>
                <div class="chart-card full-width">
                    <h3>Students at Academic Risk</h3>
                    <div id="riskStudentsContainer">
                        <table class="data-table" id="tableRiskStudents">
                            <thead>
                                <tr>
                                    <th>Standing</th>
                                    <th>Count</th>
                                    <th>Action Needed</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Revenue -->
        <div id="tab-revenue" class="tab-content">
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Revenue Summary</h3>
                    <div id="revenueSummary" class="revenue-summary-panel">
                        <!-- Populated by JS -->
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Collection Trend</h3>
                    <div class="chart-container">
                        <canvas id="chartRevenueTrend"></canvas>
                    </div>
                </div>
                <div class="chart-card full-width">
                    <h3>Scholarship Distribution</h3>
                    <div class="table-scroll">
                        <table class="data-table" id="tableScholarships">
                            <thead>
                                <tr>
                                    <th>Scholarship</th>
                                    <th>Recipients</th>
                                    <th>Discount</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const STUDENT_SYSTEM_API_TOKEN = <?php echo json_encode($api_access_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // Chart instances
        let charts = {};
        let isRefreshing = false;
        let firstLoadPending = true;
        const DEFAULT_TAB = 'overview';
        const CACHE_TTL_MS = 5 * 60 * 1000;
        const PREFETCH_DELAY_MS = 220;
        const reportCacheByFilter = {};
        const inFlightReportLoads = new Map();
        let activeFilterKey = '';
        let resizeRerenderTimer = null;
        let prefetchTimer = null;
        let dashboardCacheAgeTimer = null;
        let lastDashboardFetchedAt = 0;
        
        // Modern color palette - softer, more accessible colors
        const colors = {
            primary: ['#6366f1', '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'],
            light: ['rgba(99,102,241,0.15)', 'rgba(16,185,129,0.15)', 'rgba(59,130,246,0.15)'],
            gradient: function(ctx, color1, color2) {
                const gradient = ctx.createLinearGradient(0, 0, 0, 280);
                gradient.addColorStop(0, color1);
                gradient.addColorStop(1, color2);
                return gradient;
            }
        };
        
        // Chart.js global defaults for consistent styling
        Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#64748b';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.padding = 16;
        
        function getAvailableTabs() {
            return Array.from(document.querySelectorAll('.dashboard-tab[data-tab]'))
                .map((tab) => tab.getAttribute('data-tab'))
                .filter(Boolean);
        }

        function resolveTabFromUrl() {
            const tabs = getAvailableTabs();
            const tabParam = new URL(window.location.href).searchParams.get('tab');
            if (tabParam && tabs.includes(tabParam)) {
                return tabParam;
            }
            return tabs.includes(DEFAULT_TAB) ? DEFAULT_TAB : (tabs[0] || DEFAULT_TAB);
        }

        function syncTabToUrl(tabName) {
            const url = new URL(window.location.href);
            if (tabName === DEFAULT_TAB) {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tabName);
            }
            window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        }

        function getCurrentFilters() {
            const ay = document.getElementById('academicYear').value;
            const sem = document.getElementById('semester').value;
            return {
                ay,
                sem,
                key: `${ay}::${sem || 'all'}`
            };
        }

        function getCacheBucket(filterKey) {
            if (!reportCacheByFilter[filterKey]) {
                reportCacheByFilter[filterKey] = {};
            }
            return reportCacheByFilter[filterKey];
        }

        function getCacheEntry(cacheBucket, reportType) {
            const entry = cacheBucket[reportType];
            if (!entry) {
                return null;
            }

            // Support legacy plain-object cache values while shifting to typed entries.
            if (Object.prototype.hasOwnProperty.call(entry, 'data') && Object.prototype.hasOwnProperty.call(entry, 'fetchedAt')) {
                return entry;
            }

            return {
                data: entry,
                fetchedAt: 0
            };
        }

        function isCacheEntryFresh(entry) {
            if (!entry || typeof entry !== 'object') {
                return false;
            }

            const fetchedAt = Number(entry.fetchedAt || 0);
            if (!fetchedAt) {
                return false;
            }

            return (Date.now() - fetchedAt) <= CACHE_TTL_MS;
        }

        function getCachedReportData(cacheBucket, reportType, allowStale = false) {
            const entry = getCacheEntry(cacheBucket, reportType);
            if (!entry) {
                return null;
            }

            if (allowStale || isCacheEntryFresh(entry)) {
                return entry.data;
            }

            return null;
        }

        function setCachedReport(cacheBucket, reportType, data) {
            cacheBucket[reportType] = {
                data,
                fetchedAt: Date.now()
            };
        }

        function pruneExpiredCache(cacheBucket) {
            Object.keys(cacheBucket).forEach((reportType) => {
                const entry = getCacheEntry(cacheBucket, reportType);
                if (!isCacheEntryFresh(entry)) {
                    delete cacheBucket[reportType];
                }
            });
        }

        function getLatestFetchedAt(cacheBucket, reportTypes) {
            return reportTypes.reduce((latest, reportType) => {
                const entry = getCacheEntry(cacheBucket, reportType);
                const fetchedAt = Number(entry && entry.fetchedAt ? entry.fetchedAt : 0);
                return fetchedAt > latest ? fetchedAt : latest;
            }, 0);
        }

        function hasCachedReport(cacheBucket, reportType) {
            const entry = getCacheEntry(cacheBucket, reportType);
            return isCacheEntryFresh(entry);
        }

        function getActiveTabName() {
            const activeTab = document.querySelector('.dashboard-tab.active[data-tab]');
            if (activeTab) {
                return activeTab.getAttribute('data-tab') || DEFAULT_TAB;
            }
            return resolveTabFromUrl();
        }

        function renderFromCache(cacheBucket, tabName, allowStale = false) {
            const overviewData = getCachedReportData(cacheBucket, 'overview', allowStale);
            if (overviewData) {
                renderOverview(overviewData);
            }

            const tabData = tabName !== 'overview'
                ? getCachedReportData(cacheBucket, tabName, allowStale)
                : null;

            if (tabData) {
                if (tabName === 'enrollment') {
                    renderEnrollment(tabData);
                } else if (tabName === 'grades') {
                    renderGrades(tabData);
                } else if (tabName === 'retention') {
                    renderRetention(tabData);
                } else if (tabName === 'revenue') {
                    renderRevenue(tabData);
                }
            }
        }

        function getAdjacentTabs(tabName) {
            const tabs = getAvailableTabs();
            const index = tabs.indexOf(tabName);
            if (index === -1) {
                return [];
            }

            const neighbors = [];
            if (index > 0) {
                neighbors.push(tabs[index - 1]);
            }
            if (index < tabs.length - 1) {
                neighbors.push(tabs[index + 1]);
            }

            return neighbors;
        }

        function scheduleAdjacentPrefetch(filterKey, ay, sem, tabName) {
            const neighbors = getAdjacentTabs(tabName).filter((item) => item !== 'overview');
            if (neighbors.length === 0) {
                return;
            }

            if (prefetchTimer) {
                window.clearTimeout(prefetchTimer);
            }

            prefetchTimer = window.setTimeout(() => {
                if (activeFilterKey !== filterKey) {
                    return;
                }

                const cacheBucket = getCacheBucket(filterKey);
                const prefetchTargets = neighbors.filter((reportType) => !hasCachedReport(cacheBucket, reportType));
                if (prefetchTargets.length === 0) {
                    return;
                }

                prefetchTargets.forEach((reportType) => {
                    loadReportIntoCache(reportType, ay, sem, filterKey, cacheBucket)
                        .catch((error) => {
                            console.debug('Prefetch skipped:', error && error.message ? error.message : error);
                        });
                });
            }, PREFETCH_DELAY_MS);
        }

        function rerenderForViewport() {
            if (!activeFilterKey) {
                return;
            }

            const cacheBucket = reportCacheByFilter[activeFilterKey];
            if (!cacheBucket) {
                return;
            }

            const activeTab = getActiveTabName();
            renderFromCache(cacheBucket, activeTab, true);
        }

        async function loadReportIntoCache(reportType, ay, sem, filterKey, cacheBucket, options = {}) {
            const bypassCache = options.bypassCache === true;
            const forceRefresh = options.forceRefresh === true;
            if (!bypassCache) {
                const cachedData = getCachedReportData(cacheBucket, reportType);
                if (cachedData) {
                    return cachedData;
                }
            }

            const requestKey = `${filterKey}::${reportType}`;
            if (inFlightReportLoads.has(requestKey)) {
                return inFlightReportLoads.get(requestKey);
            }

            const requestPromise = fetchReport(reportType, ay, sem, forceRefresh)
                .then((resolvedData) => {
                    setCachedReport(cacheBucket, reportType, resolvedData);
                    return resolvedData;
                })
                .finally(() => {
                    inFlightReportLoads.delete(requestKey);
                });

            inFlightReportLoads.set(requestKey, requestPromise);
            return requestPromise;
        }

        function setRefreshState(isBusy, options = {}) {
            const showOverlay = options.showOverlay === true;
            const mode = options.mode || 'refresh';
            const container = document.querySelector('.dashboard-container');
            if (container) {
                container.classList.toggle('is-refreshing', isBusy);
                container.setAttribute('aria-busy', isBusy ? 'true' : 'false');
            }

            const refreshIndicator = document.getElementById('refreshIndicator');
            if (refreshIndicator) {
                refreshIndicator.classList.toggle('hidden', !isBusy || showOverlay);
            }

            const refreshButton = document.getElementById('refreshDashboardBtn');
            if (refreshButton) {
                refreshButton.disabled = isBusy;
                refreshButton.classList.toggle('is-loading', isBusy);
                refreshButton.textContent = isBusy && mode !== 'hard' ? 'Refreshing...' : 'Refresh';
            }

            const hardRefreshButton = document.getElementById('hardRefreshDashboardBtn');
            if (hardRefreshButton) {
                hardRefreshButton.disabled = isBusy;
                hardRefreshButton.classList.toggle('is-loading', isBusy && mode === 'hard');
                hardRefreshButton.textContent = isBusy && mode === 'hard' ? 'Hard Refreshing...' : 'Hard Refresh';
            }

            showLoading(showOverlay && isBusy);
        }

        function formatCacheAge(ageMs) {
            if (ageMs < 10000) {
                return 'just now';
            }

            if (ageMs < 60000) {
                return `${Math.floor(ageMs / 1000)}s old`;
            }

            return `${Math.floor(ageMs / 60000)}m old`;
        }

        function updateCacheAgeLabel() {
            const label = document.getElementById('dashboardCacheAge');
            if (!label) {
                return;
            }

            if (!lastDashboardFetchedAt) {
                label.textContent = 'Cache age: --';
                return;
            }

            const ageMs = Math.max(0, Date.now() - lastDashboardFetchedAt);
            let ageLabel = formatCacheAge(ageMs);
            if (ageMs > CACHE_TTL_MS) {
                ageLabel += ' (stale)';
            }
            label.textContent = 'Cache age: ' + ageLabel;
        }

        function startDashboardCacheAgeTicker() {
            if (dashboardCacheAgeTimer) {
                window.clearInterval(dashboardCacheAgeTimer);
            }

            dashboardCacheAgeTimer = window.setInterval(() => {
                updateCacheAgeLabel();
            }, 15000);
        }

        function updateLastUpdatedTimestamp(timestampMs = Date.now()) {
            const label = document.getElementById('dashboardLastUpdated');
            if (!label) {
                return;
            }

            lastDashboardFetchedAt = timestampMs;
            const now = new Date(timestampMs);
            label.textContent = 'Last updated: ' + now.toLocaleString('en-PH', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
            updateCacheAgeLabel();
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            const refreshButton = document.getElementById('refreshDashboardBtn');
            if (refreshButton) {
                refreshButton.addEventListener('click', () => refreshData({ showOverlay: false, forceRefresh: false }));
            }

            const hardRefreshButton = document.getElementById('hardRefreshDashboardBtn');
            if (hardRefreshButton) {
                hardRefreshButton.addEventListener('click', () => refreshData({ showOverlay: false, forceRefresh: true }));
            }

            const tabsContainer = document.querySelector('.dashboard-tabs');
            if (tabsContainer) {
                tabsContainer.addEventListener('click', (event) => {
                    const trigger = event.target.closest('.dashboard-tab[data-tab]');
                    if (!trigger) {
                        return;
                    }

                    showTab(trigger.getAttribute('data-tab'));
                });
            }

            const academicYearSelect = document.getElementById('academicYear');
            const semesterSelect = document.getElementById('semester');
            if (academicYearSelect) {
                academicYearSelect.addEventListener('change', () => refreshData({ showOverlay: false, forceRefresh: true }));
            }
            if (semesterSelect) {
                semesterSelect.addEventListener('change', () => refreshData({ showOverlay: false, forceRefresh: true }));
            }

            startDashboardCacheAgeTicker();
            updateCacheAgeLabel();

            showTab(resolveTabFromUrl(), false, false);
            refreshData({ showOverlay: true, forceRefresh: true });
        });

        window.addEventListener('popstate', () => {
            showTab(resolveTabFromUrl(), false);
        });

        window.addEventListener('resize', () => {
            if (resizeRerenderTimer) {
                window.clearTimeout(resizeRerenderTimer);
            }

            resizeRerenderTimer = window.setTimeout(() => {
                rerenderForViewport();
            }, 180);
        });

        function clearDashboardErrorNotice() {
            clearApiErrorNotice('dashboardErrorNotice');
        }

        function showDashboardErrorNotice(message) {
            showApiErrorNotice(
                'dashboardErrorNotice',
                message,
                () => refreshData({ showOverlay: false, forceRefresh: true }),
                { fallbackMessage: 'Unable to load analytics data right now. Please try again.' }
            );
        }
        
        // Tab switching
        function showTab(tabName, syncUrl = true, loadData = true) {
            const tabs = getAvailableTabs();
            if (tabs.length === 0) {
                return;
            }

            const safeTabName = tabs.includes(tabName) ? tabName : (tabs.includes(DEFAULT_TAB) ? DEFAULT_TAB : tabs[0]);
            const targetPanelId = 'tab-' + safeTabName;

            document.querySelectorAll('.tab-content').forEach((panel) => {
                const isActive = panel.id === targetPanelId;
                panel.classList.toggle('active', isActive);
                panel.hidden = !isActive;
                panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });

            document.querySelectorAll('.dashboard-tab').forEach((tab) => {
                const isActive = tab.getAttribute('data-tab') === safeTabName;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                tab.setAttribute('tabindex', isActive ? '0' : '-1');
            });

            if (syncUrl) {
                syncTabToUrl(safeTabName);
            }

            if (loadData) {
                refreshData({ showOverlay: false, forceRefresh: false });
            }
        }
        
        // Refresh all data
        async function refreshData(options = {}) {
            if (isRefreshing) {
                return;
            }

            const { ay, sem, key: filterKey } = getCurrentFilters();
            const activeTab = getActiveTabName();
            const forceRefresh = options.forceRefresh === true;
            const showOverlay = options.showOverlay === true && firstLoadPending;
            const filterChanged = filterKey !== activeFilterKey;

            if (filterChanged) {
                activeFilterKey = filterKey;
            }

            let cacheBucket = getCacheBucket(filterKey);
            if (forceRefresh) {
                reportCacheByFilter[filterKey] = {};
                cacheBucket = getCacheBucket(filterKey);
            } else {
                pruneExpiredCache(cacheBucket);
            }

            const requiredReports = new Set(['overview', activeTab]);
            const reportsToFetch = Array.from(requiredReports).filter((reportType) => {
                if (forceRefresh) {
                    return true;
                }
                return !hasCachedReport(cacheBucket, reportType);
            });

            if (reportsToFetch.length === 0) {
                renderFromCache(cacheBucket, activeTab);
                updateLastUpdatedTimestamp(getLatestFetchedAt(cacheBucket, Array.from(requiredReports)) || Date.now());
                scheduleAdjacentPrefetch(filterKey, ay, sem, activeTab);
                return;
            }

            isRefreshing = true;
            let refreshSucceeded = false;
            
            setRefreshState(true, { showOverlay, mode: forceRefresh ? 'hard' : 'refresh' });
            clearDashboardErrorNotice();
            
            try {
                await Promise.all(
                    reportsToFetch.map((reportType) =>
                        loadReportIntoCache(reportType, ay, sem, filterKey, cacheBucket, {
                            bypassCache: forceRefresh,
                            forceRefresh,
                        })
                    )
                );
                renderFromCache(cacheBucket, activeTab);
                updateLastUpdatedTimestamp(getLatestFetchedAt(cacheBucket, Array.from(requiredReports)) || Date.now());
                scheduleAdjacentPrefetch(filterKey, ay, sem, activeTab);
                refreshSucceeded = true;
                
            } catch (e) {
                console.error('Error loading data:', e);
                showDashboardErrorNotice(e && e.message ? e.message : 'Unable to load analytics data right now. Please try again.');
            } finally {
                setRefreshState(false, { showOverlay: false });
                isRefreshing = false;
                firstLoadPending = false;

                const latestFilter = activeFilterKey || filterKey;
                const latestTab = getActiveTabName();
                const latestBucket = getCacheBucket(latestFilter);

                if (refreshSucceeded && !hasCachedReport(latestBucket, latestTab)) {
                    refreshData({ showOverlay: false, forceRefresh: false });
                }
            }
        }
        
        async function fetchReport(type, ay, sem, forceRefresh = false) {
            const url = `../api/analytics.php?type=${type}&ay=${encodeURIComponent(ay)}${sem ? '&sem=' + sem : ''}${forceRefresh ? '&refresh=1' : ''}`;
            const requestHeaders = {
                'Accept': 'application/json'
            };
            if (STUDENT_SYSTEM_API_TOKEN) {
                requestHeaders['X-Api-Token'] = STUDENT_SYSTEM_API_TOKEN;
            }

            const response = await fetch(url, {
                headers: requestHeaders
            });
            const payload = await response.json();
            const fallbackError = 'Failed to load analytics report.';

            if (!response.ok || !payload || payload.success !== true) {
                const errMsg = payload && payload.error
                    ? (typeof payload.error === 'string' ? payload.error : (payload.error.message || fallbackError))
                    : fallbackError;
                throw new Error(errMsg);
            }

            return payload.data || {};
        }
        
        function showLoading(show) {
            document.getElementById('loadingOverlay').classList.toggle('hidden', !show);
        }
        
        // Render Overview
        function renderOverview(data) {
            // Stats cards
            document.getElementById('statStudents').textContent = data.totals?.students || 0;
            document.getElementById('statEnrolled').textContent = data.quick_stats?.enrolled_this_term || 0;
            document.getElementById('statCollection').textContent = (data.financials?.collection_rate || 0) + '%';
            document.getElementById('statCollectionSub').textContent = '₱' + numberFormat(data.financials?.collected || 0) + ' collected';
            document.getElementById('statDeans').textContent = data.quick_stats?.deans_list || 0;
            document.getElementById('statRisk').textContent = data.quick_stats?.at_risk || 0;
            document.getElementById('statScholarships').textContent = data.totals?.scholarships_active || 0;
            document.getElementById('financialTerm').textContent = data.financials?.term || '-';
            
            // Programs chart
            renderChart('chartPrograms', 'doughnut', {
                labels: data.programs?.map(p => p.program_code) || [],
                datasets: [{
                    data: data.programs?.map(p => p.count) || [],
                    backgroundColor: colors.primary
                }]
            });
            
            // Year levels chart
            renderChart('chartYearLevels', 'bar', {
                labels: data.year_levels?.map(y => 'Year ' + y.year_level) || [],
                datasets: [{
                    label: 'Students',
                    data: data.year_levels?.map(y => y.count) || [],
                    backgroundColor: colors.primary[0]
                }]
            }, { indexAxis: 'y' });
            
            // Financial chart
            renderChart('chartFinancial', 'bar', {
                labels: ['Assessed', 'Collected', 'Balance'],
                datasets: [{
                    label: 'Amount (₱)',
                    data: [data.financials?.assessed || 0, data.financials?.collected || 0, data.financials?.balance || 0],
                    backgroundColor: [colors.primary[0], colors.primary[1], colors.primary[4]]
                }]
            });
        }
        
        // Render Enrollment
        function renderEnrollment(data) {
            // By program
            renderChart('chartEnrollProgram', 'pie', {
                labels: data.by_program?.map(p => p.program_code) || [],
                datasets: [{
                    data: data.by_program?.map(p => p.enrolled) || [],
                    backgroundColor: colors.primary
                }]
            });
            
            // Trend
            renderChart('chartEnrollTrend', 'line', {
                labels: data.trend?.map(t => t.academic_year) || [],
                datasets: [{
                    label: 'Total Enrolled',
                    data: data.trend?.map(t => t.total_enrolled) || [],
                    borderColor: colors.primary[0],
                    backgroundColor: colors.light[0],
                    fill: true,
                    tension: 0.3
                }]
            });
            
            // Popular courses table
            const tbody = document.querySelector('#tablePopularCourses tbody');
            tbody.innerHTML = (data.course_popularity || []).map(c => `
                <tr>
                    <td><strong>${c.course_code}</strong></td>
                    <td>${c.course_name}</td>
                    <td>${c.enrollments}</td>
                </tr>
            `).join('') || '<tr><td colspan="3" class="table-empty">No data</td></tr>';
        }
        
        // Render Grades
        function renderGrades(data) {
            // Distribution - Using semantic colors for grades
            const gradeColors = ['#10b981', '#34d399', '#3b82f6', '#f59e0b', '#f97316', '#ef4444'];
            renderChart('chartGrades', 'doughnut', {
                labels: data.distribution?.map(d => d.label) || [],
                datasets: [{
                    data: data.distribution?.map(d => d.count) || [],
                    backgroundColor: gradeColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            });
            
            // Pass/Fail by program
            renderChart('chartPassFail', 'bar', {
                labels: data.pass_fail_rate?.map(p => p.program_code) || [],
                datasets: [
                    {
                        label: 'Pass Rate %',
                        data: data.pass_fail_rate?.map(p => p.pass_rate) || [],
                        backgroundColor: colors.primary[1]
                    }
                ]
            });
            
            // Top courses table
            const tbody = document.querySelector('#tableTopCourses tbody');
            tbody.innerHTML = (data.top_performing_courses || []).map(c => {
                const avgGrade = parseFloat(c.avg_grade);
                let status = 'status-good';
                let label = 'Excellent';
                if (avgGrade > 2.0) { status = 'status-warning'; label = 'Good'; }
                if (avgGrade > 2.5) { status = 'status-info'; label = 'Average'; }
                if (avgGrade > 3.0) { status = 'status-danger'; label = 'Needs Improvement'; }
                
                return `
                    <tr>
                        <td><strong>${c.course_code}</strong><br><small>${c.course_name}</small></td>
                        <td>${avgGrade.toFixed(2)}</td>
                        <td>${c.students}</td>
                        <td><span class="${status}">${label}</span></td>
                    </tr>
                `;
            }).join('') || '<tr><td colspan="4" class="table-empty">No data</td></tr>';
        }
        
        // Render Retention
        function renderRetention(data) {
            // Status distribution
            renderChart('chartStatus', 'doughnut', {
                labels: data.current_status?.map(s => s.status) || [],
                datasets: [{
                    data: data.current_status?.map(s => s.count) || [],
                    backgroundColor: [colors.primary[1], colors.primary[4], colors.primary[0]]
                }]
            });
            
            // Retention by cohort
            const cohorts = data.retention_by_cohort || [];
            renderChart('chartRetention', 'bar', {
                labels: cohorts.map(c => c.cohort_year),
                datasets: [
                    {
                        label: 'Retention %',
                        data: cohorts.map(c => c.retention_rate),
                        backgroundColor: colors.primary[1]
                    },
                    {
                        label: 'Graduation %',
                        data: cohorts.map(c => c.graduation_rate),
                        backgroundColor: colors.primary[0]
                    }
                ]
            });
            
            // Risk students table
            const tbody = document.querySelector('#tableRiskStudents tbody');
            tbody.innerHTML = (data.at_risk || []).map(r => {
                let action = 'Academic counseling recommended';
                if (r.standing === 'Probation') action = 'Immediate intervention required';
                if (r.standing === 'Dismissed') action = 'Review for possible readmission';
                
                return `
                    <tr>
                        <td><span class="status-danger">${r.standing}</span></td>
                        <td><strong>${r.count}</strong> students</td>
                        <td>${action}</td>
                    </tr>
                `;
            }).join('') || '<tr><td colspan="3" class="table-empty">No at-risk students</td></tr>';
        }
        
        // Render Revenue
        function renderRevenue(data) {
            const summary = data.summary || {};
            
            // Summary card - Using improved revenue grid
            document.getElementById('revenueSummary').innerHTML = `
                <div class="revenue-grid">
                    <div class="revenue-item">
                        <small>Gross Assessment</small>
                        <h3>₱${numberFormat(summary.gross_assessment || 0)}</h3>
                    </div>
                    <div class="revenue-item negative">
                        <small>Scholarships Discount</small>
                        <h3>-₱${numberFormat(summary.total_discount || 0)}</h3>
                    </div>
                    <div class="revenue-item">
                        <small>Net Assessment</small>
                        <h3>₱${numberFormat(summary.net_assessment || 0)}</h3>
                    </div>
                    <div class="revenue-item positive">
                        <small>Total Collected</small>
                        <h3>₱${numberFormat(summary.total_collected || 0)}</h3>
                    </div>
                    <div class="revenue-item warning">
                        <small>Outstanding Balance</small>
                        <h3>₱${numberFormat(summary.balance || 0)}</h3>
                    </div>
                    <div class="revenue-item negative">
                        <small>Late Fees</small>
                        <h3>₱${numberFormat(summary.late_fees || 0)}</h3>
                    </div>
                </div>
                <div class="collection-rate-panel">
                    <div class="collection-rate-header">
                        <small class="collection-rate-label">Collection Rate</small>
                        <span class="collection-rate-value">${summary.collection_rate || 0}%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="fill ${summary.collection_rate >= 80 ? 'success' : summary.collection_rate >= 50 ? 'warning' : 'danger'}" 
                             style="width: ${summary.collection_rate || 0}%"></div>
                    </div>
                </div>
            `;
            
            // Trend chart
            renderChart('chartRevenueTrend', 'line', {
                labels: data.by_term?.map(t => t.academic_year + ' S' + t.semester) || [],
                datasets: [{
                    label: 'Collected (₱)',
                    data: data.by_term?.map(t => t.collected) || [],
                    borderColor: colors.primary[1],
                    backgroundColor: colors.light[1],
                    fill: true,
                    tension: 0.3
                }]
            });
            
            // Scholarships table
            const tbody = document.querySelector('#tableScholarships tbody');
            tbody.innerHTML = (data.scholarships_impact || []).map(s => `
                <tr>
                    <td><strong>${s.name}</strong><br><small>${s.code}</small></td>
                    <td>${s.recipients}</td>
                    <td>${s.discount_type === 'percentage' ? s.discount_value + '%' : '₱' + numberFormat(s.discount_value)}</td>
                </tr>
            `).join('') || '<tr><td colspan="3" class="table-empty">No scholarships awarded</td></tr>';
        }
        
        // Render Chart helper - Enhanced with modern styling
        function renderChart(canvasId, type, data, options = {}) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return;

            const compactViewport = window.matchMedia('(max-width: 768px)').matches;
            
            // Destroy existing chart
            if (charts[canvasId]) {
                charts[canvasId].destroy();
            }
            
            const defaultOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: type === 'doughnut' || type === 'pie'
                            ? (compactViewport ? 'top' : 'right')
                            : 'top',
                        labels: {
                            boxWidth: compactViewport ? 10 : 12,
                            padding: compactViewport ? 10 : 16,
                            font: {
                                size: compactViewport ? 11 : 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(30, 41, 59, 0.95)',
                        titleColor: '#fff',
                        bodyColor: '#e2e8f0',
                        padding: compactViewport ? 10 : 12,
                        cornerRadius: 8,
                        displayColors: true,
                        boxPadding: 4
                    }
                },
                scales: type === 'bar' || type === 'line' ? {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            padding: compactViewport ? 6 : 8
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            padding: compactViewport ? 6 : 8
                        }
                    }
                } : {}
            };
            
            // Doughnut/Pie specific options
            if (type === 'doughnut' || type === 'pie') {
                defaultOptions.cutout = type === 'doughnut'
                    ? (compactViewport ? '58%' : '65%')
                    : 0;
                defaultOptions.plugins.legend.position = compactViewport ? 'top' : 'right';
            }
            
            // Merge scales properly
            const mergedOptions = { ...defaultOptions, ...options };
            if (options.scales) {
                mergedOptions.scales = { ...defaultOptions.scales, ...options.scales };
            }
            
            charts[canvasId] = new Chart(ctx, {
                type: type,
                data: data,
                options: mergedOptions
            });
        }
        
        // Number formatting
        function numberFormat(num) {
            return parseFloat(num || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
    </script>

</body>
</html>
