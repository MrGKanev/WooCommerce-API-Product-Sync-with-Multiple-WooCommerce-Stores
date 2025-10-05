<?php

/**
 * Logging functionality
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Log a message
 */
function wc_api_mps_scheduled_log($message)
{
  $log = get_option('wc_api_mps_sync_log', array());

  $log[] = array(
    'time'    => current_time('mysql'),
    'message' => $message
  );

  // Keep only last 100 entries
  if (count($log) > 100) {
    $log = array_slice($log, -100);
  }

  update_option('wc_api_mps_sync_log', $log);

  // Also log to debug.log if WP_DEBUG is enabled
  if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('WC_Scheduled_Sync: ' . $message);
  }
}

/**
 * Get recent logs
 */
function wc_api_mps_scheduled_get_logs($limit = 50)
{
  $all_logs = get_option('wc_api_mps_sync_log', array());
  return is_array($all_logs) ? array_slice($all_logs, -$limit) : array();
}

/**
 * Clear all logs
 */
function wc_api_mps_scheduled_clear_logs()
{
  update_option('wc_api_mps_sync_log', array());
}
