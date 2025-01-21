<?php
namespace WSL;

class Firebase_Integration {
    private $credentials;
    private $database_url;
    private $access_token;
    private $token_expires;
    private $jwt_token;

    /**
     * Initialize Firebase integration
     */
    public function __construct() {
        $settings = get_option('wsl_settings');
        
        if (!empty($settings['firebase_credentials'])) {
            $this->credentials = json_decode($settings['firebase_credentials'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->database_url = "https://{$this->credentials['project_id']}.firebaseio.com";
            }
        }

        // Set up cron job
        if (!wp_next_scheduled('wsl_sync_firebase_data')) {
            wp_schedule_event(time(), 'hourly', 'wsl_sync_firebase_data');
        }
        add_action('wsl_sync_firebase_data', [$this, 'sync_data']);

        // Listen for post updates
        add_action('save_post', [$this, 'handle_post_update'], 10, 3);
        add_action('before_delete_post', [$this, 'handle_post_delete']);
    }

    /**
     * Generate a JWT token for Firebase authentication
     */
    private function generate_jwt_token() {
        if (empty($this->credentials)) {
            throw new \Exception('Firebase credentials not configured');
        }

        $now = time();
        $token = [
            'iss' => $this->credentials['client_email'],
            'sub' => $this->credentials['client_email'],
            'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat' => $now,
            'exp' => $now + 3600, // Token expires in 1 hour
            'uid' => 'wsl-service-account'
        ];

        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $this->credentials['private_key_id']
        ];

        $segments = [];
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($token));
        
        $signing_input = implode('.', $segments);
        
        $private_key = openssl_pkey_get_private($this->credentials['private_key']);
        openssl_sign($signing_input, $signature, $private_key, 'SHA256');
        $segments[] = $this->urlsafeB64Encode($signature);
        
        $this->jwt_token = implode('.', $segments);
        $this->token_expires = $now + 3600;
        
        return $this->jwt_token;
    }

    /**
     * URL-safe base64 encoding
     */
    private function urlsafeB64Encode($input) {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * Check if Firebase is properly configured
     */
    public function is_configured() {
        if (empty($this->credentials)) {
            return false;
        }

        $required_fields = ['type', 'project_id', 'private_key', 'client_email', 'private_key_id'];
        foreach ($required_fields as $field) {
            if (empty($this->credentials[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Make authenticated request to Firebase
     *
     * @param string $endpoint Firebase endpoint
     * @param string $method HTTP method
     * @param array $data Data to send
     * @return array Response data
     */
    private function make_request($endpoint, $method = 'GET', $data = null) {
        if (!$this->is_configured()) {
            throw new \Exception('Firebase not properly configured');
        }

        // Check if token is expired or missing
        if (empty($this->jwt_token) || time() >= ($this->token_expires - 300)) { // Refresh 5 minutes before expiry
            $this->generate_jwt_token();
        }

        $url = rtrim($this->database_url, '/') . '/' . ltrim($endpoint, '/') . '.json';
        $url .= '?access_token=' . urlencode($this->jwt_token);

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'timeout' => 30,
        ];

        if ($data !== null) {
            $args['body'] = json_encode($data);
        }

        error_log("WSL Debug - Making Firebase request to: $url");

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("WSL Firebase Error: $error_message");
            throw new \Exception('Firebase request failed: ' . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log("WSL Debug - Firebase response code: $response_code");
        error_log("WSL Debug - Firebase response body: $response_body");

        if ($response_code !== 200) {
            throw new \Exception('Firebase error: ' . $response_body);
        }

        return json_decode($response_body, true);
    }

    /**
     * Get rate limit count from Firebase
     *
     * @return int|null Current rate limit count or null if not found
     */
    public function get_rate_limit_count() {
        try {
            $result = $this->make_request('rate_limits/api_calls');
            return isset($result['count']) ? intval($result['count']) : null;
        } catch (\Exception $e) {
            error_log('WSL Firebase Error getting rate limit: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set rate limit count in Firebase
     *
     * @param int $count Initial count
     * @param int $expiry Expiry time in seconds
     */
    public function set_rate_limit_count($count, $expiry) {
        try {
            $this->make_request('rate_limits/api_calls', 'PUT', [
                'count' => $count,
                'expires' => time() + $expiry
            ]);
        } catch (\Exception $e) {
            error_log('WSL Firebase Error setting rate limit: ' . $e->getMessage());
        }
    }

    /**
     * Increment rate limit count in Firebase
     */
    public function increment_rate_limit_count() {
        try {
            $current = $this->get_rate_limit_count();
            if ($current !== null) {
                $this->make_request('rate_limits/api_calls/count', 'PUT', $current + 1);
            }
        } catch (\Exception $e) {
            error_log('WSL Firebase Error incrementing rate limit: ' . $e->getMessage());
        }
    }

    /**
     * Get cached suggestions from Firebase
     *
     * @param string $cache_key Cache key
     * @return array|null Cached data or null if not found
     */
    public function get_cached_suggestions($cache_key) {
        try {
            $result = $this->make_request("cache/suggestions/" . md5($cache_key));
            if (isset($result['data']) && isset($result['expires']) && $result['expires'] > time()) {
                return $result['data'];
            }
            return null;
        } catch (\Exception $e) {
            error_log('WSL Firebase Error getting cache: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store cached suggestions in Firebase
     *
     * @param string $cache_key Cache key
     * @param mixed $data Data to cache
     * @param int $expiry Cache expiry time in seconds
     */
    public function store_cached_suggestions($cache_key, $data, $expiry) {
        try {
            $this->make_request("cache/suggestions/" . md5($cache_key), 'PUT', [
                'data' => $data,
                'expires' => time() + $expiry
            ]);
        } catch (\Exception $e) {
            error_log('WSL Firebase Error storing cache: ' . $e->getMessage());
        }
    }

    /**
     * Store post data in Firebase
     *
     * @param int $post_id Post ID
     * @param array $data Post data
     */
    public function store_post_data($post_id, $data) {
        try {
            $this->make_request("posts/$post_id", 'PUT', $data);
            error_log("WSL: Successfully stored post $post_id data in Firebase");
        } catch (\Exception $e) {
            error_log('WSL Firebase Error: ' . $e->getMessage());
        }
    }

    /**
     * Get post data from Firebase
     *
     * @param int $post_id Post ID
     * @return array|null Post data or null if not found
     */
    public function get_post_data($post_id) {
        try {
            return $this->make_request("posts/$post_id");
        } catch (\Exception $e) {
            error_log('WSL Firebase Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete post data from Firebase
     *
     * @param int $post_id Post ID
     */
    public function delete_post_data($post_id) {
        try {
            $this->make_request("posts/$post_id", 'DELETE');
            error_log("WSL: Successfully deleted post $post_id data from Firebase");
        } catch (\Exception $e) {
            error_log('WSL Firebase Error: ' . $e->getMessage());
        }
    }

    /**
     * Store link suggestions in Firebase
     *
     * @param int $post_id Post ID
     * @param array $suggestions Link suggestions
     */
    public function store_link_suggestions($post_id, $suggestions) {
        try {
            $this->make_request("suggestions/$post_id", 'PUT', $suggestions);
            error_log("WSL: Successfully stored suggestions for post $post_id in Firebase");
        } catch (\Exception $e) {
            error_log('WSL Firebase Error: ' . $e->getMessage());
        }
    }

    /**
     * Get link suggestions from Firebase
     *
     * @param int $post_id Post ID
     * @return array|null Link suggestions or null if not found
     */
    public function get_link_suggestions($post_id) {
        try {
            return $this->make_request("suggestions/$post_id");
        } catch (\Exception $e) {
            error_log('WSL Firebase Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handle post update
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public function handle_post_update($post_id, $post, $update) {
        if (!$this->is_configured()) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (wp_is_post_autosave($post_id)) return;

        // Check if this post type should be processed
        $settings = get_option('wsl_settings');
        $excluded_types = $settings['excluded_post_types'] ?? ['attachment'];
        if (in_array($post->post_type, $excluded_types)) return;

        // Store post data
        $this->store_post_data($post_id, [
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => get_the_excerpt($post),
            'modified' => $post->post_modified,
            'categories' => wp_get_post_categories($post_id, ['fields' => 'names'])
        ]);
    }

    /**
     * Handle post deletion
     *
     * @param int $post_id Post ID
     */
    public function handle_post_delete($post_id) {
        if (!$this->is_configured()) return;
        
        $this->delete_post_data($post_id);
    }

    /**
     * Test Firebase connection with given credentials
     *
     * @param string $test_credentials JSON string of credentials to test
     * @return bool Whether connection was successful
     */
    public function test_connection($test_credentials) {
        try {
            // Parse and validate credentials
            $temp_creds = json_decode($test_credentials, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format');
            }

            // Store current credentials
            $current_creds = $this->credentials;
            $current_db_url = $this->database_url;

            // Temporarily set test credentials
            $this->credentials = $temp_creds;
            $this->database_url = "https://{$temp_creds['project_id']}.firebaseio.com";
            
            // Clear any existing token
            $this->jwt_token = null;
            $this->token_expires = null;
// Try to write to a test location
$test_data = ['timestamp' => time(), 'test' => true];
$result = $this->make_request('wsl_connection_test', 'PUT', $test_data);

// Clean up test data
$this->make_request('wsl_connection_test', 'DELETE');

// Restore original credentials
$this->credentials = $current_creds;
$this->database_url = $current_db_url;
$this->jwt_token = null;
$this->token_expires = null;

return isset($result['timestamp']);
            return true;

        } catch (\Exception $e) {
            error_log('WSL Firebase Test Error: ' . $e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Sync data with Firebase
     */
    public function sync_data() {
        if (!$this->is_configured()) {
            error_log('WSL: Firebase not configured, skipping sync');
            return;
        }

        error_log('WSL: Starting Firebase sync');

        // Get all published posts
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => -1
        ]);

        foreach ($posts as $post) {
            $this->handle_post_update($post->ID, $post, true);
        }

        error_log('WSL: Firebase sync completed');
    }

    /**
     * Clean up when plugin is deactivated
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('wsl_sync_firebase_data');
    }
}