<?php

/**
 * Admin page display/design
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
        <span class="wc-api-mps-icon error">⚠</span>
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
      <h1>⏰ <?php _e('Scheduled Sync Dashboard', 'wc-api-mps-scheduled'); ?></h1>
      <div class="wc-api-mps-header-actions">
        <form method="post" style="display: inline-block;">
          <?php wp_nonce_field('wc_api_mps_manual_sync'); ?>
          <button type="submit" name="run_sync_now" class="button button-primary">
            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
            <?php _e('Run Sync Now', 'wc-api-mps-scheduled'); ?>
          </button>
        </form>
        <form method="post" style="display: inline-block;">
          <?php wp_nonce_field('wc_api_mps_clear_logs'); ?>
          <button type="submit" name="clear_logs" class="button button-secondary">
            <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
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
        $icon = $message['type'] === 'success' ? '✓' : '✗';
        echo '<div class="wc-api-mps-notice ' . esc_attr($type_class) . '">';
        echo '<span class="wc-api-mps-icon ' . esc_attr($type_class) . '">' . $icon . '</span>';
        echo '<div>' . esc_html($message['text']) . '</div>';
        echo '</div>';
      }
    }
    ?>

    <!-- Status Grid -->
    <div class="wc-api-mps-status-grid">

      <!-- Cron Status Card -->
      <div class="wc-api-mps-status-card <?php echo $cron_status['is_active'] ? 'active' : 'inactive'; ?>">
        <h3><?php _e('Cron Status', 'wc-api-mps-scheduled'); ?></h3>
        <div class="wc-api-mps-status-value <?php echo $cron_status['is_active'] ? 'success' : 'error'; ?>">
          <?php echo $cron_status['is_active'] ? '✓' : '✗'; ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php echo $cron_status['is_active'] ? __('Active', 'wc-api-mps-scheduled') : __('Inactive', 'wc-api-mps-scheduled'); ?>
        </div>
        <?php if ($cron_status['next_run']): ?>
          <div class="wc-api-mps-text-muted" style="font-size: 11px; margin-top: 10px;">
            <?php _e('Next:', 'wc-api-mps-scheduled'); ?> <?php echo esc_html($cron_status['next_run']); ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Current Mode Card -->
      <div class="wc-api-mps-status-card <?php echo $is_off_peak ? 'info' : 'warning'; ?>">
        <h3><?php _e('Current Mode', 'wc-api-mps-scheduled'); ?></h3>
        <div class="wc-api-mps-status-value" style="font-size: 24px;">
          <?php echo $is_off_peak ? '🌙' : '☀️'; ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php echo $is_off_peak ? __('Off-Peak', 'wc-api-mps-scheduled') : __('Peak Hours', 'wc-api-mps-scheduled'); ?>
        </div>
        <div class="wc-api-mps-text-muted" style="font-size: 11px; margin-top: 10px;">
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
          <?php echo $auto_sync_orders ? '✓' : '✗'; ?>
        </div>
        <div class="wc-api-mps-status-label">
          <?php echo $auto_sync_orders ? __('Enabled', 'wc-api-mps-scheduled') : __('Disabled', 'wc-api-mps-scheduled'); ?>
        </div>
      </div>

    </div>

    <!-- Force Sync Queue Status -->
    <?php
    $force_sync_stats = isset($force_sync_stats) ? $force_sync_stats : array(
      'pending' => 0,
      'running' => 0,
      'complete' => 0,
      'failed' => 0
    );

    $total_pending = is_array($force_sync_stats['pending']) ? count($force_sync_stats['pending']) : 0;
    $total_running = is_array($force_sync_stats['running']) ? count($force_sync_stats['running']) : 0;
    $total_complete = is_array($force_sync_stats['complete']) ? count($force_sync_stats['complete']) : 0;
    $total_failed = is_array($force_sync_stats['failed']) ? count($force_sync_stats['failed']) : 0;
    $has_active_queue = ($total_pending + $total_running) > 0;
    ?>

    <div class="wc-api-mps-card">
      <h2>
        <span class="dashicons dashicons-update"></span>
        <?php _e('Force Sync Queue', 'wc-api-mps-scheduled'); ?>
      </h2>

      <p><?php _e('Queue products from the last 15 orders for background sync. Products are processed one at a time to prevent server overload.', 'wc-api-mps-scheduled'); ?></p>

      <?php if ($has_active_queue): ?>
        <div class="wc-api-mps-notice" style="background: #e7f3ff; border-left-color: #2271b1; margin: 15px 0;">
          <span class="wc-api-mps-icon" style="color: #2271b1;">⏳</span>
          <div>
            <strong><?php _e('Sync in Progress', 'wc-api-mps-scheduled'); ?></strong>
            <p style="margin: 5px 0 0 0;">
              <?php printf(
                __('Pending: %d | Running: %d', 'wc-api-mps-scheduled'),
                $total_pending,
                $total_running
              ); ?>
            </p>
          </div>
        </div>
      <?php endif; ?>

      <div class="wc-api-mps-queue-stats" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0;">
        <div style="padding: 15px; background: #f0f0f1; border-radius: 4px; text-align: center;">
          <div style="font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo $total_pending; ?></div>
          <div style="margin-top: 5px; color: #666;">⏳ <?php _e('Pending', 'wc-api-mps-scheduled'); ?></div>
        </div>

        <div style="padding: 15px; background: #f0f0f1; border-radius: 4px; text-align: center;">
          <div style="font-size: 32px; font-weight: bold; color: #007cba;"><?php echo $total_running; ?></div>
          <div style="margin-top: 5px; color: #666;">▶️ <?php _e('Running', 'wc-api-mps-scheduled'); ?></div>
        </div>

        <div style="padding: 15px; background: #f0f0f1; border-radius: 4px; text-align: center;">
          <div style="font-size: 32px; font-weight: bold; color: #46b450;"><?php echo $total_complete; ?></div>
          <div style="margin-top: 5px; color: #666;">✓ <?php _e('Complete (1h)', 'wc-api-mps-scheduled'); ?></div>
        </div>

        <div style="padding: 15px; background: #f0f0f1; border-radius: 4px; text-align: center;">
          <div style="font-size: 32px; font-weight: bold; color: #dc3232;"><?php echo $total_failed; ?></div>
          <div style="margin-top: 5px; color: #666;">✗ <?php _e('Failed (1h)', 'wc-api-mps-scheduled'); ?></div>
        </div>
      </div>

      <div class="wc-api-mps-order-actions" style="display: flex; gap: 10px; margin-top: 20px;">
        <form method="post" style="flex: 1;">
          <?php wp_nonce_field('wc_api_mps_force_sync'); ?>
          <button type="submit" name="force_sync_orders" class="button button-primary" style="width: 100%;" <?php echo $has_active_queue ? 'disabled' : ''; ?>>
            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
            <?php _e('Queue Last 15 Orders for Sync', 'wc-api-mps-scheduled'); ?>
          </button>
        </form>

        <?php if ($has_active_queue): ?>
          <form method="post">
            <?php wp_nonce_field('wc_api_mps_cancel_sync'); ?>
            <button type="submit" name="cancel_force_sync" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to cancel all pending sync actions?', 'wc-api-mps-scheduled'); ?>');">
              <span class="dashicons dashicons-no" style="margin-top: 3px;"></span>
              <?php _e('Cancel Queue', 'wc-api-mps-scheduled'); ?>
            </button>
          </form>
        <?php endif; ?>

        <a href="<?php echo admin_url('tools.php?page=action-scheduler&s=wc_api_mps_force_sync_product'); ?>" class="button button-secondary" target="_blank">
          <span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
          <?php _e('View Details', 'wc-api-mps-scheduled'); ?>
        </a>
      </div>

      <?php if ($has_active_queue): ?>
        <p style="margin-top: 15px; color: #666; font-size: 13px;">
          ℹ️ <?php _e('Products are processed automatically in the background. This page will auto-refresh to show progress.', 'wc-api-mps-scheduled'); ?>
        </p>
        <script>
          setTimeout(function() {
            location.reload();
          }, 10000);
        </script>
      <?php endif; ?>
    </div>

    <!-- Schedule Section -->
    <div class="wc-api-mps-card">
      <h2>
        <span class="dashicons dashicons-clock"></span>
        <?php _e('Sync Schedule', 'wc-api-mps-scheduled'); ?>
      </h2>

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
            <td class="wc-api-mps-schedule-time">🌙 12:00 AM - 6:30 AM</td>
            <td><span class="wc-api-mps-schedule-badge full">full_product</span></td>
            <td><strong><?php echo esc_html($batch_size_offpeak); ?></strong> products</td>
            <td><?php _e('All product data', 'wc-api-mps-scheduled'); ?></td>
          </tr>
          <tr>
            <td class="wc-api-mps-schedule-time">☀️ 6:30 AM - 12:00 AM</td>
            <td><span class="wc-api-mps-schedule-badge light">price_and_quantity</span></td>
            <td><strong><?php echo esc_html($batch_size); ?></strong> products</td>
            <td><?php _e('Price, quantity, stock status', 'wc-api-mps-scheduled'); ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Order Products Sync Section -->
    <div class="wc-api-mps-card">
      <h2>
        <span class="dashicons dashicons-cart"></span>
        <?php _e('Order Products Sync Status', 'wc-api-mps-scheduled'); ?>
      </h2>

      <p class="description">
        <?php _e('Check if products from recent orders are synced to selected stores.', 'wc-api-mps-scheduled'); ?>
      </p>

      <div class="wc-api-mps-order-actions" style="margin: 20px 0;">
        <form method="post">
          <?php wp_nonce_field('wc_api_mps_check_orders'); ?>
          <button type="submit" name="check_orders" class="button button-secondary">
            <span class="dashicons dashicons-search" style="margin-top: 3px;"></span>
            <?php _e('Check Last 15 Orders', 'wc-api-mps-scheduled'); ?>
          </button>
        </form>
      </div>

      <?php if (!empty($order_sync_data['results'])): ?>
        <div class="wc-api-mps-order-meta" style="padding: 10px; background: #f0f0f1; border-radius: 4px; margin-bottom: 15px;">
          📊 <?php
              printf(
                __('Checked: %s | %d orders | %d products', 'wc-api-mps-scheduled'),
                $order_sync_data['checked_at'],
                $order_sync_data['order_count'],
                $order_sync_data['product_count']
              );
              ?>
        </div>

        <table class="wc-api-mps-products-table">
          <thead>
            <tr>
              <th><?php _e('Product', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('SKU', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Stock', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Sync Status', 'wc-api-mps-scheduled'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($order_sync_data['results'] as $result): ?>
              <?php
              $product = $result['product'];
              $synced_count = count($result['synced_stores']);
              $not_synced_count = count($result['not_synced_stores']);
              $total_stores = $synced_count + $not_synced_count;

              $status_color = '#999';
              $status_icon = '○';
              if ($synced_count === $total_stores) {
                $status_color = '#46b450';
                $status_icon = '✓';
              } elseif ($synced_count > 0) {
                $status_color = '#ffb900';
                $status_icon = '◐';
              } else {
                $status_color = '#dc3232';
                $status_icon = '✗';
              }
              ?>
              <tr>
                <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                <td><?php echo esc_html($product->get_sku() ?: __('No SKU', 'wc-api-mps-scheduled')); ?></td>
                <td><?php echo $product->is_in_stock() ? '✓ ' . __('In Stock', 'wc-api-mps-scheduled') : '✗ ' . __('Out of Stock', 'wc-api-mps-scheduled'); ?></td>
                <td>
                  <span style="color: <?php echo $status_color; ?>; font-size: 18px;"><?php echo $status_icon; ?></span>
                  <?php printf(__('%d of %d stores', 'wc-api-mps-scheduled'), $synced_count, $total_stores); ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="wc-api-mps-section">
      <h2 class="wc-api-mps-section-title">
        🏷️ <?php _e('Category Sync', 'wc-api-mps-scheduled'); ?>
      </h2>

      <div class="wc-api-mps-section-content">
        <div class="wc-api-mps-info-box">
          <p>
            <?php _e('Sync all product categories to destination stores, or force update category assignments on existing products.', 'wc-api-mps-scheduled'); ?>
          </p>
        </div>

        <!-- Category Statistics -->
        <div class="wc-api-mps-category-stats">
          <div class="wc-api-mps-stat-item">
            <div class="wc-api-mps-stat-value"><?php echo number_format($category_stats['total']); ?></div>
            <div class="wc-api-mps-stat-label"><?php _e('Total Categories', 'wc-api-mps-scheduled'); ?></div>
          </div>
          <div class="wc-api-mps-stat-item">
            <div class="wc-api-mps-stat-value" style="color: #46b450;"><?php echo number_format($category_stats['synced']); ?></div>
            <div class="wc-api-mps-stat-label"><?php _e('Synced', 'wc-api-mps-scheduled'); ?></div>
          </div>
          <div class="wc-api-mps-stat-item">
            <div class="wc-api-mps-stat-value" style="color: #dc3232;"><?php echo number_format($category_stats['unsynced']); ?></div>
            <div class="wc-api-mps-stat-label"><?php _e('Unsynced', 'wc-api-mps-scheduled'); ?></div>
          </div>
        </div>

        <!-- Category Sync Actions -->
        <div class="wc-api-mps-category-actions">
          <div class="wc-api-mps-action-group">
            <form method="post">
              <?php wp_nonce_field('wc_api_mps_sync_categories'); ?>
              <button type="submit"
                name="sync_all_categories"
                class="button button-primary"
                onclick="return confirm('<?php esc_attr_e('This will sync all product categories to selected destination stores. This may take a few minutes. Continue?', 'wc-api-mps-scheduled'); ?>');">
                <span class="dashicons dashicons-category" style="margin-top: 3px;"></span>
                <?php _e('Sync All Categories', 'wc-api-mps-scheduled'); ?>
              </button>
            </form>
            <p class="description">
              <?php _e('Syncs all product categories (including parent/child relationships and images) to destination stores.', 'wc-api-mps-scheduled'); ?>
            </p>
          </div>

          <div class="wc-api-mps-action-group">
            <form method="post">
              <?php wp_nonce_field('wc_api_mps_update_product_cats'); ?>
              <button type="submit"
                name="force_update_product_categories"
                class="button button-secondary"
                onclick="return confirm('<?php esc_attr_e('This will update ONLY the category assignments on all synced products. No other product data will be changed. Continue?', 'wc-api-mps-scheduled'); ?>');">
                <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                <?php _e('Force Update Product Categories', 'wc-api-mps-scheduled'); ?>
              </button>
            </form>
            <p class="description">
              <?php _e('Updates only category assignments on existing synced products (does not change prices, stock, descriptions, etc).', 'wc-api-mps-scheduled'); ?>
            </p>
          </div>
        </div>

        <!-- Important Notes -->
        <div class="wc-api-mps-info-box" style="background: #fff3cd; border-left-color: #ffc107;">
          <h4 style="margin-top: 0;">⚠️ <?php _e('Important Notes:', 'wc-api-mps-scheduled'); ?></h4>
          <ul style="margin: 10px 0 0 20px;">
            <li><?php _e('Categories are automatically synced when products are synced (during regular sync).', 'wc-api-mps-scheduled'); ?></li>
            <li><?php _e('Use "Sync All Categories" to pre-sync categories before syncing products, or to update all category data.', 'wc-api-mps-scheduled'); ?></li>
            <li><?php _e('Use "Force Update Product Categories" if you\'ve added/removed categories from products and want to update destinations immediately.', 'wc-api-mps-scheduled'); ?></li>
            <li><?php _e('Category exclusions (configured in store settings) are respected during sync.', 'wc-api-mps-scheduled'); ?></li>
            <li><?php _e('Parent-child category relationships are preserved.', 'wc-api-mps-scheduled'); ?></li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Settings Section -->
    <div class="wc-api-mps-card">
      <h2>
        <span class="dashicons dashicons-admin-settings"></span>
        <?php _e('Settings', 'wc-api-mps-scheduled'); ?>
      </h2>

      <form method="post">
        <?php wp_nonce_field('wc_api_mps_save_settings'); ?>

        <table class="wc-api-mps-form-table">
          <tr>
            <th><?php _e('Peak Hours Batch Size', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <input type="number" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50">
              <p class="description"><?php _e('Products per sync during peak hours (6:30 AM - 12:00 AM)', 'wc-api-mps-scheduled'); ?></p>
            </td>
          </tr>

          <tr>
            <th><?php _e('Off-Peak Batch Size', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <input type="number" name="batch_size_offpeak" value="<?php echo esc_attr($batch_size_offpeak); ?>" min="1" max="100">
              <p class="description"><?php _e('Products per sync during off-peak hours (12:00 AM - 6:30 AM)', 'wc-api-mps-scheduled'); ?></p>
            </td>
          </tr>

          <tr>
            <th><?php _e('Auto Sync on Orders', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="auto_sync_orders" value="1" <?php checked($auto_sync_orders, 1); ?>>
                <?php _e('Automatically sync products when orders are placed', 'wc-api-mps-scheduled'); ?>
              </label>
              <p class="description"><?php _e('Syncs products from last 15 orders when an order status changes to processing or completed.', 'wc-api-mps-scheduled'); ?></p>
            </td>
          </tr>

          <tr>
            <th><?php _e('Force Full Sync Mode', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="force_full_sync" value="1" <?php checked($force_full_sync, 1); ?>>
                <?php _e('Always run full product sync (ignore time-based schedule)', 'wc-api-mps-scheduled'); ?>
              </label>
              <p class="description"><?php _e('Enable this to sync all product data 24/7. Useful for initial bulk sync or catching up.', 'wc-api-mps-scheduled'); ?></p>
            </td>
          </tr>

          <tr>
            <th><?php _e('Selected Stores', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <?php if (empty($all_stores)): ?>
                <p class="description"><?php _e('No stores configured in main plugin.', 'wc-api-mps-scheduled'); ?></p>
              <?php else: ?>
                <div class="wc-api-mps-stores-container">
                  <label style="display: block; margin-bottom: 15px; font-weight: 600;">
                    <input type="checkbox" class="wc-api-mps-select-all-stores">
                    <?php _e('Select / Deselect All', 'wc-api-mps-scheduled'); ?>
                  </label>

                  <?php foreach ($all_stores as $store_url => $store_data): ?>
                    <div class="wc-api-mps-store-item <?php echo $store_data['status'] ? 'active' : 'inactive'; ?>">
                      <label class="wc-api-mps-store-label">
                        <input type="checkbox" name="selected_stores[]" value="<?php echo esc_attr($store_url); ?>" <?php checked(in_array($store_url, $selected_stores)); ?> <?php echo !$store_data['status'] ? 'disabled' : ''; ?>>
                        <strong><?php echo esc_html($store_url); ?></strong>
                        <?php if (!$store_data['status']): ?>
                          <span style="color: #dc3232; margin-left: 10px;"><?php _e('(Inactive)', 'wc-api-mps-scheduled'); ?></span>
                        <?php endif; ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p class="description" style="margin-top: 10px;">
                  <?php _e('Select which stores to sync to. Category/tag exclusions are automatically respected.', 'wc-api-mps-scheduled'); ?>
                </p>
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <p class="submit">
          <button type="submit" name="save_settings" class="button button-primary">
            <span class="dashicons dashicons-yes" style="margin-top: 3px;"></span>
            <?php _e('Save Settings', 'wc-api-mps-scheduled'); ?>
          </button>
        </p>
      </form>
    </div>

    <!-- Logs Section -->
    <div class="wc-api-mps-card">
      <h2>
        <span class="dashicons dashicons-media-text"></span>
        <?php _e('Recent Logs', 'wc-api-mps-scheduled'); ?>
        <span class="wc-api-mps-badge"><?php echo count($logs); ?></span>
      </h2>

      <?php if (empty($logs)): ?>
        <p style="text-align: center; padding: 20px; color: #999;">
          <?php _e('No logs yet.', 'wc-api-mps-scheduled'); ?>
        </p>
      <?php else: ?>
        <table class="wc-api-mps-logs-table">
          <thead>
            <tr>
              <th style="width: 180px;"><?php _e('Time', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Message', 'wc-api-mps-scheduled'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($logs) as $log): ?>
              <tr>
                <td><?php echo esc_html($log['time']); ?></td>
                <td><?php echo esc_html($log['message']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- SKU Logs Section -->
    <?php if (function_exists('wc_api_mps_get_sku_log_stats')): ?>
      <div class="wc-api-mps-card">
        <h2>
          <span class="dashicons dashicons-text-page"></span>
          <?php _e('SKU-Specific Logs', 'wc-api-mps-scheduled'); ?>
        </h2>

        <p class="description">
          <?php _e('Detailed sync logs are saved per SKU in /wp-content/uploads/wc-api-mps-logs/', 'wc-api-mps-scheduled'); ?>
        </p>

        <?php
        $sku_stats = wc_api_mps_get_sku_log_stats();
        if ($sku_stats['total_files'] > 0):
        ?>
          <div style="padding: 15px; background: #f0f0f1; border-radius: 4px; margin: 15px 0;">
            <strong><?php _e('Total SKU Log Files:', 'wc-api-mps-scheduled'); ?></strong>
            <?php echo number_format($sku_stats['total_files']); ?>
            (<?php echo size_format($sku_stats['total_size'], 2); ?>)
          </div>

          <form method="post" style="margin-top: 15px;">
            <?php wp_nonce_field('wc_api_mps_cleanup_logs'); ?>
            <label>
              <?php _e('Delete logs older than:', 'wc-api-mps-scheduled'); ?>
              <input type="number" name="cleanup_days" value="30" min="1" max="365" style="width: 80px;">
              <?php _e('days', 'wc-api-mps-scheduled'); ?>
            </label>
            <button type="submit" name="cleanup_sku_logs" class="button button-secondary" style="margin-left: 10px;">
              <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
              <?php _e('Cleanup Old Logs', 'wc-api-mps-scheduled'); ?>
            </button>
          </form>
        <?php else: ?>
          <p style="color: #999; padding: 20px 0;"><?php _e('No SKU logs found yet.', 'wc-api-mps-scheduled'); ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Debug Section (only if ?debug=1) -->
    <?php if (isset($_GET['debug'])): ?>
      <div class="wc-api-mps-card" style="background: #fff3cd; border-left: 4px solid #ffc107;">
        <h2>
          <span class="dashicons dashicons-warning"></span>
          <?php _e('Debug Information', 'wc-api-mps-scheduled'); ?>
        </h2>
        <?php wc_api_mps_scheduled_debug_info(); ?>
      </div>
    <?php endif; ?>

  </div>
<?php
}
