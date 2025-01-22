<?php
/**
 * Plugin Name: WP Smart Linker
 * Description: AI-powered internal linking suggestions using multiple AI providers
 * Version: 1.1.0
 * Author: Gerald Haygood
 * Plugin URI: https://github.com/bcardi0427/wp-smart-linker
 * Text Domain: wp-smart-linker
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WSL_VERSION', '1.1.0');

// Autoloader for plugin classes
spl_autoload_register(function ($class_name) {
    $prefix = 'WSL\\';
    $base_dir = WSL_PLUGIN_DIR . 'includes/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class_name, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class_name, $len);

    // Convert namespace separator to directory separator and convert to lowercase
    $relative_class = strtolower(str_replace('\\', '/', $relative_class));
    
    // Convert underscores to hyphens for file naming
    $file_name = str_replace('_', '-', $relative_class);
    
    // Build the full path with class- prefix
    $file = $base_dir . 'class-' . $file_name . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Global variable to store plugin instances
global $wsl_instances;
$wsl_instances = [];

// Initialize plugin
function wsl_init() {
    global $wsl_instances;
    
    // Initialize core classes in dependency order
    $wsl_instances['firebase'] = new WSL\Firebase_Integration();
    $wsl_instances['openai'] = new WSL\OpenAI_Integration();
    $wsl_instances['content_processor'] = new WSL\Content_Processor();
    $wsl_instances['link_manager'] = new WSL\Link_Manager();
    $wsl_instances['admin'] = new WSL\Admin();

    // Register deactivation hook for Firebase cleanup
    register_deactivation_hook(__FILE__, [WSL\Firebase_Integration::class, 'deactivate']);
}
add_action('plugins_loaded', 'wsl_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    // Create necessary database tables or options
    if (!get_option('wsl_settings')) {
        add_option('wsl_settings', [
            'openai_api_key' => '',
            'suggestion_threshold' => 0.7,
            'max_links_per_post' => 5,
            'excluded_post_types' => ['attachment']
        ]);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clean up if necessary
    delete_option('wsl_settings');
    // Remove transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wsl_%'");
});