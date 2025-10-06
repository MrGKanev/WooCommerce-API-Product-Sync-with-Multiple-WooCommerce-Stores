<?php

/**
 * Admin page and settings
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

  register_setting('wc_api_mps_scheduled_sync', 'wc_api_mps_cron_force_full_sync', array(
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

  if (!function_exists('wc_api_mps_integration')) {
?>
    <div class="wrap">
      <h1><?php _e('Scheduled Sync Status', 'wc-api-mps-scheduled'); ?></h1>
      <div class="notice notice-error">
        <p><strong><?php _e('Error:', 'wc-api-mps-scheduled'); ?></strong>
          <?php _e('WooCommerce API Product Sync plugin is not active.', 'wc-api-mps-scheduled'); ?></p>
      </div>
    </div>
  <?php
    return;
  }

  // Handle actions
  wc_api_mps_scheduled_handle_reschedule();

  if (isset($_POST['run_sync_now']) && check_admin_referer('wc_api_mps_manual_sync')) {
    wc_api_mps_scheduled_trigger_sync();
    echo '<div class="notice notice-success"><p>' . __('Manual sync completed.', 'wc-api-mps-scheduled') . '</p></div>';
  }

  if (isset($_POST['clear_logs']) && check_admin_referer('wc_api_mps_clear_logs')) {
    wc_api_mps_scheduled_clear_logs();
    echo '<div class="notice notice-success"><p>' . __('Logs cleared.', 'wc-api-mps-scheduled') . '</p></div>';
  }

  if (isset($_POST['save_settings']) && check_admin_referer('wc_api_mps_save_settings')) {
    update_option('wc_api_mps_cron_batch_size', (int) $_POST['batch_size']);
    update_option('wc_api_mps_cron_batch_size_offpeak', (int) $_POST['batch_size_offpeak']);
    update_option('wc_api_mps_cron_force_full_sync', isset($_POST['force_full_sync']) ? 1 : 0);

    // Save selected stores
    $selected_stores = isset($_POST['selected_stores']) ? $_POST['selected_stores'] : array();
    update_option('wc_api_mps_cron_selected_stores', $selected_stores);

    // Clear cache when stores change
    delete_transient('wc_api_mps_pending_count_full_product');
    delete_transient('wc_api_mps_pending_count_price_and_quantity');

    echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wc-api-mps-scheduled') . '</p></div>';
  }

  // Get data
  $all_stores = get_option('wc_api_mps_stores', array());
  $cron_status = wc_api_mps_scheduled_get_cron_status();
  $batch_size = get_option('wc_api_mps_cron_batch_size', 5);
  $batch_size_offpeak = get_option('wc_api_mps_cron_batch_size_offpeak', 20);
  $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());
  $force_full_sync = get_option('wc_api_mps_cron_force_full_sync', 0);

  // If no stores selected yet, default to all active stores
  if (empty($selected_stores) && !empty($all_stores)) {
    foreach ($all_stores as $store_url => $store_data) {
      if ($store_data['status']) {
        $selected_stores[] = $store_url;
      }
    }
  }

  $logs = wc_api_mps_scheduled_get_logs(50);
  $is_off_peak = wc_api_mps_scheduled_is_off_peak();
  $sync_type = wc_api_mps_scheduled_get_sync_type();

  try {
    $products_count = wc_api_mps_scheduled_count_products($sync_type);
  } catch (Exception $e) {
    $products_count = 0;
  }

  ?>
  <div class="wrap">
    <h1><?php _e('Scheduled Sync Status', 'wc-api-mps-scheduled'); ?></h1>

    <!-- Current Status -->
    <div class="card">
      <h2><?php _e('Current Status', 'wc-api-mps-scheduled'); ?></h2>
      <table class="form-table">
        <tr>
          <th><?php _e('Cron Status:', 'wc-api-mps-scheduled'); ?></th>
          <td><?php echo $cron_status['is_active'] ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Inactive</span>'; ?></td>
        </tr>
        <tr>
          <th><?php _e('Next Run:', 'wc-api-mps-scheduled'); ?></th>
          <td><?php echo $cron_status['next_run'] ?? 'N/A'; ?></td>
        </tr>
        <tr>
          <th><?php _e('Last Run:', 'wc-api-mps-scheduled'); ?></th>
          <td><?php echo $cron_status['last_run'] ?? 'Never'; ?></td>
        </tr>
        <tr>
          <th><?php _e('Current Mode:', 'wc-api-mps-scheduled'); ?></th>
          <td>
            <?php if ($force_full_sync): ?>
              <span style="color: blue; font-weight: bold;">🔧 FORCED FULL SYNC</span>
              <p class="description">Override active - Always running full product sync regardless of time</p>
            <?php elseif ($is_off_peak): ?>
              <span style="color: green; font-weight: bold;">🌙 Off-Peak</span>
              <p class="description">Full product sync - More products per batch</p>
            <?php else: ?>
              <span style="color: orange; font-weight: bold;">☀️ Peak Hours</span>
              <p class="description">Light sync (price & quantity) - Fewer products per batch</p>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th><?php _e('Products Pending:', 'wc-api-mps-scheduled'); ?></th>
          <td><strong><?php echo $products_count; ?></strong> products</td>
        </tr>
      </table>
    </div>

    <!-- Sync Schedule -->
    <div class="card">
      <h2><?php _e('Sync Schedule', 'wc-api-mps-scheduled'); ?></h2>
      <?php if ($force_full_sync): ?>
        <div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 10px; margin-bottom: 15px;">
          <strong>⚠️ Override Mode Active</strong><br>
          Full product sync is running 24/7 regardless of time. Batch size: <?php echo $batch_size_offpeak; ?> products per run.
        </div>
      <?php endif; ?>
      <table class="widefat">
        <thead>
          <tr>
            <th><?php _e('Time Period', 'wc-api-mps-scheduled'); ?></th>
            <th><?php _e('Sync Type', 'wc-api-mps-scheduled'); ?></th>
            <th><?php _e('Products per Run', 'wc-api-mps-scheduled'); ?></th>
          </tr>
        </thead>
        <tbody>
          <tr style="<?php echo $force_full_sync ? 'opacity: 0.5;' : ''; ?>">
            <td><strong>🌙 12:00 AM - 6:30 AM</strong></td>
            <td><code>full_product</code></td>
            <td><?php echo $batch_size_offpeak; ?></td>
          </tr>
          <tr style="<?php echo $force_full_sync ? 'opacity: 0.5;' : ''; ?>">
            <td><strong>☀️ 6:30 AM - 12:00 AM</strong></td>
            <td><code>price_and_quantity</code></td>
            <td><?php echo $batch_size; ?></td>
          </tr>
        </tbody>
      </table>
      <?php if ($force_full_sync): ?>
        <p class="description" style="margin-top: 10px;">
          <em>Time-based schedule is disabled while override mode is active.</em>
        </p>
      <?php endif; ?>
    </div>

    <!-- Settings -->
    <div class="card">
      <h2><?php _e('Settings', 'wc-api-mps-scheduled'); ?></h2>
      <form method="post">
        <?php wp_nonce_field('wc_api_mps_save_settings'); ?>
        <table class="form-table">
          <tr>
            <th><?php _e('Force Full Sync Mode:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="force_full_sync" value="1" <?php checked($force_full_sync, 1); ?>>
                <?php _e('Always run full product sync (ignore time-based schedule)', 'wc-api-mps-scheduled'); ?>
              </label>
              <p class="description">
                <?php _e('⚠️ Enable this to run full product sync 24/7. Useful for initial bulk sync or when you need to sync everything quickly. Uses off-peak batch size.', 'wc-api-mps-scheduled'); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th><?php _e('Peak Hours Batch Size:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <input type="number" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50">
              <p class="description"><?php _e('Recommended: 5-10', 'wc-api-mps-scheduled'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php _e('Off-Peak Batch Size:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <input type="number" name="batch_size_offpeak" value="<?php echo esc_attr($batch_size_offpeak); ?>" min="1" max="100">
              <p class="description"><?php _e('Recommended: 20-50', 'wc-api-mps-scheduled'); ?></p>
            </td>
          </tr>
          <tr>
            <th><?php _e('Sync to These Stores:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <?php if (empty($all_stores)): ?>
                <p class="description" style="color: #d63638;">
                  <?php _e('⚠️ No stores configured in the main plugin. Please add stores first.', 'wc-api-mps-scheduled'); ?>
                </p>
              <?php else: ?>
                <label style="display: block; margin-bottom: 10px;">
                  <input type="checkbox" class="wc-api-mps-select-all-stores" />
                  <strong><?php _e('Select/Deselect All', 'wc-api-mps-scheduled'); ?></strong>
                </label>
                <div style="border: 1px solid #ddd; padding: 10px; background: #f9f9f9; max-height: 300px; overflow-y: auto;">
                  <?php foreach ($all_stores as $store_url => $store_data): ?>
                    <?php
                    $is_active = $store_data['status'];
                    $is_selected = in_array($store_url, $selected_stores);
                    $excluded_cats = isset($store_data['exclude_categories_products']) ? $store_data['exclude_categories_products'] : array();
                    $excluded_tags = isset($store_data['exclude_tags_products']) ? $store_data['exclude_tags_products'] : array();
                    ?>
                    <div style="margin-bottom: 10px; padding: 8px; background: white; border-left: 3px solid <?php echo $is_active ? '#46b450' : '#ddd'; ?>;">
                      <label style="display: block;">
                        <input type="checkbox"
                          name="selected_stores[]"
                          value="<?php echo esc_attr($store_url); ?>"
                          <?php checked($is_selected); ?>
                          <?php disabled(!$is_active); ?> />
                        <strong><?php echo esc_html($store_url); ?></strong>
                        <?php if (!$is_active): ?>
                          <span style="color: #d63638;">(Inactive)</span>
                        <?php endif; ?>
                      </label>
                      <?php if (!empty($excluded_cats) || !empty($excluded_tags)): ?>
                        <div style="margin-left: 25px; font-size: 12px; color: #666;">
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
                            <span>🚫 Categories: <?php echo esc_html(implode(', ', $cat_names)); ?></span><br>
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
                            <span>🚫 Tags: <?php echo esc_html(implode(', ', $tag_names)); ?></span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p class="description" style="margin-top: 10px;">
                  <?php _e('⚠️ Only checked stores will receive scheduled syncs. Category/tag exclusions are automatically respected.', 'wc-api-mps-scheduled'); ?>
                </p>
              <?php endif; ?>
            </td>
          </tr>
        </table>
        <p class="submit">
          <input type="submit" name="save_settings" class="button button-primary" value="<?php _e('Save Settings', 'wc-api-mps-scheduled'); ?>">
        </p>
      </form>
    </div>

    <script>
      jQuery(document).ready(function($) {
        $('.wc-api-mps-select-all-stores').on('change', function() {
          var checked = $(this).prop('checked');
          $('input[name="selected_stores[]"]:not(:disabled)').prop('checked', checked);
        });
      });
    </script>

    <!-- Actions -->
    <div class="card">
      <h2><?php _e('Manual Actions', 'wc-api-mps-scheduled'); ?></h2>
      <form method="post" style="display: inline-block;">
        <?php wp_nonce_field('wc_api_mps_manual_sync'); ?>
        <input type="submit" name="run_sync_now" class="button button-secondary" value="<?php _e('Run Sync Now', 'wc-api-mps-scheduled'); ?>">
      </form>
      <form method="post" style="display: inline-block; margin-left: 10px;">
        <?php wp_nonce_field('wc_api_mps_clear_logs'); ?>
        <input type="submit" name="clear_logs" class="button button-secondary" value="<?php _e('Clear Logs', 'wc-api-mps-scheduled'); ?>">
      </form>
    </div>

    <!-- Logs -->
    <div class="card">
      <h2><?php _e('Recent Logs', 'wc-api-mps-scheduled'); ?></h2>
      <?php if (empty($logs)): ?>
        <p><?php _e('No logs yet.', 'wc-api-mps-scheduled'); ?></p>
      <?php else: ?>
        <table class="widefat striped">
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

    <?php
    // Show debug info if ?debug=1 is in URL
    wc_api_mps_scheduled_debug_info();
    ?>
  </div>
<?php
}
