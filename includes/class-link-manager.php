<?php
namespace WSL;

class Link_Manager {
    private $content_processor;
    private $openai_integration;

    /**
     * Initialize Link Manager
     */
    public function __construct() {
        global $wsl_instances;
        
        error_log('WSL Debug - Link Manager initialization started');
        
        // Set up dependencies if available
        if (!empty($wsl_instances)) {
            $this->content_processor = $wsl_instances['content_processor'] ?? null;
            $this->openai_integration = $wsl_instances['openai'] ?? null;
        }

        error_log('WSL Debug - Dependencies loaded: ' .
            'Content Processor: ' . ($this->content_processor ? 'Yes' : 'No') . ', ' .
            'OpenAI Integration: ' . ($this->openai_integration ? 'Yes' : 'No'));
        
        // Add hooks only if dependencies are available
        if ($this->content_processor && $this->openai_integration) {
            add_action('add_meta_boxes', [$this, 'add_meta_box']);
            add_action('save_post', [$this, 'process_suggestions'], 20, 2);
            add_action('wp_ajax_wsl_apply_suggestion', [$this, 'ajax_apply_suggestion']);
            add_action('wp_ajax_wsl_refresh_suggestions', [$this, 'ajax_refresh_suggestions']);
        }
    }

    /**
     * Add meta box to post editor
     */
    public function add_meta_box() {
        // Add meta box
        add_meta_box(
            'wsl_suggestions',
            __('Smart Link Suggestions', 'wp-smart-linker'),
            [$this, 'render_meta_box'],
            ['post', 'page'],
            'normal',
            'high'
        );

        // Add admin scripts
        wp_enqueue_script('wsl-admin', plugins_url('assets/js/admin.js', dirname(__FILE__)), ['jquery'], WSL_VERSION, true);
        wp_localize_script('wsl-admin', 'wsl', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wsl_refresh_suggestions'),
            'apply_nonce' => wp_create_nonce('wsl_apply_suggestion')
        ]);
    }

    /**
     * Render meta box content
     *
     * @param WP_Post $post Current post object
     */
    public function render_meta_box($post) {
        wp_nonce_field('wsl_meta_box', 'wsl_meta_box_nonce');
        
        $suggestions = get_post_meta($post->ID, '_wsl_link_suggestions', true) ?: [];
        $applied_links = get_post_meta($post->ID, '_wsl_applied_links', true) ?: [];
        
        error_log('WSL Debug - Rendering meta box with suggestions: ' . print_r($suggestions, true));
        
        ?>
        <div class="wsl-suggestions">
            <div class="wsl-suggestions-header">
                <button
                    type="button"
                    class="button button-secondary wsl-refresh-suggestions"
                    data-nonce="<?php echo wp_create_nonce('wsl_refresh_suggestions'); ?>"
                    data-post-id="<?php echo esc_attr($post->ID); ?>"
                >
                    <?php _e('Refresh Suggestions', 'wp-smart-linker'); ?>
                </button>
            </div>
            <?php if (empty($suggestions)): ?>
                <p><?php _e('No link suggestions available. Click the refresh button or save the post to generate suggestions.', 'wp-smart-linker'); ?></p>
            <?php else: ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Section', 'wp-smart-linker'); ?></th>
                            <th><?php _e('Suggestion', 'wp-smart-linker'); ?></th>
                            <th><?php _e('Relevance', 'wp-smart-linker'); ?></th>
                            <th><?php _e('Actions', 'wp-smart-linker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suggestions as $index => $suggestion): ?>
                            <?php 
                            $target_post = get_post($suggestion['target_post_id']);
                            if (!$target_post) continue;
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html(wp_trim_words($suggestion['section_content'], 10)); ?>
                                </td>
                                <td>
                                    <?php 
                                    printf(
                                        __('Link "%s" to "%s"', 'wp-smart-linker'),
                                        esc_html($suggestion['anchor_text']),
                                        esc_html($target_post->post_title)
                                    ); 
                                    ?>
                                </td>
                                <td>
                                    <?php echo round($suggestion['relevance_score'] * 100) . '%'; ?>
                                </td>
                                <td>
                                    <?php
                                    $suggestion_json = json_encode($suggestion);
                                    error_log('WSL Debug - Encoding suggestion for button: ' . $suggestion_json);
                                    ?>
                                    <button
                                        type="button"
                                        class="button button-secondary wsl-apply-suggestion"
                                        data-suggestion="<?php echo esc_attr($suggestion_json); ?>"
                                        data-nonce="<?php echo wp_create_nonce('wsl_apply_suggestion'); ?>"
                                        data-post-id="<?php echo esc_attr($post->ID); ?>"
                                    >
                                        <?php _e('Apply', 'wp-smart-linker'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <style>
        .wsl-suggestions-header {
            margin-bottom: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Handle refresh suggestions
            $('.wsl-refresh-suggestions').on('click', function(e) {
                e.preventDefault();
                const button = $(this);
                
                // Show confirmation if content is not saved
                if (wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').isEditedPostDirty()) {
                    if (!confirm('<?php _e("You have unsaved changes. Save your post first?", "wp-smart-linker"); ?>')) {
                        return;
                    }
                    wp.data.dispatch('core/editor').savePost();
                    return;
                }
                
                button.prop('disabled', true)
                     .text('<?php _e("Refreshing...", "wp-smart-linker"); ?>');

                // Get current editor content
                let postContent = '';
                if (wp.data && wp.data.select('core/editor')) {
                    // Gutenberg editor
                    postContent = wp.data.select('core/editor').getEditedPostContent();
                } else {
                    // Classic editor
                    postContent = $('#content').val();
                }

                // Show loading message in suggestions area
                const suggestionsArea = $('.wsl-suggestions');
                suggestionsArea.html('<p><?php _e("Analyzing content...", "wp-smart-linker"); ?></p>');
                
                $.ajax({
                    url: wsl.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wsl_refresh_suggestions',
                        post_id: postId || 0,
                        post_content: postContent,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.suggestions) {
                            const tbody = $('.wsl-suggestions tbody');
                            tbody.empty();
    
                            if (response.data.suggestions.length > 0) {
                                response.data.suggestions.forEach(function(suggestion) {
                                    // Create table row with suggestion
                                    const row = createSuggestionRow(suggestion, nonce, postId);
                                    tbody.append(row);
                                });
    
                                // Rebind click handlers for new buttons
                                bindApplyButtonHandlers();
                            } else {
                                tbody.append(
                                    '<tr><td colspan="4">' +
                                    '<?php _e("No suggestions found for this content", "wp-smart-linker"); ?>' +
                                    '</td></tr>'
                                );
                            }
                        } else {
                            alert(response.data.message || '<?php _e("Error refreshing suggestions", "wp-smart-linker"); ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        alert('<?php _e("Error refreshing suggestions", "wp-smart-linker"); ?>');
                    },
                    complete: function() {
                        button.prop('disabled', false)
                             .text('<?php _e("Refresh Suggestions", "wp-smart-linker"); ?>');
                    }
                });
            });
    
            // Handle apply suggestion
            $('.wsl-apply-suggestion').on('click', function(e) {
                e.preventDefault();
                const button = $(this);
                
                try {
                    const suggestion = JSON.parse(button.attr('data-suggestion'));
                    const nonce = button.attr('data-nonce');
                    
                    console.log('Applying suggestion:', suggestion);
                    
                    $.ajax({
                        url: wsl.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wsl_apply_suggestion',
                            suggestion: suggestion,
                            post_id: '<?php echo $post->ID; ?>',
                            nonce: nonce
                        },
                        dataType: 'json',
                    beforeSend: function() {
                        button.prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            button.closest('tr').fadeOut(400, function() {
                                $(this).remove();
                                if ($('.wsl-suggestions tbody tr').length === 0) {
                                    $('.wsl-suggestions tbody').append(
                                        '<tr><td colspan="4">' +
                                        'No more suggestions available.' +
                                        '</td></tr>'
                                    );
                                }
                            });
                            console.log('Link applied successfully');
                        } else {
                            console.error('Server error:', response.data);
                            alert(response.data.message || 'Error applying suggestion. Check browser console for details.');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error('AJAX error:', textStatus, errorThrown);
                        alert('Error applying suggestion: ' + textStatus);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            } catch (err) {
                console.error('Error parsing suggestion data:', err);
                alert('Error parsing suggestion data. Check browser console for details.');
                button.prop('disabled', false);
            }
            });
        });
        </script>
        <?php
    }

    /**
     * Process suggestions when post is saved or when manually triggered
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */

    public function process_suggestions($post_id, $post) {
        // Skip if dependencies aren't available
        if (!$this->content_processor || !$this->openai_integration) {
            return;
        }

        // Skip if autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Process on manual refresh or if nonce verified
        $is_manual_refresh = isset($_POST['action']) && $_POST['action'] === 'wsl_refresh_suggestions';
        $is_valid_save = isset($_POST['wsl_meta_box_nonce']) &&
                        wp_verify_nonce($_POST['wsl_meta_box_nonce'], 'wsl_meta_box');

        if (!$is_manual_refresh && !$is_valid_save) {
            return;
        }

        try {
            // Get content sections
            $sections = $this->content_processor->analyze_content($post->post_content);
            if (empty($sections)) return;

            // Store sections for later use
            update_post_meta($post_id, '_wsl_content_sections', $sections);

            // Get suggestions from OpenAI
            $suggestions = $this->openai_integration->analyze_content_for_links($sections, $post_id);
            
            // Update suggestions
            if (!empty($suggestions)) {
                update_post_meta($post_id, '_wsl_link_suggestions', $suggestions);
            }

            if ($is_manual_refresh) {
                wp_send_json_success([
                    'message' => __('Link suggestions refreshed successfully', 'wp-smart-linker')
                ]);
            }
        } catch (\Exception $e) {
            error_log('WSL Error: ' . $e->getMessage());
            if ($is_manual_refresh) {
                wp_send_json_error([
                    'message' => $e->getMessage()
                ]);
            }
        }
    }

    public function ajax_refresh_suggestions() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'wsl_refresh_suggestions')) {
                throw new \Exception('Invalid security token');
            }

            error_log('WSL Debug - AJAX refresh request received: ' . print_r($_POST, true));

            // Get post ID and content
            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            $post_content = isset($_POST['post_content']) ? wp_kses_post($_POST['post_content']) : '';

            error_log('WSL Debug - Processing with post_id: ' . $post_id);
            error_log('WSL Debug - Content length: ' . strlen($post_content));

            // Handle post content directly if provided
            // Always create or update post if content is provided
            if (!empty($post_content)) {
                error_log('WSL Debug - Using provided content for analysis');
                error_log('WSL Debug - Content length: ' . strlen($post_content));
                error_log('WSL Debug - Post ID: ' . ($post_id ? $post_id : 'none'));

                // Create or update post
                if (empty($post_id)) {
                    error_log('WSL Debug - Creating new auto-draft');
                    $post_data = array(
                        'post_title' => __('Draft', 'wp-smart-linker'),
                        'post_content' => $post_content,
                        'post_status' => 'auto-draft',
                        'post_type' => 'post'
                    );
                    error_log('WSL Debug - Creating new auto-draft');
                    $post_id = wp_insert_post($post_data);
                } else {
                    error_log('WSL Debug - Updating existing post: ' . $post_id);
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $post_content
                    ));
                }

                if (is_wp_error($post_id)) {
                    throw new \Exception($post_id->get_error_message());
                }

                error_log('WSL Debug - Post ID after save: ' . $post_id);

                try {
                    // Analyze content
                    error_log('WSL Debug - Starting content analysis');
                    $sections = $this->content_processor->analyze_content($post_content);
                    if (!empty($sections)) {
                        error_log('WSL Debug - Content analysis found ' . count($sections) . ' sections');
                        error_log('WSL Debug - Sections: ' . print_r($sections, true));
                        
                        // Store sections for later use
                        error_log('WSL Debug - Storing content sections');
                        update_post_meta($post_id, '_wsl_content_sections', $sections);
                        
                        // Get suggestions
                        error_log('WSL Debug - Requesting suggestions from OpenAI');
                        $suggestions = $this->openai_integration->analyze_content_for_links($sections, $post_id);
                        error_log('WSL Debug - Generated ' . count($suggestions) . ' suggestions');
                        error_log('WSL Debug - Raw suggestions: ' . print_r($suggestions, true));
                        
                        // Enhance suggestions with target titles
                        error_log('WSL Debug - Enhancing suggestions with target titles');
                        foreach ($suggestions as &$suggestion) {
                            $target_post = get_post($suggestion['target_post_id']);
                            if ($target_post) {
                                $suggestion['target_title'] = $target_post->post_title;
                                error_log('WSL Debug - Added title for post ' . $target_post->ID . ': ' . $target_post->post_title);
                            }
                        }

                        // Store suggestions
                        error_log('WSL Debug - Storing suggestions in post meta');
                        update_post_meta($post_id, '_wsl_link_suggestions', $suggestions);

                        error_log('WSL Debug - Sending success response');
                        wp_send_json_success([
                            'message' => __('Link suggestions generated successfully', 'wp-smart-linker'),
                            'suggestions' => array_values($suggestions),
                            'post_id' => $post_id
                        ]);
                        return;
                    } else {
                        error_log('WSL Debug - No sections found in content analysis');
                    }
                } catch (\Exception $e) {
                    error_log('WSL Error in content analysis: ' . $e->getMessage());
                    error_log('WSL Error trace: ' . $e->getTraceAsString());
                    throw $e;
                }
            }

            // If no content provided or sections found, try to use existing post
            if (!$post_id) {
                throw new \Exception('No content or post ID provided');
            }

            if (!current_user_can('edit_post', $post_id)) {
                throw new \Exception('Permission denied');
            }

            $post = get_post($post_id);
            if (!$post) {
                throw new \Exception('Invalid post');
            }

            error_log('WSL Debug - Using post content for analysis');

            // Process suggestions and get updated list
            $this->process_suggestions($post_id, $post);
            $suggestions = get_post_meta($post_id, '_wsl_link_suggestions', true) ?: [];

            // Enhance suggestions with target post titles and clean section content
            foreach ($suggestions as &$suggestion) {
                $target_post = get_post($suggestion['target_post_id']);
                if (!$target_post) {
                    continue;
                }
                $suggestion['target_title'] = $target_post->post_title;
                
                // Clean and truncate section content for preview
                $content = wp_strip_all_tags($suggestion['section_content']);
                $content = preg_replace('/\s+/', ' ', $content); // Normalize whitespace
                $suggestion['section_content'] = $content;
            }

            // Remove any suggestions where target post wasn't found
            $suggestions = array_filter($suggestions, function($s) {
                return !empty($s['target_title']);
            });

            // Reset array keys
            $suggestions = array_values($suggestions);

            wp_send_json_success([
                'message' => __('Link suggestions refreshed successfully', 'wp-smart-linker'),
                'suggestions' => $suggestions,
                'post_id' => $post_id
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle AJAX request to apply suggestion
     */
    public function ajax_apply_suggestion() {
        try {
            error_log('WSL Debug - AJAX request received: ' . print_r($_POST, true));

            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'wsl_apply_suggestion')) {
                throw new \Exception('Invalid security token');
            }

            $post_id = intval($_POST['post_id']);
            if (!$post_id) {
                throw new \Exception('Invalid post ID');
            }

            // Clean and validate data
            $raw_suggestion = $_POST['suggestion'];
            if (is_string($raw_suggestion)) {
                $suggestion = json_decode(stripslashes($raw_suggestion), true);
            } else {
                $suggestion = $raw_suggestion;
            }

            if (!is_array($suggestion) ||
                !isset($suggestion['anchor_text']) ||
                !isset($suggestion['target_post_id']) ||
                !isset($suggestion['section_content'])) {
                throw new \Exception('Invalid or incomplete suggestion data');
            }

            // Sanitize suggestion data
            $suggestion = array_map('sanitize_text_field', $suggestion);

            // Check permissions and get post
            if (!current_user_can('edit_post', $post_id)) {
                throw new \Exception('Permission denied');
            }

            $post = get_post($post_id);
            if (!$post || $post->post_status === 'trash') {
                throw new \Exception('Invalid post');
            }

            error_log('WSL Debug - Processing suggestion: ' . print_r($suggestion, true));

            // Get the target URL for the link
            $target_url = get_permalink($suggestion['target_post_id']);
            if (!$target_url) {
                throw new \Exception('Could not get target URL');
            }

            // Track that we applied this suggestion
            $this->track_applied_suggestion($post_id, $suggestion);
            
            error_log('WSL Debug - Successfully completed suggestion tracking');
            
            // Send back the URL for the JavaScript to handle
            wp_send_json_success([
                'message' => 'Ready to insert link',
                'target_url' => $target_url,
                'anchor_text' => $suggestion['anchor_text']
            ]);

        } catch (\Exception $e) {
            error_log('WSL Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Track that a suggestion was applied
     *
     * @param int $post_id Post ID
     * @param array $suggestion The suggestion being applied
     * @throws \Exception If updates fail
     */
    private function track_applied_suggestion($post_id, $suggestion) {
        error_log('WSL Debug - Tracking applied suggestion');

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new \Exception('Invalid post ID');
        }

        // Prepare new link data
        $new_link = [
            'target_post_id' => absint($suggestion['target_post_id']),
            'anchor_text' => sanitize_text_field($suggestion['anchor_text']),
            'date_applied' => current_time('mysql')
        ];

        // Get existing applied links with fallback
        $applied = get_post_meta($post_id, '_wsl_applied_links', true);
        $applied = is_array($applied) ? $applied : [];
        
        // Add new link
        $applied[] = $new_link;

        // Delete and re-add to ensure clean data
        delete_post_meta($post_id, '_wsl_applied_links');
        $result = add_post_meta($post_id, '_wsl_applied_links', $applied);
        
        if (!$result) {
            throw new \Exception('Failed to save applied link');
        }

        // Get existing suggestions with fallback
        $suggestions = get_post_meta($post_id, '_wsl_link_suggestions', true);
        $suggestions = is_array($suggestions) ? $suggestions : [];
        
        // Filter out the applied suggestion
        $suggestions = array_filter($suggestions, function($s) use ($suggestion) {
            return $s['section_index'] !== $suggestion['section_index'] ||
                   $s['target_post_id'] !== $suggestion['target_post_id'];
        });

        // Delete and re-add to ensure clean data
        delete_post_meta($post_id, '_wsl_link_suggestions');
        $result = add_post_meta($post_id, '_wsl_link_suggestions', array_values($suggestions));
        
        if (!$result) {
            throw new \Exception('Failed to update suggestions');
        }

        error_log('WSL Debug - Successfully tracked suggestion');
    }

    /**
     * Insert link into content
     *
     * @param string $content Post content
     * @param array $suggestion Link suggestion
     * @return string Modified content
     */
    private function insert_link($content, $suggestion) {
        error_log('WSL Debug - Starting link insertion');

        // Get and validate target URL
        $target_url = get_permalink($suggestion['target_post_id']);
        if (!$target_url) {
            throw new \Exception('Invalid target post URL');
        }

        // Prepare variables
        $anchor_text = $suggestion['anchor_text'];
        $link_html = '<a href="' . esc_url($target_url) . '">' . esc_html($anchor_text) . '</a>';

        error_log('WSL Debug - Anchor text to find: "' . $anchor_text . '"');
        error_log('WSL Debug - Link HTML to insert: "' . $link_html . '"');
        error_log('WSL Debug - Content length: ' . strlen($content));

        // Find position of anchor text
        $pos = strpos($content, $anchor_text);
        if ($pos === false) {
            error_log('WSL Debug - Could not find exact anchor text');
            throw new \Exception('Could not find the exact text to link');
        }

        // Build new content
        $new_content = substr($content, 0, $pos);
        $new_content .= $link_html;
        $new_content .= substr($content, $pos + strlen($anchor_text));

        // Verify the change
        if ($new_content === $content) {
            error_log('WSL Debug - Content unchanged after link insertion');
            throw new \Exception('Content was not modified during link insertion');
        }

        error_log('WSL Debug - Link insertion successful');
        error_log('WSL Debug - New content length: ' . strlen($new_content));
        
        error_log('WSL Debug - Link insertion complete');
        return $new_content;
    }
}