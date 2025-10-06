<?php

/**
 * Product query functions
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Get products that need syncing (excluding products in excluded categories/tags)
 */
function wc_api_mps_scheduled_get_products($sync_type, $limit = 10)
{
  // Get selected stores and their exclusions
  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

  // Collect all excluded category and tag IDs from selected stores
  $excluded_cat_ids = array();
  $excluded_tag_ids = array();

  foreach ($selected_store_urls as $store_url) {
    if (isset($all_stores[$store_url])) {
      $store_data = $all_stores[$store_url];

      if (isset($store_data['exclude_categories_products']) && is_array($store_data['exclude_categories_products'])) {
        $excluded_cat_ids = array_merge($excluded_cat_ids, $store_data['exclude_categories_products']);
      }

      if (isset($store_data['exclude_tags_products']) && is_array($store_data['exclude_tags_products'])) {
        $excluded_tag_ids = array_merge($excluded_tag_ids, $store_data['exclude_tags_products']);
      }
    }
  }

  $excluded_cat_ids = array_unique($excluded_cat_ids);
  $excluded_tag_ids = array_unique($excluded_tag_ids);

  $products = array();

  if ($sync_type === 'full_product') {
    $args = array(
      'post_type'      => 'product',
      'posts_per_page' => $limit,
      'post_status'    => 'publish',
      'fields'         => 'ids',
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'meta_query'     => array(
        'relation' => 'OR',
        array(
          'key'     => '_wc_api_mps_needs_full_sync',
          'value'   => '1',
          'compare' => '='
        ),
        array(
          'key'     => '_wc_api_mps_needs_sync',
          'value'   => '1',
          'compare' => '='
        ),
        array(
          'key'     => '_wc_api_mps_last_sync',
          'compare' => 'NOT EXISTS'
        )
      )
    );

    // Exclude products in excluded categories
    if (!empty($excluded_cat_ids)) {
      $args['tax_query'][] = array(
        'taxonomy' => 'product_cat',
        'field'    => 'term_id',
        'terms'    => $excluded_cat_ids,
        'operator' => 'NOT IN'
      );
    }

    // Exclude products with excluded tags
    if (!empty($excluded_tag_ids)) {
      $args['tax_query'][] = array(
        'taxonomy' => 'product_tag',
        'field'    => 'term_id',
        'terms'    => $excluded_tag_ids,
        'operator' => 'NOT IN'
      );
    }
  } else {
    // Light sync - price and quantity changes
    $args = array(
      'post_type'      => 'product',
      'posts_per_page' => $limit,
      'post_status'    => 'publish',
      'fields'         => 'ids',
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'meta_query'     => array(
        array(
          'key'     => '_wc_api_mps_needs_light_sync',
          'value'   => '1',
          'compare' => '='
        )
      )
    );

    // Exclude products in excluded categories
    if (!empty($excluded_cat_ids)) {
      $args['tax_query'][] = array(
        'taxonomy' => 'product_cat',
        'field'    => 'term_id',
        'terms'    => $excluded_cat_ids,
        'operator' => 'NOT IN'
      );
    }

    // Exclude products with excluded tags
    if (!empty($excluded_tag_ids)) {
      $args['tax_query'][] = array(
        'taxonomy' => 'product_tag',
        'field'    => 'term_id',
        'terms'    => $excluded_tag_ids,
        'operator' => 'NOT IN'
      );
    }
  }

  $query = new WP_Query($args);

  if ($query->have_posts()) {
    $products = $query->posts;
  }

  wp_reset_postdata();

  return array_unique($products);
}

/**
 * Get count of products needing sync (with exclusions and caching)
 */
function wc_api_mps_scheduled_count_products($sync_type)
{
  $cache_key = 'wc_api_mps_pending_count_' . $sync_type;
  $cached_count = get_transient($cache_key);

  if ($cached_count !== false) {
    return (int) $cached_count;
  }

  // Get selected stores and their exclusions
  $selected_store_urls = get_option('wc_api_mps_cron_selected_stores', array());
  $all_stores = get_option('wc_api_mps_stores', array());

  // Collect all excluded category and tag IDs
  $excluded_cat_ids = array();
  $excluded_tag_ids = array();

  foreach ($selected_store_urls as $store_url) {
    if (isset($all_stores[$store_url])) {
      $store_data = $all_stores[$store_url];

      if (isset($store_data['exclude_categories_products']) && is_array($store_data['exclude_categories_products'])) {
        $excluded_cat_ids = array_merge($excluded_cat_ids, $store_data['exclude_categories_products']);
      }

      if (isset($store_data['exclude_tags_products']) && is_array($store_data['exclude_tags_products'])) {
        $excluded_tag_ids = array_merge($excluded_tag_ids, $store_data['exclude_tags_products']);
      }
    }
  }

  $excluded_cat_ids = array_unique($excluded_cat_ids);
  $excluded_tag_ids = array_unique($excluded_tag_ids);

  global $wpdb;

  // Build exclusion WHERE clauses
  $exclusion_sql = '';

  if (!empty($excluded_cat_ids)) {
    $cat_ids_str = implode(',', array_map('intval', $excluded_cat_ids));
    $exclusion_sql .= " AND p.ID NOT IN (
            SELECT object_id FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ({$cat_ids_str})
        )";
  }

  if (!empty($excluded_tag_ids)) {
    $tag_ids_str = implode(',', array_map('intval', $excluded_tag_ids));
    $exclusion_sql .= " AND p.ID NOT IN (
            SELECT object_id FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.taxonomy = 'product_tag' AND tt.term_id IN ({$tag_ids_str})
        )";
  }

  if ($sync_type === 'full_product') {
    $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wc_api_mps_needs_full_sync'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wc_api_mps_needs_sync'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wc_api_mps_last_sync'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND (pm1.meta_value = '1' OR pm2.meta_value = '1' OR pm3.meta_id IS NULL)
            {$exclusion_sql}
        ");
  } else {
    $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND pm.meta_key = '_wc_api_mps_needs_light_sync'
            AND pm.meta_value = '1'
            {$exclusion_sql}
        ");
  }

  $count = (int) $count;
  set_transient($cache_key, $count, 60);

  return $count;
}
