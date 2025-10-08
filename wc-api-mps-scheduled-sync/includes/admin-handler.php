<?php

/**
 * Admin page logic and data processing
 * Updated to include category sync functionality
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Handle all admin actions and prepare data
 */
function wc_api_mps_scheduled_process_admin_actions()
{
  $data = array(
    'messages' => array(),
    'order_sync_data' => array(),
  );

  // Check if main plugin is active
  if (!function_exists('wc_api_mps_integration')) {
    $data['error'] = __('WooCommerce API Product Sync plugin is not active.', 'wc-api-mps-scheduled');
    return $data;
  }

  // Handle reschedule
  wc_api_mps_scheduled_handle_reschedule();

  // Handle manual sync
  if (isset($_POST['run_sync_now']) && check_admin_referer('wc_api_mps_manual_sync')) {
    wc_api_mps_scheduled_trigger_sync();
    $data['messages'][] = array('type' => 'success', 'text' => __('Manual sync completed.', 'wc-api-mps-scheduled'));
  }

  // Handle clear logs
  if (isset($_POST['clear_logs']) && check_admin_referer('wc_api_mps_clear_logs')) {
    wc_api_mps_scheduled_clear_logs();
    $data['messages'][] = array('type' => 'success', 'text' => __('Logs cleared.', 'wc-api-mps-scheduled'));
  }

  // Handle cleanup old SKU logs
  if (isset($_POST['cleanup_sku_logs']) && check_admin_referer('wc_api_mps_cleanup_logs')) {
    $days = isset($_POST['cleanup_days']) ? (int) $_POST['cleanup_days'] : 30;
    $deleted = wc_api_mps_cleanup_old_sku_logs($days);
    $data['messages'][] = array('type' => 'success', 'text' => sprintf(__('Deleted %d old log file(s).', 'wc-api-mps-scheduled'), $deleted));
  }

  // NEW: Handle sync all categories
  if (isset($_POST['sync_all_categories']) && check_admin_referer('wc_api_mps_sync_categories')) {
    $result = wc_api_mps_sync_all_categories();
    if ($result['success']) {
      $data['messages'][] = array(
        'type' => 'success',
        'text' => sprintf(
          __('Category sync completed: %d categories synced to %d store(s). Success: %d, Errors: %d', 'wc-api-mps-scheduled'),
          $result['categories_count'],
          $result['stores_count'],
          $result['success_count'],
          $result['error_count']
        )
      );
    } else {
      $data['messages'][] = array('type' => 'error', 'text' => $result['message']);
    }
  }

  // NEW: Handle force update product categories
  if (isset($_POST['force_update_product_categories']) && check_admin_referer('wc_api_mps_update_product_cats')) {
    $result = wc_api_mps_force_update_product_categories();
    if ($result['success']) {
      $data['messages'][] = array(
        'type' => 'success',
        'text' => sprintf(
          __('Product categories updated: %d products processed across %d store(s). Success: %d, Errors: %d', 'wc-api-mps-scheduled'),
          $result['products_count'],
          $result['stores_count'],
          $result['success_count'],
          $result['error_count']
        )
      );
    } else {
      $data['messages'][] = array('type' => 'error', 'text' => $result['message']);
    }
  }

  // Handle save settings
  if (isset($_POST['save_settings']) && check_admin_referer('wc_api_mps_save_settings')) {
    update_option('wc_api_mps_cron_batch_size', (int) $_POST['batch_size']);
    update_option('wc_api_mps_cron_batch_size_offpeak', (int) $_POST['batch_size_offpeak']);

    $auto_sync_orders = isset($_POST['auto_sync_orders']) ? 1 : 0;
    update_option('wc_api_mps_auto_sync_orders', $auto_sync_orders);

    $force_full_sync = isset($_POST['force_full_sync']) ? 1 : 0;
    update_option('wc_api_mps_force_full_sync', $force_full_sync);

    $selected_stores = isset($_POST['selected_stores']) ? $_POST['selected_stores'] : array();
    update_option('wc_api_mps_cron_selected_stores', $selected_stores);

    delete_transient('wc_api_mps_pending_count_full_product');
    delete_transient('wc_api_mps_pending_count_price_and_quantity');

    $data['messages'][] = array('type' => 'success', 'text' => __('Settings saved.', 'wc-api-mps-scheduled'));
  }

  // Handle force sync (sync last 15 orders now)
  if (isset($_POST['force_sync_orders']) && check_admin_referer('wc_api_mps_force_sync')) {
    $result = wc_api_mps_force_sync_last_orders();
    if ($result['success']) {
      $data['messages'][] = array(
        'type' => 'success',
        'text' => sprintf(
          __('Force sync completed: %d products synced, %d errors', 'wc-api-mps-scheduled'),
          $result['success_count'],
          $result['error_count']
        )
      );
    } else {
      $data['messages'][] = array('type' => 'error', 'text' => $result['message']);
    }
  }

  // Handle order sync check
  if (isset($_POST['check_orders']) && check_admin_referer('wc_api_mps_check_orders')) {
    $data['order_sync_data'] = wc_api_mps_check_order_sync_status();
  }

  // Get current data
  $data['all_stores'] = get_option('wc_api_mps_stores', array());
  $data['cron_status'] = wc_api_mps_scheduled_get_cron_status();
  $data['batch_size'] = get_option('wc_api_mps_cron_batch_size', 5);
  $data['batch_size_offpeak'] = get_option('wc_api_mps_cron_batch_size_offpeak', 20);
  $data['selected_stores'] = get_option('wc_api_mps_cron_selected_stores', array());

  // Default to all active stores if none selected
  if (empty($data['selected_stores']) && !empty($data['all_stores'])) {
    foreach ($data['all_stores'] as $store_url => $store_data) {
      if ($store_data['status']) {
        $data['selected_stores'][] = $store_url;
      }
    }
  }

  $data['logs'] = wc_api_mps_scheduled_get_logs(50);
  $data['is_off_peak'] = wc_api_mps_scheduled_is_off_peak();
  $data['sync_type'] = wc_api_mps_scheduled_get_sync_type();
  $data['auto_sync_orders'] = get_option('wc_api_mps_auto_sync_orders', 0);
  $data['force_full_sync'] = get_option('wc_api_mps_force_full_sync', 0);
  $data['sku_log_stats'] = wc_api_mps_get_sku_log_stats();
  $data['sku_log_files'] = wc_api_mps_get_sku_log_files();

  // NEW: Get category stats
  $data['category_stats'] = wc_api_mps_get_category_stats();

  try {
    $data['products_count'] = wc_api_mps_scheduled_count_products($data['sync_type']);
  } catch (Exception $e) {
    $data['products_count'] = 0;
  }

  return $data;
}

/**
 * Force sync products from last 15 orders immediately
 */
function wc_api_mps_force_sync_last_orders()
{
  $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());

  if (empty($selected_stores)) {
    return array(
      'success' => false,
      'message' => __('No stores selected in settings.', 'wc-api-mps-scheduled')
    );
  }

  $all_stores = get_option('wc_api_mps_stores', array());
  $stores = array();
  foreach ($selected_stores as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    return array(
      'success' => false,
      'message' => __('No active stores found.', 'wc-api-mps-scheduled')
    );
  }

  // Get last 15 orders
  $orders = wc_api_mps_scheduled_get_recent_orders(15);

  if (empty($orders)) {
    return array(
      'success' => false,
      'message' => __('No recent orders found.', 'wc-api-mps-scheduled')
    );
  }

  // Extract unique product IDs
  $product_ids = array();
  foreach ($orders as $order) {
    foreach ($order->get_items() as $item) {
      $product_id = $item->get_product_id();
      $variation_id = $item->get_variation_id();
      $id_to_use = $variation_id ? $variation_id : $product_id;
      $product_ids[$id_to_use] = $id_to_use;
    }
  }

  if (empty($product_ids)) {
    return array(
      'success' => false,
      'message' => __('No products found in recent orders.', 'wc-api-mps-scheduled')
    );
  }

  wc_api_mps_scheduled_log(sprintf(
    'Force sync triggered: Syncing %d unique products from last 15 orders to %d store(s)',
    count($product_ids),
    count($stores)
  ));

  $success_count = 0;
  $error_count = 0;

  foreach ($product_ids as $product_id) {
    try {
      $product = wc_get_product($product_id);
      if (!$product) {
        continue;
      }

      $product_sku = $product->get_sku();
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      wc_api_mps_integration($product_id, $stores, 'full_product');

      update_post_meta($product_id, '_wc_api_mps_last_sync', time());
      update_post_meta($product_id, '_wc_api_mps_last_sync_type', 'full_product');
      update_post_meta($product_id, '_wc_api_mps_needs_sync', 0);
      update_post_meta($product_id, '_wc_api_mps_needs_full_sync', 0);

      $success_count++;

      wc_api_mps_scheduled_log(sprintf('✓ Force synced: %s', $product_identifier));

      wc_api_mps_log_sku_sync(
        $product_sku,
        $product_id,
        'full_product',
        array_keys($stores),
        true
      );

      usleep(100000);
    } catch (Exception $e) {
      $error_count++;
      $product = wc_get_product($product_id);
      $product_sku = $product ? $product->get_sku() : '';
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      wc_api_mps_scheduled_log(sprintf('✗ Force sync error %s: %s', $product_identifier, $e->getMessage()));

      wc_api_mps_log_sku_sync(
        $product_sku,
        $product_id,
        'full_product',
        array_keys($stores),
        false,
        $e->getMessage()
      );
    }
  }

  wc_api_mps_scheduled_log(sprintf(
    'Force sync completed. Success: %d, Errors: %d',
    $success_count,
    $error_count
  ));

  return array(
    'success' => true,
    'success_count' => $success_count,
    'error_count' => $error_count
  );
}
