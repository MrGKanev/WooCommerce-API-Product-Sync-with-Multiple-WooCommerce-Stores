<?php

/**
 * Product query functions
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Get products that need syncing
 */
function wc_api_mps_scheduled_get_products($sync_type, $limit = 1)
{
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
  } else {
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
  }

  $query = new WP_Query($args);

  if ($query->have_posts()) {
    $products = $query->posts;
  }

  wp_reset_postdata();

  return array_unique($products);
}

/**
 * Get count of products needing sync (with caching)
 */
function wc_api_mps_scheduled_count_products($sync_type)
{
  $cache_key = 'wc_api_mps_pending_count_' . $sync_type;
  $cached_count = get_transient($cache_key);

  if ($cached_count !== false) {
    return (int) $cached_count;
  }

  global $wpdb;

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
        ");
  }

  $count = (int) $count;
  set_transient($cache_key, $count, 60);

  return $count;
}
