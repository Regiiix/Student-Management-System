<?php
// filepath: config/db_helpers.php
// Common database helper functions for prepared queries and fetches
require_once __DIR__ . '/database.php';

/**
 * Prepare and execute a query, return result or false.
 * @param mysqli $conn
 * @param string $sql
 * @param string $types
 * @param array $params
 * @return mysqli_result|false
 */
function db_query($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        logError("Query prepare failed: " . $conn->error . " | SQL: " . substr($sql, 0, 200));
        return false;
    }
    if ($types && $params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        logError("Query execute failed: " . $stmt->error . " | SQL: " . substr($sql, 0, 200));
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $stmt->close();
    
    // For INSERT/UPDATE/DELETE, get_result() returns false even on success.
    // If result is false but we didn't return early from execute failure, it means success (no result set).
    if ($result === false) {
        return true;
    }
    return $result;
}

/**
 * Fetch all rows from a result as an array
 * @param mysqli_result $result
 * @return array
 */
function db_fetch_all($result) {
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Fetch a single row from a result
 * @param mysqli_result $result
 * @return array|null
 */
function db_fetch_one($result) {
    if ($result && $row = $result->fetch_assoc()) {
        return $row;
    }
    return null;
}

/**
 * Resolve shared JSON cache directory path and create it if missing.
 * @return string|null
 */
function db_get_cache_dir() {
    static $cacheDir = null;

    if ($cacheDir !== null) {
        return $cacheDir;
    }

    $cacheDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
        logError('Unable to create cache directory: ' . $cacheDir, 'WARNING');
        return null;
    }

    return $cacheDir;
}

/**
 * Convert cache key to a filesystem-safe cache file path.
 * @param string $cacheKey
 * @return string|null
 */
function db_get_cache_file_path($cacheKey) {
    $cacheDir = db_get_cache_dir();
    if ($cacheDir === null) {
        return null;
    }

    $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$cacheKey);
    if ($safeKey === '' || $safeKey === null) {
        return null;
    }

    return $cacheDir . DIRECTORY_SEPARATOR . $safeKey . '.json';
}

/**
 * Read JSON payload from cache if still fresh.
 * @param string $cacheKey
 * @param int $ttlSeconds
 * @return mixed|null
 */
function db_read_json_cache($cacheKey, $ttlSeconds) {
    $cachePath = db_get_cache_file_path($cacheKey);
    if ($cachePath === null || !is_file($cachePath)) {
        return null;
    }

    $ttlSeconds = (int)$ttlSeconds;
    if ($ttlSeconds > 0 && (time() - filemtime($cachePath)) > $ttlSeconds) {
        return null;
    }

    $raw = @file_get_contents($cachePath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

/**
 * Persist JSON payload to cache.
 * @param string $cacheKey
 * @param mixed $payload
 * @return void
 */
function db_write_json_cache($cacheKey, $payload) {
    $cachePath = db_get_cache_file_path($cacheKey);
    if ($cachePath === null) {
        return;
    }

    @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Delete a JSON cache file.
 * @param string $cacheKey
 * @return void
 */
function db_delete_json_cache($cacheKey) {
    $cachePath = db_get_cache_file_path($cacheKey);
    if ($cachePath !== null && is_file($cachePath)) {
        @unlink($cachePath);
    }
}

/**
 * Get all programs with request + file cache.
 * @param mysqli $conn Database connection
 * @param bool $forceRefresh Force refresh from database
 * @return array List of programs
 */
function getCachedPrograms($conn, $forceRefresh = false) {
    static $requestPrograms = null;

    $cacheKey = 'programs_v1';
    $cacheExpiry = 3600; // 1 hour cache

    if (!$forceRefresh && is_array($requestPrograms)) {
        return $requestPrograms;
    }

    if (!$forceRefresh) {
        $cachedPrograms = db_read_json_cache($cacheKey, $cacheExpiry);
        if (is_array($cachedPrograms)) {
            $requestPrograms = $cachedPrograms;
            return $cachedPrograms;
        }
    }

    // Fetch from database
    $result = db_query($conn, "SELECT program_id, program_code, program_name, description FROM programs ORDER BY program_name");
    $programs = $result ? db_fetch_all($result) : [];

    db_write_json_cache($cacheKey, $programs);
    $requestPrograms = $programs;

    return $programs;
}

/**
 * Get system settings with request + short file cache.
 * @param mysqli $conn Database connection
 * @param array|null $keys Optional list of keys to return
 * @param bool $forceRefresh Force refresh from database
 * @return array
 */
function getSystemSettings($conn, $keys = null, $forceRefresh = false) {
    static $requestSettings = null;

    $cacheKey = 'system_settings_v1';
    $cacheExpiry = 60;
    $settings = null;

    if (!$forceRefresh && is_array($requestSettings)) {
        $settings = $requestSettings;
    }

    if ($settings === null && !$forceRefresh) {
        $cachedSettings = db_read_json_cache($cacheKey, $cacheExpiry);
        if (is_array($cachedSettings)) {
            $settings = $cachedSettings;
        }
    }

    if ($settings === null) {
        $settings = [];
        $settingsResult = db_query($conn, "SELECT setting_key, setting_value FROM system_settings");
        if ($settingsResult) {
            while ($row = $settingsResult->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        db_write_json_cache($cacheKey, $settings);
    }

    $requestSettings = $settings;

    if (!is_array($keys) || empty($keys)) {
        return $settings;
    }

    $filteredSettings = [];
    foreach ($keys as $key) {
        $key = (string)$key;
        if (array_key_exists($key, $settings)) {
            $filteredSettings[$key] = $settings[$key];
        }
    }

    return $filteredSettings;
}

/**
 * Get a single system setting value.
 * @param mysqli $conn Database connection
 * @param string $key Setting key
 * @param mixed $default Default value when key is missing
 * @param bool $forceRefresh Force refresh from database
 * @return mixed
 */
function getSystemSetting($conn, $key, $default = null, $forceRefresh = false) {
    $settings = getSystemSettings($conn, null, $forceRefresh);
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

/**
 * Clear all cached data
 */
function clearCache() {
    db_delete_json_cache('programs_v1');
    db_delete_json_cache('system_settings_v1');
}

/**
 * Normalize asset URL prefix (for example '' or '../').
 * @param string $prefix
 * @return string
 */
function normalizeAssetUrlPrefix($prefix) {
    $prefix = trim((string)$prefix);
    if ($prefix === '') {
        return '';
    }

    return rtrim(str_replace('\\', '/', $prefix), '/') . '/';
}

/**
 * Build a versioned asset URL using filemtime for cache busting.
 * @param string $assetPath Workspace-relative asset path from project root (e.g. css/common.css)
 * @param string $urlPrefix URL prefix for the current page location (e.g. ../)
 * @return string
 */
function app_asset($assetPath, $urlPrefix = '') {
    static $assetVersionCache = [];

    $assetPath = ltrim(str_replace('\\', '/', (string)$assetPath), '/');
    if ($assetPath === '') {
        return '';
    }

    if (!array_key_exists($assetPath, $assetVersionCache)) {
        $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $assetPath);
        $assetVersionCache[$assetPath] = is_file($fullPath) ? (string)filemtime($fullPath) : '0';
    }

    $prefix = normalizeAssetUrlPrefix($urlPrefix);
    return $prefix . $assetPath . '?v=' . rawurlencode($assetVersionCache[$assetPath]);
}

/**
 * Execute multiple queries in a transaction
 * @param mysqli $conn Database connection
 * @param callable $callback Function containing queries to execute
 * @return bool Success status
 */
function db_transaction($conn, $callback) {
    try {
        $conn->begin_transaction();
        $result = $callback($conn);
        if ($result === false) {
            $conn->rollback();
            return false;
        }
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        logError("Transaction failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get count from a table with optional conditions
 * @param mysqli $conn Database connection
 * @param string $table Table name
 * @param string $where WHERE clause (without WHERE keyword)
 * @param string $types Parameter types
 * @param array $params Parameters
 * @return int Count
 */
function db_count($conn, $table, $where = '', $types = '', $params = []) {
    $sql = "SELECT COUNT(*) as cnt FROM $table";
    if ($where) {
        $sql .= " WHERE $where";
    }
    $result = db_query($conn, $sql, $types, $params);
    $row = db_fetch_one($result);
    return $row ? (int)$row['cnt'] : 0;
}

/**
 * Increment an Academic Year string (e.g., 2025-2026 -> 2026-2027)
 * @param string $ay
 * @return string
 */
function getNextAcademicYear($ay) {
    if (!$ay || !preg_match('/^(\d{4})-(\d{4})$/', $ay, $matches)) {
        $year = (int)date('Y');
        return $year . '-' . ($year + 1);
    }
    return ((int)$matches[1] + 1) . '-' . ((int)$matches[2] + 1);
}

/**
 * Check if a record exists
 * @param mysqli $conn Database connection
 * @param string $table Table name
 * @param string $where WHERE clause
 * @param string $types Parameter types
 * @param array $params Parameters
 * @return bool True if exists
 */
function db_exists($conn, $table, $where, $types = '', $params = []) {
    return db_count($conn, $table, $where, $types, $params) > 0;
}

/**
 * Get standardized term options for a student (Current + History).
 * 
 * @param mysqli $conn
 * @param int $student_id
 * @return array Array of options derived from enrollment history and current status.
 */
function get_student_term_options($conn, $student_id) {
    // 1. Get System Settings for Current Term defaults
    $sys_settings = getSystemSettings($conn);
    $current_sys_ay = $sys_settings['current_academic_year'] ?? (date('Y') . '-' . (date('Y') + 1));

    // 2. Get Student Info for "Current" context
    $s_sql = "SELECT year_level, current_semester FROM students WHERE student_id = ?";
    $student = db_fetch_one(db_query($conn, $s_sql, 'i', [$student_id]));
    if (!$student) return [];

    // 3. Get History from Semester Status primarily (Official Terms)
    // We union with Enrollments to catch terms where subjects were taken but No official status was recorded yet
    $hist_sql = "SELECT academic_year, semester, year_level, status 
                FROM semester_status 
                WHERE student_id = ? 
                UNION
                SELECT DISTINCT e.academic_year, c.semester, c.year_level, 'Incomplete' as status
                FROM enrollments e
                JOIN curriculum c ON e.curriculum_id = c.curriculum_id
                WHERE e.student_id = ? AND NOT EXISTS (
                    SELECT 1 FROM semester_status ss 
                    WHERE ss.student_id = e.student_id 
                    AND ss.academic_year = e.academic_year 
                    AND ss.semester = c.semester
                )
                ORDER BY academic_year DESC, semester DESC";
    $history_terms = db_fetch_all(db_query($conn, $hist_sql, 'ii', [$student_id, $student_id]));

    // 4. Resolve "Current" Academic Year context
    // Projection logic: If the latest record in history is 'Completed', or we have no history,
    // we project the "Current" term using system settings but adjusted for promotion.
    $latest = !empty($history_terms) ? $history_terms[0] : null;
    $student_current_ay = $current_sys_ay;
    $student_current_yl = $student['year_level'] ?? 1;
    $student_current_sem = $student['current_semester'] ?? 1;

    if ($latest) {
        // If the latest term is already for the student's CURRENT Year Level, 
        // we use that academic year as current. No need to increment.
        if (intval($latest['year_level']) == $student_current_yl) {
            $student_current_ay = $latest['academic_year'];
        } 
        // If student's Year Level is higher than latest Enrollment Year Level, it must be a future AY
        elseif ($student_current_yl > intval($latest['year_level'])) {
            $diff = $student_current_yl - intval($latest['year_level']);
            $student_current_ay = $latest['academic_year'];
            for ($i = 0; $i < $diff; $i++) {
                $student_current_ay = getNextAcademicYear($student_current_ay);
            }
        }
    }

    // 5. Build Options using unique Key (AY|YL|Sem) to match filters
    $terms_options = [];

    // Add History first
    foreach ($history_terms as $h) {
        $ay = $h['academic_year'];
        $sem = $h['semester'];
        $yl = $h['year_level'];
        
        // Final fallback for YL: if unknown, use '?'
        if (!$yl) $yl = '?';
        
        $key = "$ay|$yl|$sem";
        
        $terms_options[$key] = [
            'ay' => $ay,
            'sem' => $sem,
            'yl' => $yl,
            'label' => "$ay " . ($sem == 1 ? '1st' : '2nd') . " Sem (Year $yl)"
        ];
    }

    // Add/Mark "Current" context
    $current_key = "$student_current_ay|$student_current_yl|$student_current_sem";
    if (!isset($terms_options[$current_key])) {
        $terms_options[$current_key] = [
            'ay' => $student_current_ay,
            'sem' => $student_current_sem,
            'yl' => $student_current_yl,
            'label' => "$student_current_ay " . ($student_current_sem == 1 ? '1st' : '2nd') . " Sem (Year $student_current_yl) - Current"
        ];
    } else {
        $terms_options[$current_key]['label'] .= " - Current";
    }

    // Include Payments-only terms if any (e.g. forward balance payments)
    $pay_sql = "SELECT DISTINCT academic_year, semester FROM payments WHERE student_id = ?";
    $pay_terms = db_fetch_all(db_query($conn, $pay_sql, 'i', [$student_id]));
    foreach ($pay_terms as $pt) {
        $ay = $pt['academic_year'];
        $sem = $pt['semester'];
        
        // We don't know the YL for a raw payment, so we check if AY|Sem exists under ANY Year Level
        $exists = false;
        foreach ($terms_options as $k => $o) {
            $parts = explode('|', $k);
            if ($parts[0] === $ay && $parts[2] == $sem) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $terms_options["$ay|?|$sem"] = [
                'ay' => $ay,
                'sem' => $sem,
                'yl' => '?',
                'label' => "$ay " . ($sem == 1 ? '1st' : '2nd') . " Sem (Year ?)"
            ];
        }
    }
    
    // Final Sort: Descending Academic Year and Semester
    uksort($terms_options, function($a, $b) {
        return strcmp($b, $a); // Reverse alphabetical/numerical sort fits our key format
    });

    return $terms_options;
}
