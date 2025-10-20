<?php

/**
 * WordPress hooks for tracking product changes
 * Modified to use queue system instead of immediate sync
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Mark products for full sync when created/updated
 * Works for both publish and pending products
 */
add_action('woocommerce_update_product', 'wc_api_mps_scheduled_mark_full_sync', 10, 1);
add_action('woocommerce_new_product', 'wc_api_mps_scheduled_mark_full_sync', 10, 1);

function wc_api_mps_scheduled_mark_full_sync($product_id)
{
  // Get product status
  $product_status = get_post_status($product_id);

  // Mark for sync if status is publish or pending (for 1:1 status matching)
  if (in_array($product_status, array('publish', 'pending'))) {
    update_post_meta($product_id, '_wc_api_mps_needs_full_sync', 1);
    update_post_meta($product_id, '_wc_api_mps_needs_sync', 1);

    // Log pending product sync marking for debugging
    if ($product_status === 'pending') {
      $product = wc_get_product($product_id);
      $product_sku = $product ? $product->get_sku() : '';
      $identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";
      wc_api_mps_scheduled_log("Pending product marked for sync: {$identifier}");
    }
  }
}

/**
 * Mark products for light sync when stock changes
 * Works for both publish and pending products
 */
add_action('woocommerce_product_set_stock', 'wc_api_mps_scheduled_mark_light_sync', 10, 1);
add_action('woocommerce_variation_set_stock', 'wc_api_mps_scheduled_mark_light_sync', 10, 1);

function wc_api_mps_scheduled_mark_light_sync($product)
{
  $product_id = $product->get_id();
  $product_status = get_post_status($product_id);

  // Mark for light sync if status is publish or pending
  if (in_array($product_status, array('publish', 'pending'))) {
    update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 1);
  }
}

/**
 * Queue products when order status changes to processing or completed
 * MODIFIED: No longer syncs immediately, adds to queue instead
 */
add_action('woocommerce_order_status_processing', 'wc_api_mps_scheduled_queue_order_products', 10, 1);
add_action('woocommerce_order_status_completed', 'wc_api_mps_scheduled_queue_order_products', 10, 1);

function wc_api_mps_scheduled_queue_order_products($order_id)
{
  // Check if auto sync is enabled
  if (!get_option('wc_api_mps_auto_sync_orders', 0)) {
    return;
  }

  $order = wc_get_order($order_id);
  if (!$order) {
    return;
  }

  // Collect products from this order
  $product_ids = array();
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

  if (empty($product_ids)) {
    return;
  }

  // Add products to queue instead of syncing immediately
  wc_api_mps_queue_add_products($product_ids, "order_{$order_id}");

  wc_api_mps_scheduled_log(sprintf(
    'Order #%d: %d product(s) added to sync queue',
    $order_id,
    count($product_ids)
  ));
}

/**
 * Track status transitions for pending products
 * When pending product changes to publish (or vice versa), trigger immediate sync
 */
add_action('transition_post_status', 'wc_api_mps_scheduled_track_status_change', 10, 3);

function wc_api_mps_scheduled_track_status_change($new_status, $old_status, $post)
{
  // Only process products
  if ($post->post_type !== 'product') {
    return;
  }

  // If status changed from/to pending or publish, mark for full sync
  $relevant_statuses = array('publish', 'pending');

  if (in_array($new_status, $relevant_statuses) || in_array($old_status, $relevant_statuses)) {
    if ($new_status !== $old_status) {
      update_post_meta($post->ID, '_wc_api_mps_needs_full_sync', 1);
      update_post_meta($post->ID, '_wc_api_mps_needs_sync', 1);

      // Log status change for tracking
      $product = wc_get_product($post->ID);
      $product_sku = $product ? $product->get_sku() : '';
      $identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$post->ID}";

      wc_api_mps_scheduled_log(sprintf(
        "Product status changed: %s (%s → %s) - marked for sync",
        $identifier,
        $old_status,
        $new_status
      ));
    }
  }
}
