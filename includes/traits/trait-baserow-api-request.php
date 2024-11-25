<?php
/**
 * Trait: Baserow API Request
 * Version: 1.4.0
 * Last Updated: 2024-01-09 14:00:00 UTC
 */

trait Baserow_API_Request_Trait {
    use Baserow_Logger_Trait;

    protected function make_api_request($endpoint, $method = 'GET', $body = null, $headers = array()) {
        $this->log_debug("Making API request to: {$endpoint}", array(
            'method' => $method,
            'headers' => $headers
        ));

        $args = array(
            'method' => $method,
            'headers' => array_merge(array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ), $headers),
            'timeout' => 30
        );

        if ($body !== null) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($endpoint, $args);

        return $this->handle_api_response($response);
    }

    protected function handle_api_response($response) {
        if (is_wp_error($response)) {
            $this->log_error("API request failed", array(
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code < 200 || $response_code >= 300) {
            $this->log_error("API request returned non-200 status", array(
                'status' => $response_code,
                'body' => $response_body
            ));
            return new WP_Error(
                'api_error',
                sprintf('API request failed with status %d', $response_code),
                array(
                    'status' => $response_code,
                    'body' => $response_body
                )
            );
        }

        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error("Failed to decode API response", array(
                'error' => json_last_error_msg(),
                'response' => $response_body
            ));
            return new WP_Error(
                'json_decode_error',
                'Failed to decode API response',
                array(
                    'error' => json_last_error_msg(),
                    'response' => $response_body
                )
            );
        }

        $this->log_debug("API request successful", array(
            'status' => $response_code
        ));

        return $decoded_response;
    }

    protected function validate_api_response($response, $required_fields = array()) {
        if (is_wp_error($response)) {
            return $response;
        }

        foreach ($required_fields as $field) {
            if (!isset($response[$field])) {
                $this->log_error("Missing required field in API response", array(
                    'field' => $field,
                    'response' => $response
                ));
                return new WP_Error(
                    'missing_field',
                    sprintf('Missing required field: %s', $field),
                    array(
                        'field' => $field,
                        'response' => $response
                    )
                );
            }
        }

        return true;
    }

    protected function build_query_string($params) {
        return http_build_query(array_filter($params, function($value) {
            return $value !== null && $value !== '';
        }));
    }
}
