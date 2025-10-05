<?php

/**
 * Plugin activation and deactivation
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Activate plugin - schedule cron
 */
function wc_api_mps_scheduled_activate()
{
  if (!wp_next_scheduled('wc_api_mps_scheduled_sync_check')) {
    wp_schedule_event(time(), 'every_15_minutes', 'wc_api_mps_scheduled_sync_check');
  }

  // Set default options
  if (false === get_option('wc_api_mps_cron_batch_size')) {
    update_option('wc_api_mps_cron_batch_size', 5);
  }

  if (false === get_option('wc_api_mps_cron_batch_size_offpeak')) {
    update_option('wc_api_mps_cron_batch_size_offpeak', 20);
  }
}

/**
 * Deactivate plugin - clear cron
 */
function wc_api_mps_scheduled_deactivate()
{
  $timestamp = wp_next_scheduled('wc_api_mps_scheduled_sync_check');
  if ($timestamp) {
    wp_unschedule_event($timestamp, 'wc_api_mps_scheduled_sync_check');
  }
}

/**
 * Add custom 15-minute cron interval
 */
function wc_api_mps_scheduled_add_interval($schedules)
{
  $schedules['every_15_minutes'] = array(
    'interval' => 900,
    'display'  => __('Every 15 Minutes', 'wc-api-mps-scheduled')
  );
  return $schedules;
}
