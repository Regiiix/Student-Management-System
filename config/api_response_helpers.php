<?php

if (!function_exists('api_response_request_id')) {
    /**
     * Return a stable request id for the current request lifecycle.
     *
     * @return string
     */
    function api_response_request_id()
    {
        static $requestId = null;

        if ($requestId !== null) {
            return $requestId;
        }

        try {
            $requestId = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $requestId = uniqid('req_', true);
        }

        return $requestId;
    }
}

if (!function_exists('api_response_meta')) {
    /**
     * Build default meta payload merged with endpoint-provided metadata.
     *
     * @param array $meta
     * @return array
     */
    function api_response_meta($meta = [])
    {
        $baseMeta = [
            'timestamp' => gmdate('c'),
            'request_id' => api_response_request_id(),
        ];

        if (!is_array($meta)) {
            return $baseMeta;
        }

        return array_merge($baseMeta, $meta);
    }
}

if (!function_exists('api_send_json')) {
    /**
     * Send JSON response and terminate script execution.
     *
     * @param array $payload
     * @param int $statusCode
     * @return void
     */
    function api_send_json($payload, $statusCode = 200)
    {
        if (!headers_sent()) {
            http_response_code((int)$statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('api_respond_success')) {
    /**
     * Send standardized success API response.
     *
     * @param mixed $data
     * @param int $statusCode
     * @param array $meta
     * @return void
     */
    function api_respond_success($data = [], $statusCode = 200, $meta = [])
    {
        api_send_json([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => api_response_meta($meta),
        ], $statusCode);
    }
}

if (!function_exists('api_respond_error')) {
    /**
     * Send standardized error API response.
     *
     * @param string $message
     * @param int $statusCode
     * @param string $errorCode
     * @param mixed $details
     * @param array $meta
     * @return void
     */
    function api_respond_error($message, $statusCode = 400, $errorCode = 'bad_request', $details = null, $meta = [])
    {
        $error = [
            'code' => $errorCode,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        api_send_json([
            'success' => false,
            'data' => null,
            'error' => $error,
            'meta' => api_response_meta($meta),
        ], $statusCode);
    }
}
