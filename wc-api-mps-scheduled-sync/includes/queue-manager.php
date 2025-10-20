<?php

/**
 * Queue Manager for Product Sync
 * Handles batching and deduplication of product syncs
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Add product to sync queue
 * 
 * @param int $product_id Product ID to queue
 * @param string $trigger What triggered the queue (order_id, manual, etc)
 */
function wc_api_mps_queue_add_product($product_id, $trigger = 'unknown')
{
  $queue = get_option('wc_api_mps_sync_queue', array());

  // Initialize if not exists
  if (!is_array($queue)) {
    $queue = array();
  }

  // Add to queue with metadata
  $queue[$product_id] = array(
    'product_id' => $product_id,
    'added_at' => time(),
    'trigger' => $trigger,
    'retry_count' => 0,
  );

  update_option('wc_api_mps_sync_queue', $queue, false);

  wc_api_mps_scheduled_log(sprintf(
    'Product queued: ID %d (Trigger: %s)',
    $product_id,
    $trigger
  ));
}

/**
 * Add multiple products to sync queue
 * 
 * @param array $product_ids Array of product IDs
 * @param string $trigger What triggered the queue
 */
function wc_api_mps_queue_add_products($product_ids, $trigger = 'unknown')
{
  if (empty($product_ids) || !is_array($product_ids)) {
    return;
  }

  $queue = get_option('wc_api_mps_sync_queue', array());

  if (!is_array($queue)) {
    $queue = array();
  }

  $added_count = 0;
  foreach ($product_ids as $product_id) {
    // Skip if already queued
    if (isset($queue[$product_id])) {
      continue;
    }

    $queue[$product_id] = array(
      'product_id' => $product_id,
      'added_at' => time(),
      'trigger' => $trigger,
      'retry_count' => 0,
    );
    $added_count++;
  }

  update_option('wc_api_mps_sync_queue', $queue, false);

  if ($added_count > 0) {
    wc_api_mps_scheduled_log(sprintf(
      '%d products queued (Trigger: %s)',
      $added_count,
      $trigger
    ));
  }
}

/**
 * Process the sync queue
 * Runs every 5 minutes via cron
 */
function wc_api_mps_process_sync_queue()
{
  // Check if main plugin is active
  if (!function_exists('wc_api_mps_integration')) {
    wc_api_mps_scheduled_log('Queue processor: Main plugin not found. Skipping.');
    return;
  }

  $queue = get_option('wc_api_mps_sync_queue', array());

  if (empty($queue) || !is_array($queue)) {
    return;
  }

  // Get selected stores
  $all_stores = get_option('wc_api_mps_stores', array());
  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());

  $stores = array();
  foreach ($selected_store_urls as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    wc_api_mps_scheduled_log('Queue processor: No active stores selected.');
    return;
  }

  $queue_count = count($queue);
  wc_api_mps_scheduled_log(sprintf(
    'Queue processor started: %d product(s) to sync',
    $queue_count
  ));

  $success_count = 0;
  $error_count = 0;
  $retry_count = 0;
  $removed_products = array();

  foreach ($queue as $product_id => $queue_item) {
    try {
      $product = wc_get_product($product_id);

      if (!$product) {
        // Product no longer exists, remove from queue
        $removed_products[] = $product_id;
        wc_api_mps_scheduled_log(sprintf('Product ID %d not found, removed from queue', $product_id));
        continue;
      }

      $product_sku = $product->get_sku();
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      // Sync the product (quantity only for order-based syncs)
      wc_api_mps_integration($product_id, $stores, 'quantity');

      // Update sync metadata
      update_post_meta($product_id, '_wc_api_mps_last_sync', time());
      update_post_meta($product_id, '_wc_api_mps_last_sync_type', 'quantity');
      update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);

      $success_count++;
      $removed_products[] = $product_id;

      wc_api_mps_scheduled_log(sprintf('✓ Queue sync: %s', $product_identifier));

      // Log to SKU-specific file
      wc_api_mps_log_sku_sync(
        $product_sku,
        $product_id,
        'quantity',
        array_keys($stores),
        true
      );

      // Small delay to prevent overload
      usleep(100000); // 0.1 seconds

    } catch (Exception $e) {
      $error_count++;

      $product = wc_get_product($product_id);
      $product_sku = $product ? $product->get_sku() : '';
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      $error_msg = $e->getMessage();

      // Check retry count
      $current_retry = isset($queue_item['retry_count']) ? (int)$queue_item['retry_count'] : 0;

      if ($current_retry < 3) {
        // Increment retry count and keep in queue
        $queue[$product_id]['retry_count'] = $current_retry + 1;
        $retry_count++;

        wc_api_mps_scheduled_log(sprintf(
          '⚠ Queue sync retry %d/3: %s - %s',
          $current_retry + 1,
          $product_identifier,
          $error_msg
        ));
      } else {
        // Max retries reached, remove from queue
        $removed_products[] = $product_id;

        wc_api_mps_scheduled_log(sprintf(
          '✗ Queue sync failed (max retries): %s - %s',
          $product_identifier,
          $error_msg
        ));
      }

      // Log to SKU-specific file
      wc_api_mps_log_sku_sync(
        $product_sku,
        $product_id,
        'quantity',
        array_keys($stores),
        false,
        $error_msg
      );
    }
  }

  // Remove successfully synced and failed products from queue
  foreach ($removed_products as $product_id) {
    unset($queue[$product_id]);
  }

  // Update queue
  update_option('wc_api_mps_sync_queue', $queue, false);

  $remaining = count($queue);

  wc_api_mps_scheduled_log(sprintf(
    'Queue processor completed: Success: %d, Errors: %d, Retrying: %d, Remaining: %d',
    $success_count,
    $error_count,
    $retry_count,
    $remaining
  ));
}

/**
 * Verify and sync missing products from last 15 orders
 * Runs hourly as a safety net
 */
function wc_api_mps_verify_last_orders_sync()
{
  // Check if main plugin is active
  if (!function_exists('wc_api_mps_integration')) {
    wc_api_mps_scheduled_log('Order verification: Main plugin not found. Skipping.');
    return;
  }

  // Get selected stores
  $all_stores = get_option('wc_api_mps_stores', array());
  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());

  $stores = array();
  foreach ($selected_store_urls as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    wc_api_mps_scheduled_log('Order verification: No active stores selected.');
    return;
  }

  // Get last 15 orders
  $orders = wc_get_orders(array(
    'limit' => 15,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => array('wc-processing', 'wc-completed'),
  ));

  if (empty($orders)) {
    wc_api_mps_scheduled_log('Order verification: No recent orders found.');
    return;
  }

  // Collect all product IDs
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
    wc_api_mps_scheduled_log('Order verification: No products found in recent orders.');
    return;
  }

  wc_api_mps_scheduled_log(sprintf(
    'Order verification started: Checking %d products from last 15 orders',
    count($product_ids)
  ));

  $missing_count = 0;
  $synced_count = 0;

  // Check each product to see if it's synced to all selected stores
  foreach ($product_ids as $product_id) {
    $mpsrel = get_post_meta($product_id, 'mpsrel', true);
    $needs_sync = false;

    // Check if product is synced to all selected stores
    foreach ($selected_store_urls as $store_url) {
      if (!is_array($mpsrel) || !isset($mpsrel[$store_url]) || empty($mpsrel[$store_url])) {
        $needs_sync = true;
        break;
      }
    }

    // Also check if product hasn't been synced in the last hour
    $last_sync = get_post_meta($product_id, '_wc_api_mps_last_sync', true);
    if (!$last_sync || (time() - $last_sync) > 3600) {
      $needs_sync = true;
    }

    if ($needs_sync) {
      $missing_count++;

      $product = wc_get_product($product_id);
      $product_sku = $product ? $product->get_sku() : '';
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      try {
        // Sync the missing product
        wc_api_mps_integration($product_id, $stores, 'quantity');

        // Update metadata
        update_post_meta($product_id, '_wc_api_mps_last_sync', time());
        update_post_meta($product_id, '_wc_api_mps_last_sync_type', 'quantity');

        $synced_count++;

        wc_api_mps_scheduled_log(sprintf('✓ Verification sync: %s', $product_identifier));

        // Log to SKU-specific file
        wc_api_mps_log_sku_sync(
          $product_sku,
          $product_id,
          'quantity',
          array_keys($stores),
          true
        );

        usleep(100000); // 0.1 seconds delay

      } catch (Exception $e) {
        wc_api_mps_scheduled_log(sprintf(
          '✗ Verification sync error %s: %s',
          $product_identifier,
          $e->getMessage()
        ));
      }
    }
  }

  wc_api_mps_scheduled_log(sprintf(
    'Order verification completed: %d products checked, %d missing, %d synced',
    count($product_ids),
    $missing_count,
    $synced_count
  ));
}

/**
 * Get queue statistics
 * 
 * @return array Queue stats
 */
function wc_api_mps_get_queue_stats()
{
  $queue = get_option('wc_api_mps_sync_queue', array());

  if (!is_array($queue)) {
    return array(
      'total' => 0,
      'pending' => 0,
      'retrying' => 0,
      'oldest' => null,
    );
  }

  $retrying = 0;
  $oldest_time = null;

  foreach ($queue as $item) {
    if (isset($item['retry_count']) && $item['retry_count'] > 0) {
      $retrying++;
    }

    if (isset($item['added_at'])) {
      if ($oldest_time === null || $item['added_at'] < $oldest_time) {
        $oldest_time = $item['added_at'];
      }
    }
  }

  return array(
    'total' => count($queue),
    'pending' => count($queue) - $retrying,
    'retrying' => $retrying,
    'oldest' => $oldest_time ? date('Y-m-d H:i:s', $oldest_time) : null,
  );
}

/**
 * Clear the entire sync queue
 * 
 * @return int Number of items cleared
 */
function wc_api_mps_clear_queue()
{
  $queue = get_option('wc_api_mps_sync_queue', array());
  $count = is_array($queue) ? count($queue) : 0;

  delete_option('wc_api_mps_sync_queue');

  wc_api_mps_scheduled_log(sprintf('Sync queue cleared: %d items removed', $count));

  return $count;
}

/**
 * Manually flush queue immediately
 * 
 * @return array Result with counts
 */
function wc_api_mps_flush_queue_now()
{
  $queue = get_option('wc_api_mps_sync_queue', array());

  if (empty($queue)) {
    return array(
      'success' => false,
      'message' => 'Queue is empty',
    );
  }

  $count = count($queue);

  wc_api_mps_scheduled_log(sprintf('Manual queue flush triggered: %d products', $count));

  // Process the queue immediately
  wc_api_mps_process_sync_queue();

  $remaining = get_option('wc_api_mps_sync_queue', array());
  $processed = $count - count($remaining);

  return array(
    'success' => true,
    'message' => sprintf('Processed %d products, %d remaining', $processed, count($remaining)),
    'processed' => $processed,
    'remaining' => count($remaining),
  );
}
