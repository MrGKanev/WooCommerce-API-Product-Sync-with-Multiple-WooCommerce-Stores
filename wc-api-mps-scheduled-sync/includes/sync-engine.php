<?php

/**
 * Main sync execution engine
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Run scheduled sync
 */
function wc_api_mps_scheduled_run_sync()
{
  // Check if main plugin is active
  if (!function_exists('wc_api_mps_integration')) {
    wc_api_mps_scheduled_log('Main plugin not found. Skipping.');
    return;
  }

  // Get stores
  $stores = get_option('wc_api_mps_stores');
  if (!$stores || !is_array($stores)) {
    wc_api_mps_scheduled_log('No stores configured. Skipping.');
    return;
  }

  // Determine sync type and batch size
  $sync_type = wc_api_mps_scheduled_get_sync_type();
  $is_off_peak = wc_api_mps_scheduled_is_off_peak();
  $batch_size = wc_api_mps_scheduled_get_batch_size();

  // Get products to sync
  $products_to_sync = wc_api_mps_scheduled_get_products($sync_type);

  if (empty($products_to_sync)) {
    wc_api_mps_scheduled_log(sprintf('No products need syncing (%s).', $sync_type));
    return;
  }

  wc_api_mps_scheduled_log(sprintf(
    'Starting %s sync for %d products (Off-peak: %s).',
    $sync_type,
    count($products_to_sync),
    $is_off_peak ? 'Yes' : 'No'
  ));

  $success_count = 0;
  $error_count = 0;

  // Process batch
  $products_to_process = array_slice($products_to_sync, 0, $batch_size);

  foreach ($products_to_process as $product_id) {
    try {
      // Get product SKU for logging
      $product = wc_get_product($product_id);
      $product_sku = $product ? $product->get_sku() : '';
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

      // Run sync using main plugin function
      wc_api_mps_integration($product_id, $stores, $sync_type);

      // Mark as synced
      update_post_meta($product_id, '_wc_api_mps_last_sync', time());
      update_post_meta($product_id, '_wc_api_mps_last_sync_type', $sync_type);

      // Clear sync flags
      if ($sync_type === 'full_product') {
        update_post_meta($product_id, '_wc_api_mps_needs_sync', 0);
        update_post_meta($product_id, '_wc_api_mps_needs_full_sync', 0);
        update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);
      } else {
        update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);
      }

      $success_count++;
      wc_api_mps_scheduled_log(sprintf('✓ Synced %s (%s)', $product_identifier, $sync_type));
    } catch (Exception $e) {
      $error_count++;
      $product = wc_get_product($product_id);
      $product_sku = $product ? $product->get_sku() : '';
      $product_identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";
      wc_api_mps_scheduled_log(sprintf('✗ Error syncing %s: %s', $product_identifier, $e->getMessage()));
    }

    // Prevent timeouts
    usleep($is_off_peak ? 50000 : 200000);
  }

  update_option('wc_api_mps_last_sync_check', time());
  wc_api_mps_scheduled_log(sprintf('Sync completed. Success: %d, Errors: %d', $success_count, $error_count));
}
