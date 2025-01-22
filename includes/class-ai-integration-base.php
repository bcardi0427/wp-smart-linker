<?php
namespace WSL;

abstract class AI_Integration_Base {
    protected $api_key;
    protected $model;
    protected $firebase;
    protected $cache_expiry = 86400; // 24 hours

    /**
     * Initialize AI integration
     */
    public function __construct() {
        global $wsl_instances;
        $this->firebase = $wsl_instances['firebase'] ?? null;
        $this->setup_credentials();
    }

    /**
     * Set up API credentials from settings
     */
    abstract protected function setup_credentials();

    /**
     * Validate if a model ID is valid for this provider
     *
     * @param string $model_id Model ID to check
     * @return bool Whether the model is valid
     */
    abstract public function is_valid_model($model_id);

    /**
     * Get list of available models for this provider
     *
     * @return array Array of valid model IDs
     */
    abstract public function get_available_models();

    /**
     * Generate suggestions using the AI model
     *
     * @param string $prompt The prompt to send to the AI
     * @return array The AI response
     */
    abstract protected function get_ai_suggestions($prompt);

    /**
     * Process and validate API response
     *
     * @param array $response Raw API response
     * @param array $sections Original content sections
     * @return array Processed suggestions
     */
    abstract protected function process_ai_response($response, $sections);

    /**
     * Check if the provider is properly configured
     *
     * @return bool Whether all required credentials are set
     */
    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * Get cache key for suggestions
     *
     * @param string $prompt The prompt to cache
     * @return string Cache key
     */
    protected function get_cache_key($prompt) {
        return 'wsl_' . strtolower(static::class) . '_cache_' . md5($prompt . $this->model);
    }

    /**
     * Get cached suggestions
     *
     * @param string $prompt Original prompt
     * @return array|false Cached data or false if not found
     */
    protected function get_cached_response($prompt) {
        $cache_key = $this->get_cache_key($prompt);
        
        // Try Firebase first if available
        if ($this->firebase && $this->firebase->is_configured()) {
            $cached = $this->firebase->get_cached_suggestions($cache_key);
            if ($cached !== null) {
                error_log('WSL Debug - Using cached API response from Firebase');
                return $cached;
            }
        }
        
        // Fall back to transients
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            error_log('WSL Debug - Using cached API response from transients');
            return $cached;
        }
        
        return false;
    }

    /**
     * Cache API response
     *
     * @param string $prompt Original prompt
     * @param array $response Response to cache
     */
    protected function cache_response($prompt, $response) {
        $cache_key = $this->get_cache_key($prompt);
        
        // Cache in Firebase if available
        if ($this->firebase && $this->firebase->is_configured()) {
            $this->firebase->store_cached_suggestions($cache_key, $response, $this->cache_expiry);
            error_log('WSL Debug - Cached API response in Firebase');
        } else {
            // Fall back to transients
            set_transient($cache_key, $response, $this->cache_expiry);
            error_log('WSL Debug - Cached API response in transients');
        }
    }

    /**
     * Analyze content for linking opportunities
     *
     * @param array $sections Content sections to analyze
     * @param int $post_id Current post ID
     * @return array Link suggestions
     */
    public function analyze_content_for_links($sections, $post_id) {
        if (!$this->is_configured()) {
            throw new AI_Exception(
                'No API key configured. Please add your API key in the settings.',
                AI_Exception::ERROR_NO_API_KEY
            );
        }

        if (empty($sections)) {
            throw new AI_Exception(
                'No content sections to analyze',
                AI_Exception::ERROR_NO_CONTENT
            );
        }

        $prompt = $this->prepare_analysis_prompt($sections, $post_id);
        if ($prompt === false) {
            error_log('WSL Debug - No valid content to analyze');
            return [];
        }

        error_log('WSL Debug - Prompt prepared successfully');
        
        try {
            $suggestions = $this->get_ai_suggestions($prompt);
            error_log('WSL Debug - Raw response: ' . print_r($suggestions, true));
            
            $processed_suggestions = $this->process_ai_response($suggestions, $sections);
            error_log('WSL Debug - Processed suggestions: ' . print_r($processed_suggestions, true));
            
            return $processed_suggestions;
        } catch (\Exception $e) {
            error_log('WSL AI Error: ' . $e->getMessage());
            if (isset($response)) {
                error_log('WSL AI Response: ' . print_r($response, true));
            }
            return [];
        }
    }

    /**
     * Prepare prompt for content analysis
     *
     * @param array $sections Content sections
     * @param int $post_id Current post ID
     * @return string|false Prepared prompt or false if no valid content
     */
    protected function prepare_analysis_prompt($sections, $post_id) {
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

        return $this->get_base_prompt($content_text, $target_text);
    }

    /**
     * Get base prompt template
     *
     * @param string $content_text Content sections text
     * @param string $target_text Target posts text
     * @return string Complete prompt
     */
    protected function get_base_prompt($content_text, $target_text) {
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
     * Get potential posts to link to
     *
     * @param int $current_post_id Current post ID to exclude
     * @return array Array of potential target posts
     */
    protected function get_potential_link_targets($current_post_id) {
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
}

class AI_Exception extends \Exception {
    const ERROR_NO_API_KEY = 1;
    const ERROR_RATE_LIMIT = 2;
    const ERROR_API_ERROR = 3;
    const ERROR_INVALID_RESPONSE = 4;
    const ERROR_NO_CONTENT = 5;
}