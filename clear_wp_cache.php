<?php
require_once('wp-load.php');

if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
    echo "WordPress object cache cleared successfully";
} else {
    echo "WordPress object cache not enabled";
}