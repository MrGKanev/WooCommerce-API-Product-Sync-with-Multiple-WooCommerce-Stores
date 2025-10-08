<?php

/**
 * Admin page logic and data processing
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

  // Handle force sync (queue products instead of processing immediately)
  if (isset($_POST['force_sync_orders']) && check_admin_referer('wc_api_mps_force_sync')) {
    $result = wc_api_mps_queue_force_sync_orders();
    if ($result['success']) {
      $data['messages'][] = array(
        'type' => 'success',
        'text' => $result['message']
      );
    } else {
      $data['messages'][] = array('type' => 'error', 'text' => $result['message']);
    }
  }

  // Handle cancel force sync queue
  if (isset($_POST['cancel_force_sync']) && check_admin_referer('wc_api_mps_cancel_sync')) {
    $cancelled = wc_api_mps_cancel_force_sync_queue();
    $data['messages'][] = array(
      'type' => 'success',
      'text' => sprintf(__('Cancelled %d queued sync actions.', 'wc-api-mps-scheduled'), $cancelled)
    );
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

  // Get force sync queue stats
  $data['force_sync_stats'] = wc_api_mps_get_force_sync_stats();

  try {
    $data['products_count'] = wc_api_mps_scheduled_count_products($data['sync_type']);
  } catch (Exception $e) {
    $data['products_count'] = 0;
  }

  return $data;
}

/**
 * Check sync status of products from last 15 orders
 */
function wc_api_mps_check_order_sync_status()
{
  $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());

  if (empty($selected_stores)) {
    return array();
  }

  $orders = wc_get_orders(array(
    'limit' => 15,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => array('wc-processing', 'wc-completed'),
  ));

  if (empty($orders)) {
    return array();
  }

  $all_stores = get_option('wc_api_mps_stores', array());
  $stores = array();
  foreach ($selected_stores as $store_url) {
    if (isset($all_stores[$store_url])) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  // Collect product IDs
  $product_ids = array();
  foreach ($orders as $order) {
    foreach ($order->get_items() as $item) {
      $product_id = $item->get_product_id();
      if ($product_id) {
        $product_ids[] = $product_id;
      }
      $variation_id = $item->get_variation_id();
      if ($variation_id) {
        $product_ids[] = $variation_id;
      }
    }
  }
  $product_ids = array_unique($product_ids);

  // Check sync status
  $results = array();
  foreach ($product_ids as $product_id) {
    $product = wc_get_product($product_id);
    if (!$product) continue;

    $mpsrel = get_post_meta($product_id, 'mpsrel', true);
    if (!is_array($mpsrel)) {
      $mpsrel = array();
    }

    $synced_stores = array();
    $not_synced_stores = array();

    foreach ($stores as $store_url => $store_data) {
      if (isset($mpsrel[$store_url]) && $mpsrel[$store_url]) {
        $synced_stores[] = $store_url;
      } else {
        $not_synced_stores[] = $store_url;
      }
    }

    $results[] = array(
      'product' => $product,
      'synced_stores' => $synced_stores,
      'not_synced_stores' => $not_synced_stores,
    );
  }

  return array(
    'checked_at' => current_time('mysql'),
    'order_count' => count($orders),
    'product_count' => count($product_ids),
    'results' => $results,
  );
}
