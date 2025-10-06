<?php

/**
 * Order products sync status checker
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Get last N orders
 */
function wc_api_mps_scheduled_get_recent_orders($limit = 15)
{
  $args = array(
    'limit' => $limit,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => array('completed', 'processing', 'on-hold'),
  );

  return wc_get_orders($args);
}

/**
 * Extract unique products from orders
 */
function wc_api_mps_scheduled_extract_order_products($orders)
{
  $products = array();

  foreach ($orders as $order) {
    foreach ($order->get_items() as $item) {
      $product_id = $item->get_product_id();
      $variation_id = $item->get_variation_id();

      // Use variation if exists, otherwise main product
      $id_to_use = $variation_id ? $variation_id : $product_id;

      if (!isset($products[$id_to_use])) {
        $product = wc_get_product($id_to_use);
        if ($product) {
          $products[$id_to_use] = array(
            'id' => $id_to_use,
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'price' => $product->get_price(),
          );
        }
      }
    }
  }

  return $products;
}

/**
 * Check sync status for products
 */
function wc_api_mps_scheduled_check_products_sync($products)
{
  $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

  $results = array();

  foreach ($products as $product_id => $product_data) {
    $mpsrel = get_post_meta($product_id, 'mpsrel', true);
    $last_sync = get_post_meta($product_id, '_wc_api_mps_last_sync', true);
    $last_sync_type = get_post_meta($product_id, '_wc_api_mps_last_sync_type', true);

    $store_status = array();

    foreach ($selected_stores as $store_url) {
      $is_synced = false;
      $destination_id = null;

      if (is_array($mpsrel) && isset($mpsrel[$store_url]) && !empty($mpsrel[$store_url])) {
        $is_synced = true;
        $destination_id = $mpsrel[$store_url];
      }

      $store_status[$store_url] = array(
        'synced' => $is_synced,
        'destination_id' => $destination_id,
        'store_active' => isset($all_stores[$store_url]) && $all_stores[$store_url]['status'],
      );
    }

    $results[$product_id] = array(
      'product' => $product_data,
      'stores' => $store_status,
      'last_sync' => $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Never',
      'last_sync_type' => $last_sync_type ?: 'N/A',
    );
  }

  return $results;
}

/**
 * Get cached or fresh order sync status
 */
function wc_api_mps_scheduled_get_order_sync_status($force_refresh = false)
{
  $cache_key = 'wc_api_mps_order_sync_status';

  if (!$force_refresh) {
    $cached = get_transient($cache_key);
    if ($cached !== false) {
      return $cached;
    }
  }

  $orders = wc_api_mps_scheduled_get_recent_orders(15);
  $products = wc_api_mps_scheduled_extract_order_products($orders);
  $results = wc_api_mps_scheduled_check_products_sync($products);

  $data = array(
    'results' => $results,
    'checked_at' => current_time('mysql'),
    'order_count' => count($orders),
    'product_count' => count($products),
  );

  set_transient($cache_key, $data, 300); // Cache for 5 minutes

  return $data;
}

/**
 * Re-sync a single product (price and quantity only)
 */
function wc_api_mps_scheduled_resync_product($product_id)
{
  if (!function_exists('wc_api_mps_integration')) {
    return array('success' => false, 'message' => 'Main plugin not active');
  }

  $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

  // Filter to only selected stores
  $stores = array();
  foreach ($selected_stores as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    return array('success' => false, 'message' => 'No active stores selected');
  }

  try {
    // Use price_and_quantity sync type
    wc_api_mps_integration($product_id, $stores, 'price_and_quantity');

    // Update sync metadata
    update_post_meta($product_id, '_wc_api_mps_last_sync', time());
    update_post_meta($product_id, '_wc_api_mps_last_sync_type', 'price_and_quantity');

    // Clear cache
    delete_transient('wc_api_mps_order_sync_status');

    $product = wc_get_product($product_id);
    $identifier = $product ? ($product->get_sku() ?: "ID: {$product_id}") : "ID: {$product_id}";

    wc_api_mps_scheduled_log("Manual re-sync: {$identifier} (price & quantity)");

    return array(
      'success' => true,
      'message' => 'Product synced successfully',
      'synced_to' => count($stores) . ' store(s)'
    );
  } catch (Exception $e) {
    wc_api_mps_scheduled_log("Re-sync error for product {$product_id}: " . $e->getMessage());
    return array('success' => false, 'message' => $e->getMessage());
  }
}
