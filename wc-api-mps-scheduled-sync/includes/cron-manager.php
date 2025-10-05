<?php

/**
 * Cron management utilities
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Get cron status information
 */
function wc_api_mps_scheduled_get_cron_status()
{
  $next_scheduled = wp_next_scheduled('wc_api_mps_scheduled_sync_check');
  $last_check = get_option('wc_api_mps_last_sync_check');

  return array(
    'is_active' => (bool) $next_scheduled,
    'next_run'  => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : null,
    'last_run'  => $last_check ? date('Y-m-d H:i:s', $last_check) : null,
  );
}

/**
 * Manually trigger sync
 */
function wc_api_mps_scheduled_trigger_sync()
{
  wc_api_mps_scheduled_run_sync();
}
