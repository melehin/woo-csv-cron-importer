<?php
/**
 * Plugin Name:       Woo CSV Cron Importer
 * Plugin URI:        https://wordpress.org/plugins/woo-csv-cron-importer/
 * Description:       This plugin imports products from a CSV file by cron scheduler using the standard WooCommerce import library. Supports downloading from http / https sources.
 * Version:           0.0.1
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Fedor Melekhin <fedormelexin@gmail.com>
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocci
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('WOOCCI_MAIN_FILE', __FILE__);

/**
 * Add menu
 */
add_action("admin_menu", "woocci_import_add_menu");
function woocci_import_add_menu() {
	add_submenu_page("woocommerce", "Woo CSV Cron Importer", "WOOCCI", "import", "woocci_import", "woocci_import");
}

/**
 * Include settings page
 */
add_action( 'plugins_loaded', 'woocci_import_plugin_loaded' );
function woocci_import_plugin_loaded() {
    require_once( plugin_dir_path( __FILE__ ) . 'settings.php' );
}

/**
 * Price markup filter
 */
add_filter( 'woocommerce_product_importer_parsed_data', 'woocci_import_price_append', 10, 2 );
function woocci_import_price_append( $parsed_data, $raw_data ){
    if( !empty($parsed_data["regular_price"]) ) {
        $markup = intval( get_option('woocci_markup') );
        $precision = intval( get_option('woocci_markup_precision') );
        $regular_price = floatval( $parsed_data["regular_price"] );
        $regular_price = $regular_price + ($regular_price * $markup / 100);
        $parsed_data["regular_price"] = (string) round($regular_price, $precision);
    }
    
	return $parsed_data;
}

/**
 * Main cron job
 */
add_action( 'woocci_main_job_action', 'woocci_main_job_function', 10, 3 );
function woocci_main_job_function( $file_path, $start_pos, $update_existing ) {
    if ( get_option( 'woocci_new_status' ) == 'stopped' || get_option( 'woocci_update_status' ) == 'stopped' ) {
        return;
    }
    require_once( WP_PLUGIN_DIR . '/woocommerce/includes/import/class-wc-product-csv-importer.php' );
    
    // get headers
    $f = fopen($file_path, 'r');
    $headers = fgetcsv( $f , 0, ',' );
    fclose($f);

    $mapping = array();
    $mapping_to = array();
    $mapping_option = 'woocci_' . ($update_existing ? 'update' : 'new') . '_mapping';
    $mapping_from_settings = get_option( $mapping_option );

    $mapping_to = explode(",", $mapping_from_settings);

    if($mapping_to !== false) {
        foreach($mapping_to as $k => $v) {
            $size = sizeof($headers);
            if( $k < $size ) {
                $mapping[ $headers[$k] ] = $v;
            }
        }
    }

    if( sizeof($mapping) == 0 ) {
        update_option( 'woocci_' . ($update_existing ? 'update' : 'new') . '_status', 'mapping not found' );
        return;
    }

    update_option( $mapping_option, implode( ",", $mapping_to ) );

    $args = array(
        'mapping'          => $mapping,
        'parse'            => true,
        'start_pos'           => $start_pos,
        'update_existing' => $update_existing,
    );

    global $current_user;
    error_log( 'User id is ' . $current_user->ID);
    error_log( 'Continue from pos: ' . $start_pos );
    $importer = new WC_Product_CSV_Importer( $file_path, $args );
    try {
        $results  = $importer->import();
    } catch (Exception $e) {
        error_log( $e );
    }
    if($importer->get_file_position() != $start_pos) {
        update_option( 'woocci_results', $results );
        error_log( 'Send task to import continue ' . (string)$importer->get_percent_complete() );
        update_option( 'woocci_' . ($update_existing ? 'update' : 'new') . '_status', 'importing '.$importer->get_percent_complete().'%' );
        wp_schedule_single_event( time(), 'woocci_main_job_action', array( 
            'file_path' => $file_path,
            'start_pos' => $importer->get_file_position(),
            'update_existing' => $update_existing
        ));
    } else {
        error_log( 'Deleting temporary file' );
        update_option( 'woocci_last_complete',  date_i18n( $format = 'r' ) );
        update_option( 'woocci_results', '' );
        update_option( 'woocci_' . ($update_existing ? 'update' : 'new') . '_status', 'completed' );
        @unlink( $file_path );
    }
}

add_action( 'bl_woocci_cron_hook', 'woocci_cron_hook' );
function woocci_check_file_exists( $uri ) {
    if ($uri == "") {
        return false;
    } else if (strpos($uri, 'http') === 0) {
        $file_headers = @get_headers($uri);
        return $file_headers[0] == 'HTTP/1.1 200 OK';
    } else {
        return file_exists( $uri );
    }
}

function woocci_prepare_file( $option_name ) {
    $uri = get_option( $option_name );
    if (strpos($uri, 'http') === 0) {
        require_once( ABSPATH . "wp-admin" . '/includes/file.php');
        $res = download_url( $uri );
        rename($res, $res . '.csv');
        $res = $res . '.csv';
        error_log( 'Downloaded file ' . $uri . ' to ' . $res );
        return $res;
    } else {
        return $uri;
    }
}

function woocci_cron_hook( $from_ajax = false ) {
    $webhook = get_option('woocci_init_webhook');
    if( $webhook != "" && !$from_ajax ) {
        update_option( 'woocci_new_status', 'webhook initiated' );
        update_option( 'woocci_update_status', 'webhook initiated' );
        wp_remote_get( $webhook );
        return;
    }

    // Download files or use local
    update_option( 'woocci_new_status', 'check files' );
    update_option( 'woocci_update_status', 'check files' );
    $new_file_uri = woocci_prepare_file( 'woocci_new_file_uri' );
    $update_file_uri = woocci_prepare_file( 'woocci_update_file_uri' );

    if ($new_file_uri != "" && filesize( $new_file_uri ) ) {
        wp_schedule_single_event( time(), 'woocci_main_job_action', array( 
            'file_path' => $new_file_uri,
            'start_pos' => 0,
            'update_existing' => false
        ));
    } else {
        update_option( 'woocci_new_status', 'no import file' );
    }
    
    if ($update_file_uri != ""  && filesize( $update_file_uri ) ) {
        wp_schedule_single_event( time(), 'woocci_main_job_action', array( 
            'file_path' => $update_file_uri,
            'start_pos' => 0,
            'update_existing' => true
        ));
    } else {
        update_option( 'woocci_update_status', 'no import file' );
    }
}

function woocci_import() {
    ?><div class="wrap">
    <h2>Woo CSV Cron Importer</h2>
    <button id="start"><?php echo _e('Force start'); ?></button> <button id="stop"><?php echo _e('Stop'); ?></button> <a href="<?php echo admin_url( 'options-general.php#woocci_settings' ); ?>"><button><?php _e('Settings'); ?></button></a> <br/>
    <table>
        <tr>
            <th></th><th>New products</th><th>Update products</th>
        </tr>
        <tr>
            <th>Status</th><td id="new_status" style="text-align: center">wait..</td><td id="update_status" style="text-align: center">wait..</td>
        </tr>
    </table>
    Last run datetime: <b id="last_complete">wait..</b><br/>
    Results: <span id="results">wait..</span>
    </div><?php
}


/** 
 * AJAX section
*/

/**
 * AJAX cron initiator
 */
add_action( 'wp_ajax_init_action', 'woocci_init_action' );
add_action( 'wp_ajax_nopriv_init_action', 'woocci_init_action' );
function woocci_init_action() {
    global $current_user;
    if( isset( $_GET['key'] ) && $_GET['key'] == get_option( 'woocci_init_action_key' ) ) {
        $user = get_user_by('id', 1);
        $user_id = $user->ID; 
        $user_login = $user->Username; 
        // escalate user from 0 to 1
        wp_set_current_user($user_id, $user_login);
        error_log( 'User id is ' . $user_id . ' login is ' . $user_login);
        woocci_cron_hook( $from_ajax = true );
        echo 'ok';
    } else {
        echo 'key is broken';
    }
    wp_die();
}

/**
 * Frontend script
 */
add_action( 'admin_footer', 'woocci_action_javascript' ); 

function woocci_action_javascript() { ?>
	<script type="text/javascript" >
	jQuery(document).ready(function($) {

        var update = function() {
            jQuery.post(ajaxurl, {'action': 'woocci_ajax_action'}, function(response) {
                $('#new_status').html(response.new_status);
                $('#update_status').html(response.update_status);
                $('#last_complete').html(response.last_complete != "" ? response.last_complete : "no");
                $('#results').html(JSON.stringify( response.results ));
            });
        };

        $('#stop').click(function(){
            jQuery.post(ajaxurl, {'action': 'woocci_ajax_action', 'stop': true}, function(response) {
                $('#stop').html('Stopped');
                update();
            });
        });

        $('#start').click(function(){
            jQuery.post(ajaxurl, {'action': 'woocci_ajax_action', 'start': true}, function(response) {
                $('#start').html('Running');
                update();
            });
        });

        update();

		setInterval(update, 30000);
	});
	</script> <?php
}

/**
 * Backend json
 */

add_action( 'wp_ajax_woocci_ajax_action', 'woocci_ajax_action' );

function woocci_ajax_action() {
    if( isset( $_POST['stop'] ) ) {
        update_option( 'woocci_new_status', 'stopped' );
        update_option( 'woocci_update_status', 'stopped' );
        wp_die();
    } elseif ( isset( $_POST['start'] ) ) {
        woocci_cron_hook( );
        wp_die();
    }

    header('Content-Type: application/json');
    echo json_encode(array(
        'new_status' => get_option( 'woocci_new_status' ),
        'update_status' => get_option( 'woocci_update_status' ),
        'last_complete' => get_option( 'woocci_last_complete' ),
        'results' => get_option( 'woocci_results' ),
    ) );

	wp_die(); // this is required to terminate immediately and return a proper response
}
?>