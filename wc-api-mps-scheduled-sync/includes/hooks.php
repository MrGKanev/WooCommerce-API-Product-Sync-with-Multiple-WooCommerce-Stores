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
