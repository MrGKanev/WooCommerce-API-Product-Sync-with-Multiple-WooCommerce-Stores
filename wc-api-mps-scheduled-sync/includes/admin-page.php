<?php

/**
 * Admin page and settings
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Handle AJAX re-sync request
 */
function wc_api_mps_scheduled_ajax_resync()
{
  check_ajax_referer('wc_api_mps_resync', 'nonce');

  if (!current_user_can('manage_options')) {
    wp_send_json_error('Insufficient permissions');
  }

  $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

  if (!$product_id) {
    wp_send_json_error('Invalid product ID');
  }

  $result = wc_api_mps_scheduled_resync_product($product_id);

  if ($result['success']) {
    wp_send_json_success($result);
  } else {
    wp_send_json_error($result['message']);
  }
}
add_action('wp_ajax_wc_api_mps_scheduled_resync', 'wc_api_mps_scheduled_ajax_resync');

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

  register_setting('wc_api_mps_scheduled_sync', 'wc_api_mps_force_full_sync', array(
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

  if (isset($_POST['check_orders']) && check_admin_referer('wc_api_mps_check_orders')) {
    $order_sync_data = wc_api_mps_scheduled_get_order_sync_status(true);
  } else {
    $order_sync_data = wc_api_mps_scheduled_get_order_sync_status(false);
  }

  if (isset($_POST['save_settings']) && check_admin_referer('wc_api_mps_save_settings')) {
    update_option('wc_api_mps_cron_batch_size', (int) $_POST['batch_size']);
    update_option('wc_api_mps_cron_batch_size_offpeak', (int) $_POST['batch_size_offpeak']);
    update_option('wc_api_mps_force_full_sync', isset($_POST['force_full_sync']) ? (int) $_POST['force_full_sync'] : 0);

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
  $force_full_sync = get_option('wc_api_mps_force_full_sync', 0);
  $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());

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
              <span style="color: blue; font-weight: bold;">⚡ FORCED FULL SYNC</span>
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

    <?php if ($force_full_sync): ?>
      <div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 10px; margin-bottom: 15px;">
        <strong>⚠️ Override Mode Active</strong><br>
        Full product sync is running 24/7 regardless of time. Batch size: <?php echo $batch_size_offpeak; ?> products per run.
      </div>
    <?php endif; ?>

    <!-- Sync Schedule -->
    <div class="card">
      <h2><?php _e('Sync Schedule', 'wc-api-mps-scheduled'); ?></h2>
      <table class="widefat">
        <thead>
          <tr>
            <th><?php _e('Time Period', 'wc-api-mps-scheduled'); ?></th>
            <th><?php _e('Sync Type', 'wc-api-mps-scheduled'); ?></th>
            <th><?php _e('Products per Run', 'wc-api-mps-scheduled'); ?></th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>🌙 12:00 AM - 6:30 AM</strong></td>
            <td><code>full_product</code></td>
            <td><?php echo $batch_size_offpeak; ?></td>
          </tr>
          <tr>
            <td><strong>☀️ 6:30 AM - 12:00 AM</strong></td>
            <td><code>price_and_quantity</code></td>
            <td><?php echo $batch_size; ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Settings -->
    <div class="card">
      <h2><?php _e('Settings', 'wc-api-mps-scheduled'); ?></h2>
      <form method="post">
        <?php wp_nonce_field('wc_api_mps_save_settings'); ?>
        <table class="form-table">
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
            <th><?php _e('Force Full Sync 24/7:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <input type="hidden" name="force_full_sync" value="0" />
              <input type="checkbox" name="force_full_sync" value="1" <?php checked($force_full_sync, 1); ?> />
              <span style="color: #d63638; font-weight: bold;">⚠️ Override Mode</span>
              <p class="description">
                <?php _e('Force full product sync 24/7 regardless of time (uses off-peak batch size). Use temporarily for bulk updates.', 'wc-api-mps-scheduled'); ?>
              </p>
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

    <!-- Order Sync Status Check -->
    <div class="card">
      <h2><?php _e('Order Products Sync Status', 'wc-api-mps-scheduled'); ?></h2>
      <p class="description">
        <?php _e('Check if products from recent orders are synced to selected stores (price, quantity, stock status).', 'wc-api-mps-scheduled'); ?>
      </p>

      <form method="post" style="margin: 15px 0;">
        <?php wp_nonce_field('wc_api_mps_check_orders'); ?>
        <input type="submit" name="check_orders" class="button button-secondary" value="<?php _e('Check Last 15 Orders', 'wc-api-mps-scheduled'); ?>">
        <?php if (!empty($order_sync_data)): ?>
          <span style="margin-left: 15px; color: #666;">
            <?php printf(
              __('Last checked: %s (%d orders, %d products)', 'wc-api-mps-scheduled'),
              $order_sync_data['checked_at'],
              $order_sync_data['order_count'],
              $order_sync_data['product_count']
            ); ?>
          </span>
        <?php endif; ?>
      </form>

      <?php if (!empty($order_sync_data['results'])): ?>
        <?php
        $selected_stores = get_option('wc_api_mps_cron_selected_stores', array());
        if (empty($selected_stores)):
        ?>
          <div class="notice notice-warning inline">
            <p><?php _e('⚠️ No stores selected in settings. Please select stores first.', 'wc-api-mps-scheduled'); ?></p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="widefat striped">
              <thead>
                <tr>
                  <th style="width: 80px;"><?php _e('ID', 'wc-api-mps-scheduled'); ?></th>
                  <th style="width: 100px;"><?php _e('SKU', 'wc-api-mps-scheduled'); ?></th>
                  <th><?php _e('Product', 'wc-api-mps-scheduled'); ?></th>
                  <th style="width: 100px;"><?php _e('Stock', 'wc-api-mps-scheduled'); ?></th>
                  <?php foreach ($selected_stores as $store_url): ?>
                    <th style="width: 120px; text-align: center;">
                      <?php
                      $domain = parse_url($store_url, PHP_URL_HOST);
                      echo esc_html($domain ?: $store_url);
                      ?>
                    </th>
                  <?php endforeach; ?>
                  <th style="width: 100px;"><?php _e('Action', 'wc-api-mps-scheduled'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($order_sync_data['results'] as $product_id => $data): ?>
                  <tr id="product-row-<?php echo $product_id; ?>">
                    <td><?php echo $product_id; ?></td>
                    <td><code><?php echo esc_html($data['product']['sku'] ?: '-'); ?></code></td>
                    <td>
                      <a href="<?php echo get_edit_post_link($product_id); ?>" target="_blank">
                        <?php echo esc_html($data['product']['name']); ?>
                      </a>
                      <br>
                      <small style="color: #666;">
                        <?php echo ucfirst($data['product']['type']); ?> |
                        <?php printf(__('Last sync: %s', 'wc-api-mps-scheduled'), $data['last_sync']); ?>
                      </small>
                    </td>
                    <td>
                      <?php
                      $stock_status = $data['product']['stock_status'];
                      $stock_qty = $data['product']['stock_quantity'];
                      $status_color = $stock_status === 'instock' ? 'green' : 'orange';
                      ?>
                      <span style="color: <?php echo $status_color; ?>;">
                        <?php echo ucfirst(str_replace('_', ' ', $stock_status)); ?>
                      </span>
                      <?php if ($stock_qty !== null): ?>
                        <br><small>(<?php echo $stock_qty; ?>)</small>
                      <?php endif; ?>
                    </td>
                    <?php
                    $all_synced = true;
                    foreach ($selected_stores as $store_url):
                      $store_data = $data['stores'][$store_url];
                      $is_synced = $store_data['synced'];
                      if (!$is_synced) $all_synced = false;
                    ?>
                      <td style="text-align: center;">
                        <?php if ($is_synced): ?>
                          <span style="color: green; font-size: 18px;" title="Synced">✓</span>
                          <br>
                          <small style="color: #666;">ID: <?php echo $store_data['destination_id']; ?></small>
                        <?php else: ?>
                          <span style="color: red; font-size: 18px;" title="Not synced">✗</span>
                          <br>
                          <small style="color: red;">Not synced</small>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                    <td>
                      <button type="button"
                        class="button button-small wc-api-mps-resync-btn"
                        data-product-id="<?php echo $product_id; ?>"
                        data-product-name="<?php echo esc_attr($data['product']['name']); ?>"
                        style="<?php echo $all_synced ? '' : 'background: #d63638; border-color: #d63638; color: white;'; ?>">
                        <?php echo $all_synced ? '🔄 Re-sync' : '⚠️ Sync Now'; ?>
                      </button>
                      <div class="wc-api-mps-sync-status" style="margin-top: 5px; font-size: 11px;"></div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <script>
            jQuery(document).ready(function($) {
              $('.wc-api-mps-resync-btn').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var productName = btn.data('product-name');
                var statusDiv = btn.siblings('.wc-api-mps-sync-status');

                if (!confirm('Re-sync "' + productName + '" (price & quantity)?')) {
                  return;
                }

                btn.prop('disabled', true).text('Syncing...');
                statusDiv.html('<span style="color: orange;">⏳ Processing...</span>');

                $.ajax({
                  url: ajaxurl,
                  type: 'POST',
                  data: {
                    action: 'wc_api_mps_scheduled_resync',
                    product_id: productId,
                    nonce: '<?php echo wp_create_nonce('wc_api_mps_resync'); ?>'
                  },
                  success: function(response) {
                    if (response.success) {
                      statusDiv.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                      btn.text('✓ Synced').css({
                        'background': '#46b450',
                        'border-color': '#46b450',
                        'color': 'white'
                      });

                      // Reload the row after 1 second
                      setTimeout(function() {
                        location.reload();
                      }, 1000);
                    } else {
                      statusDiv.html('<span style="color: red;">✗ ' + response.data + '</span>');
                      btn.prop('disabled', false).text('🔄 Re-sync');
                    }
                  },
                  error: function() {
                    statusDiv.html('<span style="color: red;">✗ Error occurred</span>');
                    btn.prop('disabled', false).text('🔄 Re-sync');
                  }
                });
              });
            });
          </script>
        <?php endif; ?>
      <?php else: ?>
        <p style="color: #666; font-style: italic;">
          <?php _e('Click "Check Last 15 Orders" to see sync status.', 'wc-api-mps-scheduled'); ?>
        </p>
      <?php endif; ?>
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
