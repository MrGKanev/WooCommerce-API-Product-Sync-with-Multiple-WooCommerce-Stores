<?php

/**
 * Admin page display/design
 * Updated with new design: full width, rem units, no emojis/dashicons, 2-column layout
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Add admin menu
 */
function wc_api_mps_scheduled_add_menu()
{
  global $menu;
  $parent_exists = false;

  if (is_array($menu)) {
    foreach ($menu as $item) {
      if (isset($item[2]) && $item[2] === 'wc_api_mps') {
        $parent_exists = true;
        break;
      }
    }
  }

  if ($parent_exists) {
    add_submenu_page(
      'wc_api_mps',
      __('Scheduled Sync', 'wc-api-mps-scheduled'),
      __('Scheduled Sync', 'wc-api-mps-scheduled'),
      'manage_options',
      'wc-api-mps-scheduled-sync',
      'wc_api_mps_scheduled_admin_page'
    );
  } else {
    add_menu_page(
      __('Scheduled Sync', 'wc-api-mps-scheduled'),
      __('Scheduled Sync', 'wc-api-mps-scheduled'),
      'manage_options',
      'wc-api-mps-scheduled-sync',
      'wc_api_mps_scheduled_admin_page',
      'dashicons-update',
      56
    );
  }
}

/**
 * Register settings
 */
function wc_api_mps_scheduled_register_settings()
{
  register_setting('wc_api_mps_scheduled_sync', 'wc_api_mps_cron_batch_size', array(
    'type' => 'integer',
    'default' => 5,
    'sanitize_callback' => 'absint'
  ));

  register_setting('wc_api_mps_scheduled_sync', 'wc_api_mps_cron_batch_size_offpeak', array(
    'type' => 'integer',
    'default' => 20,
    'sanitize_callback' => 'absint'
  ));

  register_setting('wc_api_mps_scheduled_sync', 'wc_api_mps_cron_selected_stores', array(
    'type' => 'array',
    'default' => array(),
    'sanitize_callback' => 'wc_api_mps_scheduled_sanitize_stores'
  ));

  register_setting('wc_api_mps_scheduled_sync', 'wc_api_mps_auto_sync_orders', array(
    'type' => 'integer',
    'default' => 0,
    'sanitize_callback' => 'absint'
  ));
}

/**
 * Sanitize selected stores
 */
function wc_api_mps_scheduled_sanitize_stores($input)
{
  if (!is_array($input)) {
    return array();
  }
  return array_map('esc_url_raw', $input);
}

/**
 * Admin page content
 */
function wc_api_mps_scheduled_admin_page()
{
  @set_time_limit(60);

  if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'wc-api-mps-scheduled'));
  }

  // Process actions and get data
  $data = wc_api_mps_scheduled_process_admin_actions();

  // Check for error
  if (isset($data['error'])) {
?>
    <div class="wrap wc-api-mps-scheduled-wrap">
      <h1><?php _e('Scheduled Sync Status', 'wc-api-mps-scheduled'); ?></h1>
      <div class="wc-api-mps-notice error">
        <span class="wc-api-mps-icon error">ERROR</span>
        <div>
          <strong><?php _e('Error:', 'wc-api-mps-scheduled'); ?></strong>
          <?php echo esc_html($data['error']); ?>
        </div>
      </div>
    </div>
  <?php
    return;
  }

  // Extract data
  extract($data);

  ?>
  <div class="wrap wc-api-mps-scheduled-wrap">

    <!-- Header -->
    <div class="wc-api-mps-scheduled-header">
      <h1><?php _e('Scheduled Sync Dashboard', 'wc-api-mps-scheduled'); ?></h1>
      <div class="wc-api-mps-header-actions">
        <form method="post" style="display: inline-block;">
          <?php wp_nonce_field('wc_api_mps_manual_sync'); ?>
          <button type="submit" name="run_sync_now" class="button button-primary">
            <?php _e('Run Sync Now', 'wc-api-mps-scheduled'); ?>
          </button>
        </form>
        <form method="post" style="display: inline-block;">
          <?php wp_nonce_field('wc_api_mps_clear_logs'); ?>
          <button type="submit" name="clear_logs" class="button button-secondary">
            <?php _e('Clear Logs', 'wc-api-mps-scheduled'); ?>
          </button>
        </form>
      </div>
    </div>

    <?php
    // Display messages
    if (!empty($messages)) {
      foreach ($messages as $message) {
        $type_class = $message['type'] === 'success' ? 'success' : 'error';
        $icon_text = $message['type'] === 'success' ? 'SUCCESS' : 'ERROR';
        echo '<div class="wc-api-mps-notice ' . esc_attr($type_class) . '">';
        echo '<span class="wc-api-mps-icon ' . esc_attr($type_class) . '">' . $icon_text . '</span>';
        echo '<div>' . esc_html($message['text']) . '</div>';
        echo '</div>';
      }
    }
    ?>

    <!-- Status Grid (4 cards) -->
    <div class="wc-api-mps-status-grid">

      <!-- Cron Status Card -->
      <div class="wc-api-mps-status-card <?php echo $cron_status['is_active'] ? 'active' : 'inactive'; ?>">
        <h3><?php _e('Cron Status', 'wc-api-mps-scheduled'); ?></h3>
        <div class="wc-api-mps-status-value <?php echo $cron_status['is_active'] ? 'success' : 'error'; ?>">
          <?php echo $cron_status['is_active'] ? __('Active', 'wc-api-mps-scheduled') : __('Inactive', 'wc-api-mps-scheduled'); ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php echo $cron_status['is_active'] ? __('Running', 'wc-api-mps-scheduled') : __('Not Running', 'wc-api-mps-scheduled'); ?>
        </div>
        <?php if ($cron_status['next_run']): ?>
          <div class="wc-api-mps-text-muted" style="margin-top: 0.71rem;">
            <?php _e('Next:', 'wc-api-mps-scheduled'); ?> <?php echo esc_html($cron_status['next_run']); ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Current Mode Card -->
      <div class="wc-api-mps-status-card <?php echo $is_off_peak ? 'info' : 'warning'; ?>">
        <h3><?php _e('Current Mode', 'wc-api-mps-scheduled'); ?></h3>
        <div class="wc-api-mps-status-value" style="font-size: 1.71rem;">
          <?php echo $is_off_peak ? __('Night', 'wc-api-mps-scheduled') : __('Day', 'wc-api-mps-scheduled'); ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php echo $is_off_peak ? __('Off-Peak', 'wc-api-mps-scheduled') : __('Peak Hours', 'wc-api-mps-scheduled'); ?>
        </div>
        <div class="wc-api-mps-text-muted" style="margin-top: 0.71rem;">
          <code><?php echo esc_html($sync_type); ?></code>
        </div>
      </div>

      <!-- Pending Products Card -->
      <div class="wc-api-mps-status-card info">
        <h3><?php _e('Pending Sync', 'wc-api-mps-scheduled'); ?></h3>
        <div class="wc-api-mps-status-value" style="color: #0073aa;">
          <?php echo number_format($products_count); ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php _e('Products', 'wc-api-mps-scheduled'); ?>
        </div>
      </div>

      <!-- Auto Order Sync Card -->
      <div class="wc-api-mps-status-card <?php echo $auto_sync_orders ? 'active' : 'inactive'; ?>">
        <h3><?php _e('Auto Order Sync', 'wc-api-mps-scheduled'); ?></h3>
        <div class="wc-api-mps-status-value <?php echo $auto_sync_orders ? 'success' : ''; ?>" style="<?php echo !$auto_sync_orders ? 'color: #999;' : ''; ?>">
          <?php echo $auto_sync_orders ? __('Enabled', 'wc-api-mps-scheduled') : __('Disabled', 'wc-api-mps-scheduled'); ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php echo $auto_sync_orders ? __('Active', 'wc-api-mps-scheduled') : __('Inactive', 'wc-api-mps-scheduled'); ?>
        </div>
      </div>

    </div>

    <!-- 2-Column Grid for Large Sections -->
    <div class="wc-api-mps-main-grid">

      <!-- Queue Status Section -->
      <div class="wc-api-mps-card">
        <h2><?php _e('Sync Queue Status', 'wc-api-mps-scheduled'); ?></h2>

        <p class="description">
          <?php _e('Products are automatically queued when orders are placed. The queue is processed every 5 minutes to reduce server load.', 'wc-api-mps-scheduled'); ?>
        </p>

        <?php
        $queue_stats = isset($queue_stats) ? $queue_stats : wc_api_mps_get_queue_stats();
        $has_queue = $queue_stats['total'] > 0;
        ?>

        <div class="wc-api-mps-stats-grid">
          <div class="wc-api-mps-stat-box">
            <div class="wc-api-mps-stat-value" style="color: #0073aa;">
              <?php echo number_format($queue_stats['total']); ?>
            </div>
            <div class="wc-api-mps-stat-label">
              <?php _e('Total in Queue', 'wc-api-mps-scheduled'); ?>
            </div>
          </div>

          <div class="wc-api-mps-stat-box">
            <div class="wc-api-mps-stat-value" style="color: #46b450;">
              <?php echo number_format($queue_stats['pending']); ?>
            </div>
            <div class="wc-api-mps-stat-label">
              <?php _e('Pending (New)', 'wc-api-mps-scheduled'); ?>
            </div>
          </div>

          <div class="wc-api-mps-stat-box">
            <div class="wc-api-mps-stat-value" style="color: #ffb900;">
              <?php echo number_format($queue_stats['retrying']); ?>
            </div>
            <div class="wc-api-mps-stat-label">
              <?php _e('Retrying (Errors)', 'wc-api-mps-scheduled'); ?>
            </div>
          </div>

          <div class="wc-api-mps-stat-box">
            <div class="wc-api-mps-stat-value" style="font-size: 1rem; color: #666;">
              <?php echo $queue_stats['oldest'] ? esc_html($queue_stats['oldest']) : __('N/A', 'wc-api-mps-scheduled'); ?>
            </div>
            <div class="wc-api-mps-stat-label">
              <?php _e('Oldest Queued Item', 'wc-api-mps-scheduled'); ?>
            </div>
          </div>
        </div>

        <?php if ($has_queue): ?>
          <div class="wc-api-mps-notice info">
            <span class="wc-api-mps-icon">INFO</span>
            <div>
              <strong><?php _e('Queue is active!', 'wc-api-mps-scheduled'); ?></strong>
              <p style="margin: 0.36rem 0 0 0;">
                <?php _e('Products will be synced automatically within the next 5 minutes. No action required.', 'wc-api-mps-scheduled'); ?>
              </p>
            </div>
          </div>
        <?php else: ?>
          <div class="wc-api-mps-notice" style="background: #f0f0f0; border-left-color: #999;">
            <span class="wc-api-mps-icon" style="color: #666;">INFO</span>
            <div>
              <p style="margin: 0; color: #666;">
                <?php _e('Queue is empty. Products will be added automatically when orders are placed.', 'wc-api-mps-scheduled'); ?>
              </p>
            </div>
          </div>
        <?php endif; ?>

        <div class="wc-api-mps-actions">
          <form method="post">
            <?php wp_nonce_field('wc_api_mps_flush_queue'); ?>
            <button
              type="submit"
              name="flush_queue"
              class="button button-primary"
              <?php echo !$has_queue ? 'disabled' : ''; ?>>
              <?php _e('Process Queue Now', 'wc-api-mps-scheduled'); ?>
            </button>
          </form>

          <form method="post" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to clear the queue? This will remove all pending syncs.', 'wc-api-mps-scheduled'); ?>');">
            <?php wp_nonce_field('wc_api_mps_clear_queue'); ?>
            <button
              type="submit"
              name="clear_queue"
              class="button button-secondary"
              <?php echo !$has_queue ? 'disabled' : ''; ?>>
              <?php _e('Clear Queue', 'wc-api-mps-scheduled'); ?>
            </button>
          </form>
        </div>
      </div>

      <!-- Schedule Section -->
      <div class="wc-api-mps-card">
        <h2><?php _e('Sync Schedule', 'wc-api-mps-scheduled'); ?></h2>

        <p class="description">
          <?php _e('Syncs run every 15 minutes. The type of sync and batch size varies based on time of day:', 'wc-api-mps-scheduled'); ?>
        </p>

        <table class="wc-api-mps-schedule-table">
          <thead>
            <tr>
              <th><?php _e('Time Period', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Sync Type', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Batch Size', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('What Syncs', 'wc-api-mps-scheduled'); ?></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="wc-api-mps-schedule-time"><?php _e('12:00 AM - 6:30 AM', 'wc-api-mps-scheduled'); ?></td>
              <td><span class="wc-api-mps-schedule-badge full">full_product</span></td>
              <td><strong><?php echo esc_html($batch_size_offpeak); ?></strong> <?php _e('products', 'wc-api-mps-scheduled'); ?></td>
              <td><?php _e('All product data', 'wc-api-mps-scheduled'); ?></td>
            </tr>
            <tr>
              <td class="wc-api-mps-schedule-time"><?php _e('6:30 AM - 12:00 AM', 'wc-api-mps-scheduled'); ?></td>
              <td><span class="wc-api-mps-schedule-badge light">price_and_quantity</span></td>
              <td><strong><?php echo esc_html($batch_size); ?></strong> <?php _e('products', 'wc-api-mps-scheduled'); ?></td>
              <td><?php _e('Price, quantity, stock status', 'wc-api-mps-scheduled'); ?></td>
            </tr>
          </tbody>
        </table>
      </div>

    </div><!-- Close main-grid -->

    <!-- SKU-Based Full Product Sync Section (Full Width) -->
    <div class="wc-api-mps-card">
      <h2><?php _e('Update Products by SKU', 'wc-api-mps-scheduled'); ?></h2>

      <p class="description">
        <?php _e('Trigger a full product sync for specific SKUs. Enter one or multiple SKUs (comma or line-separated). Products will be matched on remote stores by SKU and fully updated.', 'wc-api-mps-scheduled'); ?>
      </p>

      <form method="post">
        <?php wp_nonce_field('wc_api_mps_sku_sync'); ?>

        <div class="wc-api-mps-settings-row">
          <div class="wc-api-mps-settings-label">
            <?php _e('SKU(s)', 'wc-api-mps-scheduled'); ?>
          </div>
          <div class="wc-api-mps-settings-input">
            <textarea
              name="sku_list"
              rows="5"
              style="width: 100%; max-width: 600px;"
              placeholder="<?php esc_attr_e('Enter SKUs (comma or line-separated, e.g., SKU-001, SKU-002 or one per line)', 'wc-api-mps-scheduled'); ?>"><?php echo isset($_POST['sku_list']) ? esc_textarea($_POST['sku_list']) : ''; ?></textarea>
            <span class="wc-api-mps-settings-help">
              <?php _e('Examples: "SKU-001, SKU-002, SKU-003" or one SKU per line', 'wc-api-mps-scheduled'); ?>
            </span>
          </div>
        </div>

        <div class="wc-api-mps-actions">
          <button type="submit" name="sync_by_sku" class="button button-primary">
            <?php _e('Sync Selected SKUs', 'wc-api-mps-scheduled'); ?>
          </button>
        </div>
      </form>

      <?php if (isset($sku_sync_result) && !empty($sku_sync_result)): ?>
        <div style="margin-top: 1.43rem;">
          <h3><?php _e('Sync Results', 'wc-api-mps-scheduled'); ?></h3>

          <?php if ($sku_sync_result['success'] && $sku_sync_result['queued_count'] > 0): ?>
            <div class="wc-api-mps-notice success">
              <span class="wc-api-mps-icon success">QUEUED</span>
              <div>
                <strong><?php _e('Products Queued for Background Sync:', 'wc-api-mps-scheduled'); ?></strong>
                <p style="margin: 0.36rem 0 0 0;">
                  <?php echo sprintf(__('%d product(s) have been queued for background processing.', 'wc-api-mps-scheduled'), $sku_sync_result['queued_count']); ?>
                </p>
                <p style="margin: 0.36rem 0 0 0;">
                  <?php _e('Go to <strong>WooCommerce > Status > Scheduled Actions</strong> to monitor progress.', 'wc-api-mps-scheduled'); ?>
                </p>
                <p style="margin: 0.36rem 0 0 0;">
                  <em><?php _e('The sync will process in the background. You can close this page safely.', 'wc-api-mps-scheduled'); ?></em>
                </p>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($sku_sync_result['not_found'])): ?>
            <div class="wc-api-mps-notice error">
              <span class="wc-api-mps-icon error">WARNING</span>
              <div>
                <strong><?php _e('SKUs Not Found or Not Published:', 'wc-api-mps-scheduled'); ?></strong>
                <ul style="margin: 0.36rem 0 0 1.43rem;">
                  <?php foreach ($sku_sync_result['not_found'] as $sku): ?>
                    <li><?php echo esc_html($sku); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($sku_sync_result['errors'])): ?>
            <div class="wc-api-mps-notice error">
              <span class="wc-api-mps-icon error">ERROR</span>
              <div>
                <strong><?php _e('Configuration Errors:', 'wc-api-mps-scheduled'); ?></strong>
                <ul style="margin: 0.36rem 0 0 1.43rem;">
                  <?php foreach ($sku_sync_result['errors'] as $item): ?>
                    <li><?php echo esc_html($item['error']); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Settings Section (Full Width) -->
    <div class="wc-api-mps-card">
      <h2><?php _e('Settings', 'wc-api-mps-scheduled'); ?></h2>

      <form method="post">
        <?php wp_nonce_field('wc_api_mps_save_settings'); ?>

        <div class="wc-api-mps-settings-row">
          <div class="wc-api-mps-settings-label">
            <?php _e('Peak Hours Batch Size', 'wc-api-mps-scheduled'); ?>
          </div>
          <div class="wc-api-mps-settings-input">
            <input
              type="number"
              name="batch_size"
              value="<?php echo esc_attr($batch_size); ?>"
              min="1"
              max="50"
              class="small-text">
            <span class="wc-api-mps-settings-help">
              <?php _e('Number of products to sync per run during peak hours (6:30 AM - 12:00 AM)', 'wc-api-mps-scheduled'); ?>
            </span>
          </div>
        </div>

        <div class="wc-api-mps-settings-row">
          <div class="wc-api-mps-settings-label">
            <?php _e('Off-Peak Hours Batch Size', 'wc-api-mps-scheduled'); ?>
          </div>
          <div class="wc-api-mps-settings-input">
            <input
              type="number"
              name="batch_size_offpeak"
              value="<?php echo esc_attr($batch_size_offpeak); ?>"
              min="1"
              max="100"
              class="small-text">
            <span class="wc-api-mps-settings-help">
              <?php _e('Number of products to sync per run during off-peak hours (12:00 AM - 6:30 AM)', 'wc-api-mps-scheduled'); ?>
            </span>
          </div>
        </div>

        <div class="wc-api-mps-settings-row">
          <div class="wc-api-mps-settings-label">
            <?php _e('Auto Sync on Order Status Change', 'wc-api-mps-scheduled'); ?>
          </div>
          <div class="wc-api-mps-settings-input">
            <label>
              <input
                type="checkbox"
                name="auto_sync_orders"
                value="1"
                <?php checked($auto_sync_orders, 1); ?>>
              <?php _e('Automatically queue products when order status changes to Processing or Completed', 'wc-api-mps-scheduled'); ?>
            </label>
          </div>
        </div>

        <div class="wc-api-mps-settings-row">
          <div class="wc-api-mps-settings-label">
            <?php _e('Force Full Sync Mode', 'wc-api-mps-scheduled'); ?>
          </div>
          <div class="wc-api-mps-settings-input">
            <label>
              <input
                type="checkbox"
                name="force_full_sync"
                value="1"
                <?php checked($force_full_sync, 1); ?>>
              <?php _e('Always run full product sync (ignores time-based scheduling)', 'wc-api-mps-scheduled'); ?>
            </label>
            <span class="wc-api-mps-settings-help">
              <?php _e('Enable this for initial bulk sync or to catch up. Uses off-peak batch size.', 'wc-api-mps-scheduled'); ?>
            </span>
          </div>
        </div>

        <div class="wc-api-mps-settings-row">
          <div class="wc-api-mps-settings-label">
            <?php _e('Selected Stores', 'wc-api-mps-scheduled'); ?>
          </div>
          <div class="wc-api-mps-settings-input">
            <div class="wc-api-mps-store-list">
              <?php if (!empty($all_stores)): ?>
                <?php foreach ($all_stores as $store_url => $store_data): ?>
                  <?php if ($store_data['status']): ?>
                    <div class="wc-api-mps-store-item">
                      <input
                        type="checkbox"
                        name="selected_stores[]"
                        value="<?php echo esc_attr($store_url); ?>"
                        id="store_<?php echo esc_attr(md5($store_url)); ?>"
                        <?php checked(in_array($store_url, $selected_stores)); ?>>
                      <label for="store_<?php echo esc_attr(md5($store_url)); ?>">
                        <?php echo esc_html($store_data['name']); ?>
                      </label>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="wc-api-mps-text-muted">
                  <?php _e('No stores configured in the main plugin.', 'wc-api-mps-scheduled'); ?>
                </p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="wc-api-mps-actions">
          <button type="submit" name="save_settings" class="button button-primary">
            <?php _e('Save Settings', 'wc-api-mps-scheduled'); ?>
          </button>
        </div>
      </form>
    </div>

    <!-- Activity Logs Section (Full Width) -->
    <div class="wc-api-mps-card">
      <h2><?php _e('Activity Log', 'wc-api-mps-scheduled'); ?></h2>

      <p class="description">
        <?php _e('Recent sync activity (last 50 entries)', 'wc-api-mps-scheduled'); ?>
      </p>

      <?php if (!empty($logs)): ?>
        <div class="wc-api-mps-logs">
          <?php foreach ($logs as $log): ?>
            <div class="wc-api-mps-log-entry">
              <span class="wc-api-mps-log-time"><?php echo esc_html($log['time']); ?></span>
              <span class="wc-api-mps-log-message"><?php echo esc_html($log['message']); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="wc-api-mps-notice">
          <span class="wc-api-mps-icon">INFO</span>
          <div>
            <p style="margin: 0;">
              <?php _e('No log entries found.', 'wc-api-mps-scheduled'); ?>
            </p>
          </div>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- Close wrap -->
<?php
}
