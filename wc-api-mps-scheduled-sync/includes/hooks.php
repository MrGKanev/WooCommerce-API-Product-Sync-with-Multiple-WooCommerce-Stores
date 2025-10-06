<?php

/**
 * WordPress hooks for tracking product changes
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Mark products for full sync when created/updated
 */
add_action('woocommerce_update_product', 'wc_api_mps_scheduled_mark_full_sync', 10, 1);
add_action('woocommerce_new_product', 'wc_api_mps_scheduled_mark_full_sync', 10, 1);

function wc_api_mps_scheduled_mark_full_sync($product_id)
{
  update_post_meta($product_id, '_wc_api_mps_needs_full_sync', 1);
  update_post_meta($product_id, '_wc_api_mps_needs_sync', 1);
}

/**
 * Mark products for light sync when stock changes
 */
add_action('woocommerce_product_set_stock', 'wc_api_mps_scheduled_mark_light_sync', 10, 1);
add_action('woocommerce_variation_set_stock', 'wc_api_mps_scheduled_mark_light_sync', 10, 1);

function wc_api_mps_scheduled_mark_light_sync($product)
{
  $product_id = $product->get_id();
  update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 1);
}

/**
 * Auto sync products when order status changes to processing or completed
 */
add_action('woocommerce_order_status_processing', 'wc_api_mps_scheduled_order_status_sync', 10, 1);
add_action('woocommerce_order_status_completed', 'wc_api_mps_scheduled_order_status_sync', 10, 1);

function wc_api_mps_scheduled_order_status_sync($order_id)
{
  // Check if auto sync is enabled
  if (!get_option('wc_api_mps_auto_sync_orders', 0)) {
    return;
  }

  // Schedule a one-time cron event to run in 1 minute
  if (!wp_next_scheduled('wc_api_mps_order_sync_event', array($order_id))) {
    wp_schedule_single_event(time() + 60, 'wc_api_mps_order_sync_event', array($order_id));
  }
}
