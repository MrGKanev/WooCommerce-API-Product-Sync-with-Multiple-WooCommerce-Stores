<?php

/**
 * Order-based sync functionality
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Sync products from last 15 orders when triggered by order status change
 */
function wc_api_mps_sync_last_orders($triggered_order_id)
{
  // Check if main plugin is active
  if (!function_exists('wc_api_mps_integration')) {
    wc_api_mps_scheduled_log('Order sync: Main plugin not found. Skipping.');
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
    wc_api_mps_scheduled_log('Order sync: No active stores selected.');
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
    wc_api_mps_scheduled_log('Order sync: No recent orders found.');
    return;
  }

  // Collect all product IDs from these orders
  $product_ids = array();
  foreach ($orders as $order) {
    foreach ($order->get_items() as $item) {
      $product_id = $item->get_product_id();
      if ($product_id) {
        $product_ids[] = $product_id;
      }

      // Also get variation parent if it's a variation
      $variation_id = $item->get_variation_id();
      if ($variation_id) {
        $product_ids[] = $variation_id;
      }
    }
  }

  $product_ids = array_unique($product_ids);

  if (empty($product_ids)) {
    wc_api_mps_scheduled_log('Order sync: No products found in recent orders.');
    return;
  }

  wc_api_mps_scheduled_log(sprintf(
    'Order sync triggered by order #%d: Syncing %d unique products from last 15 orders to %d store(s)',
    $triggered_order_id,
    count($product_ids),
    count($stores)
  ));

  $success_count = 0;
  $error_count = 0;

  // Sync each product (quantity only)
  foreach ($product_ids as $product_id) {
    try {
      $product = wc_get_product($product_id);
      if (!$product) {
        continue;
      }

      $product_sku = $product->get_sku();
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      // Use quantity-only sync type
      wc_api_mps_integration($product_id, $stores, 'quantity');

      // Mark as synced
      update_post_meta($product_id, '_wc_api_mps_last_sync', time());
      update_post_meta($product_id, '_wc_api_mps_last_sync_type', 'quantity');
      update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);

      $success_count++;

      // Log to both systems
      wc_api_mps_scheduled_log(sprintf('✓ Order sync: %s', $product_identifier));

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

      // Log to both systems
      wc_api_mps_scheduled_log(sprintf('✗ Order sync error %s: %s', $product_identifier, $error_msg));

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

  wc_api_mps_scheduled_log(sprintf(
    'Order sync completed for order #%d. Success: %d, Errors: %d',
    $triggered_order_id,
    $success_count,
    $error_count
  ));
}
