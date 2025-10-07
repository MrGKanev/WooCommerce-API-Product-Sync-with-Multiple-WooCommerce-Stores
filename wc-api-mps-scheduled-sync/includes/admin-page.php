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
    wp_die(__('You do not have sufficient permissions to access this page.'));
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

    <!-- Schedule Section -->
    <div class="wc-api-mps-section" id="schedule-section">
      <div class="wc-api-mps-section-header">
        <h2>
          <span class="dashicons dashicons-clock"></span>
          <?php _e('Sync Schedule', 'wc-api-mps-scheduled'); ?>
        </h2>
        <span class="wc-api-mps-section-toggle">▼</span>
      </div>
      <div class="wc-api-mps-section-content">
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
    </div>

    <!-- Order Products Sync Section -->
    <div class="wc-api-mps-section" id="order-sync-section">
      <div class="wc-api-mps-section-header">
        <h2>
          <span class="dashicons dashicons-cart"></span>
          <?php _e('Order Products Sync Status', 'wc-api-mps-scheduled'); ?>
        </h2>
        <span class="wc-api-mps-section-toggle">▼</span>
      </div>
      <div class="wc-api-mps-section-content">
        <p class="description">
          <?php _e('Check if products from recent orders are synced to selected stores.', 'wc-api-mps-scheduled'); ?>
        </p>

        <div class="wc-api-mps-order-actions">
          <form method="post">
            <?php wp_nonce_field('wc_api_mps_check_orders'); ?>
            <button type="submit" name="check_orders" class="button button-secondary">
              <span class="dashicons dashicons-search" style="margin-top: 3px;"></span>
              <?php _e('Check Last 15 Orders', 'wc-api-mps-scheduled'); ?>
            </button>
          </form>

          <form method="post">
            <?php wp_nonce_field('wc_api_mps_force_sync'); ?>
            <button type="submit" name="force_sync_orders" class="button button-primary">
              <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
              <?php _e('Force Sync Last 15 Orders Now', 'wc-api-mps-scheduled'); ?>
            </button>
          </form>
        </div>

        <?php if (!empty($order_sync_data['results'])): ?>
          <div class="wc-api-mps-order-meta">
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
                  <td><?php echo esc_html($product->get_sku() ?: '-'); ?></td>
                  <td>
                    <?php
                    if ($product->is_in_stock()) {
                      echo '<span style="color: #46b450;">●</span> ';
                      if ($product->managing_stock()) {
                        echo esc_html($product->get_stock_quantity());
                      } else {
                        echo __('In Stock', 'wc-api-mps-scheduled');
                      }
                    } else {
                      echo '<span style="color: #dc3232;">●</span> ' . __('Out of Stock', 'wc-api-mps-scheduled');
                    }
                    ?>
                  </td>
                  <td>
                    <div class="wc-api-mps-sync-status">
                      <span class="wc-api-mps-sync-indicator" style="color: <?php echo $status_color; ?>;">
                        <?php echo $status_icon; ?>
                      </span>
                      <span style="font-weight: bold;">
                        <?php echo $synced_count; ?>/<?php echo $total_stores; ?>
                      </span>
                    </div>
                    <?php if ($not_synced_count > 0): ?>
                      <span class="wc-api-mps-sync-missing">
                        <?php _e('Missing:', 'wc-api-mps-scheduled'); ?>
                        <?php echo esc_html(implode(', ', array_map(function ($url) {
                          return parse_url($url, PHP_URL_HOST);
                        }, $result['not_synced_stores']))); ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Settings Section -->
    <div class="wc-api-mps-section" id="settings-section">
      <div class="wc-api-mps-section-header">
        <h2>
          <span class="dashicons dashicons-admin-generic"></span>
          <?php _e('Settings', 'wc-api-mps-scheduled'); ?>
        </h2>
        <span class="wc-api-mps-section-toggle">▼</span>
      </div>
      <div class="wc-api-mps-section-content">
        <form method="post">
          <?php wp_nonce_field('wc_api_mps_save_settings'); ?>

          <table class="wc-api-mps-form-table">
            <tr>
              <th><?php _e('Peak Hours Batch Size', 'wc-api-mps-scheduled'); ?></th>
              <td>
                <input type="number" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50" style="width: 80px;">
                <p class="description"><?php _e('Products to sync per run during peak hours (6:30 AM - 12:00 AM). Recommended: 5-10', 'wc-api-mps-scheduled'); ?></p>
              </td>
            </tr>

            <tr>
              <th><?php _e('Off-Peak Batch Size', 'wc-api-mps-scheduled'); ?></th>
              <td>
                <input type="number" name="batch_size_offpeak" value="<?php echo esc_attr($batch_size_offpeak); ?>" min="1" max="100" style="width: 80px;">
                <p class="description"><?php _e('Products to sync per run during off-peak (12:00 AM - 6:30 AM). Recommended: 20-50', 'wc-api-mps-scheduled'); ?></p>
              </td>
            </tr>

            <tr>
              <th><?php _e('Auto Order Sync', 'wc-api-mps-scheduled'); ?></th>
              <td>
                <label>
                  <input type="checkbox" name="auto_sync_orders" value="1" <?php checked($auto_sync_orders, 1); ?>>
                  <?php _e('Automatically sync products when order status changes to Processing or Completed', 'wc-api-mps-scheduled'); ?>
                </label>
                <p class="description">
                  <?php _e('When enabled, products from the last 15 orders will be synced (quantity only) whenever an order reaches Processing or Completed status.', 'wc-api-mps-scheduled'); ?>
                </p>
              </td>
            </tr>

            <tr>
              <th><?php _e('Sync to These Stores', 'wc-api-mps-scheduled'); ?></th>
              <td>
                <?php if (empty($all_stores)): ?>
                  <div class="wc-api-mps-notice error">
                    <span class="wc-api-mps-icon error">⚠</span>
                    <div><?php _e('No stores configured in the main plugin. Please add stores first.', 'wc-api-mps-scheduled'); ?></div>
                  </div>
                <?php else: ?>
                  <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" class="wc-api-mps-select-all-stores" />
                    <strong><?php _e('Select/Deselect All', 'wc-api-mps-scheduled'); ?></strong>
                  </label>

                  <div class="wc-api-mps-stores-container">
                    <?php foreach ($all_stores as $store_url => $store_data): ?>
                      <?php
                      $is_active = $store_data['status'];
                      $is_selected = in_array($store_url, $selected_stores);
                      $excluded_cats = isset($store_data['exclude_categories_products']) ? $store_data['exclude_categories_products'] : array();
                      $excluded_tags = isset($store_data['exclude_tags_products']) ? $store_data['exclude_tags_products'] : array();
                      ?>
                      <div class="wc-api-mps-store-item <?php echo $is_active ? 'active' : 'inactive'; ?>">
                        <label class="wc-api-mps-store-label">
                          <input type="checkbox"
                            name="selected_stores[]"
                            value="<?php echo esc_attr($store_url); ?>"
                            <?php checked($is_selected); ?>
                            <?php disabled(!$is_active); ?> />
                          <strong><?php echo esc_html($store_url); ?></strong>
                          <?php if (!$is_active): ?>
                            <span class="wc-api-mps-badge inactive"><?php _e('Inactive', 'wc-api-mps-scheduled'); ?></span>
                          <?php endif; ?>
                        </label>

                        <?php if (!empty($excluded_cats) || !empty($excluded_tags)): ?>
                          <div class="wc-api-mps-store-exclusions">
                            <?php if (!empty($excluded_cats)): ?>
                              <?php
                              $cat_names = array();
                              foreach ($excluded_cats as $cat_id) {
                                $term = get_term($cat_id, 'product_cat');
                                if ($term && !is_wp_error($term)) {
                                  $cat_names[] = $term->name;
                                }
                              }
                              ?>
                              <div>🚫 Categories: <?php echo esc_html(implode(', ', $cat_names)); ?></div>
                            <?php endif; ?>

                            <?php if (!empty($excluded_tags)): ?>
                              <?php
                              $tag_names = array();
                              foreach ($excluded_tags as $tag_id) {
                                $term = get_term($tag_id, 'product_tag');
                                if ($term && !is_wp_error($term)) {
                                  $tag_names[] = $term->name;
                                }
                              }
                              ?>
                              <div>🚫 Tags: <?php echo esc_html(implode(', ', $tag_names)); ?></div>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <p class="description wc-api-mps-mt-15">
                    ⚠️ <?php _e('Only checked stores will receive scheduled syncs. Category/tag exclusions are automatically respected.', 'wc-api-mps-scheduled'); ?>
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
    </div>

    <!-- Logs Section -->
    <div class="wc-api-mps-section" id="logs-section">
      <div class="wc-api-mps-section-header collapsed">
        <h2>
          <span class="dashicons dashicons-media-text"></span>
          <?php _e('Recent Logs', 'wc-api-mps-scheduled'); ?>
          <span class="wc-api-mps-badge" style="margin-left: 10px;"><?php echo count($logs); ?></span>
        </h2>
        <span class="wc-api-mps-section-toggle">▼</span>
      </div>
      <div class="wc-api-mps-section-content" style="display: none;">
        <?php if (empty($logs)): ?>
          <p class="wc-api-mps-text-muted wc-api-mps-text-center"><?php _e('No logs yet.', 'wc-api-mps-scheduled'); ?></p>
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
                  <td class="wc-api-mps-log-time"><?php echo esc_html($log['time']); ?></td>
                  <td class="wc-api-mps-log-message"><?php echo esc_html($log['message']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php
    // Show debug info if ?debug=1 is in URL
    if (isset($_GET['debug'])) {
    ?>
      <div class="wc-api-mps-section wc-api-mps-debug" id="debug-section">
        <div class="wc-api-mps-section-header">
          <h2>
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Debug Information', 'wc-api-mps-scheduled'); ?>
          </h2>
          <span class="wc-api-mps-section-toggle">▼</span>
        </div>
        <div class="wc-api-mps-section-content">
          <?php wc_api_mps_scheduled_debug_info(); ?>
        </div>
      </div>
    <?php
    }
    ?>

  </div>
<?php
}
