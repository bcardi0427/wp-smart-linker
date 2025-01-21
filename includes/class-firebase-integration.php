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
            'aud' => 'https://firestore.googleapis.com/',
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
     * Convert PHP value to Firestore value format
     *
     * @param mixed $value The PHP value to convert
     * @return array The Firestore value representation
     */
    private function convert_to_firestore_value($value) {
        if (is_null($value)) {
            return ['nullValue' => null];
        } elseif (is_bool($value)) {
            return ['booleanValue' => $value];
        } elseif (is_int($value)) {
            return ['integerValue' => (string)$value];
        } elseif (is_float($value)) {
            return ['doubleValue' => $value];
        } elseif (is_string($value)) {
            return ['stringValue' => $value];
        } elseif (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                // Associative array (map)
                $fields = [];
                foreach ($value as $k => $v) {
                    $fields[$k] = $this->convert_to_firestore_value($v);
                }
                return ['mapValue' => ['fields' => $fields]];
            } else {
                // Sequential array
                $arrayValues = [];
                foreach ($value as $v) {
                    $arrayValues[] = $this->convert_to_firestore_value($v);
                }
                return ['arrayValue' => ['values' => $arrayValues]];
            }
        }
        throw new \Exception('Unsupported value type for Firestore: ' . gettype($value));
    }

    /**
     * Convert Firestore value to PHP value
     *
     * @param array $value The Firestore value to convert
     * @return mixed The PHP value
     */
    private function convert_from_firestore_value($value) {
        if (!is_array($value) || empty($value)) {
            return null;
        }

        $type = key($value);
        $val = current($value);

        switch ($type) {
            case 'nullValue':
                return null;
            case 'booleanValue':
                return (bool)$val;
            case 'integerValue':
                return (int)$val;
            case 'doubleValue':
                return (float)$val;
            case 'stringValue':
                return (string)$val;
            case 'mapValue':
                if (!isset($val['fields'])) {
                    return [];
                }
                $result = [];
                foreach ($val['fields'] as $k => $v) {
                    $result[$k] = $this->convert_from_firestore_value($v);
                }
                return $result;
            case 'arrayValue':
                if (!isset($val['values'])) {
                    return [];
                }
                return array_map([$this, 'convert_from_firestore_value'], $val['values']);
            default:
                return null;
        }
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
    private function make_request($collection, $method = 'GET', $data = null, $doc_id = null) {
        if (!$this->is_configured()) {
            throw new \Exception('Firebase not properly configured');
        }

        // Check if token is expired or missing
        if (empty($this->jwt_token) || time() >= ($this->token_expires - 300)) { // Refresh 5 minutes before expiry
            $this->generate_jwt_token();
        }

        // Build Firestore REST API URL
        if (empty($this->credentials['project_id'])) {
            throw new \Exception('Invalid Firebase credentials: Missing project_id');
        }
        $project_id = $this->credentials['project_id'];
        error_log("WSL Debug - Using project ID: {$project_id}");
        $base_url = "https://firestore.googleapis.com/v1";
        $parent = "projects/{$project_id}/databases/(default)/documents";

        // Adjust PUT requests to use POST for document creation
        if ($method === 'PUT') {
            $method = 'PATCH';
            if ($doc_id) {
                $url = "{$base_url}/{$parent}/{$collection}/{$doc_id}";
                error_log("WSL Debug - Using PATCH for document update: $url");
            } else {
                throw new \Exception('Document ID required for PUT/PATCH operations');
            }
        } else if ($method === 'POST') {
            $url = "{$base_url}/{$parent}/{$collection}";
            if ($doc_id) {
                $url .= "?documentId={$doc_id}";
            }
            error_log("WSL Debug - Using POST for document creation: $url");
        } else {
            if ($doc_id) {
                $url = "{$base_url}/{$parent}/{$collection}/{$doc_id}";
            } else {
                $url = "{$base_url}/{$parent}/{$collection}";
            }
        }

        error_log("WSL Debug - Document parent: {$parent}");

        $args = [
           'method' => $method,
           'headers' => [
               'Content-Type' => 'application/json',
               'Accept' => 'application/json',
               'Authorization' => 'Bearer ' . $this->jwt_token
           ],
           'timeout' => 30,
       ];

       if ($data !== null) {
           // Convert PHP data to Firestore format
           $fields = [];
           foreach ($data as $key => $value) {
               $fields[$key] = $this->convert_to_firestore_value($value);
           }
           $args['body'] = json_encode(['fields' => $fields]);
       }

        error_log("WSL Debug - Making Firebase request to: $url");

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("WSL Firebase Error:");
            error_log("- Request URL: $url");
            error_log("- Method: $method");
            error_log("- Error: $error_message");
            if ($data !== null) {
                error_log("- Request Data: " . json_encode(array_keys($data))); // Log just the keys for privacy
            }
            throw new \Exception('Firebase request failed: ' . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log("WSL Debug - Firebase response code: $response_code");
        error_log("WSL Debug - Firebase response body: $response_body");

        // Handle different response codes
        if ($response_code !== 200) {
            $error_message = '';
            
            // Try to parse error message from response body if it's JSON
            $parsed_body = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($parsed_body['error']['message'])) {
                $error_message = $parsed_body['error']['message'];
            } else {
                $error_message = $response_body;
            }

            // Special handling for 404 errors
            if ($response_code === 404) {
                if (strpos($url, '/documents/posts/') !== false) {
                    // Document not found in posts collection, but that's okay for new posts
                    if ($method === 'PUT' || $method === 'POST') {
                        error_log("WSL: Creating new document in posts collection");
                    } else {
                        throw new \Exception("Document not found: $url");
                    }
                } else {
                    throw new \Exception("Resource not found: $url");
                }
            }
            // Handle other error codes
            else if ($response_code !== 200) {
                error_log("WSL Firebase HTTP Error:");
                error_log("- Status Code: $response_code");
                error_log("- Request URL: $url");
                error_log("- Method: $method");
                if ($data !== null) {
                    error_log("- Request Data Keys: " . json_encode(array_keys($data)));
                }
                error_log("- Response Body: $response_body");
                
                switch ($response_code) {
                    case 401:
                        error_log("- Detail: Authentication token may be expired or invalid");
                        throw new \Exception("Authentication failed. Please check Firebase credentials and ensure system time is accurate.");
                    case 403:
                        error_log("- Detail: Service account may lack required permissions");
                        throw new \Exception("Access forbidden. Please verify Firebase service account permissions and project settings.");
                    case 400:
                        if (strpos($error_message, 'Document parent') !== false) {
                            error_log("WSL: Attempting to create parent collection");
                            // Let the operation continue as this might be the first write
                        } else {
                            error_log("- Detail: Request validation failed");
                            throw new \Exception("Invalid request format or data: $error_message");
                        }
                        break;
                    case 404:
                        error_log("- Detail: Resource or collection not found");
                        throw new \Exception("Resource not found: Please verify collection and document paths");
                    case 429:
                        error_log("- Detail: Too many requests");
                        throw new \Exception("Rate limit exceeded. Please reduce request frequency or increase quotas.");
                    case 500:
                    case 502:
                    case 503:
                        error_log("- Detail: Firebase service disruption");
                        throw new \Exception("Firebase service error (HTTP $response_code). Please retry after a brief delay.");
                    default:
                        error_log("- Detail: Unexpected response code");
                        throw new \Exception("Firebase error (HTTP $response_code): $error_message");
                }
            }
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
     * @return bool Whether the operation was successful
     */
    public function store_post_data($post_id, $data) {
        try {
            // Verify post exists and is published
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                error_log("WSL: Skipping post $post_id - post does not exist or is not published");
                return false;
            }

            // Validate required data fields
            $required_fields = ['title', 'content', 'modified'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    error_log("WSL: Missing required field '$field' for post $post_id");
                    return false;
                }
            }

            try {
                // First try to get the document
                $existing = $this->make_request("posts/$post_id", 'GET');
                if ($existing) {
                    // Document exists, update it
                    $this->make_request("posts/$post_id", 'PATCH', $data);
                    error_log("WSL: Updated existing post $post_id in Firebase");
                }
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'not found') !== false) {
                    // Document doesn't exist, create it
                    $this->make_request("posts", 'POST', $data, $post_id);
                    error_log("WSL: Created new post $post_id in Firebase");
                } else {
                    // Some other error occurred
                    throw $e;
                }
            }
            return true;

        } catch (\Exception $e) {
            error_log("WSL Firebase Error storing post $post_id: " . $e->getMessage());
            return false;
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

            // Log credential info (without sensitive data)
            error_log("WSL Debug - Testing Firebase connection with:");
            error_log("WSL Debug - Project ID: " . ($temp_creds['project_id'] ?? 'missing'));
            error_log("WSL Debug - Service Account: " . ($temp_creds['client_email'] ?? 'missing'));
            error_log("WSL Debug - Auth Type: " . ($temp_creds['type'] ?? 'missing'));

            // Store current credentials
            $current_creds = $this->credentials;

            // Temporarily set test credentials
            $this->credentials = $temp_creds;
            
            // Clear any existing token
            $this->jwt_token = null;
            $this->token_expires = null;

            // Try to create a test document
            $test_id = 'test_' . time();
            $test_data = ['timestamp' => time(), 'test' => true];
            
            // This will throw an exception if it fails
            $result = $this->make_request('wsl_connection_tests', 'POST', $test_data, $test_id);
            
            // If we get here, we successfully created the document
            $created_doc = $result;
            
            // Verify the document has our test data
            if (isset($created_doc['fields']) &&
                isset($created_doc['fields']['timestamp']) &&
                isset($created_doc['fields']['test'])) {
                
                // Clean up the test document
                $this->make_request('wsl_connection_tests', 'DELETE', null, $test_id);
                
                // Restore original credentials
                $this->credentials = $current_creds;
                $this->jwt_token = null;
                $this->token_expires = null;

                return true;
            }
            
            throw new \Exception('Invalid response format from Firestore');

        } catch (\Exception $e) {
            // Restore original credentials
            if (isset($current_creds)) {
                $this->credentials = $current_creds;
                $this->jwt_token = null;
                $this->token_expires = null;
            }
            
            error_log('WSL Firebase Test Error: ' . $e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Ensure Firestore database exists
     */
    private function ensure_database_exists() {
        try {
            $project_id = $this->credentials['project_id'];
            $url = "https://firestore.googleapis.com/v1/projects/{$project_id}/databases/(default)";
            
            $args = [
                'method' => 'GET',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->jwt_token
                ],
                'timeout' => 30,
            ];

            $response = wp_remote_request($url, $args);
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code === 404) {
                // Database doesn't exist, create it
                error_log("WSL: Creating Firestore database for project {$project_id}");
                
                $create_args = [
                    'method' => 'POST',
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->jwt_token
                    ],
                    'body' => json_encode([
                        'type' => 'FIRESTORE_NATIVE'
                    ]),
                    'timeout' => 30,
                ];

                $create_response = wp_remote_request(
                    "https://firestore.googleapis.com/v1/projects/{$project_id}/databases?databaseId=(default)",
                    $create_args
                );

                if (is_wp_error($create_response)) {
                    throw new \Exception('Failed to create Firestore database: ' . $create_response->get_error_message());
                }

                $create_code = wp_remote_retrieve_response_code($create_response);
                if ($create_code !== 200 && $create_code !== 409) { // 409 means database already exists
                    throw new \Exception('Failed to create Firestore database. Response code: ' . $create_code);
                }
            } elseif (is_wp_error($response)) {
                throw new \Exception('Failed to check database existence: ' . $response->get_error_message());
            }

            return true;
        } catch (\Exception $e) {
            error_log('WSL Firebase Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync data with Firebase
     */
    /**
     * Initialize required Firestore collections
     */
    private function init_collections() {
        try {
            // Initialize posts collection with a test document
            $test_doc = [
                'title' => 'Collection Initialization',
                'content' => 'Initializing posts collection',
                'created' => current_time('mysql')
            ];
            $this->make_request('posts', 'POST', $test_doc, '_init');
            error_log('WSL: Successfully initialized posts collection');
            
            // Clean up initialization document
            $this->make_request('posts/_init', 'DELETE');
            return true;
        } catch (\Exception $e) {
            error_log('WSL Firebase Error initializing collections: ' . $e->getMessage());
            return false;
        }
    }

    public function sync_data() {
        try {
            if (!$this->is_configured()) {
                throw new \Exception('Firebase not configured');
            }

            error_log('WSL: Starting Firebase sync');

            // Initialize collections if needed
            if (!$this->init_collections()) {
                throw new \Exception('Failed to initialize Firestore collections');
            }

            // Get all published posts
            $posts = get_posts([
                'post_type' => ['post', 'page'],
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'ID',
                'order' => 'ASC'
            ]);

            if (empty($posts)) {
                error_log('WSL: No published posts found to sync');
                return true;
            }

            $sync_count = 0;
            $error_count = 0;
            $total_posts = count($posts);

            error_log("WSL: Found {$total_posts} posts to sync");

            foreach ($posts as $post) {
                try {
                    // Get fresh post data to ensure it's still published
                    $current_post = get_post($post->ID);
                    if (!$current_post || $current_post->post_status !== 'publish') {
                        error_log("WSL: Skipping post {$post->ID} - not published or deleted");
                        continue;
                    }

                    // Prepare post data
                    $post_data = [
                        'title' => $current_post->post_title,
                        'content' => $current_post->post_content,
                        'excerpt' => get_the_excerpt($current_post),
                        'modified' => $current_post->post_modified,
                        'categories' => wp_get_post_categories($post->ID, ['fields' => 'names']),
                        'status' => $current_post->post_status,
                        'type' => $current_post->post_type,
                        'last_synced' => current_time('mysql')
                    ];

                    try {
                        // Try to update existing document first
                        $this->make_request("posts/{$post->ID}", 'PATCH', $post_data);
                    } catch (\Exception $e) {
                        if (strpos($e->getMessage(), 'not found') !== false) {
                            // Document doesn't exist, create it
                            $this->make_request('posts', 'POST', $post_data, $post->ID);
                        } else {
                            throw $e;
                        }
                    }
                    $sync_count++;
                    
                    if ($sync_count % 10 === 0) {
                        error_log("WSL: Progress - synced {$sync_count} of {$total_posts} posts");
                    }

                } catch (\Exception $e) {
                    $error_message = $e->getMessage();
                    error_log("WSL Firebase Error syncing post {$post->ID}:");
                    error_log("- Title: " . $current_post->post_title);
                    error_log("- Error: " . $error_message);
                    error_log("- Type: " . $current_post->post_type);
                    error_log("- Modified: " . $current_post->post_modified);
                    
                    // Try to identify specific error conditions
                    if (strpos($error_message, 'Invalid request') !== false) {
                        error_log("- Likely cause: Malformed data in post content");
                    } else if (strpos($error_message, 'Permission denied') !== false) {
                        error_log("- Likely cause: Firebase permissions issue");
                    }
                    
                    $error_count++;
                    
                    // Optional: Add retry logic for certain error types
                    if (strpos($error_message, 'timeout') !== false ||
                        strpos($error_message, 'network') !== false) {
                        error_log("- Attempting retry for network-related error");
                        try {
                            sleep(2); // Brief delay before retry
                            $this->make_request("posts/{$post->ID}", 'PATCH', $post_data);
                            error_log("- Retry successful for post {$post->ID}");
                            $error_count--; // Decrement error count on successful retry
                            $sync_count++;
                            continue;
                        } catch (\Exception $retry_e) {
                            error_log("- Retry failed: " . $retry_e->getMessage());
                        }
                    }
                }
            }

            $success = $error_count === 0;
            $status = $success ? "Successfully" : "Partially";
            error_log("WSL: Firebase sync {$status} completed. Synced: {$sync_count}, Errors: {$error_count}, Total: {$total_posts}");
            
            return $success;

        } catch (\Exception $e) {
            error_log('WSL Firebase Error during sync: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up when plugin is deactivated
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('wsl_sync_firebase_data');
    }
}