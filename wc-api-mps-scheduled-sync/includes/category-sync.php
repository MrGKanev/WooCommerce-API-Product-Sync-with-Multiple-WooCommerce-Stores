<?php

/**
 * Category Sync Functions
 * Handles syncing categories and updating product category assignments
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Sync all categories to selected destination stores
 */
function wc_api_mps_sync_all_categories()
{
  if (!function_exists('wc_api_mps_destination_category_id')) {
    return array(
      'success' => false,
      'message' => __('Main plugin functions not available.', 'wc-api-mps-scheduled')
    );
  }

  // Get selected stores
  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

  // Filter to only selected stores
  $stores = array();
  foreach ($selected_store_urls as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    return array(
      'success' => false,
      'message' => __('No active stores selected in settings.', 'wc-api-mps-scheduled')
    );
  }

  // Get all product categories
  $categories = get_terms(array(
    'taxonomy' => 'product_cat',
    'hide_empty' => false,
    'orderby' => 'term_id',
    'order' => 'ASC'
  ));

  if (is_wp_error($categories) || empty($categories)) {
    return array(
      'success' => false,
      'message' => __('No categories found to sync.', 'wc-api-mps-scheduled')
    );
  }

  wc_api_mps_scheduled_log(sprintf(
    'Starting category sync: %d categories to %d store(s)',
    count($categories),
    count($stores)
  ));

  $success_count = 0;
  $error_count = 0;
  $store_results = array();

  // Process each store
  foreach ($stores as $store_url => $store_data) {
    require_once WC_API_MPS_PLUGIN_PATH . 'includes/class-api.php';
    $api = new WC_API_MPS(
      $store_url,
      $store_data['consumer_key'],
      $store_data['consumer_secret']
    );

    $store_success = 0;
    $store_errors = 0;
    $store_skipped = 0;

    // Get excluded categories for this store
    $exclude_categories = isset($store_data['exclude_categories_products']) ? $store_data['exclude_categories_products'] : array();

    // Sync each category
    foreach ($categories as $category) {
      try {
        // Skip excluded categories for this store
        if (in_array($category->term_id, $exclude_categories)) {
          $store_skipped++;
          wc_api_mps_scheduled_log(sprintf(
            'Skipped excluded category "%s" for %s',
            $category->name,
            $store_url
          ));
          continue;
        }

        $exclude_term_description = isset($store_data['exclude_term_description']) ? $store_data['exclude_term_description'] : 0;

        // Use existing function to sync category
        $destination_cat_id = wc_api_mps_destination_category_id(
          $api,
          $store_url,
          $category->term_id,
          $exclude_term_description
        );

        if ($destination_cat_id) {
          $store_success++;
          $success_count++;
        } else {
          $store_errors++;
          $error_count++;
        }

        // Small delay to prevent overload
        usleep(100000); // 0.1 seconds
      } catch (Exception $e) {
        $store_errors++;
        $error_count++;
        wc_api_mps_scheduled_log(sprintf(
          'Error syncing category "%s" to %s: %s',
          $category->name,
          $store_url,
          $e->getMessage()
        ));
      }
    }

    $store_results[$store_url] = array(
      'success' => $store_success,
      'errors' => $store_errors,
      'skipped' => $store_skipped
    );

    wc_api_mps_scheduled_log(sprintf(
      'Category sync to %s: %d success, %d errors, %d skipped (excluded)',
      $store_url,
      $store_success,
      $store_errors,
      $store_skipped
    ));
  }

  wc_api_mps_scheduled_log(sprintf(
    'Category sync completed: %d total synced, %d errors across %d store(s)',
    $success_count,
    $error_count,
    count($stores)
  ));

  return array(
    'success' => true,
    'categories_count' => count($categories),
    'stores_count' => count($stores),
    'success_count' => $success_count,
    'error_count' => $error_count,
    'store_results' => $store_results
  );
}

/**
 * Force update product categories only (no other product data)
 * Updates category assignments on all products that have been synced
 */
function wc_api_mps_force_update_product_categories()
{
  if (!function_exists('wc_api_mps_destination_category_id')) {
    return array(
      'success' => false,
      'message' => __('Main plugin functions not available.', 'wc-api-mps-scheduled')
    );
  }

  // Get selected stores
  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

  // Filter to only selected stores
  $stores = array();
  foreach ($selected_store_urls as $store_url) {
    if (isset($all_stores[$store_url]) && $all_stores[$store_url]['status']) {
      $stores[$store_url] = $all_stores[$store_url];
    }
  }

  if (empty($stores)) {
    return array(
      'success' => false,
      'message' => __('No active stores selected in settings.', 'wc-api-mps-scheduled')
    );
  }

  // Get all products that have been synced (have mpsrel meta)
  $args = array(
    'post_type' => 'product',
    'posts_per_page' => -1,
    'post_status' => array('publish', 'pending'),
    'fields' => 'ids',
    'meta_query' => array(
      array(
        'key' => 'mpsrel',
        'compare' => 'EXISTS'
      )
    )
  );

  $product_ids = get_posts($args);

  if (empty($product_ids)) {
    return array(
      'success' => false,
      'message' => __('No synced products found.', 'wc-api-mps-scheduled')
    );
  }

  wc_api_mps_scheduled_log(sprintf(
    'Starting force category update: %d products to %d store(s)',
    count($product_ids),
    count($stores)
  ));

  $success_count = 0;
  $error_count = 0;
  $store_results = array();

  // Process each store
  foreach ($stores as $store_url => $store_data) {
    require_once WC_API_MPS_PLUGIN_PATH . 'includes/class-api.php';
    $api = new WC_API_MPS(
      $store_url,
      $store_data['consumer_key'],
      $store_data['consumer_secret']
    );

    $store_success = 0;
    $store_errors = 0;

    $exclude_categories = isset($store_data['exclude_categories_products']) ? $store_data['exclude_categories_products'] : array();
    $exclude_term_description = isset($store_data['exclude_term_description']) ? $store_data['exclude_term_description'] : 0;

    // Process each product
    foreach ($product_ids as $product_id) {
      try {
        $mpsrel = get_post_meta($product_id, 'mpsrel', true);

        // Skip if product not synced to this store
        if (!is_array($mpsrel) || !isset($mpsrel[$store_url])) {
          continue;
        }

        $destination_product_id = $mpsrel[$store_url];

        // Get product categories
        $product = wc_get_product($product_id);
        if (!$product) {
          continue;
        }

        $category_ids = $product->get_category_ids();

        if (empty($category_ids)) {
          continue;
        }

        // Build category array for destination
        $destination_categories = array();
        foreach ($category_ids as $category_id) {
          // Skip excluded categories
          if (in_array($category_id, $exclude_categories)) {
            continue;
          }

          // Get/create destination category ID
          $dest_cat_id = wc_api_mps_destination_category_id(
            $api,
            $store_url,
            $category_id,
            $exclude_term_description
          );

          if ($dest_cat_id) {
            $destination_categories[] = array('id' => $dest_cat_id);
          }
        }

        // Skip if no categories to update
        if (empty($destination_categories)) {
          continue;
        }

        // Update ONLY categories on destination product
        $update_data = array(
          'categories' => $destination_categories
        );

        $result = $api->updateProduct($update_data, $destination_product_id);

        if (isset($result->id)) {
          $store_success++;
          $success_count++;
        } else {
          $store_errors++;
          $error_count++;
        }

        // Small delay to prevent overload
        usleep(50000); // 0.05 seconds
      } catch (Exception $e) {
        $store_errors++;
        $error_count++;

        $product_sku = $product ? $product->get_sku() : '';
        $identifier = $product_sku ? "SKU: {$product_sku}" : "ID: {$product_id}";

        wc_api_mps_scheduled_log(sprintf(
          'Error updating categories for %s on %s: %s',
          $identifier,
          $store_url,
          $e->getMessage()
        ));
      }
    }

    $store_results[$store_url] = array(
      'success' => $store_success,
      'errors' => $store_errors
    );

    wc_api_mps_scheduled_log(sprintf(
      'Category update for %s: %d products updated, %d errors',
      $store_url,
      $store_success,
      $store_errors
    ));
  }

  wc_api_mps_scheduled_log(sprintf(
    'Force category update completed: %d products updated, %d errors across %d store(s)',
    $success_count,
    $error_count,
    count($stores)
  ));

  return array(
    'success' => true,
    'products_count' => count($product_ids),
    'stores_count' => count($stores),
    'success_count' => $success_count,
    'error_count' => $error_count,
    'store_results' => $store_results
  );
}

/**
 * Get category sync statistics
 */
function wc_api_mps_get_category_stats()
{
  $categories = get_terms(array(
    'taxonomy' => 'product_cat',
    'hide_empty' => false,
  ));

  $total_categories = is_array($categories) ? count($categories) : 0;

  // Count synced categories
  $synced_count = 0;
  if (is_array($categories)) {
    foreach ($categories as $category) {
      $mpsrel = get_term_meta($category->term_id, 'mpsrel', true);
      if (is_array($mpsrel) && !empty($mpsrel)) {
        $synced_count++;
      }
    }
  }

  return array(
    'total' => $total_categories,
    'synced' => $synced_count,
    'unsynced' => $total_categories - $synced_count
  );
}
