<?php

/**
 * Plugin Name: WooCommerce Product Sync - Scheduled Sync
 * Description: Time-based scheduled sync with queue system - Light sync (price/qty) during peak hours, full sync during off-peak (12AM-6:30AM)
 * Version: 1.2.1
 * Author: MGKNeT.com
 * Author URI: https://mgknet.com
 * Text Domain: wc-api-mps-scheduled
 * Requires: WooCommerce API Product Sync plugin
 */

if (!defined('ABSPATH')) {
  exit('restricted access');
}

// Define plugin constants
define('WC_API_MPS_SCHEDULED_PATH', plugin_dir_path(__FILE__));
define('WC_API_MPS_SCHEDULED_URL', plugin_dir_url(__FILE__));
define('WC_API_MPS_SCHEDULED_VERSION', '1.2.1');

// Load files
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/activation.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/cron-manager.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/sync-engine.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/product-queries.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/time-manager.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/logger.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/sku-logger.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/category-sync.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/admin-handler.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/admin-page.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/hooks.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/debug-helper.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/order-sync.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/action-scheduler-handler.php';
require_once WC_API_MPS_SCHEDULED_PATH . 'includes/queue-manager.php'; // NEW: Queue system

// Register activation/deactivation
register_activation_hook(__FILE__, 'wc_api_mps_scheduled_activate');
register_deactivation_hook(__FILE__, 'wc_api_mps_scheduled_deactivate');

// Initialize admin interface
add_action('admin_menu', 'wc_api_mps_scheduled_add_menu', 100);
add_action('admin_init', 'wc_api_mps_scheduled_register_settings');

// Enqueue admin assets
add_action('admin_enqueue_scripts', 'wc_api_mps_scheduled_enqueue_assets');

/**
 * Enqueue CSS and JS assets for admin page
 */
function wc_api_mps_scheduled_enqueue_assets($hook)
{
  // Only load on our admin page
  if ($hook !== 'product-sync_page_wc-api-mps-scheduled-sync' && $hook !== 'toplevel_page_wc-api-mps-scheduled-sync') {
    return;
  }

  wp_enqueue_style(
    'wc-api-mps-scheduled-admin',
    WC_API_MPS_SCHEDULED_URL . 'assets/css/admin-styles.css',
    array(),
    WC_API_MPS_SCHEDULED_VERSION
  );

  wp_enqueue_script(
    'wc-api-mps-scheduled-admin',
    WC_API_MPS_SCHEDULED_URL . 'assets/js/admin-scripts.js',
    array('jquery'),
    WC_API_MPS_SCHEDULED_VERSION,
    true
  );
}

// Initialize cron hooks
add_action('wc_api_mps_scheduled_sync_check', 'wc_api_mps_scheduled_run_sync');

// NEW: Queue processor (runs every 5 minutes)
add_action('wc_api_mps_process_queue', 'wc_api_mps_process_sync_queue');

// NEW: Hourly verification check (safety net)
add_action('wc_api_mps_verify_orders', 'wc_api_mps_verify_last_orders_sync');

// Register Action Scheduler hook for force sync
add_action('wc_api_mps_force_sync_product', 'wc_api_mps_process_force_sync_product', 10, 2);

// Register Action Scheduler hook for SKU-based sync
add_action('wc_api_mps_process_sku_sync', 'wc_api_mps_process_sku_sync', 10, 3);

// Always register the custom intervals (not just on activation)
add_filter('cron_schedules', 'wc_api_mps_scheduled_add_interval');

// Ensure crons are scheduled on every page load (lightweight check)
add_action('init', function () {
  // Main sync check (every 15 minutes)
  if (!wp_next_scheduled('wc_api_mps_scheduled_sync_check')) {
    wp_schedule_event(time(), 'every_15_minutes', 'wc_api_mps_scheduled_sync_check');
  }

  // Queue processor (every 5 minutes)
  if (!wp_next_scheduled('wc_api_mps_process_queue')) {
    wp_schedule_event(time(), 'every_5_minutes', 'wc_api_mps_process_queue');
  }

  // Hourly verification
  if (!wp_next_scheduled('wc_api_mps_verify_orders')) {
    wp_schedule_event(time(), 'hourly', 'wc_api_mps_verify_orders');
  }
});
