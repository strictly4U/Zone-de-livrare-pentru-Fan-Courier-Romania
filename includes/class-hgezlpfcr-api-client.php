<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_API_Client {
    protected string $user;
    protected string $pass;
    protected int $timeout;
    protected int $retries;
    protected ?string $token = null;
    protected ?string $token_expires = null;

    public function __construct() {
        $this->user    = (string) HGEZLPFCR_Settings::get('hgezlpfcr_user', '');
        $this->pass    = (string) HGEZLPFCR_Settings::get('hgezlpfcr_pass', '');
        $this->timeout = (int) HGEZLPFCR_Settings::get('hgezlpfcr_timeout', 20);
        $this->retries = (int) HGEZLPFCR_Settings::get('hgezlpfcr_retries', 2);
        
        // Load cached token
        $this->load_cached_token();
    }

    /**
     * POST request with form data (application/x-www-form-urlencoded)
     * Used for eCommerce API endpoints
     */
    protected function post_form(string $url, array $body, array $headers = []) {
        // Get authentication token
        $token = $this->get_auth_token();
        if (!$token) {
            HGEZLPFCR_Logger::error('Could not get authentication token');
            return new WP_Error('hgezlpfcr_auth_failed', 'Could not obtain authentication token');
        }

        // Add domain to all eCommerce API requests
        $body['domain'] = site_url();

        $args = [
            'timeout'   => $this->timeout,
            'headers'   => array_merge([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'   => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ], $headers),
            'sslverify' => true,
            'redirection' => 1,
            'body'      => http_build_query($body),
        ];

        $attempt = 0; $delay_ms = 200;
        do {
            $attempt++;
            $res = wp_remote_post($url, $args);
            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $raw  = wp_remote_retrieve_body($res);

                HGEZLPFCR_Logger::log('eCommerce API response', [
                    'url' => $url,
                    'code' => $code,
                    'raw' => $raw
                ]);

                // Handle token expiration (401 Unauthorized)
                if ($code === 401) {
                    HGEZLPFCR_Logger::log('Token expired, clearing and retrying', ['attempt' => $attempt]);
                    $this->clear_token();

                    // Try to get a new token
                    $new_token = $this->get_auth_token();
                    if ($new_token && $attempt <= $this->retries) {
                        // Update token in args and retry
                        $args['headers']['Authorization'] = 'Bearer ' . $new_token;
                        continue;
                    } else {
                        return new WP_Error('hgezlpfcr_auth_failed', 'Token expired and could not be regenerated', ['code'=>$code]);
                    }
                }

                if ($code >= 200 && $code < 300) {
                    // Try to decode as JSON
                    $data = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $data;
                    } else {
                        // Return raw response for non-JSON responses
                        return ['raw' => $raw, 'available' => $raw == '1'];
                    }
                }
                // 429/5xx => retry
                if (!in_array($code, [429,500,502,503,504], true)) {
                    return new WP_Error('hgezlpfcr_http_'.$code, 'FanCourier eCommerce API Error', ['response' => $raw, 'code'=>$code]);
                }
                HGEZLPFCR_Logger::error('API transient error, retrying', ['code'=>$code, 'attempt'=>$attempt]);
            } else {
                HGEZLPFCR_Logger::error('HTTP error', ['err'=>$res->get_error_message(), 'attempt'=>$attempt]);
            }
            if ($attempt <= $this->retries) usleep($delay_ms * 1000);
            $delay_ms = min($delay_ms * 2, 2000);
        } while ($attempt <= $this->retries);

        return is_wp_error($res) ? $res : new WP_Error('hgezlpfcr_api_failed', 'Could not communicate with FanCourier API after retry.');
    }

    protected function post(string $url, array $body, array $headers = [], ?string $idempotency_key = null) {
        // Get authentication token
        $token = $this->get_auth_token();
        if (!$token) {
            HGEZLPFCR_Logger::error('Could not get authentication token');
            return new WP_Error('hgezlpfcr_auth_failed', 'Could not obtain authentication token');
        }

        $args = [
            'timeout'   => $this->timeout,
            'headers'   => array_merge([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'   => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ], $headers),
            'sslverify' => true,
            'redirection' => 1,
            'body'      => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
        if ($idempotency_key) {
            $args['headers']['Idempotency-Key'] = $idempotency_key;
            $args['headers']['X-Request-ID']    = $idempotency_key;
        }

        $attempt = 0; $delay_ms = 200;
        do {
            $attempt++;
            $res = wp_remote_post($url, $args);
            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $raw  = wp_remote_retrieve_body($res);
                $data = json_decode($raw, true);
                
                // Handle token expiration (401 Unauthorized)
                if ($code === 401) {
                    HGEZLPFCR_Logger::log('Token expired, clearing and retrying', ['attempt' => $attempt]);
                    $this->clear_token();
                    
                    // Try to get a new token
                    $new_token = $this->get_auth_token();
                    if ($new_token && $attempt <= $this->retries) {
                        // Retry with new token
                        continue;
                    } else {
                        return new WP_Error('hgezlpfcr_auth_failed', 'Token-ul a expirat și nu s-a putut regenera', ['response' => $data, 'code'=>$code]);
                    }
                }
                
                if ($code >= 200 && $code < 300 && is_array($data)) {
                    return $data;
                }
                // 429/5xx => retry
                if (!in_array($code, [429,500,502,503,504], true)) {
                    return new WP_Error('hgezlpfcr_http_'.$code, 'FanCourier API Error', ['response' => $data, 'code'=>$code]);
                }
                HGEZLPFCR_Logger::error('API transient error, retrying', ['code'=>$code, 'attempt'=>$attempt]);
            } else {
                HGEZLPFCR_Logger::error('HTTP error', ['err'=>$res->get_error_message(), 'attempt'=>$attempt]);
            }
            if ($attempt <= $this->retries) usleep($delay_ms * 1000);
            $delay_ms = min($delay_ms * 2, 2000);
        } while ($attempt <= $this->retries);

        return is_wp_error($res) ? $res : new WP_Error('hgezlpfcr_api_failed', 'Could not communicate with FanCourier API after retry.');
    }

    protected function get(string $url, array $headers = []) {
        // Get authentication token
        $token = $this->get_auth_token();
        if (!$token) {
            HGEZLPFCR_Logger::error('Could not get authentication token');
            return new WP_Error('hgezlpfcr_auth_failed', 'Could not obtain authentication token');
        }

        $args = [
            'timeout'   => $this->timeout,
            'headers'   => array_merge([
                'Accept'       => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'   => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ], $headers),
            'sslverify' => true,
            'redirection' => 1,
        ];

        $attempt = 0; $delay_ms = 200;
        do {
            $attempt++;
            $res = wp_remote_get($url, $args);
            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $raw  = wp_remote_retrieve_body($res);

                // Handle token expiration (401 Unauthorized)
                if ($code === 401) {
                    HGEZLPFCR_Logger::log('Token expired, clearing and retrying', ['attempt' => $attempt]);
                    $this->clear_token();

                    // Try to get a new token
                    $new_token = $this->get_auth_token();
                    if ($new_token && $attempt <= $this->retries) {
                        // Retry with new token
                        continue;
                    } else {
                        return new WP_Error('hgezlpfcr_auth_failed', 'Token expired and could not be regenerated', ['code'=>$code]);
                    }
                }

                if ($code >= 200 && $code < 300) {
                    // Try to decode as JSON first
                    $data = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $data;
                    } else {
                        // Return raw response for non-JSON responses (like PDF)
                        return ['raw_response' => $raw, 'content_type' => wp_remote_retrieve_header($res, 'content-type')];
                    }
                }
                // 429/5xx => retry
                if (!in_array($code, [429,500,502,503,504], true)) {
                    return new WP_Error('hgezlpfcr_http_'.$code, 'FanCourier API Error', ['code'=>$code]);
                }
                HGEZLPFCR_Logger::error('API transient error, retrying', ['code'=>$code, 'attempt'=>$attempt]);
            } else {
                HGEZLPFCR_Logger::error('HTTP error', ['err'=>$res->get_error_message(), 'attempt'=>$attempt]);
            }
            if ($attempt <= $this->retries) usleep($delay_ms * 1000);
            $delay_ms = min($delay_ms * 2, 2000);
        } while ($attempt <= $this->retries);

        return is_wp_error($res) ? $res : new WP_Error('hgezlpfcr_api_failed', 'Could not communicate with FanCourier API after retry.');
    }

    /**
     * GET request for old API endpoints (uses username/password authentication)
     */
    protected function get_old_api(string $url, array $headers = []) {
        // Get old API authentication token
        $token = $this->get_old_api_token();
        if (!$token) {
            HGEZLPFCR_Logger::error('Could not get old API authentication token');
            return new WP_Error('hgezlpfcr_auth_failed', 'Nu s-a putut obține token-ul de autentificare pentru API');
        }

        $args = [
            'timeout'   => $this->timeout,
            'headers'   => array_merge([
                'Accept'       => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'   => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ], $headers),
            'sslverify' => true,
            'redirection' => 1,
        ];

        $attempt = 0; $delay_ms = 200;
        do {
            $attempt++;
            $res = wp_remote_get($url, $args);
            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $raw  = wp_remote_retrieve_body($res);

                HGEZLPFCR_Logger::log('Old API GET response', [
                    'url' => $url,
                    'code' => $code,
                    'content_type' => wp_remote_retrieve_header($res, 'content-type'),
                    'body_length' => strlen($raw)
                ]);

                // Handle token expiration (401 Unauthorized)
                if ($code === 401) {
                    HGEZLPFCR_Logger::log('Old API token expired, clearing and retrying', ['attempt' => $attempt]);
                    $this->clear_old_api_token();

                    // Try to get a new token
                    $new_token = $this->get_old_api_token();
                    if ($new_token && $attempt <= $this->retries) {
                        // Update token in args and retry
                        $args['headers']['Authorization'] = 'Bearer ' . $new_token;
                        continue;
                    } else {
                        return new WP_Error('hgezlpfcr_auth_failed', 'Token expired and could not be regenerated. Please verify credentials (username/password).', ['code'=>$code]);
                    }
                }

                if ($code >= 200 && $code < 300) {
                    // Try to decode as JSON first
                    $data = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $data;
                    } else {
                        // Return raw response for non-JSON responses (like PDF)
                        return ['raw_response' => $raw, 'content_type' => wp_remote_retrieve_header($res, 'content-type')];
                    }
                }
                // 429/5xx => retry
                if (!in_array($code, [429,500,502,503,504], true)) {
                    return new WP_Error('hgezlpfcr_http_'.$code, 'Eroare API FanCourier', ['code'=>$code, 'raw_response' => substr($raw, 0, 500)]);
                }
                HGEZLPFCR_Logger::error('Old API transient error, retrying', ['code'=>$code, 'attempt'=>$attempt]);
            } else {
                HGEZLPFCR_Logger::error('Old API HTTP error', ['err'=>$res->get_error_message(), 'attempt'=>$attempt]);
            }
            if ($attempt <= $this->retries) usleep($delay_ms * 1000);
            $delay_ms = min($delay_ms * 2, 2000);
        } while ($attempt <= $this->retries);

        return is_wp_error($res) ? $res : new WP_Error('hgezlpfcr_api_failed', 'Could not communicate with FanCourier API after retry.');
    }

    /**
     * POST request for old API endpoints (uses username/password authentication)
     */
    protected function post_old_api(string $url, array $body, array $headers = [], ?string $idempotency_key = null) {
        // Get old API authentication token
        $token = $this->get_old_api_token();
        if (!$token) {
            HGEZLPFCR_Logger::error('Could not get old API authentication token');
            return new WP_Error('hgezlpfcr_auth_failed', 'Nu s-a putut obține token-ul de autentificare pentru API');
        }

        $args = [
            'timeout'   => $this->timeout,
            'headers'   => array_merge([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'   => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ], $headers),
            'sslverify' => true,
            'redirection' => 1,
            'body'      => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
        if ($idempotency_key) {
            $args['headers']['Idempotency-Key'] = $idempotency_key;
            $args['headers']['X-Request-ID']    = $idempotency_key;
        }

        $attempt = 0; $delay_ms = 200;
        do {
            $attempt++;
            $res = wp_remote_post($url, $args);
            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $raw  = wp_remote_retrieve_body($res);
                $data = json_decode($raw, true);

                // Handle token expiration (401 Unauthorized)
                if ($code === 401) {
                    HGEZLPFCR_Logger::log('Old API token expired, clearing and retrying', ['attempt' => $attempt]);
                    $this->clear_old_api_token();

                    // Try to get a new token
                    $new_token = $this->get_old_api_token();
                    if ($new_token && $attempt <= $this->retries) {
                        // Update token and retry
                        $args['headers']['Authorization'] = 'Bearer ' . $new_token;
                        continue;
                    } else {
                        return new WP_Error('hgezlpfcr_auth_failed', 'Token-ul a expirat și nu s-a putut regenera. Verificați credențialele (username/parolă).', ['response' => $data, 'code'=>$code]);
                    }
                }

                if ($code >= 200 && $code < 300 && is_array($data)) {
                    return $data;
                }
                // 429/5xx => retry
                if (!in_array($code, [429,500,502,503,504], true)) {
                    return new WP_Error('hgezlpfcr_http_'.$code, 'FanCourier API Error', ['response' => $data, 'code'=>$code]);
                }
                HGEZLPFCR_Logger::error('Old API transient error, retrying', ['code'=>$code, 'attempt'=>$attempt]);
            } else {
                HGEZLPFCR_Logger::error('Old API HTTP error', ['err'=>$res->get_error_message(), 'attempt'=>$attempt]);
            }
            if ($attempt <= $this->retries) usleep($delay_ms * 1000);
            $delay_ms = min($delay_ms * 2, 2000);
        } while ($attempt <= $this->retries);

        return is_wp_error($res) ? $res : new WP_Error('hgezlpfcr_api_failed', 'Could not communicate with FanCourier API after retry.');
    }

    /** Load cached token from WordPress options */
    protected function load_cached_token() {
        $this->token = get_option('hgezlpfcr_api_token', null);
        $this->token_expires = get_option('hgezlpfcr_api_token_expires', null);
        
        // Check if token is expired or will expire in next 5 minutes
        if ($this->token && $this->token_expires) {
            $expires_time = strtotime($this->token_expires);
            if ($expires_time && $expires_time > (time() + 300)) { // 5 minutes buffer
                return true; // Token is valid
            }
        }
        
        // Token is expired or doesn't exist, clear it
        $this->token = null;
        $this->token_expires = null;
        return false;
    }

    /** Save token to WordPress options */
    protected function save_token($token, $expires_at) {
        $this->token = $token;
        $this->token_expires = $expires_at;
        
        update_option('hgezlpfcr_api_token', $token);
        update_option('hgezlpfcr_api_token_expires', $expires_at);
        
        HGEZLPFCR_Logger::log('API token saved', [
            'token' => substr($token, 0, 10) . '...',
            'expires_at' => $expires_at
        ]);
    }

    /** Clear cached token */
    protected function clear_token() {
        $this->token = null;
        $this->token_expires = null;
        
        delete_option('hgezlpfcr_api_token');
        delete_option('hgezlpfcr_api_token_expires');
        
        HGEZLPFCR_Logger::log('API token cleared');
    }

    /** Get authentication token (generate if needed) */
    protected function get_auth_token() {
        // Check if we have a valid cached token
        if ($this->load_cached_token()) {
            return $this->token;
        }

        // Generate new token
        return $this->generate_auth_token();
    }

    /** Generate new authentication token */
    protected function generate_auth_token() {
        HGEZLPFCR_Logger::log('Generating new eCommerce API token');

        // Use eCommerce API authShop endpoint
        $url = 'https://ecommerce.fancourier.ro/authShop';
        $domain = site_url();

        $args = [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ],
            'body' => http_build_query(['domain' => $domain]),
            'sslverify' => true
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            HGEZLPFCR_Logger::error('Token generation failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        HGEZLPFCR_Logger::log('Token generation response', [
            'code' => $code,
            'url' => $url,
            'domain' => $domain,
            'response' => $data
        ]);

        // eCommerce API returns {"token": "xxx"} directly
        if ($code === 200 && isset($data['token'])) {
            $token = $data['token'];

            // Set expiration to 24 hours from now (eCommerce tokens don't expire quickly)
            $expires_at = gmdate('Y-m-d H:i:s', time() + (24 * 3600));

            $this->save_token($token, $expires_at);
            return $token;
        }

        HGEZLPFCR_Logger::error('Token generation failed - invalid response', [
            'code' => $code,
            'response' => $data
        ]);

        return false;
    }

    /**
     * Get authentication token for old API (username/password based)
     * Used for AWB generation, PDF download, status check, etc.
     */
    protected function get_old_api_token() {
        // Check if we have valid credentials
        if (empty($this->user) || empty($this->pass)) {
            HGEZLPFCR_Logger::error('Old API credentials not configured');
            return false;
        }

        // Check cache first
        $cached_token = get_option('hgezlpfcr_old_api_token', null);
        $cached_expires = get_option('hgezlpfcr_old_api_token_expires', null);

        if ($cached_token && $cached_expires) {
            $expires_time = strtotime($cached_expires);
            if ($expires_time && $expires_time > (time() + 300)) { // 5 minutes buffer
                HGEZLPFCR_Logger::log('Using cached old API token');
                return $cached_token;
            }
        }

        HGEZLPFCR_Logger::log('Generating new old API token');

        // Use old API login endpoint
        $url = 'https://api.fancourier.ro/login';

        $args = [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'WooFanCourier/'.HGEZLPFCR_PLUGIN_VER.'; '. home_url(),
            ],
            'body' => wp_json_encode([
                'username' => $this->user,
                'password' => $this->pass
            ]),
            'sslverify' => true
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            HGEZLPFCR_Logger::error('Old API token generation failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        HGEZLPFCR_Logger::log('Old API token generation response', [
            'code' => $code,
            'url' => $url,
            'username' => $this->user,
            'response' => $data
        ]);

        // Old API returns nested structure: {"data": {"token": "xxx"}}
        if ($code === 200 && isset($data['data']['token'])) {
            $token = $data['data']['token'];

            // Set expiration to 24 hours from now
            $expires_at = gmdate('Y-m-d H:i:s', time() + (24 * 3600));

            update_option('hgezlpfcr_old_api_token', $token);
            update_option('hgezlpfcr_old_api_token_expires', $expires_at);

            HGEZLPFCR_Logger::log('Old API token saved', [
                'token' => substr($token, 0, 10) . '...',
                'expires_at' => $expires_at
            ]);

            return $token;
        }

        HGEZLPFCR_Logger::error('Old API token generation failed - invalid response', [
            'code' => $code,
            'response' => $data
        ]);

        return false;
    }

    /** Clear old API token cache */
    protected function clear_old_api_token() {
        delete_option('hgezlpfcr_old_api_token');
        delete_option('hgezlpfcr_old_api_token_expires');
        HGEZLPFCR_Logger::log('Old API token cleared');
    }

    public function create_awb(array $order_payload, string $idem_key) {
        $endpoint = 'https://api.fancourier.ro/intern-awb';
        return $this->post_old_api($endpoint, $order_payload, [], $idem_key);
    }

    public function get_awb_pdf(string $awb) {
        $client_id = HGEZLPFCR_Settings::get('hgezlpfcr_client', '');
        if (empty($client_id)) {
            HGEZLPFCR_Logger::error('Client ID not configured for PDF download', ['awb' => $awb]);
            return new WP_Error('fc_config_error', 'Client ID is not configured');
        }

        $endpoint = 'https://api.fancourier.ro/awb/label?' . http_build_query([
            'clientId' => $client_id,
            'awbs[]' => $awb,
            'awbs[]' => $awb, // Duplicate AWB to display twice
            'pdf' => '1',
            'format' => 'A4', // A4 format for page
            'dpi' => '300'
        ]);

        HGEZLPFCR_Logger::log('PDF download request with duplicate AWB', [
            'awb' => $awb,
            'client_id' => $client_id,
            'endpoint' => $endpoint
        ]);

        return $this->get_old_api($endpoint);
    }

    public function get_status(string $awb) {
        $client_id = HGEZLPFCR_Settings::get('hgezlpfcr_client', '');
        if (empty($client_id)) {
            HGEZLPFCR_Logger::error('Client ID not configured for status check', ['awb' => $awb]);
            return new WP_Error('fc_config_error', 'Client ID is not configured');
        }

        $endpoint = 'https://api.fancourier.ro/reports/awb/tracking?' . http_build_query([
            'clientId' => $client_id,
            'awb[]' => $awb
        ]);

        HGEZLPFCR_Logger::log('Status check request', [
            'awb' => $awb,
            'client_id' => $client_id,
            'endpoint' => $endpoint
        ]);

        return $this->get_old_api($endpoint);
    }
    
    /**
     * Check if AWB exists in FanCourier Borderou for specific date.
     * Returns true if AWB exists, false if not found, WP_Error on API errors.
     *
     * Optimized: caches borderou response per date (5 min transient) so
     * multiple check_awb_exists calls for the same date cost only 1 API request.
     */
    public function check_awb_exists(string $awb, string $generation_date = null) {
        $client_id = HGEZLPFCR_Settings::get('hgezlpfcr_client', '');
        if (empty($client_id)) {
            HGEZLPFCR_Logger::error('Client ID not configured for AWB existence check', ['awb' => $awb]);
            return new WP_Error('fc_config_error', 'Client ID is not configured');
        }

        // Use current date adjusted for FanCourier timezone if not provided
        if (empty($generation_date)) {
            $generation_date = gmdate('Y-m-d', time() + (3 * 3600));
        }

        // Normalize date to Y-m-d (the only format FanCourier API accepts)
        $normalized_date = gmdate('Y-m-d', strtotime($generation_date));
        if (!$normalized_date) {
            $normalized_date = $generation_date;
        }

        // Try to get the AWB set from transient cache first
        $awb_set = $this->get_borderou_awb_set($client_id, $normalized_date);

        // Cache miss or error — fetch from API
        if ($awb_set === null) {
            $awb_set = $this->fetch_and_cache_borderou($client_id, $normalized_date);
        }

        // API error propagation
        if (is_wp_error($awb_set)) {
            return $awb_set;
        }

        // Empty borderou (no AWBs for this date)
        if (empty($awb_set)) {
            HGEZLPFCR_Logger::log('AWB borderou check: no AWBs found for date', [
                'awb' => $awb,
                'date' => $normalized_date
            ]);
            return false;
        }

        // O(1) lookup in the pre-built set
        $found = isset($awb_set[strtoupper($awb)]);

        HGEZLPFCR_Logger::log('AWB borderou check result', [
            'awb' => $awb,
            'date' => $normalized_date,
            'found' => $found,
            'total_awbs_in_borderou' => count($awb_set)
        ]);

        return $found;
    }

    /**
     * Get cached AWB set for a given client + date.
     * Returns associative array (AWB => true) or null on cache miss.
     */
    private function get_borderou_awb_set(string $client_id, string $date) {
        $cache_key = 'hgezlpfcr_borderou_' . md5($client_id . '_' . $date);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            HGEZLPFCR_Logger::log('AWB borderou cache hit', ['date' => $date, 'count' => count($cached)]);
            return $cached;
        }
        return null;
    }

    /**
     * Fetch borderou from API, build an AWB lookup set, and cache it.
     * Returns associative array (AWB => true), empty array, or WP_Error.
     */
    private function fetch_and_cache_borderou(string $client_id, string $date) {
        $endpoint = 'https://api.fancourier.ro/reports/awb?' . http_build_query([
            'clientId' => $client_id,
            'date' => $date
        ]);

        HGEZLPFCR_Logger::log('AWB borderou API request', [
            'date' => $date,
            'endpoint' => $endpoint
        ]);

        $response = $this->get_old_api($endpoint);

        // Handle API errors
        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();

            // 404 / "no data" means no AWBs for this date — cache empty set
            if ($error_code === 'fc_http_404' ||
                strpos(strtolower($error_message), 'no data') !== false ||
                strpos(strtolower($error_message), 'without data') !== false) {
                $this->cache_borderou_set($client_id, $date, []);
                return [];
            }

            HGEZLPFCR_Logger::warning('AWB borderou API error', [
                'date' => $date,
                'error_code' => $error_code,
                'error_message' => $error_message
            ]);
            return $response;
        }

        // Empty or invalid response
        if (empty($response['data']) || !is_array($response['data'])) {
            HGEZLPFCR_Logger::log('AWB borderou: empty response data', ['date' => $date]);
            $this->cache_borderou_set($client_id, $date, []);
            return [];
        }

        // Build lookup set: uppercase AWB => true
        $awb_set = [];
        foreach ($response['data'] as $entry) {
            $entry_awb = $this->extract_awb_from_entry($entry);
            if ($entry_awb) {
                $awb_set[strtoupper($entry_awb)] = true;
            }
        }

        HGEZLPFCR_Logger::log('AWB borderou fetched and cached', [
            'date' => $date,
            'total_awbs' => count($awb_set)
        ]);

        $this->cache_borderou_set($client_id, $date, $awb_set);
        return $awb_set;
    }

    /**
     * Extract AWB number from a borderou entry, checking all known field names.
     */
    private function extract_awb_from_entry(array $entry): ?string {
        if (isset($entry['info']['awbNumber'])) {
            return (string) $entry['info']['awbNumber'];
        }
        foreach (['awb', 'awbNumber', 'awb_number', 'AWB', 'number', 'trackingNumber'] as $field) {
            if (isset($entry[$field]) && $entry[$field] !== '') {
                return (string) $entry[$field];
            }
        }
        return null;
    }

    /**
     * Store borderou AWB set in transient cache.
     */
    private function cache_borderou_set(string $client_id, string $date, array $awb_set): void {
        $cache_key = 'hgezlpfcr_borderou_' . md5($client_id . '_' . $date);
        set_transient($cache_key, $awb_set, 5 * MINUTE_IN_SECONDS);
    }
    
    public function get_tariff(array $params) {
        // Use eCommerce API endpoint for tariff calculation
        $endpoint = 'https://ecommerce.fancourier.ro/get-tariff';

        // Map service name to serviceTypeId
        $service_map = [
            'Standard' => 1,
            'Cont Colector' => 4,
            'FANbox' => 27,
            'FANbox COD' => 28,
            'Express Loco' => 3,
            'Red Code' => 7,
        ];

        // Allow direct serviceTypeId to be passed (used by PRO plugin for extended services)
        if (isset($params['serviceTypeId']) && is_numeric($params['serviceTypeId'])) {
            $service_type_id = (int) $params['serviceTypeId'];
        } else {
            $service_name = $params['service'] ?? 'Standard';
            $service_type_id = $service_map[$service_name] ?? 1;
        }

        // Build eCommerce API request body
        $body = [
            'serviceTypeId' => $service_type_id,
            'recipientCounty' => $params['county'] ?? '',
            'recipientLocality' => $params['locality'] ?? '',
            'weight' => $params['weight'] ?? 1,
            'length' => $params['length'] ?? 1,
            'width' => $params['width'] ?? 1,
            'height' => $params['height'] ?? 1,
        ];

        HGEZLPFCR_Logger::log('Get tariff request', [
            'endpoint' => $endpoint,
            'service' => $service_name,
            'params' => $body
        ]);

        $response = $this->post_form($endpoint, $body);

        // Parse response - eCommerce API returns JSON with tariff
        if (is_wp_error($response)) {
            return $response;
        }

        // Response format: {"tariff": 12.34}
        if (isset($response['tariff'])) {
            return ['price' => (float) $response['tariff']];
        }

        // If raw response, try to decode
        if (isset($response['raw'])) {
            $data = json_decode($response['raw'], true);
            if ($data && isset($data['tariff'])) {
                return ['price' => (float) $data['tariff']];
            }
        }

        HGEZLPFCR_Logger::error('Invalid tariff response', ['response' => $response]);
        return new WP_Error('fc_tariff_error', 'Invalid response from API for tariff');
    }
    
    public function check_service(array $params) {
        // Use eCommerce API endpoint for service check
        $endpoint = 'https://ecommerce.fancourier.ro/check-service';

        // Map service name to serviceTypeId
        $service_map = [
            'Standard' => 1,
            'Cont Colector' => 4,
            'FANbox' => 27,
            'FANbox COD' => 28,
            'Express Loco' => 3,
            'Red Code' => 7,
        ];

        // Allow direct serviceTypeId to be passed (used by PRO plugin for extended services)
        if (isset($params['serviceTypeId']) && is_numeric($params['serviceTypeId'])) {
            $service_type_id = (int) $params['serviceTypeId'];
        } else {
            $service_name = $params['service'] ?? 'Standard';
            $service_type_id = $service_map[$service_name] ?? 1;
        }

        // Build eCommerce API request body
        $body = [
            'serviceTypeId' => $service_type_id,
            'recipientCounty' => $params['county'] ?? '',
            'recipientLocality' => $params['locality'] ?? '',
            'weight' => $params['weight'] ?? 1,
            'packageLength' => $params['length'] ?? 1,
            'packageWidth' => $params['width'] ?? 1,
            'packageHeight' => $params['height'] ?? 1,
        ];

        HGEZLPFCR_Logger::log('Check service request', [
            'endpoint' => $endpoint,
            'service' => $service_name,
            'params' => $body
        ]);

        $response = $this->post_form($endpoint, $body);

        // Parse response - eCommerce API returns "1" for available, "0" for not available
        if (is_wp_error($response)) {
            return $response;
        }

        $is_available = false;
        if (isset($response['available']) && $response['available']) {
            $is_available = true;
        } elseif (isset($response['raw']) && $response['raw'] == '1') {
            $is_available = true;
        }

        return ['available' => $is_available];
    }
    
    public function ping() {
        $token = $this->get_auth_token();
        if ($token) {
            return ['status' => 'success', 'message' => 'Authentication successful'];
        } else {
            return new WP_Error('hgezlpfcr_auth_failed', 'Authentication failed');
        }
    }
}
