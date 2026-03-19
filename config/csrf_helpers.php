<?php

if (!function_exists('csrf_log_message')) {
    /**
     * Write CSRF runtime diagnostics to application or PHP logs.
     *
     * @param string $message
     * @param string $type
     * @return void
     */
    function csrf_log_message($message, $type = 'WARNING')
    {
        $message = '[CSRF] ' . (string)$message;

        if (function_exists('logError')) {
            logError($message, $type);
            return;
        }

        error_log($message);
    }
}

if (!function_exists('csrf_is_https_request')) {
    /**
     * Determine if current request is served over HTTPS.
     *
     * @return bool
     */
    function csrf_is_https_request()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        return false;
    }
}

if (!function_exists('csrf_resolve_session_save_path')) {
    /**
     * Extract filesystem session path from php.ini value.
     *
     * @param string $rawPath
     * @return string
     */
    function csrf_resolve_session_save_path($rawPath)
    {
        $rawPath = trim((string)$rawPath);
        if ($rawPath === '') {
            return '';
        }

        $parts = explode(';', $rawPath);
        return trim((string)end($parts));
    }
}

if (!function_exists('csrf_ensure_session')) {
    /**
     * Ensure session is started before accessing CSRF token storage.
     *
     * @return bool
     */
    function csrf_ensure_session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        // Keep CSRF storage on cookie-backed sessions even when server defaults differ.
        @ini_set('session.use_cookies', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        if (csrf_is_https_request()) {
            @ini_set('session.cookie_secure', '1');
        }

        $configuredPath = csrf_resolve_session_save_path(ini_get('session.save_path'));
        $needsFallbackPath = ($configuredPath === '' || !@is_dir($configuredPath) || !@is_writable($configuredPath));

        if ($needsFallbackPath) {
            $fallbackPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'sessions';
            if (!is_dir($fallbackPath)) {
                @mkdir($fallbackPath, 0755, true);
            }
            if (@is_dir($fallbackPath) && @is_writable($fallbackPath)) {
                @session_save_path($fallbackPath);
            }
        }

        if (headers_sent($file, $line)) {
            csrf_log_message('Cannot start session because headers already sent at ' . $file . ':' . $line);
            return false;
        }

        if (!@session_start()) {
            csrf_log_message(
                'session_start failed; save_path=' . (string)session_save_path() . '; use_cookies=' . (string)ini_get('session.use_cookies')
            );
            return false;
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }
}

if (!function_exists('csrf_token_ttl_seconds')) {
    /**
     * Token validity window in seconds.
     *
     * @return int
     */
    function csrf_token_ttl_seconds()
    {
        return 7200;
    }
}

if (!function_exists('csrf_generate_token')) {
    /**
     * Generate and store a new CSRF token for a given scope.
     *
     * @param string $scope
     * @return string
     */
    function csrf_generate_token($scope = 'default')
    {
        if (!csrf_ensure_session()) {
            return '';
        }

        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = hash('sha256', uniqid('csrf_', true) . mt_rand());
        }

        $_SESSION['csrf_tokens'][$scope] = [
            'token' => $token,
            'created_at' => time(),
        ];

        return $token;
    }
}

if (!function_exists('csrf_get_token')) {
    /**
     * Get existing token for scope or create a new one.
     *
     * @param string $scope
     * @param bool $rotate
     * @return string
     */
    function csrf_get_token($scope = 'default', $rotate = false)
    {
        if (!csrf_ensure_session()) {
            return '';
        }

        $tokenEntry = isset($_SESSION['csrf_tokens'][$scope]) ? $_SESSION['csrf_tokens'][$scope] : null;
        $isValidEntry = is_array($tokenEntry) && isset($tokenEntry['token'], $tokenEntry['created_at']);
        $isExpired = $isValidEntry && ((time() - (int)$tokenEntry['created_at']) > csrf_token_ttl_seconds());

        if ($rotate || !$isValidEntry || $isExpired) {
            return csrf_generate_token($scope);
        }

        return (string)$tokenEntry['token'];
    }
}

if (!function_exists('csrf_token_field')) {
    /**
     * Render hidden input field with CSRF token.
     *
     * @param string $scope
     * @param string $fieldName
     * @return string
     */
    function csrf_token_field($scope = 'default', $fieldName = 'csrf_token')
    {
        $token = csrf_get_token($scope);
        if ($token === '') {
            return '';
        }

        return '<input type="hidden" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_validate_token')) {
    /**
     * Validate token against current session storage.
     *
     * @param string $providedToken
     * @param string $scope
     * @param bool $consume
     * @return bool
     */
    function csrf_validate_token($providedToken, $scope = 'default', $consume = true)
    {
        if (!csrf_ensure_session()) {
            return false;
        }

        if (!is_string($providedToken) || $providedToken === '') {
            return false;
        }

        if (!isset($_SESSION['csrf_tokens'][$scope]) || !is_array($_SESSION['csrf_tokens'][$scope])) {
            return false;
        }

        $entry = $_SESSION['csrf_tokens'][$scope];
        if (!isset($entry['token'], $entry['created_at'])) {
            return false;
        }

        if ((time() - (int)$entry['created_at']) > csrf_token_ttl_seconds()) {
            unset($_SESSION['csrf_tokens'][$scope]);
            return false;
        }

        $expectedToken = (string)$entry['token'];
        $isValid = hash_equals($expectedToken, $providedToken);

        if ($isValid && $consume) {
            csrf_generate_token($scope);
        }

        return $isValid;
    }
}

if (!function_exists('csrf_validate_request_token')) {
    /**
     * Validate CSRF token from POST field or X-CSRF-Token header.
     *
     * @param string $scope
     * @param string $fieldName
     * @param bool $consume
     * @return bool
     */
    function csrf_validate_request_token($scope = 'default', $fieldName = 'csrf_token', $consume = true)
    {
        $token = '';

        if (isset($_POST[$fieldName])) {
            $token = (string)$_POST[$fieldName];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        return csrf_validate_token($token, $scope, $consume);
    }
}
