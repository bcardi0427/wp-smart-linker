<?php
namespace WSL;

class Admin {
    private $openai;

    /**
     * Initialize admin functionality
     */
    private $firebase;

    public function __construct() {
        global $wsl_instances;
        $this->openai = $wsl_instances['openai'] ?? null;
        $this->firebase = $wsl_instances['firebase'] ?? null;
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_wsl_test_firebase', [$this, 'handle_firebase_test']);
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
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section(
            'wsl_main_section',
            __('General Settings', 'wp-smart-linker'),
            [$this, 'render_section_description'],
            'wp-smart-linker'
        );

        // Add API Key field
        add_settings_field(
            'openai_api_key',
            __('OpenAI API Key', 'wp-smart-linker'),
            [$this, 'render_api_key_field'],
            'wp-smart-linker',
            'wsl_main_section'
        );

        // Add Model Selection field
        add_settings_field(
            'openai_model',
            __('OpenAI Model', 'wp-smart-linker'),
            [$this, 'render_model_field'],
            'wp-smart-linker',
            'wsl_main_section'
        );

        add_settings_field(
            'suggestion_threshold',
            __('Suggestion Threshold', 'wp-smart-linker'),
            [$this, 'render_threshold_field'],
            'wp-smart-linker',
            'wsl_main_section'
        );

        add_settings_field(
            'max_links_per_post',
            __('Maximum Links per Post', 'wp-smart-linker'),
            [$this, 'render_max_links_field'],
            'wp-smart-linker',
            'wsl_main_section'
        );

        add_settings_field(
            'excluded_post_types',
            __('Excluded Post Types', 'wp-smart-linker'),
            [$this, 'render_excluded_types_field'],
            'wp-smart-linker',
            'wsl_main_section'
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
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ('settings_page_wp-smart-linker' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wsl-admin',
            WSL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WSL_VERSION
        );

        wp_enqueue_script(
            'wsl-admin',
            WSL_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WSL_VERSION,
            true
        );

        // Add our localized script data
        wp_localize_script('wsl-admin', 'wslAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wsl_firebase_test'),
            'testingConnection' => __('Testing connection...', 'wp-smart-linker'),
            'connectionSuccess' => __('Connection successful!', 'wp-smart-linker'),
            'connectionFailed' => __('Connection failed: ', 'wp-smart-linker')
        ]);
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
    public function render_section_description() {
        echo '<p>' . esc_html__('Configure the Smart Linker plugin settings below.', 'wp-smart-linker') . '</p>';
    }

    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $options = get_option('wsl_settings');
        $api_key = $options['openai_api_key'] ?? '';
        ?>
        <input 
            type="password" 
            id="wsl_openai_api_key" 
            name="wsl_settings[openai_api_key]" 
            value="<?php echo esc_attr($api_key); ?>" 
            class="regular-text"
        />
        <p class="description">
            <?php _e('Enter your OpenAI API key. Get one from', 'wp-smart-linker'); ?>
            <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Dashboard</a>
        </p>
        <?php
    }

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
            name="wsl_settings[suggestion_threshold]" 
            min="0.1" 
            max="1" 
            step="0.1" 
            value="<?php echo esc_attr($threshold); ?>"
        />
        <span class="threshold-value"><?php echo $threshold; ?></span>
        <p class="description">
            <?php _e('Minimum relevance score for link suggestions (0.1 to 1.0)', 'wp-smart-linker'); ?>
        </p>
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
            <?php _e('Maximum number of link suggestions per post (1 to 20)', 'wp-smart-linker'); ?>
        </p>
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
    * Render model selection field
    */
   public function render_model_field() {
       $options = get_option('wsl_settings');
       $selected_model = $options['openai_model'] ?? 'gpt-3.5-turbo';
       
       // Get available models from OpenAI
       $model_ids = [];
       if ($this->openai) {
           try {
               $model_ids = $this->openai->get_available_models();
           } catch (OpenAI_Exception $e) {
               error_log('WSL Error fetching models: ' . $e->getMessage());
               // Fallback to basic models
               $model_ids = ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo'];
           }
       }

       // Create display names for models
       $models = [];
       foreach ($model_ids as $id) {
           $display_name = ucwords(str_replace(['-', '.'], [' ', ' '], $id));
           $models[$id] = $display_name;
       }

       // Sort models alphabetically
       asort($models);
       ?>
       <select
           id="wsl_openai_model"
           name="wsl_settings[openai_model]"
           class="regular-text"
       >
           <?php foreach ($models as $value => $label): ?>
               <option
                   value="<?php echo esc_attr($value); ?>"
                   <?php selected($selected_model, $value); ?>
               >
                   <?php echo esc_html($label); ?>
               </option>
           <?php endforeach; ?>
       </select>
       <p class="description">
           <?php _e('Select which OpenAI model to use for generating link suggestions', 'wp-smart-linker'); ?>
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
               <button type="button" id="wsl_test_firebase" class="button button-secondary">
                   <?php _e('Test Connection', 'wp-smart-linker'); ?>
               </button>
               <span id="wsl_firebase_test_result" style="margin-left: 10px; display: none;"></span>
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
        
        // API Key
        if (isset($input['openai_api_key'])) {
            $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
        }

        // Model
        if (isset($input['openai_model'])) {
            $model = sanitize_text_field($input['openai_model']);
            // Validate model if OpenAI integration is available
            if ($this->openai && !$this->openai->is_valid_model($model)) {
                add_settings_error(
                    'wsl_settings',
                    'invalid_model',
                    sprintf(
                        __('Invalid model "%s" selected. Falling back to gpt-3.5-turbo.', 'wp-smart-linker'),
                        $model
                    )
                );
                $model = 'gpt-3.5-turbo';
            }
            $sanitized['openai_model'] = $model;
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
}