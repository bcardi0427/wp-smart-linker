jQuery(document).ready(function($) {
    // Handle threshold range input
    const $thresholdInput = $('#wsl_suggestion_threshold');
    const $thresholdValue = $thresholdInput.next('.threshold-value');
    
    $thresholdInput.on('input change', function() {
        $thresholdValue.text($(this).val());
    });

    // Handle refresh suggestions
    $('.wsl-refresh-suggestions').on('click', async function(e) {
        e.preventDefault();
        const $button = $(this);
        const $suggestionsArea = $('.wsl-suggestions');
        
        try {
            // Get current content
            let postContent = '';
            if (wp.data && wp.data.select('core/editor')) {
                postContent = wp.data.select('core/editor').getEditedPostContent();
            } else if (tinyMCE && tinyMCE.get('content')) {
                postContent = tinyMCE.get('content').getContent();
            } else {
                postContent = $('#content').val();
            }

            // Show loading state
            $button.prop('disabled', true).text('Analyzing content...');
            $suggestionsArea.find('table').fadeOut(200, function() {
                const $loadingMessage = $('<div class="wsl-loading">').text('Analyzing content and generating suggestions...');
                $suggestionsArea.append($loadingMessage);
            });

            // Make AJAX request
            const response = await $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsl_refresh_suggestions',
                    post_id: $('#post_ID').val() || 0,
                    post_content: postContent,
                    nonce: wsl.nonce
                }
            });

            // Handle response
            if (response.success) {
                const $tbody = $('.wsl-suggestions tbody');
                $tbody.empty();
                
                // Update post ID if new post was created
                if (response.data.post_id) {
                    $('#post_ID').val(response.data.post_id);
                }
                
                if (response.data.suggestions && response.data.suggestions.length > 0) {
                    // Add suggestions to table
                    response.data.suggestions.forEach(function(suggestion) {
                        const $row = createSuggestionRow(suggestion);
                        $tbody.append($row);
                    });

                    // Show table and success message
                    $('.wsl-loading').remove();
                    $suggestionsArea.find('table').fadeIn();
                    showNotice('Suggestions generated successfully', 'success');

                    // Rebind handlers
                    bindSuggestionHandlers();
                } else {
                    // Show no suggestions message
                    $('.wsl-loading').remove();
                    $tbody.append($('<tr><td colspan="4">').text('No suggestions found for this content'));
                    $suggestionsArea.find('table').fadeIn();
                    showNotice('Analysis complete - no suggestions found', 'info');
                }
            } else {
                throw new Error(response.data.message || 'Error refreshing suggestions');
            }
        } catch (error) {
            console.error('Refresh error:', error);
            showNotice(error.message || 'Error refreshing suggestions', 'error');
        } finally {
            // Reset button state
            $button.prop('disabled', false).text('Refresh Suggestions');
            $('.wsl-loading').remove();
            $suggestionsArea.find('table').fadeIn();
        }
    });

    // Handle suggestion application
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

            // Send AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsl_apply_suggestion',
                    suggestion: suggestion,
                    post_id: $('#post_ID').val(),
                    nonce: wsl.apply_nonce
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
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    showNotice('Error applying suggestion: ' + error, 'error');
                    $row.removeClass('wsl-loading');
                    $button.prop('disabled', false);
                }
            });
        });
    }

    // Initial bind
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

    // Insert link into content
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