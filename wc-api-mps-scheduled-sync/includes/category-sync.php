<?php

/**
 * Async Category Sync Functions
 * Uses WordPress actions for background processing
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Register background processing hooks
 */
add_action('wc_api_mps_async_sync_categories', 'wc_api_mps_async_sync_categories_handler');
add_action('wc_api_mps_async_update_product_categories', 'wc_api_mps_async_update_product_categories_handler');

/**
 * Initiate async category sync
 */
function wc_api_mps_initiate_async_category_sync()
{
  // Store start time
  update_option('wc_api_mps_category_sync_started', time());
  update_option('wc_api_mps_category_sync_status', 'running');
  delete_option('wc_api_mps_category_sync_result');

  wc_api_mps_scheduled_log('Category sync initiated (background process)');

  // Schedule immediate action
  if (!wp_next_scheduled('wc_api_mps_async_sync_categories')) {
    wp_schedule_single_event(time(), 'wc_api_mps_async_sync_categories');
  }

  return array(
    'success' => true,
    'message' => __('Category sync started in background. Refresh page in 1-2 minutes to see results.', 'wc-api-mps-scheduled')
  );
}

/**
 * Background handler for category sync
 */
function wc_api_mps_async_sync_categories_handler()
{
  // Prevent timeout
  @set_time_limit(0);

  wc_api_mps_scheduled_log('Background category sync processing started');

  // Run the actual sync
  $result = wc_api_mps_sync_all_categories_internal();

  // Store result
  update_option('wc_api_mps_category_sync_result', $result);
  update_option('wc_api_mps_category_sync_status', 'completed');
  update_option('wc_api_mps_category_sync_completed', time());

  wc_api_mps_scheduled_log(sprintf(
    'Background category sync completed: %d synced, %d errors',
    $result['success_count'],
    $result['error_count']
  ));
}

/**
 * Initiate async product category update
 */
function wc_api_mps_initiate_async_product_category_update()
{
  // Store start time
  update_option('wc_api_mps_product_cat_update_started', time());
  update_option('wc_api_mps_product_cat_update_status', 'running');
  delete_option('wc_api_mps_product_cat_update_result');

  wc_api_mps_scheduled_log('Product category update initiated (background process)');

  // Schedule immediate action
  if (!wp_next_scheduled('wc_api_mps_async_update_product_categories')) {
    wp_schedule_single_event(time(), 'wc_api_mps_async_update_product_categories');
  }

  return array(
    'success' => true,
    'message' => __('Product category update started in background. Refresh page in 1-2 minutes to see results.', 'wc-api-mps-scheduled')
  );
}

/**
 * Background handler for product category update
 */
function wc_api_mps_async_update_product_categories_handler()
{
  // Prevent timeout
  @set_time_limit(0);

  wc_api_mps_scheduled_log('Background product category update processing started');

  // Run the actual update
  $result = wc_api_mps_force_update_product_categories_internal();

  // Store result
  update_option('wc_api_mps_product_cat_update_result', $result);
  update_option('wc_api_mps_product_cat_update_status', 'completed');
  update_option('wc_api_mps_product_cat_update_completed', time());

  wc_api_mps_scheduled_log(sprintf(
    'Background product category update completed: %d updated, %d errors',
    $result['success_count'],
    $result['error_count']
  ));
}

/**
 * Get category sync status
 */
function wc_api_mps_get_category_sync_status()
{
  $status = get_option('wc_api_mps_category_sync_status', 'idle');
  $started = get_option('wc_api_mps_category_sync_started', 0);
  $completed = get_option('wc_api_mps_category_sync_completed', 0);
  $result = get_option('wc_api_mps_category_sync_result', null);

  $data = array(
    'status' => $status,
    'started' => $started,
    'completed' => $completed,
    'result' => $result,
    'elapsed' => $started ? (time() - $started) : 0
  );

  return $data;
}

/**
 * Get product category update status
 */
function wc_api_mps_get_product_cat_update_status()
{
  $status = get_option('wc_api_mps_product_cat_update_status', 'idle');
  $started = get_option('wc_api_mps_product_cat_update_started', 0);
  $completed = get_option('wc_api_mps_product_cat_update_completed', 0);
  $result = get_option('wc_api_mps_product_cat_update_result', null);

  $data = array(
    'status' => $status,
    'started' => $started,
    'completed' => $completed,
    'result' => $result,
    'elapsed' => $started ? (time() - $started) : 0
  );

  return $data;
}

/**
 * Internal sync function (renamed from original)
 * This is called by the background handler
 */
function wc_api_mps_sync_all_categories_internal()
{
  if (!function_exists('wc_api_mps_destination_category_id')) {
    return array(
      'success' => false,
      'message' => __('Main plugin functions not available.', 'wc-api-mps-scheduled')
    );
  }

  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

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

    $exclude_categories = isset($store_data['exclude_categories_products']) ? $store_data['exclude_categories_products'] : array();

    foreach ($categories as $category) {
      try {
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

        usleep(100000);
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

/**
 * Internal product category update function
 */
function wc_api_mps_force_update_product_categories_internal()
{
  if (!function_exists('wc_api_mps_destination_category_id')) {
    return array(
      'success' => false,
      'message' => __('Main plugin functions not available.', 'wc-api-mps-scheduled')
    );
  }

  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

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

    foreach ($product_ids as $product_id) {
      try {
        $mpsrel = get_post_meta($product_id, 'mpsrel', true);

        if (!is_array($mpsrel) || !isset($mpsrel[$store_url])) {
          continue;
        }

        $destination_product_id = $mpsrel[$store_url];

        $product = wc_get_product($product_id);
        if (!$product) {
          continue;
        }

        $category_ids = $product->get_category_ids();

        if (empty($category_ids)) {
          continue;
        }

        $destination_categories = array();
        foreach ($category_ids as $category_id) {
          if (in_array($category_id, $exclude_categories)) {
            continue;
          }

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

        if (empty($destination_categories)) {
          continue;
        }

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

        usleep(50000);
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
