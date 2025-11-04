<?php

/**
 * Product defaults helper - ensures products can be created even with missing fields
 *
 * Since we can't modify the main plugin, we use WordPress pre_http_request filter
 * to intercept API calls and ensure required fields have defaults.
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Intercept WooCommerce API POST requests to add product defaults
 */
add_filter('pre_http_request', 'wc_api_mps_scheduled_add_product_defaults', 10, 3);

function wc_api_mps_scheduled_add_product_defaults($preempt, $parsed_args, $url)
{
  // Only intercept POST requests to WooCommerce REST API products endpoint
  if (
    isset($parsed_args['method']) &&
    $parsed_args['method'] === 'POST' &&
    strpos($url, '/wp-json/wc/v3/products') !== false &&
    !strpos($url, '/variations') && // Don't intercept variation requests
    isset($parsed_args['body'])
  ) {

    // Decode the body
    $body = json_decode($parsed_args['body'], true);

    if (is_array($body)) {
      $modified = false;

      // Check if this is a product creation (no ID in URL path after /products/)
      // If URL is like .../products/123, it's an update. If it's just .../products, it's a create
      $url_parts = parse_url($url);
      $path = isset($url_parts['path']) ? $url_parts['path'] : '';
      $is_create = preg_match('#/products/?(\?|$)#', $path);

      if ($is_create) {
        // Apply defaults for missing required fields
        if (empty($body['name'])) {
          $body['name'] = 'New Product';
          $modified = true;
        }

        if (empty($body['type'])) {
          $body['type'] = 'simple';
          $modified = true;
        }

        // Regular price - set to 0 if missing (some stores require this)
        if (!isset($body['regular_price']) || $body['regular_price'] === '') {
          $body['regular_price'] = '0';
          $modified = true;
        }

        // Stock status default
        if (!isset($body['stock_status'])) {
          $body['stock_status'] = 'instock';
          $modified = true;
        }

        // Tax status default
        if (!isset($body['tax_status'])) {
          $body['tax_status'] = 'taxable';
          $modified = true;
        }

        // Catalog visibility default
        if (empty($body['catalog_visibility'])) {
          $body['catalog_visibility'] = 'visible';
          $modified = true;
        }

        // Stock management default
        if (!isset($body['manage_stock'])) {
          $body['manage_stock'] = false;
          $modified = true;
        }

        // Virtual default
        if (!isset($body['virtual'])) {
          $body['virtual'] = false;
          $modified = true;
        }

        // Downloadable default
        if (!isset($body['downloadable'])) {
          $body['downloadable'] = false;
          $modified = true;
        }

        // Reviews default
        if (!isset($body['reviews_allowed'])) {
          $body['reviews_allowed'] = true;
          $modified = true;
        }

        // Backorders default
        if (!isset($body['backorders'])) {
          $body['backorders'] = 'no';
          $modified = true;
        }

        // Sold individually default
        if (!isset($body['sold_individually'])) {
          $body['sold_individually'] = false;
          $modified = true;
        }

        // If we modified the data, update the request
        if ($modified) {
          $parsed_args['body'] = json_encode($body);

          wc_api_mps_scheduled_log(sprintf(
            'Applied default values for product creation API call: %s',
            isset($body['sku']) ? $body['sku'] : (isset($body['name']) ? $body['name'] : 'Unknown')
          ));
        }
      }
    }
  }

  // Return false to allow the request to proceed normally
  return false;
}
