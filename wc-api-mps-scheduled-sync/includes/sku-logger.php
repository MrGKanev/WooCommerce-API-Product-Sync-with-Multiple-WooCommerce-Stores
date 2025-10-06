<?php

/**
 * SKU-specific logging to dated text files
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Get log directory path
 */
function wc_api_mps_get_sku_log_dir()
{
  $upload_dir = wp_upload_dir();
  $log_dir = $upload_dir['basedir'] . '/wc-api-mps-sync-logs';

  // Create directory if it doesn't exist
  if (!file_exists($log_dir)) {
    wp_mkdir_p($log_dir);

    // Add .htaccess to protect logs
    $htaccess_file = $log_dir . '/.htaccess';
    if (!file_exists($htaccess_file)) {
      file_put_contents($htaccess_file, "deny from all\n");
    }

    // Add index.php to prevent directory listing
    $index_file = $log_dir . '/index.php';
    if (!file_exists($index_file)) {
      file_put_contents($index_file, "<?php\n// Silence is golden.\n");
    }
  }

  return $log_dir;
}

/**
 * Get current log file path (based on today's date)
 */
function wc_api_mps_get_sku_log_file()
{
  $log_dir = wc_api_mps_get_sku_log_dir();
  $date = date('Y-m-d');
  return $log_dir . '/sync-log-' . $date . '.txt';
}

/**
 * Count lines in a file
 */
function wc_api_mps_count_log_lines($file_path)
{
  if (!file_exists($file_path)) {
    return 0;
  }

  $line_count = 0;
  $handle = fopen($file_path, 'r');
  if ($handle) {
    while (!feof($handle)) {
      fgets($handle);
      $line_count++;
    }
    fclose($handle);
  }

  return $line_count;
}

/**
 * Rotate log file if it exceeds 5000 lines
 */
function wc_api_mps_rotate_sku_log($file_path)
{
  if (!file_exists($file_path)) {
    return;
  }

  $line_count = wc_api_mps_count_log_lines($file_path);

  if ($line_count >= 5000) {
    // Create backup with timestamp
    $timestamp = date('Y-m-d_His');
    $backup_path = str_replace('.txt', '-part-' . $timestamp . '.txt', $file_path);

    // Rename current file
    rename($file_path, $backup_path);

    // Log rotation event in new file
    $rotation_msg = sprintf(
      "[%s] === Log rotated at 5000 lines, previous log saved as: %s ===\n",
      date('Y-m-d H:i:s'),
      basename($backup_path)
    );
    file_put_contents($file_path, $rotation_msg, FILE_APPEND);
  }
}

/**
 * Log SKU sync to dated text file
 * 
 * @param string $sku Product SKU
 * @param int $product_id Product ID
 * @param string $sync_type Type of sync (full_product, price_and_quantity, quantity)
 * @param array $stores Array of store URLs synced to
 * @param bool $success Whether sync was successful
 * @param string $error_message Optional error message
 */
function wc_api_mps_log_sku_sync($sku, $product_id, $sync_type, $stores = array(), $success = true, $error_message = '')
{
  $log_file = wc_api_mps_get_sku_log_file();

  // Check if rotation needed
  wc_api_mps_rotate_sku_log($log_file);

  // Prepare log entry
  $timestamp = date('Y-m-d H:i:s');
  $status = $success ? 'SUCCESS' : 'ERROR';
  $store_list = !empty($stores) ? implode(', ', array_map(function ($url) {
    return parse_url($url, PHP_URL_HOST);
  }, $stores)) : 'N/A';

  $identifier = $sku ? "SKU: {$sku}" : "ID: {$product_id}";

  // Format: [timestamp] STATUS | SKU/ID | sync_type | stores | error (if any)
  if ($success) {
    $log_entry = sprintf(
      "[%s] %s | %s | %s | Stores: %s\n",
      $timestamp,
      $status,
      $identifier,
      $sync_type,
      $store_list
    );
  } else {
    $log_entry = sprintf(
      "[%s] %s | %s | %s | Stores: %s | Error: %s\n",
      $timestamp,
      $status,
      $identifier,
      $sync_type,
      $store_list,
      $error_message
    );
  }

  // Write to file
  file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Get list of all log files
 */
function wc_api_mps_get_sku_log_files()
{
  $log_dir = wc_api_mps_get_sku_log_dir();

  if (!is_dir($log_dir)) {
    return array();
  }

  $files = glob($log_dir . '/sync-log-*.txt');

  // Sort by date descending (newest first)
  usort($files, function ($a, $b) {
    return filemtime($b) - filemtime($a);
  });

  return $files;
}

/**
 * Get log file contents
 */
function wc_api_mps_get_sku_log_contents($file_path, $lines = 100)
{
  if (!file_exists($file_path)) {
    return array();
  }

  // Read last N lines
  $file_lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

  if (!$file_lines) {
    return array();
  }

  // Return last N lines
  return array_slice($file_lines, -$lines);
}

/**
 * Delete old log files (older than X days)
 */
function wc_api_mps_cleanup_old_sku_logs($days = 30)
{
  $log_dir = wc_api_mps_get_sku_log_dir();
  $files = glob($log_dir . '/sync-log-*.txt');

  $cutoff_time = time() - ($days * 24 * 60 * 60);
  $deleted = 0;

  foreach ($files as $file) {
    if (filemtime($file) < $cutoff_time) {
      unlink($file);
      $deleted++;
    }
  }

  return $deleted;
}

/**
 * Get log statistics
 */
function wc_api_mps_get_sku_log_stats()
{
  $log_files = wc_api_mps_get_sku_log_files();

  $total_files = count($log_files);
  $total_size = 0;
  $total_lines = 0;

  foreach ($log_files as $file) {
    $total_size += filesize($file);
    $total_lines += wc_api_mps_count_log_lines($file);
  }

  return array(
    'total_files' => $total_files,
    'total_size' => $total_size,
    'total_size_mb' => round($total_size / 1024 / 1024, 2),
    'total_lines' => $total_lines,
    'log_dir' => wc_api_mps_get_sku_log_dir(),
  );
}
