<?php

/**
 * Action Scheduler handlers for background product sync
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Queue products from last 15 orders for background sync
 * 
 * @return array Result with success status and message
 */
function wc_api_mps_queue_force_sync_orders()
{
  // Check if Action Scheduler is available
  if (!function_exists('as_enqueue_async_action')) {
    return array(
      'success' => false,
      'message' => __('Action Scheduler not available. Please ensure WooCommerce is active.', 'wc-api-mps-scheduled')
    );
  }

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
      'message' => __('No active stores available.', 'wc-api-mps-scheduled')
    );
  }

  // Get last 15 orders
  $orders = wc_get_orders(array(
    'limit' => 15,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => array('wc-processing', 'wc-completed'),
  ));

  if (empty($orders)) {
    return array(
      'success' => false,
      'message' => __('No recent orders found.', 'wc-api-mps-scheduled')
    );
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

  if (empty($product_ids)) {
    return array(
      'success' => false,
      'message' => __('No products found in recent orders.', 'wc-api-mps-scheduled')
    );
  }

  // Cancel any existing pending force sync actions to avoid duplicates
  as_unschedule_all_actions('wc_api_mps_force_sync_product', array(), 'wc-api-mps-force-sync');

  // Queue each product as a separate action
  $queued_count = 0;
  foreach ($product_ids as $product_id) {
    // Queue the action with product ID and store URLs
    as_enqueue_async_action(
      'wc_api_mps_force_sync_product',
      array(
        'product_id' => $product_id,
        'store_urls' => array_keys($stores)
      ),
      'wc-api-mps-force-sync'
    );
    $queued_count++;
  }

  wc_api_mps_scheduled_log(sprintf(
    'Force sync: Queued %d products from %d orders for background processing',
    $queued_count,
    count($orders)
  ));

  return array(
    'success' => true,
    'queued_count' => $queued_count,
    'message' => sprintf(
      __('Queued %d products for background sync. Go to Tools > Scheduled Actions to monitor progress.', 'wc-api-mps-scheduled'),
      $queued_count
    )
  );
}

/**
 * Process a single product sync (called by Action Scheduler)
 * 
 * @param int $product_id Product ID to sync
 * @param array $store_urls Array of store URLs to sync to
 */
function wc_api_mps_process_force_sync_product($product_id, $store_urls)
{
  // Check if main plugin is active
  if (!function_exists('wc_api_mps_integration')) {
    throw new Exception('Main plugin not found');
  }

  // Get stores data
  $all_stores = get_option('wc_api_mps_stores', array());
  $stores = array();
  foreach ($store_urls as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    throw new Exception('No active stores available');
  }

  // Get product for logging
  $product = wc_get_product($product_id);
  if (!$product) {
    throw new Exception(sprintf('Product ID %d not found', $product_id));
  }

  $product_sku = $product->get_sku();
  $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

  // Sync the product (quantity only)
  wc_api_mps_integration($product_id, $stores, 'quantity');

  // Update metadata
  update_post_meta($product_id, '_wc_api_mps_last_sync', time());
  update_post_meta($product_id, '_wc_api_mps_last_sync_type', 'quantity');
  update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);

  // Log success
  wc_api_mps_scheduled_log(sprintf('✓ Force sync (AS): %s', $product_identifier));

  // Log to SKU-specific file
  wc_api_mps_log_sku_sync(
    $product_sku,
    $product_id,
    'quantity',
    $store_urls,
    true
  );
}

/**
 * Get force sync queue statistics
 * 
 * @return array Statistics about queued, running, and completed actions
 */
function wc_api_mps_get_force_sync_stats()
{
  if (!function_exists('as_get_scheduled_actions')) {
    return array(
      'pending' => 0,
      'running' => 0,
      'complete' => 0,
      'failed' => 0
    );
  }

  return array(
    'pending' => as_get_scheduled_actions(array(
      'hook' => 'wc_api_mps_force_sync_product',
      'status' => 'pending',
      'per_page' => -1
    ), 'ids'),
    'running' => as_get_scheduled_actions(array(
      'hook' => 'wc_api_mps_force_sync_product',
      'status' => 'in-progress',
      'per_page' => -1
    ), 'ids'),
    'complete' => as_get_scheduled_actions(array(
      'hook' => 'wc_api_mps_force_sync_product',
      'status' => 'complete',
      'per_page' => -1,
      'date' => strtotime('-1 hour')
    ), 'ids'),
    'failed' => as_get_scheduled_actions(array(
      'hook' => 'wc_api_mps_force_sync_product',
      'status' => 'failed',
      'per_page' => -1,
      'date' => strtotime('-1 hour')
    ), 'ids')
  );
}

/**
 * Cancel all pending force sync actions
 * 
 * @return int Number of actions cancelled
 */
function wc_api_mps_cancel_force_sync_queue()
{
  if (!function_exists('as_unschedule_all_actions')) {
    return 0;
  }

  $cancelled = as_unschedule_all_actions('wc_api_mps_force_sync_product', array(), 'wc-api-mps-force-sync');
  
  wc_api_mps_scheduled_log(sprintf('Force sync queue cancelled (%d actions removed)', $cancelled));
  
  return $cancelled;
}