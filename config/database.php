<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'school_db21');

// Error logging configuration
define('LOG_ERRORS', true);
define('LOG_FILE', __DIR__ . '/../logs/error.log');

/**
 * Log an error message to file
 * @param string $message Error message to log
 * @param string $type Error type (ERROR, WARNING, INFO)
 */
function logError($message, $type = 'ERROR') {
    if (!LOG_ERRORS) return;
    
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
    error_log($logMessage, 3, LOG_FILE);
}

/**
 * Get database connection with singleton pattern for connection reuse
 * @return mysqli Database connection object
 */
function getDBConnection() {
    static $conn = null;
    
    // Reuse existing connection if available and still alive
    if ($conn !== null && $conn->ping()) {
        return $conn;
    }
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        logError("Database connection failed: " . $conn->connect_error);
        die("Connection failed. Please try again later.");
    }
    
    // Set charset to utf8mb4 for full Unicode support
    $conn->set_charset('utf8mb4');
    
    return $conn;
}

