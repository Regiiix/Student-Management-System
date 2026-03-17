<?php

if (!function_exists('csrf_ensure_session')) {
    /**
     * Ensure session is started before accessing CSRF token storage.
     *
     * @return void
     */
    function csrf_ensure_session()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
        csrf_ensure_session();

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
        csrf_ensure_session();

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
        csrf_ensure_session();

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
