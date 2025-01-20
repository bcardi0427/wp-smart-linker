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
        
        // Set up dependencies if available
        if (!empty($wsl_instances)) {
            $this->content_processor = $wsl_instances['content_processor'] ?? null;
            $this->openai_integration = $wsl_instances['openai'] ?? null;
        }
        
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
            'nonce' => wp_create_nonce('wsl_apply_suggestion')
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
                const nonce = button.data('nonce');
                const postId = button.data('post-id');
                
                button.prop('disabled', true)
                     .text('<?php _e("Refreshing...", "wp-smart-linker"); ?>');
                
                $.ajax({
                    url: wsl.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wsl_refresh_suggestions',
                        post_id: postId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload(); // Reload to show new suggestions
                        } else {
                            alert(response.data.message || '<?php _e("Error refreshing suggestions", "wp-smart-linker"); ?>');
                        }
                    },
                    error: function() {
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
            $sections = $this->content_processor->get_content_sections($post->post_content);
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

            $post_id = intval($_POST['post_id']);
            if (!$post_id) {
                throw new \Exception('Invalid post ID');
            }

            // Check permissions
            if (!current_user_can('edit_post', $post_id)) {
                throw new \Exception('Permission denied');
            }

            $post = get_post($post_id);
            if (!$post) {
                throw new \Exception('Invalid post');
            }

            // Process suggestions
            $this->process_suggestions($post_id, $post);

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