<?php
if (!defined('ABSPATH')) {
    exit;
}

class Baserow_Auth_Handler {
    private $api_url = 'https://api.dropshipzone.com.au';
    private $token_option_name = 'baserow_dsz_auth_token';
    private $token_expiry_option_name = 'baserow_dsz_token_expiry';

    /**
     * Get the authentication token, refreshing if necessary
     *
     * @return string|WP_Error Token or error
     */
    public function get_token() {
        $token = get_option($this->token_option_name);
        $expiry = get_option($this->token_expiry_option_name);

        // If no token or expired, get new one
        if (!$token || !$expiry || $expiry <= time()) {
            return $this->refresh_token();
        }

        return $token;
    }

    /**
     * Refresh the authentication token
     *
     * @return string|WP_Error New token or error
     */
    public function refresh_token() {
        $credentials = $this->get_credentials();
        if (is_wp_error($credentials)) {
            return $credentials;
        }

        $response = wp_remote_post($this->api_url . '/auth', array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'email' => $credentials['email'],
                'password' => $credentials['password']
            ))
        ));

        if (is_wp_error($response)) {
            Baserow_Logger::error('Failed to refresh DSZ token: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200 || !isset($body['token'])) {
            $error_message = isset($body['message']) ? $body['message'] : 'Unknown error';
            Baserow_Logger::error('DSZ authentication failed: ' . $error_message);
            return new WP_Error('auth_failed', 'Failed to authenticate with DSZ: ' . $error_message);
        }

        // Store new token and expiry
        update_option($this->token_option_name, $body['token']);
        update_option($this->token_expiry_option_name, $body['exp']);

        Baserow_Logger::info('DSZ token refreshed successfully');
        return $body['token'];
    }

    /**
     * Get stored API credentials
     *
     * @return array|WP_Error Credentials or error
     */
    private function get_credentials() {
        $email = get_option('baserow_dsz_api_email');
        $password = get_option('baserow_dsz_api_password');

        if (!$email || !$password) {
            Baserow_Logger::error('DSZ API credentials not configured');
            return new WP_Error('missing_credentials', 'DSZ API credentials not configured');
        }

        return array(
            'email' => $email,
            'password' => $password
        );
    }

    /**
     * Clear stored authentication data
     */
    public function clear_auth_data() {
        delete_option($this->token_option_name);
        delete_option($this->token_expiry_option_name);
    }

    /**
     * Check if authentication is configured
     *
     * @return boolean
     */
    public function is_configured() {
        $email = get_option('baserow_dsz_api_email');
        $password = get_option('baserow_dsz_api_password');
        return !empty($email) && !empty($password);
    }
}
