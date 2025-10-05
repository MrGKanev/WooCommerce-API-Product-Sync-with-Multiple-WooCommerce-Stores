<?php

/**
 * Debug helper functions
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Add debug info to admin page
 */
function wc_api_mps_scheduled_debug_info()
{
  if (!current_user_can('manage_options')) {
    return;
  }

  // Check if debug parameter is set
  if (!isset($_GET['debug'])) {
    return;
  }

?>
  <div class="card" style="background: #fff3cd; border-left: 4px solid #ffc107;">
    <h2>🔍 Debug Information</h2>

    <h3>WordPress Cron Status</h3>
    <table class="widefat">
      <tr>
        <th style="width: 250px;">WP_CRON Enabled:</th>
        <td><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? '❌ Disabled' : '✅ Enabled'; ?></td>
      </tr>
      <tr>
        <th>All Scheduled Events:</th>
        <td>
          <?php
          $cron_array = _get_cron_array();
          if ($cron_array) {
            echo '<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">';
            foreach ($cron_array as $timestamp => $cron) {
              echo 'Time: ' . date('Y-m-d H:i:s', $timestamp) . "\n";
              foreach ($cron as $hook => $events) {
                foreach ($events as $key => $event) {
                  if (strpos($hook, 'wc_api_mps') !== false) {
                    echo "  Hook: {$hook}\n";
                    echo "  Schedule: " . ($event['schedule'] ?? 'one-time') . "\n";
                    echo "  Interval: " . ($event['interval'] ?? 'N/A') . " seconds\n";
                    echo "\n";
                  }
                }
              }
            }
            echo '</pre>';
          } else {
            echo 'No events scheduled';
          }
          ?>
        </td>
      </tr>
      <tr>
        <th>Our Hook Scheduled:</th>
        <td>
          <?php
          $next = wp_next_scheduled('wc_api_mps_scheduled_sync_check');
          if ($next) {
            echo '✅ Yes - Next run: ' . date('Y-m-d H:i:s', $next);
          } else {
            echo '❌ Not scheduled';
          }
          ?>
        </td>
      </tr>
      <tr>
        <th>Custom Intervals:</th>
        <td>
          <?php
          $schedules = wp_get_schedules();
          if (isset($schedules['every_15_minutes'])) {
            echo '✅ every_15_minutes interval exists (' . $schedules['every_15_minutes']['interval'] . ' seconds)';
          } else {
            echo '❌ every_15_minutes interval NOT registered';
          }
          ?>
        </td>
      </tr>
    </table>

    <h3>Actions</h3>
    <form method="post" style="margin-top: 10px;">
      <?php wp_nonce_field('wc_api_mps_reschedule_cron'); ?>
      <input type="submit" name="reschedule_cron" class="button button-primary" value="🔄 Force Reschedule Cron">
      <p class="description">This will clear and recreate the cron schedule</p>
    </form>

    <form method="post" style="margin-top: 10px;">
      <?php wp_nonce_field('wc_api_mps_test_cron'); ?>
      <input type="submit" name="test_cron" class="button button-secondary" value="▶️ Test Run Sync Now">
      <p class="description">Manually trigger sync to test if it works</p>
    </form>
  </div>
<?php
}

/**
 * Handle reschedule action
 */
function wc_api_mps_scheduled_handle_reschedule()
{
  if (isset($_POST['reschedule_cron']) && check_admin_referer('wc_api_mps_reschedule_cron')) {
    // Clear existing schedule
    $timestamp = wp_next_scheduled('wc_api_mps_scheduled_sync_check');
    if ($timestamp) {
      wp_unschedule_event($timestamp, 'wc_api_mps_scheduled_sync_check');
    }

    // Clear all instances
    wp_clear_scheduled_hook('wc_api_mps_scheduled_sync_check');

    // Reschedule
    wp_schedule_event(time(), 'every_15_minutes', 'wc_api_mps_scheduled_sync_check');

    echo '<div class="notice notice-success"><p>✅ Cron rescheduled successfully!</p></div>';

    wc_api_mps_scheduled_log('Cron manually rescheduled via debug panel');
  }

  if (isset($_POST['test_cron']) && check_admin_referer('wc_api_mps_test_cron')) {
    wc_api_mps_scheduled_log('Manual test sync triggered from debug panel');
    wc_api_mps_scheduled_run_sync();
    echo '<div class="notice notice-success"><p>✅ Test sync completed! Check logs below.</p></div>';
  }
}
