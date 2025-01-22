<?php
namespace WSL;

class DeepSeek_Integration extends AI_Integration_Base {
    private $api_endpoint = 'https://api.deepseek.com/v1/chat/completions';
    private $valid_models = [
        'deepseek-chat' => 'DeepSeek Chat',
        'deepseek-coder' => 'DeepSeek Coder',
    ];

    /**
     * Set up API credentials from settings
     */
    protected function setup_credentials() {
        $settings = get_option('wsl_settings');
        $this->api_key = $settings['deepseek_api_key'] ?? '';
        $this->model = $settings['deepseek_model'] ?? 'deepseek-chat';
    }

    /**
     * Validate if a model ID is valid
     *
     * @param string $model_id Model ID to check
     * @return bool Whether the model is valid
     */
    public function is_valid_model($model_id) {
        return isset($this->valid_models[$model_id]);
    }

    /**
     * Get list of available models
     *
     * @return array Array of model ID => display name pairs
     */
    public function get_available_models() {
        return $this->valid_models;
    }

    /**
     * Generate suggestions using the DeepSeek API
     *
     * @param string $prompt The prompt to send to the AI
     * @return array The AI response
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
            'temperature' => 0.7,
            'max_tokens' => 1500
        ];

        error_log('WSL Debug - DeepSeek Request: ' . print_r($request_body, true));

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

        error_log('WSL Debug - DeepSeek Response Code: ' . $response_code);
        error_log('WSL Debug - DeepSeek Response Body: ' . $response_body);

        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message'])
                ? $error_data['error']['message']
                : 'Unknown API error occurred';
                
            throw new AI_Exception(
                'DeepSeek API Error: ' . $error_message,
                AI_Exception::ERROR_API_ERROR
            );
        }

        $body = json_decode($response_body, true);
        if (empty($body['choices'][0]['message']['content'])) {
            throw new AI_Exception(
                'Invalid API response: No content returned from DeepSeek',
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
     *
     * @param array $response Raw API response
     * @param array $sections Original content sections
     * @return array Processed suggestions
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
     *
     * @param array $suggestion Suggestion to validate
     * @param int $section_count Total number of sections
     * @return bool Whether suggestion is valid
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