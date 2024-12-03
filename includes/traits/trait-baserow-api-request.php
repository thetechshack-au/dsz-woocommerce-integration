<?php
/**
 * Trait: Baserow API Request
 * Provides standardized API request handling with advanced features.
 * 
 * @version 1.6.0
 */

trait Baserow_API_Request_Trait {
    use Baserow_Logger_Trait;

    /** @var int */
    protected $max_retries = 3;
    
    /** @var int */
    protected $retry_delay = 1000; // milliseconds
    
    /** @var array */
    protected $rate_limits = [];
    
    /** @var array */
    protected $cache = [];
    
    /** @var int */
    protected $cache_ttl = 300; // 5 minutes

    /**
     * Make an API request with retry logic and performance tracking
     *
     * @param string $endpoint
     * @param string $method
     * @param array|null $body
     * @param array $headers
     * @param array $options
     * @return array|WP_Error
     */
    protected function make_api_request(
        string $endpoint,
        string $method = 'GET',
        ?array $body = null,
        array $headers = [],
        array $options = []
    ) {
        $start_time = microtime(true);
        $attempt = 1;
        $options = array_merge([
            'timeout' => 30,
            'retry_on_status' => [408, 429, 500, 502, 503, 504],
            'cache' => true,
            'validate_ssl' => true
        ], $options);

        // Check rate limits
        if (!$this->check_rate_limit($endpoint)) {
            return new WP_Error(
                'rate_limit_exceeded',
                'API rate limit exceeded',
                ['endpoint' => $endpoint]
            );
        }

        // Try to get from cache for GET requests
        if ($method === 'GET' && $options['cache'] && isset($this->cache[$endpoint])) {
            $cache_data = $this->cache[$endpoint];
            if (time() - $cache_data['time'] < $this->cache_ttl) {
                $this->log_debug('Returning cached response', [
                    'endpoint' => $endpoint,
                    'cache_age' => time() - $cache_data['time']
                ]);
                return $cache_data['data'];
            }
        }

        do {
            $this->log_debug("Making API request (attempt {$attempt})", [
                'endpoint' => $endpoint,
                'method' => $method,
                'headers' => $this->sanitize_headers($headers)
            ]);

            $args = $this->prepare_request_args($method, $body, $headers, $options);
            $response = wp_remote_request($endpoint, $args);
            $result = $this->handle_api_response($response, $options);

            if (!is_wp_error($result) || !$this->should_retry($result, $attempt, $options)) {
                break;
            }

            $this->log_warning("Retrying API request", [
                'attempt' => $attempt,
                'error' => $result->get_error_message()
            ]);

            usleep($this->retry_delay * 1000);
            $attempt++;
        } while ($attempt <= $this->max_retries);

        // Cache successful GET responses
        if ($method === 'GET' && !is_wp_error($result) && $options['cache']) {
            $this->cache[$endpoint] = [
                'time' => time(),
                'data' => $result
            ];
        }

        // Log performance metrics
        $this->log_performance('api_request', $start_time, [
            'endpoint' => $endpoint,
            'method' => $method,
            'attempts' => $attempt
        ]);

        return $result;
    }

    /**
     * Prepare request arguments
     *
     * @param string $method
     * @param array|null $body
     * @param array $headers
     * @param array $options
     * @return array
     */
    protected function prepare_request_args(
        string $method,
        ?array $body,
        array $headers,
        array $options
    ): array {
        $args = [
            'method' => $method,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'Baserow-WooCommerce-Integration/1.6.0'
            ], $headers),
            'timeout' => $options['timeout'],
            'sslverify' => $options['validate_ssl']
        ];

        if ($body !== null) {
            $args['body'] = json_encode($body);
        }

        return $args;
    }

    /**
     * Handle API response
     *
     * @param array|WP_Error $response
     * @param array $options
     * @return array|WP_Error
     */
    protected function handle_api_response($response, array $options) {
        if (is_wp_error($response)) {
            $this->log_error("API request failed", [
                'error' => $response->get_error_message()
            ]);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Update rate limit tracking
        $this->update_rate_limits($response);

        if ($response_code < 200 || $response_code >= 300) {
            $error_data = [
                'status' => $response_code,
                'body' => $response_body,
                'headers' => wp_remote_retrieve_headers($response)
            ];

            $this->log_error("API request returned non-200 status", $error_data);
            
            return new WP_Error(
                'api_error',
                sprintf('API request failed with status %d', $response_code),
                $error_data
            );
        }

        $decoded_response = $this->decode_response($response_body);
        if (is_wp_error($decoded_response)) {
            return $decoded_response;
        }

        $this->log_debug("API request successful", [
            'status' => $response_code,
            'headers' => wp_remote_retrieve_headers($response)
        ]);

        return $decoded_response;
    }

    /**
     * Decode JSON response
     *
     * @param string $response_body
     * @return array|WP_Error
     */
    protected function decode_response(string $response_body) {
        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_data = [
                'error' => json_last_error_msg(),
                'response' => $response_body
            ];
            
            $this->log_error("Failed to decode API response", $error_data);
            
            return new WP_Error(
                'json_decode_error',
                'Failed to decode API response',
                $error_data
            );
        }

        return $decoded_response;
    }

    /**
     * Validate API response
     *
     * @param array|WP_Error $response
     * @param array $required_fields
     * @param array $field_types
     * @return true|WP_Error
     */
    protected function validate_api_response($response, array $required_fields = [], array $field_types = []) {
        if (is_wp_error($response)) {
            return $response;
        }

        foreach ($required_fields as $field) {
            if (!isset($response[$field])) {
                $error_data = [
                    'field' => $field,
                    'response' => $response
                ];
                
                $this->log_error("Missing required field in API response", $error_data);
                
                return new WP_Error(
                    'missing_field',
                    sprintf('Missing required field: %s', $field),
                    $error_data
                );
            }

            if (isset($field_types[$field])) {
                $valid = $this->validate_field_type(
                    $response[$field],
                    $field_types[$field]
                );
                
                if (!$valid) {
                    return new WP_Error(
                        'invalid_field_type',
                        sprintf(
                            'Invalid type for field %s. Expected %s, got %s',
                            $field,
                            $field_types[$field],
                            gettype($response[$field])
                        )
                    );
                }
            }
        }

        return true;
    }

    /**
     * Validate field type
     *
     * @param mixed $value
     * @param string $expected_type
     * @return bool
     */
    protected function validate_field_type($value, string $expected_type): bool {
        switch ($expected_type) {
            case 'string':
                return is_string($value);
            case 'int':
            case 'integer':
                return is_int($value);
            case 'float':
            case 'double':
                return is_float($value);
            case 'bool':
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value);
            case 'object':
                return is_object($value);
            default:
                return true;
        }
    }

    /**
     * Build query string from parameters
     *
     * @param array $params
     * @return string
     */
    protected function build_query_string(array $params): string {
        return http_build_query(array_filter($params, function($value) {
            return $value !== null && $value !== '';
        }));
    }

    /**
     * Check if should retry request
     *
     * @param WP_Error $error
     * @param int $attempt
     * @param array $options
     * @return bool
     */
    protected function should_retry(WP_Error $error, int $attempt, array $options): bool {
        if ($attempt >= $this->max_retries) {
            return false;
        }

        $data = $error->get_error_data();
        return isset($data['status']) && in_array($data['status'], $options['retry_on_status']);
    }

    /**
     * Update rate limit tracking
     *
     * @param array $response
     * @return void
     */
    protected function update_rate_limits(array $response): void {
        $headers = wp_remote_retrieve_headers($response);
        
        if (isset($headers['x-ratelimit-remaining'])) {
            $this->rate_limits['remaining'] = (int)$headers['x-ratelimit-remaining'];
        }
        
        if (isset($headers['x-ratelimit-reset'])) {
            $this->rate_limits['reset'] = (int)$headers['x-ratelimit-reset'];
        }
    }

    /**
     * Check rate limit
     *
     * @param string $endpoint
     * @return bool
     */
    protected function check_rate_limit(string $endpoint): bool {
        if (empty($this->rate_limits)) {
            return true;
        }

        if (isset($this->rate_limits['remaining']) && $this->rate_limits['remaining'] <= 0) {
            if (isset($this->rate_limits['reset']) && time() < $this->rate_limits['reset']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize headers for logging
     *
     * @param array $headers
     * @return array
     */
    protected function sanitize_headers(array $headers): array {
        $sensitive_headers = ['Authorization', 'Cookie', 'X-API-Key'];
        
        return array_map(function($key, $value) use ($sensitive_headers) {
            if (in_array($key, $sensitive_headers, true)) {
                return '***';
            }
            return $value;
        }, array_keys($headers), $headers);
    }
}
