<?php
namespace WSL;

class Content_Processor {
    /**
     * Initialize the content processor
     */
    public function __construct() {
        add_action('save_post', [$this, 'process_post_content'], 10, 3);
    }

    /**
     * Process post content on save
     *
     * @param int $post_id Post ID
     * @param object $post Post object
     * @param bool $update Whether this is an update
     */
    public function process_post_content($post_id, $post, $update) {
        try {
            // Skip autosaves and revisions
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
            if (wp_is_post_revision($post_id)) return;
            if (wp_is_post_autosave($post_id)) return;

            // Check if this post type should be processed
            $settings = get_option('wsl_settings') ?: [];
            $excluded_types = $settings['excluded_post_types'] ?? ['attachment'];
            if (in_array($post->post_type, $excluded_types)) return;

            // Get post content and ensure it's not empty
            $content = $post->post_content;
            if (empty($content)) return;
            
            // Analyze content
            $sections = $this->analyze_content($content);
            
            // Only store if we found sections
            if (!empty($sections)) {
                update_post_meta($post_id, '_wsl_content_sections', $sections);
            } else {
                delete_post_meta($post_id, '_wsl_content_sections');
            }
            
        } catch (\Exception $e) {
            error_log('WSL Content Processing Error: ' . $e->getMessage());
            delete_post_meta($post_id, '_wsl_content_sections');
        }
    }

    /**
     * Analyze content using WP_HTML_Tag_Processor
     *
     * @param string $content Post content
     * @return array Analyzed sections
     */
    public function analyze_content($content) {
        if (!class_exists('WP_HTML_Tag_Processor')) {
            require_once(ABSPATH . WPINC . '/html-api/class-wp-html-tag-processor.php');
        }

        $sections = [];
        
        error_log('WSL Debug - Starting content analysis');
        error_log('WSL Debug - Content length: ' . strlen($content));

        try {
            // Check if content is from Gutenberg
            $is_gutenberg = has_blocks($content);
            error_log('WSL Debug - Content type: ' . ($is_gutenberg ? 'Gutenberg' : 'Classic'));

            if ($is_gutenberg) {
                $blocks = parse_blocks($content);
                error_log('WSL Debug - Found ' . count($blocks) . ' blocks');
                error_log('WSL Debug - Block types: ' . implode(', ', array_filter(array_column($blocks, 'blockName'))));
                $this->process_blocks($blocks, $sections);
            }

            // Always try legacy processing as fallback
            error_log('WSL Debug - Starting legacy DOM processing');
            $dom = new \DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($dom);
            
            // Query for all text blocks
            $elements = $xpath->query('//p|//div[not(descendant::p)]');
            error_log('WSL Debug - Found ' . $elements->length . ' DOM elements');
            
            $this->process_elements($elements, $sections);
        } catch (\Exception $e) {
            error_log('WSL Error in content analysis: ' . $e->getMessage());
            error_log('WSL Error trace: ' . $e->getTraceAsString());
            throw $e;
        }

        error_log('WSL Debug - Content processing complete. Found ' . count($sections) . ' total sections');

        if (empty($sections)) {
            error_log('WSL Debug - No valid paragraph sections found');
        } else {
            error_log('WSL Debug - Found ' . count($sections) . ' valid sections');
        }

        return $sections;
    }

    /**
     * Process Gutenberg blocks recursively
     *
     * @param array $blocks Array of blocks
     * @param array &$sections Reference to sections array
     * @param int $index Current position index
     */
    private function process_blocks($blocks, &$sections, &$index = 0) {
        foreach ($blocks as $block) {
            error_log('WSL Debug - Processing block: ' . $block['blockName']);
            
            if ($block['blockName'] === 'core/paragraph') {
                $content = trim(wp_strip_all_tags($block['innerHTML']));
                
                if (!empty($content)) {
                    // Clean and decode content
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $word_count = str_word_count($content);
                    
                    error_log('WSL Debug - Found paragraph with ' . $word_count . ' words');
                    
                    if ($word_count >= 5) {
                        $sections[] = [
                            'content' => $content,
                            'position' => $index++,
                            'type' => 'p',
                            'word_count' => $word_count,
                            'heading_level' => 0
                        ];
                        error_log('WSL Debug - Added paragraph section: ' . substr($content, 0, 100));
                    } else {
                        error_log('WSL Debug - Skipped short paragraph: ' . substr($content, 0, 100));
                    }
                }
            } elseif (preg_match('/^core\/(column|group|columns|cover)$/', $block['blockName'])) {
                error_log('WSL Debug - Processing container block: ' . $block['blockName']);
                // Process container blocks
                if (!empty($block['innerBlocks'])) {
                    $this->process_blocks($block['innerBlocks'], $sections, $index);
                }
                
                // Check for content in the container itself
                $container_content = trim(wp_strip_all_tags($block['innerHTML']));
                if (!empty($container_content)) {
                    $word_count = str_word_count($container_content);
                    if ($word_count >= 5) {
                        $sections[] = [
                            'content' => $container_content,
                            'position' => $index++,
                            'type' => 'p',
                            'word_count' => $word_count,
                            'heading_level' => 0
                        ];
                        error_log('WSL Debug - Added container content section: ' . substr($container_content, 0, 100));
                    }
                }
            }
            
            // Process any other nested blocks
            if (!empty($block['innerBlocks'])) {
                error_log('WSL Debug - Processing nested blocks');
                $this->process_blocks($block['innerBlocks'], $sections, $index);
            }
        }
        
        error_log('WSL Debug - Block processing complete. Current section count: ' . count($sections));
    }

    /**
     * Process DOM elements
     *
     * @param DOMNodeList $elements List of DOM elements
     * @param array &$sections Reference to sections array
     */
    private function process_elements($elements, &$sections) {
        $index = 0;
        foreach ($elements as $element) {
            $tag_name = strtolower($element->nodeName);
            $content = trim($element->nodeValue);
            
            if (!empty($content)) {
                // Clean and decode content
                $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
                // Only add paragraphs with meaningful content
                if ($tag_name === 'p') {
                    $word_count = str_word_count($content);
                    if ($word_count < 5) { // Reduced minimum word count
                        continue;
                    }
                }

                $sections[] = [
                    'content' => $content,
                    'position' => $index++,
                    'type' => $tag_name,
                    'word_count' => str_word_count($content),
                    'heading_level' => $this->get_heading_level($tag_name)
                ];
                
                error_log('WSL Debug - Added section: ' . substr($content, 0, 100));
            }
        }
    }

    /**
     * Get heading level from tag name
     *
     * @param string $tag_name HTML tag name
     * @return int Heading level (0 for non-headings)
     */
    private function get_heading_level($tag_name) {
        if (preg_match('/h([1-6])/', $tag_name, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Check if text contains existing links
     *
     * @param string $content Content to check
     * @return bool True if contains links
     */
    private function has_existing_links($content) {
        return strpos($content, '<a href') !== false;
    }

    /**
     * Get context around a specific position
     *
     * @param string $content Full content
     * @param int $position Position to get context around
     * @param int $context_length Number of characters for context
     * @return string Context
     */
    public function get_context($content, $position, $context_length = 100) {
        $start = max(0, $position - $context_length);
        $length = $context_length * 2;
        
        return substr($content, $start, $length);
    }
}