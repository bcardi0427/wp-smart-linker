<?php
namespace WSL;

class OpenAI_Integration extends AI_Integration_Base {
    private $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    private $models_endpoint = 'https://api.openai.com/v1/models';
    private $valid_models = null;
    private $default_models = [
        'gpt-3.5-turbo' => true,
        'gpt-4' => true,
        'gpt-4-turbo' => true
    ];

    /**
     * Set up API credentials from settings
     */
    protected function setup_credentials() {
        $settings = get_option('wsl_settings');
        $this->api_key = $settings['openai_api_key'] ?? '';
        $this->model = $settings['openai_model'] ?? 'gpt-3.5-turbo';

        // If no API key, use default model
        if (empty($this->api_key)) {
            $this->model = 'gpt-3.5-turbo';
            return;
        }

        // Validate model if API key exists
        if (!$this->is_valid_model($this->model)) {
            error_log('WSL Warning: Invalid model selected, falling back to gpt-3.5-turbo');
            $this->model = 'gpt-3.5-turbo';
        }
    }

    /**
     * Get available models from OpenAI API
     *
     * @return array List of valid model IDs
     */
    private function fetch_valid_models() {
        if ($this->valid_models !== null) {
            return $this->valid_models;
        }

        // Early return with default models if no API key is set
        if (empty($this->api_key)) {
            $this->valid_models = $this->default_models;
            return $this->valid_models;
        }

        try {
            error_log('WSL Debug - Fetching OpenAI models');
            $response = wp_remote_get($this->models_endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key
                ],
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                throw new AI_Exception(
                    'Failed to fetch models: ' . $response->get_error_message(),
                    AI_Exception::ERROR_API_ERROR
                );
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                throw new AI_Exception(
                    'Failed to fetch models: ' . $response_body,
                    AI_Exception::ERROR_API_ERROR
                );
            }

            $data = json_decode($response_body, true);
            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new AI_Exception(
                    'Invalid models response format',
                    AI_Exception::ERROR_INVALID_RESPONSE
                );
            }

            $models = [];
            foreach ($data['data'] as $model) {
                if (isset($model['id'])) {
                    $models[] = $model['id'];
                    error_log('WSL Debug - Found model: ' . $model['id']);
                }
            }

            error_log('WSL Debug - Total models found: ' . count($models));

            // Output select HTML directly
            ob_start();
            echo '<select name="openai_models">';
            foreach ($models as $model) {
                echo '<option value="' . esc_attr($model) . '">' . esc_html($model) . '</option>';
            }
            echo '</select>';
            $output = ob_get_clean();

            $this->valid_models = array_fill_keys($models, true);
            set_transient('wsl_openai_models', $this->valid_models, DAY_IN_SECONDS);

            error_log('WSL Debug - Generated select HTML: ' . $output);
            return $output;

        } catch (\Exception $e) {
            error_log('WSL Error fetching models: ' . $e->getMessage());
            return $this->default_models;
        }
    }

    /**
     * Validate if a model ID is valid
     */
    public function is_valid_model($model_id) {
        // Try to get cached models first
        $cached_models = get_transient('wsl_openai_models');
        if ($cached_models !== false) {
            return isset($cached_models[$model_id]);
        }

        // Fetch fresh list if no cache
        $models = $this->fetch_valid_models();
        return isset($models[$model_id]);
    }

    /**
     * Get list of available models
     */
    public function get_available_models() {
        // Return default models if no API key is set
        if (empty($this->api_key)) {
            return [
                'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                'gpt-4' => 'GPT-4',
                'gpt-4-turbo' => 'GPT-4 Turbo'
            ];
        }

        // Try to get cached models first
        $cached_models = get_transient('wsl_openai_models');
        if ($cached_models !== false) {
            error_log('WSL Debug - Using cached models: ' . print_r($cached_models, true));
            $models = array_keys($cached_models);
        } else {
            // Fetch fresh list if no cache
            $models = array_keys($this->fetch_valid_models());
            error_log('WSL Debug - Using freshly fetched models: ' . print_r($models, true));
        }

        // Create display names for models
        $formatted_models = [];
        foreach ($models as $model_id) {
            // Clean up the model ID for display
            $display_name = ucwords(str_replace(['-', '.'], [' ', ' '], $model_id));
            $formatted_models[$model_id] = $display_name;
        }

        // Sort models by display name
        asort($formatted_models);
        error_log('WSL Debug - Final formatted models: ' . print_r($formatted_models, true));
        return $formatted_models;
    }

    /**
     * Generate suggestions using the OpenAI API
     */
    protected function get_ai_suggestions($prompt) {
        // Try to get cached response first
        $cached = $this->get_cached_response($prompt);
        if ($cached !== false) {
            return $cached;
        }

        $request_body = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert content editor and SEO specialist who understands how to create natural, engaging internal links that enhance readability while improving SEO. Focus on creating links that feel like a natural part of the conversation, adding value to the reader\'s experience. Respond with valid JSON only.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.8,
            'max_tokens' => 1500,
            'frequency_penalty' => 0.3,
            'presence_penalty' => 0.3
        ];

        error_log('WSL Debug - OpenAI Request: ' . print_r($request_body, true));

        $response = wp_remote_post($this->api_endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($request_body),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new AI_Exception(
                'API Request Error: ' . $response->get_error_message(),
                AI_Exception::ERROR_API_ERROR
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message'])
                ? $error_data['error']['message']
                : 'Unknown API error occurred';
                
            throw new AI_Exception(
                'OpenAI API Error: ' . $error_message,
                AI_Exception::ERROR_API_ERROR
            );
        }

        $body = json_decode($response_body, true);
        if (empty($body['choices'][0]['message']['content'])) {
            throw new AI_Exception(
                'Invalid API response: No content returned from OpenAI',
                AI_Exception::ERROR_INVALID_RESPONSE
            );
        }

        // Clean up the response content
        $content = $body['choices'][0]['message']['content'];
        
        // Remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        
        // Try to parse the JSON
        $suggestions = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('WSL Debug - Failed to parse JSON: ' . $content);
            throw new AI_Exception(
                'Invalid JSON in API response: ' . json_last_error_msg(),
                AI_Exception::ERROR_INVALID_RESPONSE
            );
        }

        // Validate response structure
        if (!isset($suggestions['suggestions']) || !is_array($suggestions['suggestions'])) {
            error_log('WSL Debug - Invalid response structure: missing suggestions array');
            throw new AI_Exception(
                'Invalid response structure: missing suggestions array',
                AI_Exception::ERROR_INVALID_RESPONSE
            );
        }

        error_log('WSL Debug - Parsed suggestions: ' . print_r($suggestions, true));
        
        // Cache successful response
        $this->cache_response($prompt, $suggestions);
        
        return $suggestions;
    }

    /**
     * Process and validate API response
     */
    protected function process_ai_response($response, $sections) {
        if (!isset($response['suggestions']) || !is_array($response['suggestions'])) {
            error_log('WSL Debug - No suggestions found in AI response');
            return [];
        }

        error_log('WSL Debug - Processing ' . count($response['suggestions']) . ' suggestions');
        $processed = [];
        $used_sections = [];

        foreach ($response['suggestions'] as $suggestion) {
            try {
                // Basic validation
                if (!$this->validate_suggestion($suggestion, count($sections))) {
                    continue;
                }

                $section_index = intval($suggestion['section_index']);
                
                // Prevent multiple links in the same section
                if (isset($used_sections[$section_index])) {
                    error_log('WSL Debug - Section already has a link: ' . $section_index);
                    continue;
                }

                // Find matching section
                foreach ($sections as $section) {
                    if ($section['type'] === 'p') {
                        $content = strip_tags($section['content']);
                        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        
                        if (empty($suggestion['anchor_text']) || stripos($content, $suggestion['anchor_text']) === false) {
                            continue;
                        }

                        $processed[] = [
                            'section_index' => $section_index,
                            'target_post_id' => $suggestion['target_post_id'],
                            'anchor_text' => sanitize_text_field($suggestion['anchor_text']),
                            'relevance_score' => (float) $suggestion['relevance_score'],
                            'section_content' => $content
                        ];
                        
                        $used_sections[$section_index] = true;
                        error_log('WSL Debug - Added suggestion for section ' . $section_index .
                                ' with anchor text: ' . $suggestion['anchor_text']);
                        break;
                    }
                }

            } catch (\Exception $e) {
                error_log('WSL Debug - Error processing suggestion: ' . $e->getMessage());
                continue;
            }
        }

        return $processed;
    }

    /**
     * Validate a single suggestion
     */
    private function validate_suggestion($suggestion, $section_count) {
        // Check required fields
        if (!isset($suggestion['section_index']) ||
            !isset($suggestion['target_post_id']) ||
            !isset($suggestion['anchor_text']) ||
            !isset($suggestion['relevance_score'])) {
            error_log('WSL Debug - Missing required fields in suggestion');
            return false;
        }

        // Validate section index
        if (!is_numeric($suggestion['section_index']) ||
            $suggestion['section_index'] < 0 ||
            $suggestion['section_index'] >= $section_count) {
            error_log('WSL Debug - Invalid section index: ' . $suggestion['section_index']);
            return false;
        }

        // Validate anchor text exists and isn't empty
        if (empty($suggestion['anchor_text'])) {
            error_log('WSL Debug - Empty anchor text');
            return false;
        }

        // Validate relevance score
        if (!is_numeric($suggestion['relevance_score']) ||
            $suggestion['relevance_score'] < 0 ||
            $suggestion['relevance_score'] > 1) {
            error_log('WSL Debug - Invalid relevance score: ' . $suggestion['relevance_score']);
            return false;
        }

        // Validate post exists and is published
        $post = get_post($suggestion['target_post_id']);
        if (!$post || $post->post_status !== 'publish') {
            error_log('WSL Debug - Invalid post ID or unpublished post: ' . $suggestion['target_post_id']);
            return false;
        }

        return true;
    }
}