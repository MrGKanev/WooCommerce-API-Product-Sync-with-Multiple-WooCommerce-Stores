<?php

/**
 * Plugin Name: WooCommerce Product Sync - Scheduled Sync
 * Description: Time-based scheduled sync - Light sync (price/qty) during peak hours, full sync during off-peak (12AM-6:30AM). Runs every 5 minutes.
 * Version: 1.1.0
 * Author: Your Name
 * Requires: WooCommerce API Product Sync plugin
 */

if (!defined('ABSPATH')) {
  exit('restricted access');
}

// Define plugin constants
define('WC_API_MPS_SCHEDULED_PATH', plugin_dir_path(__FILE__));
define('WC_API_MPS_SCHEDULED_URL', plugin_dir_url(__FILE__));
define('WC_API_MPS_SCHEDULED_VERSION', '1.1.0');

// Load files
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/activation.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/cron-manager.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/sync-engine.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/product-queries.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/time-manager.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/logger.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/admin-page.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/hooks.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/debug-helper.php';

// Register activation/deactivation
register_activation_hook(__FILE__, 'wc_api_mps_scheduled_activate');
register_deactivation_hook(__FILE__, 'wc_api_mps_scheduled_deactivate');

// Initialize admin interface
add_action('admin_menu', 'wc_api_mps_scheduled_add_menu', 100);
add_action('admin_init', 'wc_api_mps_scheduled_register_settings');

// Initialize cron
add_action('wc_api_mps_scheduled_sync_check', 'wc_api_mps_scheduled_run_sync');

// Always register the custom interval (not just on activation)
add_filter('cron_schedules', 'wc_api_mps_scheduled_add_interval');

// Ensure cron is scheduled on every page load (lightweight check)
add_action('init', function () {
  if (!wp_next_scheduled('wc_api_mps_scheduled_sync_check')) {
    wp_schedule_event(time(), 'every_5_minutes', 'wc_api_mps_scheduled_sync_check');
  }
});
