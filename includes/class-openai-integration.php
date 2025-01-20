<?php
namespace WSL;

class OpenAI_Integration {
    private $api_key;
    private $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    private $model = 'gpt-3.5-turbo';

    /**
     * Initialize OpenAI integration
     */
    public function __construct() {
        $settings = get_option('wsl_settings');
        $this->api_key = $settings['openai_api_key'] ?? '';
    }

    /**
     * Analyze content sections for linking opportunities
     *
     * @param array $sections Content sections to analyze
     * @param int $post_id Current post ID
     * @return array Link suggestions
     */
    public function analyze_content_for_links($sections, $post_id) {
        // Check for API key
        if (empty($this->api_key)) {
            error_log('WSL: No OpenAI API key configured');
            return [];
        }

        // Validate sections
        if (empty($sections)) {
            error_log('WSL: No content sections to analyze');
            return [];
        }

        // Filter and verify sections before proceeding
        $prompt = $this->prepare_analysis_prompt($sections, $post_id);
        if ($prompt === false) {
            error_log('WSL Debug - No valid content to analyze');
            return [];
        }

        error_log('WSL Debug - Prompt prepared successfully');
        
        // Get suggestions from OpenAI
        try {
            $suggestions = $this->get_ai_suggestions($prompt);
            error_log('WSL Debug - Raw OpenAI response: ' . print_r($suggestions, true));
            
            $processed_suggestions = $this->process_ai_response($suggestions, $sections);
            error_log('WSL Debug - Processed suggestions: ' . print_r($processed_suggestions, true));
            
            return $processed_suggestions;
        } catch (\Exception $e) {
            error_log('WSL OpenAI Error: ' . $e->getMessage());
            if (isset($response)) {
                error_log('WSL OpenAI Response: ' . print_r($response, true));
            }
            return [];
        }
    }

    /**
     * Get potential posts to link to
     *
     * @param int $current_post_id Current post ID to exclude
     * @return array Array of potential target posts
     */
    private function get_potential_link_targets($current_post_id) {
        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'post__not_in' => [$current_post_id],
            'orderby' => ['modified' => 'DESC'],
            'meta_query' => [
                [
                    'key' => '_wsl_content_sections',
                    'compare' => 'EXISTS'
                ]
            ]
        ];

        error_log('WSL Debug - Fetching target posts with args: ' . print_r($args, true));
        
        $posts = get_posts($args);
        if (empty($posts)) {
            error_log('WSL Debug - No potential target posts found');
            return [];
        }

        error_log('WSL Debug - Found ' . count($posts) . ' potential target posts');
        return $posts;
    }

    /**
     * Prepare prompt for OpenAI analysis
     *
     * @param array $sections Content sections
     * @param array $target_posts Potential posts to link to
     * @return string Prepared prompt
     */
    private function prepare_analysis_prompt($sections, $post_id) {
        error_log('WSL Debug - Preparing content analysis...');

        // Get target posts first
        $target_posts = $this->get_potential_link_targets($post_id);
        if (empty($target_posts)) {
            error_log('WSL Debug - No target posts available for linking');
            return false;
        }

        // First pass: identify valid paragraphs and their context
        $paragraph_sections = [];
        $current_heading = '';
        
        foreach ($sections as $index => $section) {
            $content = strip_tags($section['content']);
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = trim(preg_replace('/\s+/', ' ', $content));
            
            // Skip empty content
            if (empty($content)) {
                continue;
            }

            // Update current heading or process paragraph
            if (strpos($section['type'], 'h') === 0) {
                $current_heading = $content;
                error_log('WSL Debug - Found heading: ' . $current_heading);
            } elseif ($section['type'] === 'p' && str_word_count($content) >= 5) {
                $paragraph_sections[$index] = [
                    'content' => $content,
                    'word_count' => str_word_count($content),
                    'context_heading' => $current_heading,
                    'original_index' => $index
                ];
                error_log('WSL Debug - Added paragraph under "' . $current_heading . '": ' .
                         substr($content, 0, 100) . ' (Words: ' . str_word_count($content) . ')');
            }
        }
        
        // Verify we have content to analyze
        if (empty($paragraph_sections)) {
            error_log('WSL Debug - No valid paragraph sections found after filtering');
            return false;
        }
        
        // Build content text with rich context
        $content_text = '';
        foreach ($paragraph_sections as $index => $section) {
            $content_text .= "Section {$section['original_index']} ";
            $content_text .= "(Under heading: {$section['context_heading']}, {$section['word_count']} words):\n";
            $content_text .= "{$section['content']}\n\n";
        }
        
        // Build target posts text with enhanced metadata
        $target_text = '';
        foreach ($target_posts as $post) {
            $excerpt = wp_strip_all_tags(get_the_excerpt($post));
            $post_date = get_the_date('Y-m-d', $post);
            $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
            $target_text .= "Post ID {$post->ID} ({$post_date}):\n";
            $target_text .= "Title: {$post->post_title}\n";
            $target_text .= "Categories: " . implode(', ', $categories) . "\n";
            $target_text .= "Excerpt: {$excerpt}\n\n";
        }
        
        error_log('WSL Debug - Analysis preparation complete:');
        error_log('WSL Debug - Valid paragraphs found: ' . count($paragraph_sections));
        error_log('WSL Debug - Target posts available: ' . count($target_posts));
        error_log('WSL Debug - Content structure: ' . json_encode(array_keys($paragraph_sections)));
        if (!empty($paragraph_sections)) {
            $first = reset($paragraph_sections);
            error_log('WSL Debug - First paragraph preview: ' . substr($first['content'], 0, 100));
            error_log('WSL Debug - Under heading: ' . $first['context_heading']);
        }

        return <<<PROMPT
You are an AI expert in content analysis and SEO. Your task is to find natural linking opportunities in content sections while considering their heading context. Your response must be valid JSON.

Task: Analyze each content section and its heading context to suggest strategic internal links that enhance both user experience and SEO value.

Content sections to analyze:
$content_text

Available posts to link to:
$target_text

Key Guidelines:
1. Focus on paragraph content, not headings
2. Look for natural opportunities where linking would add value
3. Choose anchor text that:
   - Flows naturally in the sentence
   - Is conversational and readable
   - Provides context about the linked content
   - Is typically 2-4 words long
4. Avoid generic phrases like "click here" or "read more"
5. Prefer sections that tell a story or explain concepts
6. Skip sections that are too technical or list-like

Instructions:
For each linking opportunity, provide:
- section_index: The paragraph where the link should be placed
- target_post_id: ID of the relevant post to link to
- anchor_text: The exact text to turn into a link (must exist in the section)
- relevance_score: How relevant and natural the link feels (0.1-1.0)

Return ONLY a JSON object like:
{
    "suggestions": [
        {
            "section_index": 0,
            "target_post_id": 123,
            "anchor_text": "natural flowing text",
            "relevance_score": 0.85
        }
    ]
}

Requirements:
- Only suggest links in paragraph sections (type "p")
- anchor_text must be an exact substring of the section content
- Choose text that forms natural, readable links
- Relevance score should consider both content match and how natural the link feels
PROMPT;
    }

    /**
     * Get suggestions from OpenAI API
     *
     * @param string $prompt Prepared prompt
     * @return array Raw API response
     */
    private function get_ai_suggestions($prompt) {
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
            throw new \Exception('API Request Error: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('WSL Debug - OpenAI Response Code: ' . $response_code);
        error_log('WSL Debug - OpenAI Response Body: ' . $response_body);

        if ($response_code !== 200) {
            throw new \Exception('API Error: ' . $response_body);
        }

        $body = json_decode($response_body, true);
        if (empty($body['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid API response: No content in response');
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
            throw new \Exception('Invalid JSON in API response: ' . json_last_error_msg());
        }

        error_log('WSL Debug - Parsed suggestions: ' . print_r($suggestions, true));
        return $suggestions;
    }

    /**
     * Process and validate API response
     *
     * @param array $response Raw API response
     * @param array $sections Original content sections
     * @return array Processed suggestions
     */
    private function process_ai_response($response, $sections) {
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
                $found = false;
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
                        $found = true;
                        error_log('WSL Debug - Added suggestion for section ' . $section_index .
                                ' with anchor text: ' . $suggestion['anchor_text']);
                        break;
                    }
                }

                if (!$found) {
                    error_log('WSL Debug - No valid paragraph found for anchor text: ' .
                            $suggestion['anchor_text']);
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
        error_log('WSL Debug - Validating suggestion: ' . print_r($suggestion, true));

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

        error_log('WSL Debug - Suggestion validated successfully');
        return true;
    }
}