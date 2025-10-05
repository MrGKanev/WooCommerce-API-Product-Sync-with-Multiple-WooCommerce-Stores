<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

/*
 * Deleted options when plugin uninstall.
 */
$uninstall = get_option( 'wc_api_mps_uninstall' );
if ( $uninstall ) {
    global $wpdb;

    $wpdb->delete( $wpdb->prefix.'postmeta', array( 'meta_key' => 'mpsrel' ) );
    $wpdb->delete( $wpdb->prefix.'termmeta', array( 'meta_key' => 'mpsrel' ) );

    delete_option( 'wc_api_mps_stores' );
    delete_option( 'wc_api_mps_sync_type' );
    delete_option( 'wc_api_mps_authorization' );
    delete_option( 'wc_api_mps_old_products_sync_by' );
    delete_option( 'wc_api_mps_product_sync_type' );
    delete_option( 'wc_api_mps_stock_sync' );
    delete_option( 'wc_api_mps_product_delete' );
    delete_option( 'wc_api_mps_stores' );
    delete_option( 'wc_api_mps_stores' );
}