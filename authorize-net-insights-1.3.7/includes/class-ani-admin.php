<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class ANI_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }
    public function menu() {
        add_menu_page(__('AuthNet Insights','ani'), __('AuthNet Insights','ani'), 'manage_options', 'ani-dashboard', array($this, 'render_dashboard'), 'dashicons-chart-pie', 58);
        add_submenu_page('ani-dashboard', __('Settings','ani'), __('Settings','ani'), 'manage_options', 'ani-settings', array($this, 'render_settings'));
    }
    public function assets($hook) {
        if (strpos($hook, 'ani') === false) { return; }
        wp_enqueue_style('ani-admin', ANI_PLUGIN_URL . 'assets/css/admin.css', array(), ANI_VERSION);
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);
        wp_enqueue_script('ani-admin', ANI_PLUGIN_URL . 'assets/js/admin.js', array('chartjs'), ANI_VERSION, true);
    }
    public function register_settings() {
        register_setting('ani_settings_group', 'ani_settings', array($this, 'sanitize_settings'));
        add_settings_section('ani_api', __('API Credentials','ani'), function(){ echo '<p>Enter your Authorize.Net API Login ID and Transaction Key. Choose sandbox or live.</p>'; }, 'ani-settings');
        add_settings_field('api_login_id', __('API Login ID','ani'), array($this,'field_text'), 'ani-settings', 'ani_api', array('key'=>'api_login_id'));
        add_settings_field('transaction_key', __('Transaction Key','ani'), array($this,'field_text'), 'ani-settings', 'ani_api', array('key'=>'transaction_key'));
        add_settings_field('environment', __('Environment','ani'), array($this,'field_env'), 'ani-settings', 'ani_api');
        add_settings_field('timeout', __('HTTP Timeout (seconds)','ani'), array($this,'field_number'), 'ani-settings', 'ani_api', array('key'=>'timeout', 'min'=>5, 'max'=>60));
        add_settings_field('cache_ttl', __('Cache TTL (seconds)','ani'), array($this,'field_number'), 'ani-settings', 'ani_api', array('key'=>'cache_ttl', 'min'=>60, 'max'=>3600));
        add_settings_field('deep_lookup', __('Deep interval lookup','ani'), array($this,'field_checkbox'), 'ani-settings', 'ani_api');
        add_settings_field('deep_lookup_limit', __('Deep lookup limit','ani'), array($this,'field_number'), 'ani-settings', 'ani_api', array('key'=>'deep_lookup_limit', 'min'=>10, 'max'=>1000));
    }
    public function sanitize_settings($input) {
        $out = get_option('ani_settings', array());
        $out['api_login_id'] = sanitize_text_field($input['api_login_id'] ?? '');
        $out['transaction_key'] = sanitize_text_field($input['transaction_key'] ?? '');
        $env = ($input['environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
        $out['environment'] = $env;
        $out['timeout'] = max(5, min(60, intval($input['timeout'] ?? 20)));
        $out['cache_ttl'] = max(60, min(3600, intval($input['cache_ttl'] ?? 900)));
        $out['deep_lookup'] = empty($input['deep_lookup']) ? 0 : 1;
        $out['deep_lookup_limit'] = max(10, min(1000, intval($input['deep_lookup_limit'] ?? 100)));
        delete_transient('ani_stats_cache');
        return $out;
    }
    public function field_text($args) {
        $settings = get_option('ani_settings', array());
        $key = $args['key']; $val = esc_attr($settings[$key] ?? '');
        printf('<input type="text" class="regular-text" name="ani_settings[%s]" value="%s" autocomplete="off" />', esc_attr($key), $val);
    }
    public function field_number($args) {
        $settings = get_option('ani_settings', array());
        $key = $args['key']; $val = esc_attr($settings[$key] ?? '');
        $min = intval($args['min'] ?? 0); $max = intval($args['max'] ?? 100);
        printf('<input type="number" min="%d" max="%d" class="small-text" name="ani_settings[%s]" value="%s" />', $min, $max, esc_attr($key), $val);
    }
    public function field_checkbox() {
        $settings = get_option('ani_settings', array()); $val = !empty($settings['deep_lookup']) ? 1 : 0;
        printf('<label><input type="checkbox" name="ani_settings[deep_lookup]" value="1" %s /> Enable per‑subscription detail fetch (gets precise interval)</label>', checked(1, $val, false));
    }
    public function field_env() {
        $settings = get_option('ani_settings', array()); $env = $settings['environment'] ?? 'sandbox';
        echo '<select name="ani_settings[environment]">';
        echo '<option value="sandbox" '.selected($env,'sandbox',false).'>Sandbox</option>';
        echo '<option value="live" '.selected($env,'live',false).'>Live</option>';
        echo '</select>';
    }
    private function quick_link($label, $from, $to) {
        $url = add_query_arg(array_filter(array('page'=>'ani-dashboard','ani_from'=>$from?:null,'ani_to'=>$to?:null)), admin_url('admin.php'));
        return '<a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    }
    private function localize_chart_data($stats) {
        $series = get_option('ani_timeseries', array());
        $dates = array(); $active = array(); $total  = array();
        foreach ($series as $row) { $dates[]=$row['date']; $active[]=intval($row['active']); $total[]=intval($row['total']); }
        wp_localize_script('ani-admin', 'aniChartData', array(
            'histLabels' => $stats['hist_labels'],
            'histCounts' => $stats['hist_counts'],
            'intervalCounts' => $stats['interval_counts'],
            'segments' => array(
                'Active' => intval($stats['active']),
                'Inactive' => intval($stats['inactive']),
                'Suspended' => intval($stats['suspended']),
                'Past Due' => intval($stats['past_due']),
            ),
            'spark' => array('labels'=>$dates,'active'=>$active,'total'=>$total),
        ));
    }
    private function get_date_val($key) {
        $val = isset($_GET[$key]) ? sanitize_text_field($_GET[$key]) : '';
        if ($val && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) { return ''; }
        return $val;
    }
    public function render_settings() {
        echo '<div class="wrap"><h1>Authorize.Net Insights — Settings</h1>';
        echo '<form method="post" action="options.php">'; settings_fields('ani_settings_group'); do_settings_sections('ani-settings'); submit_button(); echo '</form>';
        echo '<hr/><h2>Tips</h2><ul><li>If your list response uses <code>subscriptionDetails[]</code>, turn on <strong>Deep interval lookup</strong> to fetch exact interval/unit for charts.</li><li>Increase <strong>Deep lookup limit</strong> if you want more precise interval mix at the cost of slower refresh.</li></ul></div>';
    }
    public function render_dashboard() {
        if ( ! current_user_can('manage_options') ) { return; }
        $client = new ANI_Client();
        $from = $this->get_date_val('ani_from'); $to = $this->get_date_val('ani_to');
        $now  = current_time('timestamp');
        $ymd  = function($ts){ return date_i18n('Y-m-d', $ts); };
        $start_month = $ymd( strtotime(date_i18n('Y-m-01', $now)) );
        $last_month_start = $ymd( strtotime('-1 month', strtotime(date_i18n('Y-m-01', $now))) );
        $last_month_end   = $ymd( strtotime('-1 day', strtotime($start_month)) );
        $start_q = $ymd( strtotime(date_i18n('Y-01-01', $now) . ' +' . (floor((date_i18n('n',$now)-1)/3)*3) . ' months') );
        $start_year = $ymd( strtotime(date_i18n('Y-01-01', $now)) );
        if ($from || $to) {
            $segments = $client->get_segments();
            if ( is_wp_error($segments) ) { $stats = $segments; }
            else { $stats = $client->compute_stats($segments, $from ?: null, $to ?: null); }
        } else { $stats = $client->get_stats(); }
        echo '<div class="wrap ani-wrap"><h1>Authorize.Net Insights</h1><p class="description">Overview of your ARB subscriptions with quick charts. Data is cached for the configured TTL.</p>';
        echo '<form method="get" action="" class="ani-filter"><input type="hidden" name="page" value="ani-dashboard" />';
        echo '<label>From: <input type="date" name="ani_from" value="'.esc_attr($from).'" /></label>';
        echo '<label>To: <input type="date" name="ani_to" value="'.esc_attr($to).'" /></label>';
        echo '<button class="button button-primary" type="submit">Apply</button>';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=ani-dashboard')).'">Clear</a>';
        echo '<span style="margin-left:8px">Quick:</span>';
        echo $this->quick_link('This Month', $start_month, '');
        echo ' ' . $this->quick_link('Last Month', $last_month_start, $last_month_end);
        echo ' ' . $this->quick_link('QTD', $start_q, '');
        echo ' ' . $this->quick_link('YTD', $start_year, '');
        echo '</form>';
        if ( is_wp_error($stats) ) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> '.esc_html($stats->get_error_message()).'</p></div>';
            echo '</div>'; return;
        } else { $this->localize_chart_data($stats); }
        echo '<div class="ani-grid">';
        echo '<div class="ani-card"><div class="ani-kpi-label">Active</div><div class="ani-kpi-value">'.intval($stats['active']).'</div><div class="ani-kpi-sub">'.esc_html($stats['active_percent']).'% of total</div></div>';
        echo '<div class="ani-card"><div class="ani-kpi-label">Inactive</div><div class="ani-kpi-value">'.intval($stats['inactive']).'</div><div class="ani-kpi-sub">'.esc_html($stats['inactive_percent']).'% of total</div></div>';
        echo '<div class="ani-card"><div class="ani-kpi-label">Suspended</div><div class="ani-kpi-value">'.intval($stats['suspended']).'</div><div class="ani-kpi-sub">Temporarily halted</div></div>';
        echo '<div class="ani-card"><div class="ani-kpi-label">Past Due</div><div class="ani-kpi-value">'.intval($stats['past_due']).'</div><div class="ani-kpi-sub">Needs attention</div></div>';
        echo '<div class="ani-card"><div class="ani-kpi-label">Total Subscriptions</div><div class="ani-kpi-value">'.intval($stats['total']).'</div><div class="ani-kpi-sub">Across segments</div></div>';
        echo '<div class="ani-card"><div class="ani-kpi-label">Estimated MRR</div><div class="ani-kpi-value">$'.number_format((float)$stats['active_mrr'], 2).'</div><div class="ani-kpi-sub">From active plans '.(($from||$to)?'(filtered)':'').'</div></div>';
        echo '</div>';
        echo '<p class="ani-updated">Last refreshed: '.esc_html($stats['refreshed_at']).' &middot; <a href="'.esc_url( wp_nonce_url( admin_url('admin.php?page=ani-dashboard&ani_refresh=1'), 'ani_refresh' ) ).'">Refresh now</a></p>';
        echo '<div style="margin:10px 0">';
        foreach (['active'=>'Active','inactive'=>'Inactive','suspended'=>'Suspended','past_due'=>'Past Due'] as $key=>$label) {
            echo '<form method="get" action="'.esc_url( admin_url('admin-post.php') ).'" style="display:inline-block">';
            echo '<input type="hidden" name="action" value="ani_export_csv" />';
            echo '<input type="hidden" name="ani_from" value="'.esc_attr($from).'" />';
            echo '<input type="hidden" name="ani_to" value="'.esc_attr($to).'" />';
            echo '<input type="hidden" name="seg" value="'.esc_attr($key).'" />';
            wp_nonce_field('ani_export_csv');
            echo '<button class="button">Export '.$label.' CSV</button></form> ';
        }
        echo '</div><hr/>';
        echo '<div class="ani-charts">';
        echo '<div class="ani-chart-card"><h2>Plan Amount Distribution '.(($from||$to)?'(filtered)':'').'</h2><canvas id="aniHistogram"></canvas></div>';
        echo '<div class="ani-chart-card"><h2>Interval Mix '.(($from||$to)?'(filtered)':'').'</h2><canvas id="aniIntervalPie"></canvas></div>';
        echo '<div class="ani-chart-card"><h2>Segments '.(($from||$to)?'(filtered)':'').'</h2><canvas id="aniSegmentsBar"></canvas></div>';
        echo '<div class="ani-chart-card"><h2>Active Subscriptions — 30‑day Sparkline</h2><canvas id="aniSparkline"></canvas><p class="description">Sparkline shows overall trend (not affected by date filter). Snapshots are captured daily during refresh.</p></div>';
        echo '</div></div>';
    }
}
