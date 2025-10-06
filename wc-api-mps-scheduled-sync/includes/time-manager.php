<?php

/**
 * Time-based sync management
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Check if current time is off-peak hours (12:00 AM - 6:30 AM)
 */
function wc_api_mps_scheduled_is_off_peak()
{
  $current_time = current_time('timestamp');
  $current_hour = (int) date('G', $current_time);
  $current_minute = (int) date('i', $current_time);

  // Between 12:00 AM (midnight) and 6:30 AM
  if ($current_hour < 6 || ($current_hour == 6 && $current_minute <= 30)) {
    return true;
  }

  return false;
}

/**
 * Get sync type based on time of day
 */
function wc_api_mps_scheduled_get_sync_type()
{
  $force_full_sync = get_option('wc_api_mps_force_full_sync', 0);

  if ($force_full_sync) {
    return 'full_product';
  }

  if (wc_api_mps_scheduled_is_off_peak()) {
    return 'full_product';
  } else {
    return 'price_and_quantity';
  }
}

/**
 * Get batch size based on time
 */
function wc_api_mps_scheduled_get_batch_size()
{
  $force_full_sync = get_option('wc_api_mps_force_full_sync', 0);

  if ($force_full_sync || wc_api_mps_scheduled_is_off_peak()) {
    return get_option('wc_api_mps_cron_batch_size_offpeak', 20);
  } else {
    return get_option('wc_api_mps_cron_batch_size', 5);
  }
}
