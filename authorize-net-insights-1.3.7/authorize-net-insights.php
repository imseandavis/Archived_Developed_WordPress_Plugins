<?php
/**
 * Plugin Name: Authorize.Net Insights
 * Description: Displays subscription/customer statistics from Authorize.Net (ARB). Admin page: Analytics → AuthNet Insights.
 * Version: 1.3.7
 * Author: ChatGPT
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define('ANI_VERSION', '1.3.7');
define('ANI_PLUGIN_FILE', __FILE__);
define('ANI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ANI_PLUGIN_URL', plugin_dir_url(__FILE__));
require_once ANI_PLUGIN_DIR . 'includes/class-ani-client.php';
require_once ANI_PLUGIN_DIR . 'includes/class-ani-admin.php';
function ani_activate() {
    if ( version_compare(PHP_VERSION, '7.4', '<') ) {
        deactivate_plugins( plugin_basename(__FILE__) );
        wp_die('Authorize.Net Insights requires PHP 7.4 or newer.');
    }
    if ( ! wp_next_scheduled('ani_refresh_cache_event') ) {
        wp_schedule_event( time() + 60, 'hourly', 'ani_refresh_cache_event' );
    }
    $defaults = array(
        'api_login_id'      => '',
        'transaction_key'   => '',
        'environment'       => 'sandbox',
        'timeout'           => 20,
        'cache_ttl'         => 900,
        'deep_lookup'       => 0,
        'deep_lookup_limit' => 100,
    );
    add_option('ani_settings', $defaults);
    add_option('ani_timeseries', array());
}
register_activation_hook( __FILE__, 'ani_activate' );
function ani_deactivate() {
    $timestamp = wp_next_scheduled('ani_refresh_cache_event');
    if ($timestamp) { wp_unschedule_event($timestamp, 'ani_refresh_cache_event'); }
}
register_deactivation_hook( __FILE__, 'ani_deactivate' );
add_action('ani_refresh_cache_event', function() {
    $client = new ANI_Client();
    $client->refresh_all_caches();
});
add_action('admin_post_ani_export_csv', function(){
    if ( ! current_user_can('manage_options') ) { wp_die('Forbidden'); }
    check_admin_referer('ani_export_csv');
    $from = isset($_GET['ani_from']) ? sanitize_text_field($_GET['ani_from']) : '';
    $to   = isset($_GET['ani_to'])   ? sanitize_text_field($_GET['ani_to'])   : '';
    $seg  = isset($_GET['seg'])      ? sanitize_text_field($_GET['seg'])      : 'active';
    $client = new ANI_Client();
    $segments = $client->get_segments();
    if ( is_wp_error($segments) ) { wp_die( 'Export failed: ' . $segments->get_error_message() ); }
    $data = isset($segments[$seg]) && is_array($segments[$seg]) ? $segments[$seg] : array();
    $from_ts = $from ? strtotime($from) : null;
    $to_ts   = $to   ? strtotime($to)   : null;
    $out = array();
    foreach ($data as $s) {
        $start = isset($s['paymentSchedule']['startDate']) ? strtotime($s['paymentSchedule']['startDate']) : (isset($s['createTimeStampUTC']) ? strtotime($s['createTimeStampUTC']) : null);
        if ($from_ts && ($start === null || $start < $from_ts)) continue;
        if ($to_ts && ($start === null || $start > $to_ts)) continue;
        $out[] = $s;
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="authorize_net_' . $seg . '_export.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, array('id','name','status','amount','intervalLength','intervalUnit','startDate','customerEmail','createTimeStampUTC'));
    foreach ($out as $s) {
        $amount = isset($s['paymentSchedule']['amount']) ? $s['paymentSchedule']['amount'] : (isset($s['amount']) ? $s['amount'] : '');
        $len    = isset($s['paymentSchedule']['interval']['length']) ? $s['paymentSchedule']['interval']['length'] : '';
        $unit   = isset($s['paymentSchedule']['interval']['unit']) ? $s['paymentSchedule']['interval']['unit'] : '';
        $startD = isset($s['paymentSchedule']['startDate']) ? $s['paymentSchedule']['startDate'] : (isset($s['createTimeStampUTC']) ? $s['createTimeStampUTC'] : '');
        $email  = isset($s['customer']['email']) ? $s['customer']['email'] : (isset($s['customerEmail']) ? $s['customerEmail'] : '');
        $ts     = isset($s['createTimeStampUTC']) ? $s['createTimeStampUTC'] : '';
        fputcsv($fh, array(isset($s['id']) ? $s['id'] : '', isset($s['name']) ? $s['name'] : '', isset($s['status']) ? $s['status'] : '', $amount, $len, $unit, $startD, $email, $ts));
    }
    fclose($fh);
    exit;
});
add_action('plugins_loaded', function() {
    if ( is_admin() ) { new ANI_Admin(); }
});
