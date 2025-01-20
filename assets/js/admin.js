jQuery(document).ready(function($) {
    // Handle threshold range input
    const $thresholdInput = $('#wsl_suggestion_threshold');
    const $thresholdValue = $thresholdInput.next('.threshold-value');
    
    $thresholdInput.on('input change', function() {
        $thresholdValue.text($(this).val());
    });

    // Handle refresh suggestions
    $('.wsl-refresh-suggestions').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        // Check for unsaved changes
        if (wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').isEditedPostDirty()) {
            if (!confirm('You have unsaved changes. Save your post first?')) {
                return;
            }
            wp.data.dispatch('core/editor').savePost();
            return;
        }
        
        // Show loading state
        $button.prop('disabled', true).text('Refreshing...');
        const $suggestionsArea = $('.wsl-suggestions');
        const $loadingMessage = $('<p>').text('Analyzing content...');
        $suggestionsArea.find('table').hide();
        $suggestionsArea.append($loadingMessage);
        
        // Get current content
        let postContent = '';
        if (wp.data && wp.data.select('core/editor')) {
            postContent = wp.data.select('core/editor').getEditedPostContent();
        } else if (tinyMCE && tinyMCE.get('content')) {
            postContent = tinyMCE.get('content').getContent();
        } else {
            postContent = $('#content').val();
        }
        
        // Send refresh request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wsl_refresh_suggestions',
                post_id: $('#post_ID').val() || 0,
                post_content: postContent,
                nonce: wsl.nonce
            },
            beforeSend: function() {
                console.log('Sending refresh request with:', {
                    postId: $('#post_ID').val() || 0,
                    contentLength: postContent.length,
                    nonce: wsl.nonce
                });
            },
            success: function(response) {
                if (response.success) {
                    const $tbody = $('.wsl-suggestions tbody');
                    $tbody.empty();
                    
                    if (response.data.suggestions && response.data.suggestions.length > 0) {
                        response.data.suggestions.forEach(function(suggestion) {
                            const $row = createSuggestionRow(suggestion);
                            $tbody.append($row);
                        });
                        $suggestionsArea.find('table').show();
                        showNotice('Suggestions refreshed successfully', 'success');
                        
                        // Rebind apply suggestion handlers
                        bindSuggestionHandlers();
                    } else {
                        $tbody.append($('<tr><td colspan="4">').text('No suggestions found for this content'));
                        showNotice('No suggestions found', 'info');
                    }
                } else {
                    showNotice(response.data.message || 'Error refreshing suggestions', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                showNotice('Error refreshing suggestions: ' + error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Refresh Suggestions');
                $loadingMessage.remove();
                $suggestionsArea.find('table').show();
            }
        });
    });

    // Function to bind suggestion handlers
    function bindSuggestionHandlers() {
        $('.wsl-apply-suggestion').off('click').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $row = $button.closest('tr');
            const suggestion = JSON.parse($button.attr('data-suggestion'));
            const nonce = $button.attr('data-nonce');

            // Show loading state
            $row.addClass('wsl-loading');
            $button.prop('disabled', true);

            // Insert link function
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
                                    updateEmptyState();
                                });
                                
                                showNotice('Link successfully added!', 'success');
                            } else {
                                throw new Error('Could not insert link into content');
                            }
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
                    showNotice('Error applying suggestion', 'error');
                    $row.removeClass('wsl-loading');
                    $button.prop('disabled', false);
                }
            });
        });
    }

    // Bind initial suggestion handlers
    bindSuggestionHandlers();

    // Create suggestion row
    function createSuggestionRow(suggestion) {
        const $row = $('<tr>');
        
        // Content preview
        const words = suggestion.section_content.split(/\s+/).slice(0, 10);
        const preview = words.join(' ') + (words.length >= 10 ? '...' : '');
        $row.append($('<td>').text(preview));
        
        // Link suggestion
        $row.append(
            $('<td>').html(
                'Link "' + 
                $('<div>').text(suggestion.anchor_text).html() + 
                '" to "' + 
                $('<div>').text(suggestion.target_title).html() + 
                '"'
            )
        );
        
        // Relevance score
        $row.append(
            $('<td>').text(Math.round(suggestion.relevance_score * 100) + '%')
        );
        
        // Action button
        const $button = $('<button>')
            .addClass('button button-secondary wsl-apply-suggestion')
            .attr({
                'type': 'button',
                'data-suggestion': JSON.stringify(suggestion),
                'data-nonce': wsl.apply_nonce,
                'data-post-id': $('#post_ID').val()
            })
            .text('Apply');
            
        $row.append($('<td>').append($button));
        
        return $row;
    }

    // Update empty state message
    function updateEmptyState() {
        if ($('.wsl-suggestions tbody tr').length === 0) {
            $('.wsl-suggestions tbody').append(
                '<tr><td colspan="4">No more suggestions available.</td></tr>'
            );
        }
    }

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
     * @param {string} type Notice type (success/error/info)
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