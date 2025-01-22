<?php
namespace WSL;

class Admin {
   private $openai;
   private $deepseek;
   private $gemini;
   private $firebase;

    public function __construct() {
       global $wsl_instances;
       
       // Initialize AI provider instances
       $this->openai = $wsl_instances['openai'] ?? null;
       $this->deepseek = $wsl_instances['deepseek'] ?? null;
       $this->gemini = $wsl_instances['gemini'] ?? null;
       
       // Initialize Firebase instance
       $this->firebase = $wsl_instances['firebase'] ?? null;
        
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('wp_ajax_wsl_test_firebase', [$this, 'handle_firebase_test']);
        add_action('wp_ajax_wsl_sync_firebase', [$this, 'handle_firebase_sync']);
        add_action('wp_ajax_wsl_get_suggestions', [$this, 'handle_suggestions_ajax']);
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __('Smart Linker Settings', 'wp-smart-linker'),
            __('Smart Linker', 'wp-smart-linker'),
            'manage_options',
            'wp-smart-linker',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('wsl_settings', 'wsl_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => [
                'ai_provider' => 'openai',
                'openai_api_key' => '',
                'openai_model' => 'gpt-3.5-turbo',
                'deepseek_api_key' => '',
                'deepseek_model' => 'deepseek-chat',
                'gemini_api_key' => '',
                'gemini_model' => 'gemini-pro',
                'suggestion_threshold' => 0.7,
                'max_links_per_post' => 5,
                'excluded_post_types' => ['attachment']
            ]
        ]);

        // AI Provider Section
        add_settings_section(
            'wsl_ai_section',
            __('AI Provider Settings', 'wp-smart-linker'),
            [$this, 'render_ai_section_description'],
            'wp-smart-linker'
        );

        // AI Provider Selection
        add_settings_field(
            'ai_provider',
            __('AI Provider', 'wp-smart-linker'),
            [$this, 'render_provider_field'],
            'wp-smart-linker',
            'wsl_ai_section'
        );

        // OpenAI Settings
        add_settings_field(
            'openai_settings',
            __('OpenAI Settings', 'wp-smart-linker'),
            [$this, 'render_openai_fields'],
            'wp-smart-linker',
            'wsl_ai_section'
        );

        // DeepSeek Settings
        add_settings_field(
            'deepseek_settings',
            __('DeepSeek Settings', 'wp-smart-linker'),
            [$this, 'render_deepseek_fields'],
            'wp-smart-linker',
            'wsl_ai_section'
        );

        // Gemini Settings
        add_settings_field(
            'gemini_settings',
            __('Google Gemini Settings', 'wp-smart-linker'),
            [$this, 'render_gemini_fields'],
            'wp-smart-linker',
            'wsl_ai_section'
        );

        // Advanced Settings Section
        add_settings_section(
            'wsl_advanced_section',
            __('Advanced Settings', 'wp-smart-linker'),
            [$this, 'render_advanced_section_description'],
            'wp-smart-linker'
        );

        // Add suggestion threshold field
        add_settings_field(
            'suggestion_threshold',
            __('Suggestion Threshold', 'wp-smart-linker'),
            [$this, 'render_threshold_field'],
            'wp-smart-linker',
            'wsl_advanced_section'
        );

        add_settings_field(
            'max_links_per_post',
            __('Maximum Links per Post', 'wp-smart-linker'),
            [$this, 'render_max_links_field'],
            'wp-smart-linker',
            'wsl_advanced_section'
        );

        add_settings_field(
            'excluded_post_types',
            __('Excluded Post Types', 'wp-smart-linker'),
            [$this, 'render_excluded_types_field'],
            'wp-smart-linker',
            'wsl_advanced_section'
        );

       // Add Firebase section
       add_settings_section(
           'wsl_firebase_section',
           __('Firebase Configuration', 'wp-smart-linker'),
           [$this, 'render_firebase_section_description'],
           'wp-smart-linker'
       );

       // Add Firebase credentials field
       add_settings_field(
           'firebase_credentials',
           __('Firebase Service Account JSON', 'wp-smart-linker'),
           [$this, 'render_firebase_credentials_field'],
           'wp-smart-linker',
           'wsl_firebase_section'
       );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Always enqueue on post editor screens
        $is_editor = in_array($hook, ['post.php', 'post-new.php']);
        $is_settings = 'settings_page_wp-smart-linker' === $hook;

        if (!$is_editor && !$is_settings) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'wp-jquery-ui-dialog'  // This includes basic jQuery UI styles
        );
        
        wp_enqueue_style(
            'wsl-admin',
            plugins_url('assets/css/admin.css', dirname(__FILE__)),
            ['wp-jquery-ui-dialog'],
            WSL_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'wsl-admin',
            plugins_url('assets/js/admin.js', dirname(__FILE__)),
            ['jquery', 'jquery-ui-tooltip'],
            WSL_VERSION,
            true
        );

        // Set up localized data based on context
        // Common data for both contexts
        $common_data = [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wsl_nonce')
        ];

        // Settings page specific data
        if ($is_settings) {
            wp_localize_script('wsl-admin', 'wslAdmin', array_merge($common_data, [
                'testingConnection' => __('Testing connection...', 'wp-smart-linker'),
                'connectionSuccess' => __('Connection successful!', 'wp-smart-linker'),
                'connectionFailed' => __('Connection failed: ', 'wp-smart-linker'),
                'syncingPosts' => __('Syncing posts...', 'wp-smart-linker'),
                'syncSuccess' => __('Posts synced successfully!', 'wp-smart-linker'),
                'syncFailed' => __('Sync failed: ', 'wp-smart-linker')
            ]));
        }

        // Post editor specific data
        if ($is_editor) {
            wp_localize_script('wsl-admin', 'wslAdmin', array_merge($common_data, [
                'gettingSuggestions' => __('Getting suggestions...', 'wp-smart-linker'),
                'noSuggestionsFound' => __('No suggestions available', 'wp-smart-linker'),
                'errorGettingSuggestions' => __('Error getting suggestions: ', 'wp-smart-linker'),
                'applySuggestion' => __('Apply', 'wp-smart-linker'),
                'suggestionApplied' => __('Link added successfully!', 'wp-smart-linker'),
                'errorApplyingSuggestion' => __('Error applying suggestion: ', 'wp-smart-linker')
            ]));
        }
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wsl_settings');
                do_settings_sections('wp-smart-linker');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render section description
     */
    public function render_ai_section_description() {
        echo '<p>' . esc_html__('Configure your AI provider settings below.', 'wp-smart-linker') . '</p>';
    }

    /**
     * Render advanced section description
     */
    public function render_advanced_section_description() {
        echo '<p>' . esc_html__('Configure advanced settings for link suggestions.', 'wp-smart-linker') . '</p>';
    }

    /**
     * Render provider selection field
     */
    public function render_provider_field() {
        $options = get_option('wsl_settings');
        $current = $options['ai_provider'] ?? 'openai';
        ?>
        <select name="wsl_settings[ai_provider]" id="wsl_ai_provider" class="regular-text">
            <option value="openai" <?php selected($current, 'openai'); ?>>OpenAI</option>
            <option value="deepseek" <?php selected($current, 'deepseek'); ?>>DeepSeek</option>
            <option value="gemini" <?php selected($current, 'gemini'); ?>>Google Gemini</option>
        </select>
        <p class="description">
            <?php _e('Select which AI provider to use for generating link suggestions.', 'wp-smart-linker'); ?>
        </p>
        <script>
            jQuery(document).ready(function($) {
                function updateProviderSettings() {
                    const provider = $('#wsl_ai_provider').val();
                    $('.wsl-provider-fields').hide();
                    $('#wsl_' + provider + '_settings').show();
                }
                
                $('#wsl_ai_provider').on('change', updateProviderSettings);
                updateProviderSettings();
            });
        </script>
        <?php
    }

    /**
     * Render OpenAI settings
     */
    public function render_openai_fields() {
        $options = get_option('wsl_settings');
        $api_key = $options['openai_api_key'] ?? '';
        $selected_model = $options['openai_model'] ?? 'gpt-3.5-turbo';
        ?>
        <div id="wsl_openai_settings" class="wsl-provider-fields">
            <p>
                <label for="wsl_openai_api_key"><?php _e('API Key', 'wp-smart-linker'); ?></label><br>
                <input
                    type="password"
                    id="wsl_openai_api_key"
                    name="wsl_settings[openai_api_key]"
                    value="<?php echo esc_attr($api_key); ?>"
                    class="regular-text"
                    placeholder="sk-..."
                />
                <p class="description">
                    <?php _e('Enter your OpenAI API key. Get one from', 'wp-smart-linker'); ?>
                    <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Dashboard</a>
                </p>
            </p>
            
            <p>
                <label for="wsl_openai_model"><?php _e('Model', 'wp-smart-linker'); ?></label><br>
                <select
                    id="wsl_openai_model"
                    name="wsl_settings[openai_model]"
                    class="regular-text"
                >
                    <?php
                    $models = $this->openai && $this->openai->is_configured() ?
                        $this->openai->get_available_models() :
                        [
                            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                            'gpt-4' => 'GPT-4',
                            'gpt-4-turbo' => 'GPT-4 Turbo'
                        ];
                    foreach ($models as $value => $label):
                    ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_model, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>
        <?php
    }

    /**
     * Render DeepSeek settings
     */
    public function render_deepseek_fields() {
        $options = get_option('wsl_settings');
        $api_key = $options['deepseek_api_key'] ?? '';
        $selected_model = $options['deepseek_model'] ?? 'deepseek-chat';
        ?>
        <div id="wsl_deepseek_settings" class="wsl-provider-fields">
            <p>
                <label for="wsl_deepseek_api_key"><?php _e('API Key', 'wp-smart-linker'); ?></label><br>
                <input
                    type="password"
                    id="wsl_deepseek_api_key"
                    name="wsl_settings[deepseek_api_key]"
                    value="<?php echo esc_attr($api_key); ?>"
                    class="regular-text"
                />
                <p class="description">
                    <?php _e('Enter your DeepSeek API key.', 'wp-smart-linker'); ?>
                </p>
            </p>
            
            <p>
                <label for="wsl_deepseek_model"><?php _e('Model', 'wp-smart-linker'); ?></label><br>
                <select
                    id="wsl_deepseek_model"
                    name="wsl_settings[deepseek_model]"
                    class="regular-text"
                >
                    <?php
                    $models = [
                        'deepseek-chat' => 'DeepSeek V3 Chat',
                        'deepseek-reasoner' => 'DeepSeek R1 Reasoner'
                    ];
                    
                    if (isset($wsl_instances['deepseek']) && $wsl_instances['deepseek']->is_configured()) {
                        try {
                            $api_models = $wsl_instances['deepseek']->get_available_models();
                            if (!empty($api_models)) {
                                $models = $api_models;
                            }
                        } catch (\Exception $e) {
                            error_log('WSL Error fetching DeepSeek models: ' . $e->getMessage());
                        }
                    }

                    foreach ($models as $value => $label):
                    ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_model, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>
        <?php
    }

    /**
     * Render Gemini settings
     */
    public function render_gemini_fields() {
        $options = get_option('wsl_settings');
        $api_key = $options['gemini_api_key'] ?? '';
        $selected_model = $options['gemini_model'] ?? 'gemini-pro';
        ?>
        <div id="wsl_gemini_settings" class="wsl-provider-fields">
            <p>
                <label for="wsl_gemini_api_key"><?php _e('API Key', 'wp-smart-linker'); ?></label><br>
                <input
                    type="password"
                    id="wsl_gemini_api_key"
                    name="wsl_settings[gemini_api_key]"
                    value="<?php echo esc_attr($api_key); ?>"
                    class="regular-text"
                />
                <p class="description">
                    <?php _e('Enter your Google AI API key. Get one from', 'wp-smart-linker'); ?>
                    <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a>
                </p>
            </p>
            
            <p>
                <label for="wsl_gemini_model"><?php _e('Model', 'wp-smart-linker'); ?></label><br>
                <select
                    id="wsl_gemini_model"
                    name="wsl_settings[gemini_model]"
                    class="regular-text"
                >
                    <?php
                    $models = [
                        'gemini-2.0-flash' => 'Gemini 2.0 Flash (Experimental)',
                        'gemini-1.5-flash' => 'Gemini 1.5 Flash',
                        'gemini-1.5-pro' => 'Gemini 1.5 Pro',
                        'gemini-1.0-pro' => 'Gemini 1.0 Pro (Deprecated)',
                        'gemini-1.0-pro-vision' => 'Gemini 1.0 Pro Vision',
                        'text-embedding' => 'Text Embedding',
                        'aqa' => 'AQA (Question Answering)'
                    ];

                    // Add description/tooltip for each model
                    $model_descriptions = [
                        'gemini-2.0-flash' => 'Latest multimodal model with next-gen features, superior speed, native tool use. Supports text, images, audio, video.',
                        'gemini-1.5-flash' => 'Fast, versatile model supporting text, code, images, audio, video, PDF inputs. Ideal for high-volume applications.',
                        'gemini-1.5-pro' => 'High-performing model for reasoning tasks. Supports text, code, images, audio, video, PDF inputs.',
                        'gemini-1.0-pro' => 'Legacy model for natural language tasks and code. Text only. Deprecated as of Feb 15, 2025.',
                        'gemini-1.0-pro-vision' => 'Legacy multimodal model for text, images, video. No chat support.',
                        'text-embedding' => 'Measures text string relatedness, provides text embeddings.',
                        'aqa' => 'Source-grounded answers to questions.'
                    ];
                    
                    if (isset($wsl_instances['gemini']) && $wsl_instances['gemini']->is_configured()) {
                        try {
                            $api_models = $wsl_instances['gemini']->get_available_models();
                            if (!empty($api_models)) {
                                $models = $api_models;
                            }
                        } catch (\Exception $e) {
                            error_log('WSL Error fetching Gemini models: ' . $e->getMessage());
                        }
                    }

                    foreach ($models as $value => $label):
                        $description = isset($model_descriptions[$value]) ? $model_descriptions[$value] : '';
                    ?>
                        <option
                            value="<?php echo esc_attr($value); ?>"
                            <?php selected($selected_model, $value); ?>
                            title="<?php echo esc_attr($description); ?>"
                        >
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>

                    <script>
                        jQuery(document).ready(function($) {
                            // Initialize tooltips for model options
                            $('#wsl_gemini_model option').each(function() {
                                if ($(this).attr('title')) {
                                    $(this).tooltip();
                                }
                            });
                        });
                    </script>
                </select>
            </p>
        </div>
        <?php
    }

    /**
     * Render excluded post types field
     */
    public function render_excluded_types_field() {
        $options = get_option('wsl_settings');
        $excluded_types = $options['excluded_post_types'] ?? ['attachment'];
        $post_types = get_post_types(['public' => true], 'objects');
        
        foreach ($post_types as $post_type) {
            ?>
            <label>
                <input 
                    type="checkbox" 
                    name="wsl_settings[excluded_post_types][]" 
                    value="<?php echo esc_attr($post_type->name); ?>"
                    <?php checked(in_array($post_type->name, $excluded_types)); ?>
                />
                <?php echo esc_html($post_type->label); ?>
            </label><br>
            <?php
        }
        ?>
        <p class="description">
            <?php _e('Select post types to exclude from link suggestions', 'wp-smart-linker'); ?>
        </p>
        <?php
    }

   /**
    * Legacy method - no longer used as model selection is handled by provider-specific fields
    */
   public function render_model_field() {}

   /**
    * Render threshold field
    */
   public function render_threshold_field() {
       $options = get_option('wsl_settings');
       $threshold = $options['suggestion_threshold'] ?? 0.7;
       ?>
       <input
           type="range"
           id="wsl_suggestion_threshold"
           class="wsl-threshold"
           name="wsl_settings[suggestion_threshold]"
           min="0.1"
           max="1"
           step="0.1"
           value="<?php echo esc_attr($threshold); ?>"
       />
       <span class="wsl-threshold-value"><?php echo $threshold; ?></span>
       <p class="description">
           <?php _e('Minimum relevance score required for link suggestions (0.1 to 1.0)', 'wp-smart-linker'); ?>
       </p>
       <script>
           jQuery(document).ready(function($) {
               $('#wsl_suggestion_threshold').on('input change', function() {
                   $('.wsl-threshold-value').text($(this).val());
               });
           });
       </script>
       <?php
   }

   /**
    * Render max links field
    */
   public function render_max_links_field() {
       $options = get_option('wsl_settings');
       $max_links = $options['max_links_per_post'] ?? 5;
       ?>
       <input
           type="number"
           id="wsl_max_links"
           name="wsl_settings[max_links_per_post]"
           value="<?php echo esc_attr($max_links); ?>"
           min="1"
           max="20"
           class="small-text"
       />
       <p class="description">
           <?php _e('Maximum number of link suggestions to show per post (1 to 20)', 'wp-smart-linker'); ?>
       </p>
       <?php
   }

   /**
    * Render Firebase section description
    */
   public function render_firebase_section_description() {
       echo '<p>' . esc_html__('Configure Firebase integration for improved performance with large sites. This enables caching and faster link suggestions.', 'wp-smart-linker') . '</p>';
   }

   /**
    * Render Firebase credentials field
    */
   public function render_firebase_credentials_field() {
       $options = get_option('wsl_settings');
       $has_credentials = !empty($options['firebase_credentials']);
       ?>
       <div class="firebase-credentials-section">
           <?php if ($has_credentials): ?>
               <div class="notice notice-success inline">
                   <p><?php _e('Firebase credentials are configured.', 'wp-smart-linker'); ?></p>
               </div>
           <?php endif; ?>
           
           <textarea
               id="wsl_firebase_credentials"
               name="wsl_settings[firebase_credentials]"
               class="large-text code"
               rows="10"
               placeholder="<?php echo esc_attr__('Paste your Firebase service account JSON here', 'wp-smart-linker'); ?>"
           ><?php echo esc_textarea($options['firebase_credentials'] ?? ''); ?></textarea>

           <div class="firebase-actions">
               <div class="action-row">
                   <button type="button" id="wsl_test_firebase" class="button button-secondary">
                       <?php _e('Test Connection', 'wp-smart-linker'); ?>
                   </button>
                   <span id="wsl_firebase_test_result" style="margin-left: 10px; display: none;"></span>
               </div>
               
               <?php if ($has_credentials): ?>
               <div class="action-row" style="margin-top: 10px;">
                   <button type="button" id="wsl_sync_firebase" class="button button-secondary">
                       <?php _e('Sync All Posts', 'wp-smart-linker'); ?>
                   </button>
                   <span id="wsl_sync_result" style="margin-left: 10px; display: none;"></span>
                   <p class="description">
                       <?php _e('This will sync all published posts and pages to Firebase for faster link suggestions.', 'wp-smart-linker'); ?>
                   </p>
               </div>
               <?php endif; ?>
           </div>
           
           <p class="description">
               <?php _e('Paste the contents of your Firebase service account JSON file. Get this from Firebase Console > Project Settings > Service Accounts > Generate New Private Key.', 'wp-smart-linker'); ?>
               <br>
               <a href="https://console.firebase.google.com/project/_/settings/serviceaccounts/adminsdk" target="_blank">
                   <?php _e('Open Firebase Console →', 'wp-smart-linker'); ?>
               </a>
           </p>
       </div>
       <?php
   }

   /**
    * Sanitize settings
     *
     * @param array $input The submitted settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
       $sanitized = [];
       
       // AI Provider
       if (isset($input['ai_provider'])) {
           $provider = sanitize_text_field($input['ai_provider']);
           if (in_array($provider, ['openai', 'deepseek', 'gemini'])) {
               $sanitized['ai_provider'] = $provider;
           } else {
               $sanitized['ai_provider'] = 'openai';
           }
       }

       // OpenAI Settings
       if (isset($input['openai_api_key'])) {
           $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
       }
       if (isset($input['openai_model'])) {
           $model = sanitize_text_field($input['openai_model']);
           if ($this->openai && !$this->openai->is_valid_model($model)) {
               add_settings_error(
                   'wsl_settings',
                   'invalid_openai_model',
                   sprintf(
                       __('Invalid OpenAI model "%s" selected. Falling back to gpt-3.5-turbo.', 'wp-smart-linker'),
                       $model
                   )
               );
               $model = 'gpt-3.5-turbo';
           }
           $sanitized['openai_model'] = $model;
       }

       // DeepSeek Settings
       if (isset($input['deepseek_api_key'])) {
           $sanitized['deepseek_api_key'] = sanitize_text_field($input['deepseek_api_key']);
       }
       if (isset($input['deepseek_model'])) {
           $model = sanitize_text_field($input['deepseek_model']);
           if (!in_array($model, ['deepseek-chat', 'deepseek-coder'])) {
               $model = 'deepseek-chat';
           }
           $sanitized['deepseek_model'] = $model;
       }

       // Gemini Settings
       if (isset($input['gemini_api_key'])) {
           $sanitized['gemini_api_key'] = sanitize_text_field($input['gemini_api_key']);
       }
       if (isset($input['gemini_model'])) {
           $model = sanitize_text_field($input['gemini_model']);
           if (!in_array($model, ['gemini-pro', 'gemini-pro-vision'])) {
               $model = 'gemini-pro';
           }
           $sanitized['gemini_model'] = $model;
       }
        
        // Threshold
        if (isset($input['suggestion_threshold'])) {
            $threshold = floatval($input['suggestion_threshold']);
            $sanitized['suggestion_threshold'] = min(max($threshold, 0.1), 1.0);
        }
        
        // Max Links
        if (isset($input['max_links_per_post'])) {
            $max_links = intval($input['max_links_per_post']);
            $sanitized['max_links_per_post'] = min(max($max_links, 1), 20);
        }
        
        // Excluded Post Types
        if (isset($input['excluded_post_types']) && is_array($input['excluded_post_types'])) {
            $sanitized['excluded_post_types'] = array_map('sanitize_text_field', $input['excluded_post_types']);
        } else {
            $sanitized['excluded_post_types'] = ['attachment'];
        }

        // Firebase Credentials
        if (isset($input['firebase_credentials'])) {
            $credentials = trim($input['firebase_credentials']);
            if (!empty($credentials)) {
                // Attempt to parse and validate JSON
                $json_data = json_decode($credentials, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    add_settings_error(
                        'wsl_settings',
                        'invalid_firebase_json',
                        __('Invalid Firebase credentials JSON format', 'wp-smart-linker')
                    );
                } else {
                    // Validate required fields
                    $required_fields = ['type', 'project_id', 'private_key', 'client_email'];
                    $missing_fields = array_filter($required_fields, function($field) use ($json_data) {
                        return empty($json_data[$field]);
                    });

                    if (!empty($missing_fields)) {
                        add_settings_error(
                            'wsl_settings',
                            'missing_firebase_fields',
                            __('Firebase credentials JSON is missing required fields', 'wp-smart-linker')
                        );
                    } else {
                        $sanitized['firebase_credentials'] = $credentials;
                    }
                }
            }
        }
        
        return $sanitized;
    }

    /**
     * Handle Firebase connection test AJAX request
     */
    public function handle_firebase_test() {
        check_ajax_referer('wsl_firebase_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-smart-linker'));
        }

        try {
            // Get credentials from POST since they might not be saved yet
            $credentials = isset($_POST['credentials']) ? stripslashes($_POST['credentials']) : '';
            
            if (empty($credentials)) {
                throw new \Exception(__('No credentials provided', 'wp-smart-linker'));
            }

            // Basic JSON validation
            $json_data = json_decode($credentials, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception(__('Invalid JSON format', 'wp-smart-linker'));
            }

            // Test connection using current Firebase instance or temporary one
            if ($this->firebase) {
                $success = $this->firebase->test_connection($credentials);
            } else {
                // Create temporary Firebase instance
                $temp_firebase = new Firebase_Integration();
                $success = $temp_firebase->test_connection($credentials);
            }

            if ($success) {
                wp_send_json_success(__('Successfully connected to Firebase', 'wp-smart-linker'));
            } else {
                throw new \Exception(__('Could not establish connection', 'wp-smart-linker'));
            }

        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handle Firebase sync AJAX request
     */
    public function handle_firebase_sync() {
        check_ajax_referer('wsl_firebase_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-smart-linker'));
        }

        try {
            if (!$this->firebase || !$this->firebase->is_configured()) {
                throw new \Exception(__('Firebase is not properly configured', 'wp-smart-linker'));
            }

            // Start the sync process
            $result = $this->firebase->sync_data();
            
            if ($result === false) {
                throw new \Exception(__('Sync process failed', 'wp-smart-linker'));
            }

            wp_send_json_success(__('All posts have been synced to Firebase', 'wp-smart-linker'));

        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        $options = get_option('wsl_settings');
        $excluded_types = $options['excluded_post_types'] ?? [];
        
        $post_types = get_post_types(['public' => true]);
        foreach ($post_types as $type) {
            if (!in_array($type, $excluded_types)) {
                add_meta_box(
                    'wsl_suggestions',
                    __('Smart Link Suggestions', 'wp-smart-linker'),
                    [$this, 'render_meta_box'],
                    $type,
                    'side'
                );
            }
        }
    }

    /**
     * Render meta box
     */
    public function render_meta_box($post) {
        $options = get_option('wsl_settings');
        $provider = $options['ai_provider'] ?? 'openai';
        
        wp_nonce_field('wsl_meta_box', 'wsl_meta_box_nonce');
        ?>
        <div class="wsl-suggestions-wrapper">
            <div class="wsl-suggestions-content">
                <p><?php _e('Click to get AI-powered link suggestions.', 'wp-smart-linker'); ?></p>
                <button type="button" class="button wsl-get-suggestions" data-provider="<?php echo esc_attr($provider); ?>">
                    <?php _e('Get Suggestions', 'wp-smart-linker'); ?>
                </button>
                <span class="spinner"></span>
            </div>
            <div class="wsl-suggestions-list"></div>
        </div>
        <?php
    }

    /**
     * Handle suggestions AJAX request
     */
    public function handle_suggestions_ajax() {
        check_ajax_referer('wsl_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }

        try {
            global $wsl_instances;
            $options = get_option('wsl_settings');
            $provider = $options['ai_provider'] ?? 'openai';
            
            // Get the appropriate AI instance
            $ai = $wsl_instances[$provider] ?? null;
            if (!$ai || !$ai->is_configured()) {
                throw new \Exception("AI provider {$provider} not configured");
            }

            // Get content processor
            $content_processor = $wsl_instances['content_processor'] ?? null;
            if (!$content_processor) {
                throw new \Exception('Content processor not available');
            }

            // Get suggestions
            $sections = $content_processor->get_content_sections($post_id);
            if (empty($sections)) {
                throw new \Exception('No content sections found');
            }

            $suggestions = $ai->analyze_content_for_links($sections, $post_id);
            wp_send_json_success([
                'suggestions' => $suggestions,
                'sections' => $sections
            ]);

        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}