<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

/*
 * This is a class for WooCommerce API.
 */
if ( ! class_exists( 'WC_API_MPS' ) ) {
    class WC_API_MPS {
        
        var $url;
        var $site_url;
        var $consumer_key;
        var $consumer_secret;
        
        function __construct( $url, $consumer_key, $consumer_secret ) {
                        
            $this->url              = rtrim( $url, '/' ).'/wp-json/wc/v3';
            $this->site_url         = $url;
            $this->consumer_key     = $consumer_key;
            $this->consumer_secret  = $consumer_secret;
        }
        
        function authentication() {

            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products?per_page=1&'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products?per_page=1';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }

            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
                $response = array( 'code' => 404 );
                $response = (object) $response;
            }
            
            return $response;
        }
        
        function getProducts( $search ) {
            
            $old_products_sync_by = get_option( 'wc_api_mps_old_products_sync_by' );
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                if ( $old_products_sync_by == 'sku' ) {
                    $url = $this->url.'/products?sku='.$search.'&'.$query_string;
                } else {
                    $url = $this->url.'/products?slug='.$search.'&'.$query_string;
                }

                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                if ( $old_products_sync_by == 'sku' ) {
                    $url = $this->url.'/products?sku='.$search;
                } else {
                    $url = $this->url.'/products?slug='.$search;
                }

                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function getProduct( $product_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/'.$product_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/'.$product_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );

            return $response;
        }
        
        function addProduct( $data ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateProduct( $data, $product_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/'.$product_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/'.$product_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function getProductVariations( $product_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/'.$product_id.'/variations?per_page=100&'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/'.$product_id.'/variations?per_page=100';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function getProductVariation( $product_id, $variation_product_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function addProductVariation( $data, $product_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/'.$product_id.'/variations?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/'.$product_id.'/variations';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateProductVariation( $data, $product_id, $variation_product_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function getCategories( $slug ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/categories?slug='.$slug.'&'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/categories?slug='.$slug;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function getCategory( $category_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/categories/'.$category_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/categories/'.$category_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }

        function addCategory( $data ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/categories?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/categories';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateCategory( $data, $category_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/categories/'.$category_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/categories/'.$category_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function getTags( $slug ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/tags?slug='.$slug.'&'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/tags?slug='.$slug;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function getTag( $tag_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/tags/'.$tag_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/tags/'.$tag_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function addTag( $data ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/tags?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/tags';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateTag( $data, $tag_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/tags/'.$tag_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/tags/'.$tag_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function getAttributes() {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function addAttribute( $data ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateAttribute( $data, $attribute_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes/'.$attribute_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes/'.$attribute_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function getAttributeTerms( $slug, $attribute_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms?slug='.$slug.'&'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms?slug='.$slug;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function getAttributeTerm( $attribute_term_id, $attribute_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms/'.$attribute_term_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms/'.$attribute_term_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }

        function addAttributeTerm( $data, $attribute_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateAttributeTerm( $data, $attribute_term_id, $attribute_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms/'.$attribute_term_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/attributes/'.$attribute_id.'/terms/'.$attribute_term_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function deleteProduct( $product_id, $force = 0 ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                if ( $force ) {
                    $url = $this->url.'/products/'.$product_id.'?'.$query_string.'&force=true';
                } else {
                    $url = $this->url.'/products/'.$product_id.'?'.$query_string;
                }

                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                if ( $force ) {
                    $url = $this->url.'/products/'.$product_id.'?force=true';
                } else {
                    $url = $this->url.'/products/'.$product_id;
                }

                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'method'        => 'DELETE',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_request( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }

        function deleteProductVariation( $product_id, $variation_product_id, $force = 0 ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                if ( $force ) {
                    $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id.'?'.$query_string.'&force=true';
                } else {
                    $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id.'?'.$query_string;
                }

                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                if ( $force ) {
                    $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id.'?force=true';
                } else {
                    $url = $this->url.'/products/'.$product_id.'/variations/'.$variation_product_id;
                }
                
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'method'        => 'DELETE',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_request( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }

        function getBrands( $slug ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/brands?slug='.$slug.'&'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/brands?slug='.$slug;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }
        
        function getBrand( $brand_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/brands/'.$brand_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/brands/'.$brand_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $args = array(
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_get( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            
            return $response;
        }

        function addBrand( $data ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/brands?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/brands';
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
        
        function updateBrand( $data, $brand_id ) {
            
            $authorization = get_option( 'wc_api_mps_authorization' );
            if ( $authorization == 'query' ) {
                $query_string_parameters = array(
                    'consumer_key'      => $this->consumer_key,
                    'consumer_secret'   => $this->consumer_secret,
                );
                $query_string = http_build_query( $query_string_parameters );
                $url = $this->url.'/products/brands/'.$brand_id.'?'.$query_string;
                $header = array(
                    'Content-Type'  => 'application/json',
                );
            } else {
                $url = $this->url.'/products/brands/'.$brand_id;
                $header = array(
                    'Authorization' => 'Basic '.base64_encode( $this->consumer_key.':'.$this->consumer_secret ),
                    'Content-Type'  => 'application/json',
                );
            }
            
            $data = json_encode( $data );
            $args = array(
                'method'        => 'POST',
                'timeout'       => 120,
                'httpversion'   => '1.0',
                'headers'       => $header,
                'body'          => $data,
                'sslverify'     => false,
            );
            $wp_remote_response = wp_remote_post( $url, $args );
            $json_response = '';
            if ( ! is_wp_error( $wp_remote_response ) ) {
                $json_response = $wp_remote_response['body'];
            }
            
            $response = json_decode( $json_response );
            if ( isset( $response->code ) ) {
                $log = "errorCode: ".$response->code."\n";
                $log .= "message: ".$response->message."\n";
                $log .= "API Call: ".__FUNCTION__."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            $response_code = wp_remote_retrieve_response_code( $wp_remote_response );
            if ( $response_code == 404 ) {
                $log = "status: ".$response_code."\n";
                $log .= "Date: ".date( 'Y-m-d H:i:s' )."\n\n";                               

                file_put_contents( WC_API_MPS_PLUGIN_PATH.'debug.log', $log, FILE_APPEND );
            }
            
            return $response;
        }
    }
}