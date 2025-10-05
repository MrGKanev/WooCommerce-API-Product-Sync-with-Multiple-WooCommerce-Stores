<?php

/**
 * Plugin Name: WooCommerce Product Sync - Scheduled Sync
 * Description: Time-based scheduled sync - Light sync (price/qty) during peak hours, full sync during off-peak (12AM-6:30AM)
 * Version: 1.0.0
 * Author: Your Name
 * Requires: WooCommerce API Product Sync plugin
 */

if (!defined('ABSPATH')) {
  exit('restricted access');
}

class WC_API_MPS_Scheduled_Sync
{

  private $cron_hook = 'wc_api_mps_scheduled_sync_check';
  private $option_last_check = 'wc_api_mps_last_sync_check';
  private $option_sync_log = 'wc_api_mps_sync_log';

  public function __construct()
  {
    // Register activation/deactivation hooks
    register_activation_hook(__FILE__, array($this, 'activate'));
    register_deactivation_hook(__FILE__, array($this, 'deactivate'));

    // Add cron action
    add_action($this->cron_hook, array($this, 'run_scheduled_sync'));

    // Add admin menu - priority 100 to ensure it loads after main plugin (which uses 20)
    add_action('admin_menu', array($this, 'add_admin_menu'), 100);

    // Check if main plugin is active
    add_action('admin_init', array($this, 'check_main_plugin'));

    // Add settings
    add_action('admin_init', array($this, 'register_settings'));
  }

  /**
   * Activate plugin - schedule cron
   */
  public function activate()
  {
    if (!wp_next_scheduled($this->cron_hook)) {
      // Schedule to run every 15 minutes
      wp_schedule_event(time(), 'every_15_minutes', $this->cron_hook);
    }

    // Add custom cron interval
    add_filter('cron_schedules', array($this, 'add_cron_interval'));
  }

  /**
   * Deactivate plugin - clear cron
   */
  public function deactivate()
  {
    $timestamp = wp_next_scheduled($this->cron_hook);
    if ($timestamp) {
      wp_unschedule_event($timestamp, $this->cron_hook);
    }
  }

  /**
   * Add custom 15-minute cron interval
   */
  public function add_cron_interval($schedules)
  {
    $schedules['every_15_minutes'] = array(
      'interval' => 900, // 15 minutes in seconds
      'display'  => __('Every 15 Minutes', 'wc-api-mps-scheduled')
    );
    return $schedules;
  }

  /**
   * Main scheduled sync function
   */
  public function run_scheduled_sync()
  {
    // Check if the main plugin is active
    if (!function_exists('wc_api_mps_integration')) {
      $this->log('WooCommerce API Product Sync plugin not found. Skipping.');
      return;
    }

    // Get stores
    $stores = get_option('wc_api_mps_stores');
    if (!$stores || !is_array($stores)) {
      $this->log('No stores configured. Skipping.');
      return;
    }

    // Determine sync type based on time of day
    $sync_type = $this->get_sync_type_for_time();
    $is_off_peak = $this->is_off_peak_hours();

    // Get products that need syncing
    $products_to_sync = $this->get_products_needing_sync($sync_type);

    if (empty($products_to_sync)) {
      $this->log(sprintf('No products need syncing (%s).', $sync_type));
      return;
    }

    $this->log(sprintf(
      'Starting %s sync for %d products (Off-peak: %s).',
      $sync_type,
      count($products_to_sync),
      $is_off_peak ? 'Yes' : 'No'
    ));

    $success_count = 0;
    $error_count = 0;

    // Get batch size based on time - larger batches during off-peak
    $batch_size = $is_off_peak
      ? get_option('wc_api_mps_cron_batch_size_offpeak', 20)
      : get_option('wc_api_mps_cron_batch_size', 5);

    $products_to_process = array_slice($products_to_sync, 0, $batch_size);

    foreach ($products_to_process as $product_id) {
      try {
        // Run the sync with specific sync type
        wc_api_mps_integration($product_id, $stores, $sync_type);

        // Mark as synced
        update_post_meta($product_id, '_wc_api_mps_last_sync', time());
        update_post_meta($product_id, '_wc_api_mps_last_sync_type', $sync_type);

        // Clear sync flags based on what was synced
        if ($sync_type === 'full_product') {
          update_post_meta($product_id, '_wc_api_mps_needs_sync', 0);
          update_post_meta($product_id, '_wc_api_mps_needs_full_sync', 0);
          update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);
        } else {
          // Only clear light sync flag
          update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 0);
        }

        $success_count++;
        $this->log(sprintf('Successfully synced product ID: %d (%s)', $product_id, $sync_type));
      } catch (Exception $e) {
        $error_count++;
        $this->log(sprintf('Error syncing product ID %d: %s', $product_id, $e->getMessage()));
      }

      // Prevent timeouts - smaller delay during off-peak since we have more time
      usleep($is_off_peak ? 50000 : 200000); // 0.05s off-peak, 0.2s peak
    }

    update_option($this->option_last_check, time());
    $this->log(sprintf('Sync completed. Success: %d, Errors: %d', $success_count, $error_count));
  }

  /**
   * Check if current time is off-peak hours (12:00 AM - 6:30 AM)
   */
  private function is_off_peak_hours()
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
  private function get_sync_type_for_time()
  {
    if ($this->is_off_peak_hours()) {
      // Off-peak hours: Full sync
      return 'full_product';
    } else {
      // Peak hours: Light sync (just price and quantity)
      return 'price_and_quantity';
    }
  }

  /**
   * Get products that need syncing based on sync type
   * Optimized with limits to prevent timeouts
   */
  private function get_products_needing_sync($sync_type = 'full_product', $limit = 25)
  {
    $products = array();

    if ($sync_type === 'full_product') {
      // Off-peak: Get products that need full sync
      $args = array(
        'post_type'      => 'product',
        'posts_per_page' => $limit,  // LIMIT added
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'meta_query'     => array(
          'relation' => 'OR',
          array(
            'key'     => '_wc_api_mps_needs_full_sync',
            'value'   => '1',
            'compare' => '='
          ),
          array(
            'key'     => '_wc_api_mps_needs_sync',
            'value'   => '1',
            'compare' => '='
          ),
          array(
            'key'     => '_wc_api_mps_last_sync',
            'compare' => 'NOT EXISTS'
          )
        )
      );
    } else {
      // Peak hours: Get products with recent stock/price changes
      $args = array(
        'post_type'      => 'product',
        'posts_per_page' => $limit,  // LIMIT added
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'meta_query'     => array(
          'relation' => 'OR',
          array(
            'key'     => '_wc_api_mps_needs_light_sync',
            'value'   => '1',
            'compare' => '='
          )
        )
      );
    }

    $query = new WP_Query($args);

    if ($query->have_posts()) {
      $products = $query->posts;
    }

    wp_reset_postdata();

    return array_unique($products);
  }

  /**
   * Get count of products needing sync (faster query with caching)
   */
  private function get_products_needing_sync_count($sync_type = 'full_product')
  {
    // Check cache first (60 second cache)
    $cache_key = 'wc_api_mps_pending_count_' . $sync_type;
    $cached_count = get_transient($cache_key);

    if ($cached_count !== false) {
      return (int) $cached_count;
    }

    global $wpdb;

    if ($sync_type === 'full_product') {
      $count = $wpdb->get_var("
                SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wc_api_mps_needs_full_sync'
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wc_api_mps_needs_sync'
                LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wc_api_mps_last_sync'
                WHERE p.post_type = 'product' 
                AND p.post_status = 'publish'
                AND (pm1.meta_value = '1' OR pm2.meta_value = '1' OR pm3.meta_id IS NULL)
            ");
    } else {
      $count = $wpdb->get_var("
                SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_type = 'product' 
                AND p.post_status = 'publish'
                AND pm.meta_key = '_wc_api_mps_needs_light_sync'
                AND pm.meta_value = '1'
            ");
    }

    $count = (int) $count;

    // Cache for 60 seconds
    set_transient($cache_key, $count, 60);

    return $count;
  }

  /**
   * Log messages
   */
  private function log($message)
  {
    $log = get_option($this->option_sync_log, array());

    $log[] = array(
      'time'    => current_time('mysql'),
      'message' => $message
    );

    // Keep only last 100 entries
    if (count($log) > 100) {
      $log = array_slice($log, -100);
    }

    update_option($this->option_sync_log, $log);

    // Also log to debug.log if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log('WC_API_MPS_Scheduled_Sync: ' . $message);
    }
  }

  /**
   * Add admin menu
   */
  public function add_admin_menu()
  {
    // Check if the parent menu exists
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

    // Add as submenu if parent exists, otherwise create standalone menu
    if ($parent_exists) {
      add_submenu_page(
        'wc_api_mps',
        __('Scheduled Sync', 'wc-api-mps-scheduled'),
        __('Scheduled Sync', 'wc-api-mps-scheduled'),
        'manage_options',
        'wc-api-mps-scheduled-sync',
        array($this, 'admin_page')
      );
    } else {
      // Fallback: create standalone menu
      add_menu_page(
        __('Scheduled Sync', 'wc-api-mps-scheduled'),
        __('Scheduled Sync', 'wc-api-mps-scheduled'),
        'manage_options',
        'wc-api-mps-scheduled-sync',
        array($this, 'admin_page'),
        'dashicons-update',
        56
      );
    }
  }

  /**
   * Add admin notice if main plugin not active
   */
  public function check_main_plugin()
  {
    if (!function_exists('wc_api_mps_integration')) {
      add_action('admin_notices', array($this, 'main_plugin_notice'));
    }
  }

  /**
   * Show notice if main plugin not active
   */
  public function main_plugin_notice()
  {
?>
    <div class="notice notice-error">
      <p>
        <strong><?php _e('WooCommerce Product Sync - Scheduled Sync', 'wc-api-mps-scheduled'); ?></strong>
        <?php _e('requires the WooCommerce API Product Sync plugin to be installed and activated.', 'wc-api-mps-scheduled'); ?>
      </p>
    </div>
    <?php
  }

  /**
   * Register settings
   */
  public function register_settings()
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
  }

  /**
   * Admin page
   */
  public function admin_page()
  {
    // Increase PHP timeout for this page
    @set_time_limit(60);

    // Check user capabilities
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Check if main plugin is active
    if (!function_exists('wc_api_mps_integration')) {
    ?>
      <div class="wrap">
        <h1><?php _e('Scheduled Sync Status', 'wc-api-mps-scheduled'); ?></h1>
        <div class="notice notice-error">
          <p>
            <strong><?php _e('Error:', 'wc-api-mps-scheduled'); ?></strong>
            <?php _e('WooCommerce API Product Sync plugin is not active. Please install and activate it first.', 'wc-api-mps-scheduled'); ?>
          </p>
        </div>
      </div>
    <?php
      return;
    }

    // Handle manual sync trigger
    if (isset($_POST['run_sync_now']) && check_admin_referer('wc_api_mps_manual_sync')) {
      $this->run_scheduled_sync();
      echo '<div class="notice notice-success"><p>' . __('Manual sync completed. Check logs below.', 'wc-api-mps-scheduled') . '</p></div>';
    }

    // Handle clear logs
    if (isset($_POST['clear_logs']) && check_admin_referer('wc_api_mps_clear_logs')) {
      update_option($this->option_sync_log, array());
      echo '<div class="notice notice-success"><p>' . __('Logs cleared.', 'wc-api-mps-scheduled') . '</p></div>';
    }

    // Save settings
    if (isset($_POST['save_settings']) && check_admin_referer('wc_api_mps_save_settings')) {
      update_option('wc_api_mps_cron_batch_size', (int) $_POST['batch_size']);
      update_option('wc_api_mps_cron_batch_size_offpeak', (int) $_POST['batch_size_offpeak']);
      echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wc-api-mps-scheduled') . '</p></div>';
    }

    $last_check = get_option($this->option_last_check);
    $next_scheduled = wp_next_scheduled($this->cron_hook);
    $batch_size = get_option('wc_api_mps_cron_batch_size', 5);
    $batch_size_offpeak = get_option('wc_api_mps_cron_batch_size_offpeak', 20);

    // Get logs with limit to prevent memory issues
    $all_logs = get_option($this->option_sync_log, array());
    $logs = is_array($all_logs) ? array_slice($all_logs, -50) : array(); // Last 50 only for display

    $is_off_peak = $this->is_off_peak_hours();
    $sync_type = $this->get_sync_type_for_time();

    // Use COUNT query instead of loading all products - much faster!
    try {
      $products_pending_count = $this->get_products_needing_sync_count($sync_type);
    } catch (Exception $e) {
      $products_pending_count = 0;
      echo '<div class="notice notice-warning"><p>Unable to count pending products: ' . esc_html($e->getMessage()) . '</p></div>';
    }

    ?>
    <div class="wrap">
      <h1><?php _e('Scheduled Sync Status', 'wc-api-mps-scheduled'); ?></h1>

      <div class="card">
        <h2><?php _e('Current Status', 'wc-api-mps-scheduled'); ?></h2>
        <table class="form-table">
          <tr>
            <th><?php _e('Cron Status:', 'wc-api-mps-scheduled'); ?></th>
            <td><?php echo $next_scheduled ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Inactive</span>'; ?></td>
          </tr>
          <tr>
            <th><?php _e('Next Run:', 'wc-api-mps-scheduled'); ?></th>
            <td><?php echo $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'N/A'; ?></td>
          </tr>
          <tr>
            <th><?php _e('Last Check:', 'wc-api-mps-scheduled'); ?></th>
            <td><?php echo $last_check ? date('Y-m-d H:i:s', $last_check) : 'Never'; ?></td>
          </tr>
          <tr>
            <th><?php _e('Current Mode:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <?php if ($is_off_peak): ?>
                <span style="color: green; font-weight: bold;">🌙 Off-Peak (12:00 AM - 6:30 AM)</span>
                <p class="description">Full product sync - More products per batch</p>
              <?php else: ?>
                <span style="color: orange; font-weight: bold;">☀️ Peak Hours (6:30 AM - 12:00 AM)</span>
                <p class="description">Light sync (price & quantity only) - Fewer products per batch</p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th><?php _e('Current Sync Type:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <code><?php echo esc_html($sync_type); ?></code>
            </td>
          </tr>
          <tr>
            <th><?php _e('Products Pending Sync:', 'wc-api-mps-scheduled'); ?></th>
            <td>
              <strong><?php echo $products_pending_count; ?></strong> products
              <?php if ($is_off_peak): ?>
                (will sync <strong><?php echo $batch_size_offpeak; ?></strong> per run)
              <?php else: ?>
                (will sync <strong><?php echo $batch_size; ?></strong> per run)
              <?php endif; ?>
            </td>
          </tr>
        </table>
      </div>

      <div class="card">
        <h2><?php _e('Sync Schedule', 'wc-api-mps-scheduled'); ?></h2>
        <table class="widefat">
          <thead>
            <tr>
              <th><?php _e('Time Period', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Sync Type', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Products per Run', 'wc-api-mps-scheduled'); ?></th>
              <th><?php _e('Description', 'wc-api-mps-scheduled'); ?></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>🌙 12:00 AM - 6:30 AM</strong></td>
              <td><code>full_product</code></td>
              <td><?php echo $batch_size_offpeak; ?></td>
              <td>Full sync - all product data, images, categories, etc.</td>
            </tr>
            <tr>
              <td><strong>☀️ 6:30 AM - 12:00 AM</strong></td>
              <td><code>price_and_quantity</code></td>
              <td><?php echo $batch_size; ?></td>
              <td>Light sync - only price, stock, and sale dates</td>
            </tr>
          </tbody>
        </table>
        <p class="description" style="margin-top: 10px;">
          <strong>Note:</strong> Cron runs every 15 minutes throughout the day. Sync type automatically adjusts based on current time.
        </p>
      </div>

      <div class="card">
        <h2><?php _e('Settings', 'wc-api-mps-scheduled'); ?></h2>
        <form method="post">
          <?php wp_nonce_field('wc_api_mps_save_settings'); ?>
          <table class="form-table">
            <tr>
              <th><?php _e('Peak Hours Batch Size:', 'wc-api-mps-scheduled'); ?></th>
              <td>
                <input type="number" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50">
                <p class="description">
                  <?php _e('Products to sync per run during peak hours (6:30 AM - 12:00 AM). Lower is safer.', 'wc-api-mps-scheduled'); ?>
                  <br><strong>Recommended: 5-10</strong>
                </p>
              </td>
            </tr>
            <tr>
              <th><?php _e('Off-Peak Batch Size:', 'wc-api-mps-scheduled'); ?></th>
              <td>
                <input type="number" name="batch_size_offpeak" value="<?php echo esc_attr($batch_size_offpeak); ?>" min="1" max="100">
                <p class="description">
                  <?php _e('Products to sync per run during off-peak hours (12:00 AM - 6:30 AM). Can be higher.', 'wc-api-mps-scheduled'); ?>
                  <br><strong>Recommended: 20-50</strong>
                </p>
              </td>
            </tr>
          </table>
          <p class="submit">
            <input type="submit" name="save_settings" class="button button-primary" value="<?php _e('Save Settings', 'wc-api-mps-scheduled'); ?>">
          </p>
        </form>
      </div>

      <div class="card">
        <h2><?php _e('Manual Actions', 'wc-api-mps-scheduled'); ?></h2>
        <form method="post" style="display: inline-block;">
          <?php wp_nonce_field('wc_api_mps_manual_sync'); ?>
          <input type="submit" name="run_sync_now" class="button button-secondary" value="<?php _e('Run Sync Now', 'wc-api-mps-scheduled'); ?>">
          <p class="description">Runs sync immediately with current time-based settings</p>
        </form>
        <form method="post" style="display: inline-block; margin-left: 10px;">
          <?php wp_nonce_field('wc_api_mps_clear_logs'); ?>
          <input type="submit" name="clear_logs" class="button button-secondary" value="<?php _e('Clear Logs', 'wc-api-mps-scheduled'); ?>">
        </form>
      </div>

      <div class="card">
        <h2><?php _e('Recent Logs (Last 50 entries)', 'wc-api-mps-scheduled'); ?></h2>
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

      <div class="card" style="background: #f0f0f1; border-left: 4px solid #72aee6;">
        <h3>ℹ️ <?php _e('Performance Notes', 'wc-api-mps-scheduled'); ?></h3>
        <ul>
          <li><?php _e('Product counts are cached for 60 seconds to improve page load speed', 'wc-api-mps-scheduled'); ?></li>
          <li><?php _e('The cron runs automatically every 15 minutes - you don\'t need to keep this page open', 'wc-api-mps-scheduled'); ?></li>
          <li><?php _e('If this page times out, your server may need PHP timeout adjustments', 'wc-api-mps-scheduled'); ?></li>
          <li><?php _e('The sync process works in the background regardless of this page loading', 'wc-api-mps-scheduled'); ?></li>
        </ul>
      </div>
    </div>
<?php
  }
}

// Initialize the plugin
new WC_API_MPS_Scheduled_Sync();

/**
 * Hook into product updates to mark them for sync
 */
add_action('woocommerce_update_product', 'wc_api_mps_mark_for_sync', 10, 1);
add_action('woocommerce_new_product', 'wc_api_mps_mark_for_sync', 10, 1);

function wc_api_mps_mark_for_sync($product_id)
{
  // Mark for full sync (for off-peak hours)
  update_post_meta($product_id, '_wc_api_mps_needs_full_sync', 1);
  update_post_meta($product_id, '_wc_api_mps_needs_sync', 1);
}

/**
 * Hook into stock changes to mark for light sync
 */
add_action('woocommerce_product_set_stock', 'wc_api_mps_mark_for_light_sync', 10, 1);
add_action('woocommerce_variation_set_stock', 'wc_api_mps_mark_for_light_sync', 10, 1);

function wc_api_mps_mark_for_light_sync($product)
{
  // Mark for light sync (price/quantity only - for peak hours)
  $product_id = $product->get_id();
  update_post_meta($product_id, '_wc_api_mps_needs_light_sync', 1);
}
