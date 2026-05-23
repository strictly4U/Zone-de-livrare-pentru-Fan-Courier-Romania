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
                    // FC's /check-service endpoint returns just "1" (available)
                    // or "0" (not available) as the response body. json_decode
                    // happily parses "1" as the integer 1 (valid JSON!) and
                    // json_last_error() returns JSON_ERROR_NONE — so a naive
                    // "decode + return" would yield an int that callers expecting
                    // an array misinterpret as ['available' => null]. Treat any
                    // non-array decode the same as a non-JSON response: wrap it
                    // with the raw body + a parsed 'available' flag so callers
                    // get a consistent shape. (Pre-1.0.13 silent bug — surfaced
                    // once 1nq made check-service actually reach the API with
                    // valid county/locality values.)
                    $data = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        return $data;
                    }
                    return ['raw' => $raw, 'available' => $raw == '1'];
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
    
    /**
     * Calculate tariff for an internal (Romania) shipment.
     *
     * Since 1.0.13 this targets the OFFICIAL FAN Courier reports endpoint
     * at https://api.fancourier.ro/reports/awb/internal-tariff — the legacy
     * https://ecommerce.fancourier.ro/get-tariff(-new) endpoints are
     * deprecated server-side and return HTTP 500 for every request on
     * production accounts (confirmed by ~6000-line dev1 log on 2026-05-23
     * with zero successful tariff responses across both endpoints).
     *
     * Auth flow: Bearer token from /login (api.fancourier.ro), same token
     * already used for AWB generation. clientId comes from the
     * hgezlpfcr_client setting.
     *
     * Request shape: POST with JSON body. FC's Postman docs show GET
     * with a JSON body, but WordPress's HTTP transport cannot send that
     * combination cleanly (GET+body=string fatals in WpOrg\Requests\
     * Transport\Curl::format_get; GET+body=array silently drops the body
     * via http_build_query without producing a response — confirmed
     * twice on dev1). FC's Laravel-based router accepts POST for these
     * endpoints in practice, and POST+JSON is the verb every REST tool
     * actually uses on the FC support channels.
     *
     * Body (JSON):
     *   {
     *     "clientId": 7032158,
     *     "info": {
     *       "service": "Standard",
     *       "payment": "expeditor",
     *       "weight": 1,
     *       "options": [],
     *       "dimensions": {"length": 30, "width": 20, "height": 10},
     *       "packages": {"parcel": 1, "envelope": 0},
     *       "declaredValue": null
     *     },
     *     "recipient": {"locality": "Bucuresti", "county": "Bucuresti"}
     *   }
     *
     * Service names accepted by FC (caller passes via $params['service']):
     *   "Standard", "Cont Colector", "FANbox", "Express Loco", "RedCode",
     *   "Collect Point PayPoint", "Collect Point OMV", "Produse Albe".
     *   Legacy callers passing $params['serviceTypeId'] (numeric) are
     *   mapped back via $service_id_to_name; unknown ids fall through to
     *   the supplied $params['service'] string.
     *
     * @param array $params service|serviceTypeId, county, locality, weight,
     *                      length, width, height, declared_value (optional)
     * @return array|WP_Error On success: ['price' => float].
     *                        WP_Error on auth, transport, 4xx, or parse.
     * @since 1.0.13 — endpoint migration
     */
    public function get_tariff(array $params) {
        $endpoint = 'https://api.fancourier.ro/reports/awb/internal-tariff';

        // Resolve service name. Prefer the caller-supplied string; if only
        // a numeric serviceTypeId was passed (legacy callers), map it.
        //
        // Authoritative source: HGEZLPFCR_Pro_API::get_service_map() — the
        // PRO plugin owns the canonical service list and IDs because PRO
        // touches the extended services (Express Loco, RedCode, etc.) that
        // Standard alone doesn't expose. Keep these two maps in sync.
        // COD-variant IDs collapse to the same service-name string (COD is
        // a payment option on FC's side, not a separate service).
        $service_id_to_name = [
            1  => 'Standard',
            2  => 'RedCode',
            3  => 'Export',
            4  => 'Cont Colector',
            5  => 'Express Loco',
            6  => 'Collect Point OMV',
            7  => 'Collect Point PayPoint',
            9  => 'RedCode',                 // COD variant of 2
            10 => 'Express Loco',            // COD variant of 5
            11 => 'Collect Point OMV',       // COD variant of 6
            12 => 'Collect Point PayPoint',  // COD variant of 7
            13 => 'Produse Albe',
            14 => 'Produse Albe',            // COD variant of 13
            27 => 'FANbox',
            28 => 'FANbox',                  // COD variant of 27
        ];
        if (!empty($params['service'])) {
            $service_name = (string) $params['service'];
        } elseif (isset($params['serviceTypeId']) && is_numeric($params['serviceTypeId'])) {
            $sid = (int) $params['serviceTypeId'];
            $service_name = $service_id_to_name[$sid] ?? 'Standard';
        } else {
            $service_name = 'Standard';
        }

        // Auth via the official /login Bearer token (same flow as AWB).
        $token = $this->get_old_api_token();
        if (!$token) {
            HGEZLPFCR_Logger::error('Get tariff: could not obtain auth token', [
                'has_user' => !empty($this->user),
                'has_pass' => !empty($this->pass),
            ]);
            return new WP_Error(
                'hgezlpfcr_auth_failed',
                __('Could not authenticate with FAN Courier (verify username/password in Settings).', 'hge-zone-de-livrare-pentru-fan-courier-romania')
            );
        }

        // clientId is REQUIRED on the reports endpoint.
        $client_id = (int) HGEZLPFCR_Settings::get('hgezlpfcr_client', 0);
        if ($client_id <= 0) {
            HGEZLPFCR_Logger::error('Get tariff: hgezlpfcr_client (Client ID) not configured');
            return new WP_Error(
                'hgezlpfcr_missing_client_id',
                __('FAN Courier Client ID is not configured. Set it in WooCommerce > Settings > FAN Courier.', 'hge-zone-de-livrare-pentru-fan-courier-romania')
            );
        }

        // Defensive: bail out before hitting the API when the destination
        // is incomplete. The shipping-method calculator already guards
        // against this, but other callers (Pro FANBox cookie path, future
        // third-party integrations) may invoke get_tariff() directly. FC
        // would respond with HTTP 422 "Perechea judet-localitate
        // introdusa este incorecta" anyway — surface a clearer WP_Error
        // and skip the 4-second retry burn.
        $county_raw   = trim((string) ($params['county']   ?? ''));
        $locality_raw = trim((string) ($params['locality'] ?? ''));
        if ($county_raw === '' || $locality_raw === '') {
            return new WP_Error(
                'hgezlpfcr_missing_destination',
                __('County or locality missing from tariff request.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                ['county' => $county_raw, 'locality' => $locality_raw]
            );
        }

        // Weight must be a positive integer — FC's API rejects sub-kg
        // floats with HTTP 500. The shipping-method calculator already
        // rounds, but be defensive in case a different caller passes a
        // float (e.g. PRO FANBox cookie-derived path).
        $weight = (int) round((float) ($params['weight'] ?? 1));
        if ($weight < 1) {
            $weight = 1;
        }

        // Declared value: null = no insurance (FC's "no declaration"
        // sentinel). Only send a positive number when set.
        $declared = isset($params['declared_value']) ? (float) $params['declared_value'] : 0.0;
        $declared_value = $declared > 0 ? $declared : null;

        $body_data = [
            'clientId' => $client_id,
            'info'     => [
                'service'       => $service_name,
                'payment'       => 'expeditor', // RO term per FC docs; sender pays for tariff queries
                'weight'        => $weight,
                'options'       => [],
                'dimensions'    => [
                    'length' => (int) ($params['length'] ?? 30),
                    'width'  => (int) ($params['width']  ?? 20),
                    'height' => (int) ($params['height'] ?? 10),
                ],
                'packages'      => [
                    'parcel'   => 1,
                    'envelope' => 0,
                ],
                'declaredValue' => $declared_value,
            ],
            'recipient' => [
                'locality' => $locality_raw,
                'county'   => $county_raw,
            ],
        ];

        // Why cURL directly instead of wp_remote_*():
        //
        // FC's reports/awb/internal-tariff endpoint strictly requires
        // GET with a JSON body. The Laravel router explicitly responds
        // with HTTP 405 "The POST method is not supported. Supported
        // methods: GET, HEAD." for any other verb (confirmed empirically
        // on dev1 — see Standard 1.0.13 changelog).
        //
        // WordPress's HTTP abstraction cannot send GET+body cleanly:
        //   - body=string + method=GET  -> fatal in WpOrg\Requests\
        //     Transport\Curl::format_get() ("http_build_query(): Argument
        //     #1 must be of type array, string given").
        //   - body=array  + method=GET  -> WP silently converts to
        //     bracket-notation query string via http_build_query and
        //     drops the body; FC does not process the resulting URL and
        //     no response is logged.
        //   - method=POST + JSON body   -> HTTP 405 from FC.
        //
        // So we drop to ext-curl directly. This is the same transport WP
        // itself uses under the hood; we just keep control of the method
        // + body combination instead of letting WP's wrappers second-guess
        // us. Implementation is intentionally minimal — auth, SSL, and
        // headers are explicit, and the response shape mirrors what
        // wp_remote_retrieve_* gives us so the parsing code below didn't
        // need to change.
        $json_body = (string) wp_json_encode($body_data, JSON_UNESCAPED_UNICODE);
        $headers_list = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WooFanCourier/' . HGEZLPFCR_PLUGIN_VER . '; ' . home_url(),
            'Content-Length: ' . strlen($json_body),
        ];

        HGEZLPFCR_Logger::log('Get tariff (reports endpoint) request', [
            'endpoint' => $endpoint,
            'service'  => $service_name,
            'body'     => $body_data,
        ]);

        $attempt  = 0;
        $delay_ms = 200;
        $response = null;

        if (!function_exists('curl_init')) {
            HGEZLPFCR_Logger::error('Get tariff: ext-curl not available');
            return new WP_Error(
                'hgezlpfcr_curl_unavailable',
                __('PHP cURL extension is required for FAN Courier tariff queries.', 'hge-zone-de-livrare-pentru-fan-courier-romania')
            );
        }

        do {
            $attempt++;
            $code     = 0;
            $raw      = '';
            $curl_err = '';
            $response = null; // reset stale WP_Error from previous iteration
            $ch       = null;

            // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close
            // ---------------------------------------------------------------
            // Direct ext-curl is REQUIRED here, not a preference.
            // FC's /reports/awb/internal-tariff endpoint accepts ONLY GET
            // (FC returns HTTP 405 for any other verb — verified on dev1)
            // and requires the params as a JSON body in that GET request.
            // WordPress's HTTP layer cannot send GET-with-body:
            //   - body=string + method=GET -> http_build_query fatal in
            //     WpOrg\Requests\Transport\Curl::format_get
            //   - body=array  + method=GET -> body silently dropped, no
            //     response surfaced (confirmed in dev1 logs line 6789)
            //   - method=POST              -> FC API returns 405
            // The ext-curl path below is the same transport WP uses
            // internally — we just retain manual control of the verb +
            // body combination.
            // ---------------------------------------------------------------
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $endpoint,
                    CURLOPT_CUSTOMREQUEST  => 'GET',     // FC requires GET
                    CURLOPT_POSTFIELDS     => $json_body, // body even though method=GET (FC's quirk)
                    CURLOPT_HTTPHEADER     => $headers_list,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $raw      = (string) curl_exec($ch);
                $code     = (int)    curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err = (string) curl_error($ch);
                curl_close($ch);
            } catch (\Throwable $e) {
                HGEZLPFCR_Logger::error('Reports API threw exception', [
                    'class'   => get_class($e),
                    'message' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
                if ($ch !== null) { @curl_close($ch); }
                $response = new WP_Error('hgezlpfcr_tariff_exception', $e->getMessage());
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close

            if (!isset($response) || !is_wp_error($response)) {
                if ($curl_err !== '') {
                    HGEZLPFCR_Logger::error('Reports API cURL error', [
                        'err'     => $curl_err,
                        'attempt' => $attempt,
                    ]);
                    $response = new WP_Error('hgezlpfcr_tariff_curl', $curl_err);
                }
            }

            if (is_wp_error($response)) {
                HGEZLPFCR_Logger::error('Reports API HTTP error', [
                    'err'     => $response->get_error_message(),
                    'attempt' => $attempt,
                ]);
            } else {
                HGEZLPFCR_Logger::log('Reports API response', [
                    'url'  => $endpoint,
                    'code' => $code,
                    'raw'  => mb_strlen($raw) > 500 ? mb_substr($raw, 0, 500) . '…' : $raw,
                ]);

                // 401 — token expired; refresh once and retry.
                if ($code === 401 && $attempt <= $this->retries) {
                    delete_option('hgezlpfcr_old_api_token');
                    delete_option('hgezlpfcr_old_api_token_expires');
                    $token = $this->get_old_api_token();
                    if ($token) {
                        // Rewrite the Authorization line in the headers list
                        foreach ($headers_list as $i => $h) {
                            if (stripos($h, 'Authorization:') === 0) {
                                $headers_list[$i] = 'Authorization: Bearer ' . $token;
                                break;
                            }
                        }
                        $response = null; // reset for next loop iteration
                        continue;
                    }
                    return new WP_Error('hgezlpfcr_auth_failed', __('Token expired and could not be regenerated.', 'hge-zone-de-livrare-pentru-fan-courier-romania'), ['code' => $code]);
                }

                if ($code >= 200 && $code < 300) {
                    $data = json_decode($raw, true);

                    // FC's reports endpoints typically wrap payload in
                    // {"data": ...}. Walk the common shapes defensively.
                    $tariff = null;
                    if (is_array($data)) {
                        // Shape A: {"data": {"tariff": X}} or {"data": {"price": X}}
                        if (isset($data['data']) && is_array($data['data'])) {
                            foreach (['tariff', 'price', 'total', 'value'] as $k) {
                                if (isset($data['data'][$k]) && is_numeric($data['data'][$k])) {
                                    $tariff = (float) $data['data'][$k];
                                    break;
                                }
                            }
                            // Shape A nested: {"data": {"info": {"tariff": X}}}
                            if ($tariff === null && isset($data['data']['info']) && is_array($data['data']['info'])) {
                                foreach (['tariff', 'price', 'total'] as $k) {
                                    if (isset($data['data']['info'][$k]) && is_numeric($data['data']['info'][$k])) {
                                        $tariff = (float) $data['data']['info'][$k];
                                        break;
                                    }
                                }
                            }
                        }
                        // Shape B (flat): {"tariff": X} or {"price": X}
                        if ($tariff === null) {
                            foreach (['tariff', 'price', 'total', 'value'] as $k) {
                                if (isset($data[$k]) && is_numeric($data[$k])) {
                                    $tariff = (float) $data[$k];
                                    break;
                                }
                            }
                        }
                    } elseif (is_numeric($data)) {
                        // Shape C: a bare number (FC's endpoint sometimes
                        // returns raw scalars for legacy tariff calls).
                        $tariff = (float) $data;
                    }

                    if ($tariff !== null && $tariff > 0) {
                        return ['price' => $tariff];
                    }

                    HGEZLPFCR_Logger::error('Tariff response parsed but no price field found', [
                        'parsed' => $data,
                    ]);
                    return new WP_Error(
                        'hgezlpfcr_tariff_parse_failed',
                        __('Could not extract tariff price from FAN Courier response.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                        ['raw' => $raw]
                    );
                }

                // 4xx — service typically not available for this destination,
                // or bad request body. Don't retry; surface as WP_Error so
                // the wrapper marks the destination unavailable.
                if ($code >= 400 && $code < 500) {
                    $error_msg = '';
                    $data = json_decode($raw, true);
                    if (is_array($data)) {
                        if (isset($data['message']) && is_string($data['message'])) {
                            $error_msg = $data['message'];
                        } elseif (isset($data['errors']) && is_string($data['errors'])) {
                            $error_msg = $data['errors'];
                        }
                    }
                    return new WP_Error(
                        'hgezlpfcr_tariff_unavailable',
                        $error_msg !== ''
                            ? $error_msg
                            : sprintf(/* translators: %d HTTP code */ __('FAN Courier rejected the tariff request (HTTP %d).', 'hge-zone-de-livrare-pentru-fan-courier-romania'), $code),
                        ['code' => $code, 'raw' => $raw]
                    );
                }

                // 429 / 5xx — transient; retry with backoff.
                HGEZLPFCR_Logger::error('Reports API transient error, retrying', [
                    'code'    => $code,
                    'attempt' => $attempt,
                ]);
            }

            if ($attempt <= $this->retries) {
                usleep($delay_ms * 1000);
                $delay_ms = min($delay_ms * 2, 2000);
            }
        } while ($attempt <= $this->retries);

        return is_wp_error($response)
            ? $response
            : new WP_Error('hgezlpfcr_tariff_failed', __('Could not communicate with FAN Courier reports API after retry.', 'hge-zone-de-livrare-pentru-fan-courier-romania'));
    }
    
    public function check_service(array $params) {
        // Use eCommerce API endpoint for service check
        $endpoint = 'https://ecommerce.fancourier.ro/check-service';

        // Map service name to serviceTypeId.
        // Kept aligned with HGEZLPFCR_Pro_API::get_service_map() (the
        // canonical source). Standard 1.0.12 carried stale Express Loco=3
        // (actually Export) and "Red Code"=7 (actually Collect Point
        // PayPoint) values that quietly drifted apart; rewriting the map
        // here so the legacy /check-service endpoint, if still reachable,
        // calls the right service ID.
        $service_map = [
            'Standard'               => 1,
            'RedCode'                => 2,
            'Export'                 => 3,
            'Cont Colector'          => 4,
            'Express Loco'           => 5,
            'Collect Point OMV'      => 6,
            'Collect Point PayPoint' => 7,
            'Produse Albe'           => 13,
            'FANbox'                 => 27,
            'FANbox COD'             => 28,
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

    /**
     * @deprecated 1.0.13 The ecommerce.fancourier.ro/get-tariff-new
     * endpoint returns HTTP 500 across all production accounts (confirmed
     * empirically — see commit message and changelog for 1.0.13). All
     * tariff queries now route through get_tariff(), which targets the
     * official api.fancourier.ro/reports/awb/internal-tariff endpoint.
     *
     * This method is preserved as a thin shim so any third-party code
     * still calling it continues to work — it just forwards to get_tariff()
     * and adapts the return shape (the old method returned 'tariff' +
     * 'extra_km_cost', the new endpoint returns just 'price'). extraKmCost
     * is no longer available; callers depending on it should be migrated.
     *
     * @param array $params see get_tariff()
     * @return array|WP_Error ['tariff' => float, 'extra_km_cost' => null] on success
     */
    public function get_tariff_new(array $params) {
        HGEZLPFCR_Logger::log('get_tariff_new() called — deprecated since 1.0.13, forwarding to get_tariff()');
        $result = $this->get_tariff($params);
        if (is_wp_error($result)) {
            return $result;
        }
        return [
            'tariff'        => (float) ($result['price'] ?? 0),
            'extra_km_cost' => null,
        ];
    }

    /**
     * Combined availability + tariff lookup with 5-minute transient cache.
     *
     * Mirror of the FC Pro pattern at class-hgezlpfcr-pro-shipping-base.php:454-489.
     * Reduces the per-package checkout cost from 1 sync HTTP call (~2-3s
     * post-migration) to a single transient read (~1ms) on cache hit.
     * On cache miss runs a single call to the official reports endpoint
     * via get_tariff() and caches the combined result for 5 minutes —
     * including the "not available" outcome, which prevents API stampede
     * for unsupported destinations.
     *
     * Cache key is hgezlpfcr_twa_ + md5(service|county|locality|weight_bucket).
     * Weight bucket = round(weight*2)/2 (0.5kg granularity). Since 1.0.13
     * the upstream weight from calculate_package_weight() is always an
     * integer (1, 2, 3, ...), so the bucket math reduces to a no-op — kept
     * as-is for compat with any caller that still passes a float weight.
     * cache bucket (1.5kg). Identical key shape to FC Pro for future
     * extraction into a shared helper.
     *
     * @param array $params Same shape as check_service() + get_tariff(): service, county, locality, weight, length, width, height, declared_value.
     * @return array{available: bool, price: float, error: string|null}
     * @since 1.0.13
     */
    public function get_tariff_with_availability_cached(array $params): array {
        $weight_rounded = round(((float) ($params['weight'] ?? 0)) * 2) / 2;
        $cache_key = 'hgezlpfcr_twa_' . md5(
            ($params['service']  ?? 'Standard') . '|' .
            ($params['county']   ?? '') . '|' .
            ($params['locality'] ?? '') . '|' .
            $weight_rounded
        );

        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            HGEZLPFCR_Logger::log('Tariff with availability — cache HIT', [
                'service'   => $params['service'] ?? 'Standard',
                'available' => $cached['available'] ?? false,
                'price'     => $cached['price'] ?? 0,
            ]);
            return $cached;
        }

        // Cache MISS — single call to the FC reports endpoint via get_tariff().
        // Since 1.0.13 get_tariff() targets api.fancourier.ro/reports/awb/internal-tariff
        // (the official current endpoint). The legacy 2-step pattern
        // (check_service + get_tariff on ecommerce.fancourier.ro) has been
        // removed entirely — that endpoint family returns HTTP 500 on
        // production accounts and is effectively deprecated.
        //
        // A 4xx from the new endpoint means the destination is not
        // serviceable (FC's own "service not available" signal); a 5xx
        // means a real transient outage (already retried inside
        // get_tariff()). Either way, no point burning extra calls.
        $tariff = $this->get_tariff($params);

        if (!is_wp_error($tariff) && isset($tariff['price']) && $tariff['price'] > 0) {
            $result = [
                'available' => true,
                'price'     => (float) $tariff['price'],
                'error'     => null,
            ];
        } else {
            $result = [
                'available' => false,
                'price'     => 0.0,
                'error'     => is_wp_error($tariff) ? $tariff->get_error_message() : 'No tariff returned',
            ];
        }

        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);

        HGEZLPFCR_Logger::log('Tariff with availability — cache MISS (fresh fetch + cached 5 min)', [
            'service'   => $params['service'] ?? 'Standard',
            'available' => $result['available'],
            'price'     => $result['price'],
            'error'     => $result['error'],
        ]);

        return $result;
    }

    /**
     * Validate the configured (or supplied) Old API credentials by POSTing
     * them to https://api.fancourier.ro/login and checking whether FC
     * issues a token. This is the same endpoint that AWB generation uses,
     * so a green light here means the user is safe at AWB time.
     *
     * eCommerce API auth (authShop) uses the WP site_url() as identity
     * and does NOT rely on username/password — it cannot detect a wrong
     * username. So tariff/check-service can keep working while AWB
     * generation silently breaks. Pre-1.0.13 the plugin only discovered
     * bad credentials at the first AWB generation, which is far too late.
     *
     * Designed to be safe to call from an update_option hook:
     *  - 8-second timeout (admin save stays responsive)
     *  - Network failures are reported as 'unknown' (not 'invalid') so a
     *    transient outage doesn't blame the user
     *  - Returns a structured result for the caller to persist + surface
     *
     * @param string|null $username Override (default: hgezlpfcr_user option)
     * @param string|null $password Override (default: hgezlpfcr_pass option)
     * @return array {
     *     @type bool|null $valid true=verified ok, false=rejected by API,
     *                            null=could not determine (network/5xx)
     *     @type int       $code  HTTP code returned (0 if no response)
     *     @type string    $error Empty when $valid=true; otherwise a short
     *                            human-readable reason
     * }
     * @since 1.0.13
     */
    public function verify_old_api_credentials($username = null, $password = null): array {
        $user = $username !== null ? (string) $username : (string) $this->user;
        $pass = $password !== null ? (string) $password : (string) $this->pass;

        if ($user === '' || $pass === '') {
            return [
                'valid' => false,
                'code'  => 0,
                'error' => __('Username or password is empty.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ];
        }

        $args = [
            'timeout' => 8,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'WooFanCourier/' . HGEZLPFCR_PLUGIN_VER . '; ' . home_url(),
            ],
            'body'      => wp_json_encode([
                'username' => $user,
                'password' => $pass,
            ]),
            'sslverify' => true,
        ];

        $response = wp_remote_post('https://api.fancourier.ro/login', $args);

        if (is_wp_error($response)) {
            return [
                'valid' => null,
                'code'  => 0,
                'error' => sprintf(
                    /* translators: %s: WP HTTP error message */
                    __('Could not reach FAN Courier API: %s', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                    $response->get_error_message()
                ),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code === 200 && is_array($data) && !empty($data['data']['token'])) {
            return [
                'valid' => true,
                'code'  => 200,
                'error' => '',
            ];
        }

        if ($code === 401 || $code === 403) {
            return [
                'valid' => false,
                'code'  => $code,
                'error' => __('Username or password rejected by FAN Courier.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ];
        }

        if ($code >= 500 && $code < 600) {
            return [
                'valid' => null,
                'code'  => $code,
                'error' => sprintf(
                    /* translators: %d: HTTP status code */
                    __('FAN Courier API temporarily unavailable (HTTP %d). Will retry on next save.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                    $code
                ),
            ];
        }

        // Anything else (400, unexpected JSON, etc.) — treat as invalid + report code
        return [
            'valid' => false,
            'code'  => $code,
            'error' => sprintf(
                /* translators: %d: HTTP status code */
                __('Unexpected response from FAN Courier (HTTP %d).', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                $code
            ),
        ];
    }

    /**
     * Hook target for credential changes. Re-validates against the Old API
     * and stores the verdict so the admin notice + Settings page can
     * surface it. Also clears the tariff cache (existing behaviour kept).
     *
     * Idempotent — safe to call multiple times. Skips when the credentials
     * didn't actually change.
     *
     * @since 1.0.13
     */
    public static function on_credentials_changed($old_value, $new_value): void {
        if ((string) $old_value === (string) $new_value) {
            return;
        }

        self::clear_tariff_cache();

        // Drop both cached auth tokens so the new credentials get a fresh
        // round-trip the next time anything tries to use them:
        //   - Old API token (api.fancourier.ro/login) — used for AWB
        //     generation, tracking, PDF download.
        //   - eCommerce API token (ecommerce.fancourier.ro/authShop) —
        //     used for tariff queries, service availability checks. This
        //     one is domain-based (no username/password sent), but a
        //     credential change is the strongest signal we have that the
        //     admin is re-binding the install to a different FC account,
        //     so a stale 24-hour-cached token from the old account would
        //     be misleading.
        delete_option('hgezlpfcr_old_api_token');
        delete_option('hgezlpfcr_old_api_token_expires');
        delete_option('hgezlpfcr_api_token');
        delete_option('hgezlpfcr_api_token_expires');

        $api    = new self();
        $result = $api->verify_old_api_credentials();

        update_option('hgezlpfcr_credentials_valid', $result['valid'] === true
            ? 'yes'
            : ($result['valid'] === false ? 'no' : 'unknown'));
        update_option('hgezlpfcr_credentials_checked_at', time());
        update_option('hgezlpfcr_credentials_last_error', (string) $result['error']);
        update_option('hgezlpfcr_credentials_last_code', (int) $result['code']);

        // One-shot transient so the next admin page load can show a fresh
        // success/failure notice right above the WC settings form, in
        // addition to the persistent banner from admin_notices.
        set_transient('hgezlpfcr_credentials_save_notice', [
            'valid' => $result['valid'],
            'error' => $result['error'],
        ], 30);

        HGEZLPFCR_Logger::log('Credentials revalidated after change', [
            'valid' => $result['valid'],
            'code'  => $result['code'],
            'error' => $result['error'],
        ]);
    }

    /**
     * Wipe every transient produced by get_tariff_with_availability_cached().
     * Used by the credential-change auto-invalidation hook below and by the
     * manual admin-post handler.
     *
     * @return int Number of transient rows deleted (best-effort).
     * @since 1.0.13
     */
    public static function clear_tariff_cache(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- transient-by-prefix sweep; WP API has no parameterised LIKE-prefix delete for transients.
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hgezlpfcr_twa_%' OR option_name LIKE '_transient_timeout_hgezlpfcr_twa_%'"
        );
        HGEZLPFCR_Logger::log('Tariff cache cleared', ['rows_deleted' => (int) $deleted]);
        return (int) $deleted;
    }
}

// -----------------------------------------------------------------------------
// Credential-change hooks (since 1.0.13)
//
// On any user/pass change:
//   1. Wipe cached tariffs (they're keyed on destination + weight, not on
//      credentials, but a credential change typically means a different
//      FC account / sandbox switch and stale cache could mask config
//      mistakes).
//   2. Drop the cached Old API token — new credentials need a fresh
//      /login round-trip.
//   3. Validate the new credentials against /login (Old API). The
//      eCommerce API uses domain-based auth and CANNOT detect a wrong
//      username, so the plugin used to discover bad credentials only on
//      first AWB generation — far too late. We now save the verdict in
//      options + show a persistent admin notice when invalid.
//
// Each Settings field is stored as its own option, so we hook the
// per-option update_option_<key> action for both halves of the pair.
// -----------------------------------------------------------------------------
add_action('update_option_hgezlpfcr_user', [HGEZLPFCR_API_Client::class, 'on_credentials_changed'], 10, 2);
add_action('update_option_hgezlpfcr_pass', [HGEZLPFCR_API_Client::class, 'on_credentials_changed'], 10, 2);

// First-write variants — update_option_<key> only fires when the option
// already exists. add_option_<key> fires on the very first save (when the
// user is configuring the plugin for the first time and the option row
// doesn't exist yet). Without this hook, the inaugural credential save
// silently bypassed validation.
add_action('add_option_hgezlpfcr_user', static function ($option, $value) {
    HGEZLPFCR_API_Client::on_credentials_changed('', $value);
}, 10, 2);
add_action('add_option_hgezlpfcr_pass', static function ($option, $value) {
    HGEZLPFCR_API_Client::on_credentials_changed('', $value);
}, 10, 2);

// Persistent admin notice when the most recent credential check failed.
// Dismissable per page-load is intentional: we don't want admins to dismiss
// it forever and forget — it re-appears until the verdict flips to 'yes'.
add_action('admin_notices', static function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $valid = get_option('hgezlpfcr_credentials_valid', '');
    if ($valid !== 'no') {
        return; // Skip when valid, unknown, or never checked.
    }
    $error    = (string) get_option('hgezlpfcr_credentials_last_error', '');
    $code     = (int) get_option('hgezlpfcr_credentials_last_code', 0);
    $checked  = (int) get_option('hgezlpfcr_credentials_checked_at', 0);
    $settings_url = admin_url('admin.php?page=wc-settings&tab=hgezlpfcr');

    echo '<div class="notice notice-error"><p><strong>';
    echo esc_html__('FAN Courier:', 'hge-zone-de-livrare-pentru-fan-courier-romania');
    echo '</strong> ';
    echo esc_html__('Datele de autentificare la FAN Courier sunt invalide. Generarea de AWB nu va funcționa.', 'hge-zone-de-livrare-pentru-fan-courier-romania');
    if ($error !== '') {
        echo ' <em>' . esc_html($error) . '</em>';
    }
    if ($code !== 0) {
        echo ' <span style="color:#888;">(HTTP ' . esc_html((string) $code) . ')</span>';
    }
    if ($checked > 0) {
        echo ' <span style="color:#888;">' . esc_html(sprintf(
            /* translators: %s: human-friendly time difference (e.g. "5 minutes ago") */
            __('Verificat ultima dată acum %s.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            human_time_diff($checked, time())
        )) . '</span>';
    }
    echo ' <a href="' . esc_url($settings_url) . '">';
    echo esc_html__('Verifică Settings &rarr;', 'hge-zone-de-livrare-pentru-fan-courier-romania');
    echo '</a></p></div>';
});

// One-shot post-save banner. Fires immediately after WC settings redirect
// so the admin sees explicit confirmation that the credentials they just
// typed actually work (or don't). Backs up the persistent notice above.
add_action('admin_notices', static function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $data = get_transient('hgezlpfcr_credentials_save_notice');
    if (!is_array($data)) {
        return;
    }
    delete_transient('hgezlpfcr_credentials_save_notice');

    if ($data['valid'] === true) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>';
        echo esc_html__('FAN Courier:', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        echo '</strong> ';
        echo esc_html__('Credențialele au fost validate cu succes împotriva FAN Courier API.', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        echo '</p></div>';
        return;
    }
    if ($data['valid'] === false) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>';
        echo esc_html__('FAN Courier:', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        echo '</strong> ';
        echo esc_html__('Credențialele NU au fost acceptate de FAN Courier:', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        echo ' ' . esc_html((string) $data['error']);
        echo '</p></div>';
        return;
    }
    // $data['valid'] === null — couldn't determine; don't alarm the user.
    echo '<div class="notice notice-warning is-dismissible"><p><strong>';
    echo esc_html__('FAN Courier:', 'hge-zone-de-livrare-pentru-fan-courier-romania');
    echo '</strong> ';
    echo esc_html__('Nu am putut verifica credențialele acum (rețea / FAN Courier API indisponibil). Voi reîncerca la următoarea salvare.', 'hge-zone-de-livrare-pentru-fan-courier-romania');
    echo '</p></div>';
});

// -----------------------------------------------------------------------------
// Manual cache clear via admin-post (since 1.0.13)
//
// Nonce-protected URL handler. Admin can hit
//   admin-post.php?action=hgezlpfcr_clear_tariff_cache&_wpnonce=<nonce>
// to force a sweep without changing credentials. A UI button is intentionally
// not added in this commit (minimal risk surface); future enhancement can
// add it to the Settings page once verified safe on dev1.
// -----------------------------------------------------------------------------
add_action('admin_post_hgezlpfcr_clear_tariff_cache', static function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Permission denied.', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 403);
    }
    check_admin_referer('hgezlpfcr_clear_tariff_cache');

    $deleted = HGEZLPFCR_API_Client::clear_tariff_cache();

    set_transient('hgezlpfcr_tariff_cache_cleared_notice', (int) $deleted, MINUTE_IN_SECONDS);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wc-settings'));
    exit;
});

add_action('admin_notices', static function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $deleted = get_transient('hgezlpfcr_tariff_cache_cleared_notice');
    if ($deleted === false) {
        return;
    }
    delete_transient('hgezlpfcr_tariff_cache_cleared_notice');
    echo '<div class="notice notice-success is-dismissible"><p>'
        . esc_html(sprintf(
            /* translators: %d is the number of transient rows deleted */
            __('FAN Courier tariff cache cleared (%d rows).', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            (int) $deleted
        ))
        . '</p></div>';
});
