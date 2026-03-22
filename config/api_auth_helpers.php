<?php

if (!function_exists('api_auth_ensure_session')) {
    /**
     * Ensure a PHP session is active.
     *
     * @return bool
     */
    function api_auth_ensure_session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (headers_sent()) {
            return false;
        }

        if (@session_start()) {
            return true;
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }
}

if (!function_exists('api_auth_token_ttl_seconds')) {
    /**
     * Resolve API token TTL.
     *
     * @return int
     */
    function api_auth_token_ttl_seconds()
    {
        if (defined('API_AUTH_TOKEN_TTL_SECONDS')) {
            return max(60, (int)API_AUTH_TOKEN_TTL_SECONDS);
        }

        return 2 * 60 * 60;
    }
}

if (!function_exists('api_auth_issue_token')) {
    /**
     * Issue or reuse a session-bound API token.
     *
     * @param bool $rotate
     * @param int|null $ttlSeconds
     * @return string
     */
    function api_auth_issue_token($rotate = false, $ttlSeconds = null)
    {
        if (!api_auth_ensure_session()) {
            return '';
        }

        $ttl = max(60, (int)($ttlSeconds ?? api_auth_token_ttl_seconds()));
        $storeKey = 'api_access_token';
        $entry = isset($_SESSION[$storeKey]) && is_array($_SESSION[$storeKey])
            ? $_SESSION[$storeKey]
            : [];

        $existingToken = isset($entry['token']) ? (string)$entry['token'] : '';
        $issuedAt = isset($entry['issued_at']) ? (int)$entry['issued_at'] : 0;
        $expired = $existingToken === '' || $issuedAt <= 0 || (time() - $issuedAt) > $ttl;

        if ($rotate || $expired) {
            try {
                $existingToken = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $existingToken = hash('sha256', uniqid('api_', true));
            }

            $entry = [
                'token' => $existingToken,
                'issued_at' => time(),
            ];
            $_SESSION[$storeKey] = $entry;
        }

        return $existingToken;
    }
}

if (!function_exists('api_auth_extract_request_token')) {
    /**
    * Extract API token from request headers (preferred) or POST parameters.
     *
     * @return string
     */
    function api_auth_extract_request_token()
    {
        if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
            $headerToken = trim((string)$_SERVER['HTTP_X_API_TOKEN']);
            if ($headerToken !== '') {
                return $headerToken;
            }
        }

        if (isset($_POST['api_token'])) {
            $postToken = trim((string)$_POST['api_token']);
            if ($postToken !== '') {
                return $postToken;
            }
        }

        return '';
    }
}

if (!function_exists('api_auth_validate_token')) {
    /**
     * Validate request API token against session-bound value.
     *
     * @param int|null $ttlSeconds
     * @return bool
     */
    function api_auth_validate_token($ttlSeconds = null)
    {
        if (!api_auth_ensure_session()) {
            return false;
        }

        $ttl = max(60, (int)($ttlSeconds ?? api_auth_token_ttl_seconds()));
        $storeKey = 'api_access_token';
        if (!isset($_SESSION[$storeKey]) || !is_array($_SESSION[$storeKey])) {
            return false;
        }

        $entry = $_SESSION[$storeKey];
        $storedToken = isset($entry['token']) ? (string)$entry['token'] : '';
        $issuedAt = isset($entry['issued_at']) ? (int)$entry['issued_at'] : 0;

        if ($storedToken === '' || $issuedAt <= 0 || (time() - $issuedAt) > $ttl) {
            unset($_SESSION[$storeKey]);
            return false;
        }

        $requestToken = api_auth_extract_request_token();
        if ($requestToken === '') {
            return false;
        }

        return hash_equals($storedToken, $requestToken);
    }
}

if (!function_exists('api_auth_require_valid_token')) {
    /**
     * Require a valid API token for protected API endpoints.
     *
     * @param int|null $ttlSeconds
     * @return void
     */
    function api_auth_require_valid_token($ttlSeconds = null)
    {
        if (api_auth_validate_token($ttlSeconds)) {
            return;
        }

        if (function_exists('api_respond_error')) {
            api_respond_error('Unauthorized API access', 401, 'api_auth_required');
        }

        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'api_auth_required',
                'message' => 'Unauthorized API access',
            ],
            'meta' => [
                'timestamp' => gmdate('c'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}