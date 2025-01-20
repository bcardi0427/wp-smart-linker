jQuery(document).ready(function($) {
    // Handle threshold range input
    const $thresholdInput = $('#wsl_suggestion_threshold');
    const $thresholdValue = $thresholdInput.next('.threshold-value');
    
    $thresholdInput.on('input change', function() {
        $thresholdValue.text($(this).val());
    });

    // Handle suggestion application
    $('.wsl-apply-suggestion').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $row = $button.closest('tr');
        const suggestion = $button.data('suggestion');
        const nonce = $button.data('nonce');

        // Show loading state
        $row.addClass('wsl-loading');
        $button.prop('disabled', true);

        function insertLink(response) {
            var anchorText = response.data.anchor_text;
            var targetUrl = response.data.target_url;
            
            // Block Editor (Gutenberg)
            if (wp.data && wp.data.select('core/editor')) {
                var content = wp.data.select('core/editor').getEditedPostContent();
                var blocks = wp.blocks.parse(content);
                
                blocks = blocks.map(function(block) {
                    if (block.name === 'core/paragraph' &&
                        block.attributes &&
                        block.attributes.content &&
                        block.attributes.content.includes(anchorText)) {
                        
                        block.attributes.content = block.attributes.content.replace(
                            anchorText,
                            '<a href="' + targetUrl + '">' + anchorText + '</a>'
                        );
                    }
                    return block;
                });
                
                wp.data.dispatch('core/editor').resetBlocks(blocks);
                return true;
            }
            
            // Classic Editor
            else if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                var editor = tinyMCE.get('content');
                var content = editor.getContent();
                
                if (content.includes(anchorText)) {
                    content = content.replace(
                        anchorText,
                        '<a href="' + targetUrl + '">' + anchorText + '</a>'
                    );
                    editor.setContent(content);
                    return true;
                }
            }
            
            return false;
        }

        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wsl_apply_suggestion',
                suggestion: suggestion,
                post_id: $('#post_ID').val(),
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    try {
                        if (insertLink(response)) {
                            // Remove suggestion row
                            $row.fadeOut(400, function() {
                                $(this).remove();
                                if ($('.wsl-suggestions tbody tr').length === 0) {
                                    $('.wsl-suggestions tbody').append(
                                        '<tr><td colspan="4">No more suggestions available.</td></tr>'
                                    );
                                }
                            });
                            
                            showNotice('Link successfully added!', 'success');
                        } else {
                            throw new Error('Could not insert link into content');
                        }
                        
                        // Remove the suggestion row
                        $row.fadeOut(400, function() {
                            $(this).remove();
                            if ($('.wsl-suggestions tbody tr').length === 0) {
                                $('.wsl-suggestions tbody').append(
                                    '<tr><td colspan="4">No more suggestions available.</td></tr>'
                                );
                            }
                        });
                        
                        showNotice('Link successfully added!', 'success');
                    } catch (err) {
                        console.error('Error applying link:', err);
                        showNotice('Error applying link: ' + err.message, 'error');
                        $row.removeClass('wsl-loading');
                        $button.prop('disabled', false);
                    }
                } else {
                    showNotice(response.data.message || 'Error applying suggestion', 'error');
                    $row.removeClass('wsl-loading');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                // Show error notice
                showNotice('Error applying suggestion', 'error');
                
                // Reset loading state
                $row.removeClass('wsl-loading');
                $button.prop('disabled', false);
            }
        });
    });

    // Handle excluded post types checkboxes
    const $excludedTypes = $('input[name="wsl_settings[excluded_post_types][]"]');
    
    $excludedTypes.on('change', function() {
        // Ensure at least one post type is enabled
        if ($excludedTypes.filter(':checked').length === $excludedTypes.length) {
            $(this).prop('checked', false);
            showNotice('At least one post type must be enabled', 'error');
        }
    });

    /**
     * Show notice message
     * 
     * @param {string} message Message to display
     * @param {string} type Notice type (success/error)
     */
    function showNotice(message, type = 'success') {
        const $notice = $(
            '<div class="wsl-notice wsl-notice-' + type + '">' +
            '<p>' + message + '</p>' +
            '</div>'
        );

        // Remove any existing notices
        $('.wsl-notice').remove();

        // Add new notice
        $('.wsl-suggestions').prepend($notice);

        // Auto hide after 3 seconds
        setTimeout(function() {
            $notice.fadeOut(400, function() {
                $(this).remove();
            });
        }, 3000);
    }
});