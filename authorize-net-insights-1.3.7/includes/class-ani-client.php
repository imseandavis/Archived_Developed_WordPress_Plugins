<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class ANI_Client {
    private $settings;
    private $endpoint;
    public function __construct() {
        $this->settings = get_option('ani_settings', array());
        $env = isset($this->settings['environment']) ? $this->settings['environment'] : 'sandbox';
        $this->endpoint = ($env === 'live')
            ? 'https://api2.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';
    }
    private function auth_block() {
        return array(
            'name' => $this->settings['api_login_id'] ?? '',
            'transactionKey' => $this->settings['transaction_key'] ?? '',
        );
    }
    private function strip_bom($s) {
        if (!is_string($s)) return $s;
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        $s = preg_replace('/^\x{FEFF}/u', '', $s);
        return $s;
    }
    private function post_json($payload) {
        $args = array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
            'timeout' => (int) ($this->settings['timeout'] ?? 20),
        );
        $resp = wp_remote_post($this->endpoint, $args);
        if ( is_wp_error($resp) ) { return $resp; }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $body = $this->strip_bom($body);
        $data = json_decode($body, true);
        if ($code !== 200 || ! is_array($data)) {
            $snippet = is_string($body) ? substr($body, 0, 512) : '';
            return new WP_Error('ani_bad_response', 'Authorize.Net returned a bad response (HTTP '.$code.'): '.$snippet, array('code'=>$code,'body'=>$snippet));
        }
        return $data;
    }
    private function normalize_list_item($s) {
        $out = array(
            'id' => isset($s['id']) ? $s['id'] : null,
            'name' => isset($s['name']) ? $s['name'] : '',
            'status' => isset($s['status']) ? $s['status'] : '',
            'createTimeStampUTC' => isset($s['createTimeStampUTC']) ? $s['createTimeStampUTC'] : (isset($s['statusUpdateTimeUTC']) ? $s['statusUpdateTimeUTC'] : ''),
            'amount' => isset($s['amount']) ? $s['amount'] : (isset($s['paymentSchedule']['amount']) ? $s['paymentSchedule']['amount'] : null),
            'customerEmail' => isset($s['customerEmail']) ? $s['customerEmail'] : (isset($s['customer']['email']) ? $s['customer']['email'] : ''),
        );
        if (isset($s['paymentSchedule'])) {
            $out['paymentSchedule'] = $s['paymentSchedule'];
        } else {
            $out['paymentSchedule'] = array(
                'startDate' => isset($s['createTimeStampUTC']) ? $s['createTimeStampUTC'] : '',
                'interval' => array(
                    'length' => isset($s['intervalLength']) ? $s['intervalLength'] : '',
                    'unit' => isset($s['intervalUnit']) ? $s['intervalUnit'] : '',
                ),
                'amount' => isset($out['amount']) ? $out['amount'] : '',
            );
        }
        return $out;
    }
    public function get_subscriptions_by_type($type = 'subscriptionActive', $page_size = 1000, $max_pages = 20) {
        $all = array();
        $page = 1; // Authorize.Net: paging.offset behaves like 1-based page number
        for ($i = 0; $i < $max_pages; $i++) {
            $request = array(
                'merchantAuthentication' => $this->auth_block(),
                'searchType' => $type,
                'sorting' => array('orderBy' => 'id', 'orderDescending' => false),
                'paging' => array('limit' => (int)$page_size, 'offset' => (int)$page),
            );
            $payload = array('ARBGetSubscriptionListRequest' => $request);
            $data = $this->post_json($payload);
            if ( is_wp_error($data) ) { return $data; }
            $ok = isset($data['messages']['resultCode']) ? $data['messages']['resultCode'] : 'Ok';
            if ($ok !== 'Ok' && !isset($data['subscriptionDetails'])) {
                $msg = isset($data['messages']['message'][0]['text']) ? $data['messages']['message'][0]['text'] : 'Unknown error';
                return new WP_Error('ani_api_error', $msg, $data);
            }
            $subs = array();
            if (isset($data['subscriptionDetails'])) {
                $subs = $data['subscriptionDetails'];
            } elseif (isset($data['subscriptions']['subscription'])) {
                $subs = $data['subscriptions']['subscription'];
            }
            if (empty($subs)) { break; }
            foreach ($subs as $row) { $all[] = $this->normalize_list_item($row); }
            $total = isset($data['totalNumInResultSet']) ? intval($data['totalNumInResultSet']) : 0;
            if ($total && count($all) >= $total) { break; }
            $page += 1; // advance page number
        }
        if (!empty($all) && !empty($this->settings['deep_lookup'])) {
            $limit = max(0, (int)(isset($this->settings['deep_lookup_limit']) ? $this->settings['deep_lookup_limit'] : 100));
            $this->hydrate_details($all, $limit);
        }
        return $all;
    }
    private function hydrate_details(&$items, $limit) {
        $count = 0;
        foreach ($items as &$it) {
            if ($count >= $limit) break;
            $id = isset($it['id']) ? $it['id'] : null;
            if (!$id) continue;
            $req = array('merchantAuthentication' => $this->auth_block(), 'subscriptionId' => (string)$id);
            $payload = array('ARBGetSubscriptionRequest' => $req);
            $data = $this->post_json($payload);
            if ( is_wp_error($data) ) { continue; }
            if (isset($data['messages']['resultCode']) && $data['messages']['resultCode'] !== 'Ok') { continue; }
            $sub = isset($data['subscription']) ? $data['subscription'] : array();
            if (!empty($sub['paymentSchedule'])) {
                $it['paymentSchedule'] = $sub['paymentSchedule'];
                if (isset($sub['amount'])) { $it['amount'] = $sub['amount']; }
            }
            $count++;
        }
    }
    public function get_segments() {
        $active    = $this->get_subscriptions_by_type('subscriptionActive',   1000, 20);
        if ( is_wp_error($active) ) { return $active; }
        $inactive  = $this->get_subscriptions_by_type('subscriptionInactive', 1000, 20);
        if ( is_wp_error($inactive) ) { return $inactive; }
        $suspended = $this->get_subscriptions_by_type('subscriptionSuspended',1000, 20);
        if ( is_wp_error($suspended) ) { $suspended = array(); }
        $past_due  = $this->get_subscriptions_by_type('subscriptionPastDue', 1000, 20);
        if ( is_wp_error($past_due) ) { $past_due = array(); }
        return array('active'=>$active,'inactive'=>$inactive,'suspended'=>$suspended,'past_due'=>$past_due);
    }
    public function compute_stats($segments, $from = null, $to = null) {
        $from_ts = $from ? strtotime($from) : null;
        $to_ts   = $to   ? strtotime($to)   : null;
        $filter = function($s) use ($from_ts, $to_ts) {
            $start = null;
            if (isset($s['paymentSchedule']['startDate'])) { $start = strtotime($s['paymentSchedule']['startDate']); }
            elseif (isset($s['createTimeStampUTC'])) { $start = strtotime($s['createTimeStampUTC']); }
            if (!$from_ts && !$to_ts) return true;
            if ($start === false || $start === null) return false;
            if ($from_ts && $start < $from_ts) return false;
            if ($to_ts && $start > $to_ts) return false;
            return true;
        };
        $act = array_values(array_filter($segments['active'], $filter));
        $ina = array_values(array_filter($segments['inactive'], $filter));
        $sus = array_values(array_filter($segments['suspended'], $filter));
        $pd  = array_values(array_filter($segments['past_due'], $filter));
        $active_count = count($act);
        $inactive_count = count($ina);
        $suspended_count = count($sus);
        $past_due_count = count($pd);
        $total = $active_count + $inactive_count + $suspended_count + $past_due_count;
        $active_mrr = 0.0;
        $amounts_for_hist = array();
        $interval_counts = array('weekly'=>0,'monthly'=>0,'annual'=>0,'other'=>0);
        foreach ($act as $s) {
            $amt = isset($s['paymentSchedule']['amount']) ? floatval($s['paymentSchedule']['amount']) : (isset($s['amount']) ? floatval($s['amount']) : 0.0);
            $intervalLength = isset($s['paymentSchedule']['interval']['length']) ? intval($s['paymentSchedule']['interval']['length']) : 0;
            $intervalUnit = isset($s['paymentSchedule']['interval']['unit']) ? strtolower($s['paymentSchedule']['interval']['unit']) : '';
            if ($intervalUnit === 'days') {
                if ($intervalLength == 7) { $interval_counts['weekly']++; }
                elseif ($intervalLength == 30) { $interval_counts['monthly']++; }
                else { $interval_counts['other']++; }
                $monthly_equiv = $amt * (30 / max(1,$intervalLength));
            } elseif ($intervalUnit === 'weeks') {
                if ($intervalLength == 1) { $interval_counts['weekly']++; } else { $interval_counts['other']++; }
                $monthly_equiv = $amt * (4.34524 / max(1,$intervalLength));
            } elseif ($intervalUnit === 'months') {
                if ($intervalLength == 1) { $interval_counts['monthly']++; }
                elseif ($intervalLength == 12) { $interval_counts['annual']++; }
                else { $interval_counts['other']++; }
                $monthly_equiv = $amt / max(1,$intervalLength);
            } elseif ($intervalUnit === 'years') {
                if ($intervalLength == 1) { $interval_counts['annual']++; } else { $interval_counts['other']++; }
                $monthly_equiv = $amt / (12 * max(1,$intervalLength));
            } else {
                $interval_counts['other']++;
                $monthly_equiv = $amt;
            }
            $active_mrr += $monthly_equiv;
            $amounts_for_hist[] = $amt;
        }
        $edges = array(0,5,10,20,30,50,75,100,150,200,300,500,1000,2000);
        $labels = array();
        $counts = array();
        for ($i=0;$i<count($edges);$i++){
            $low = $edges[$i];
            $high = ($i+1<count($edges)) ? $edges[$i+1] : null;
            if ($high===null) { $labels[] = '$'.number_format($low).'+'; }
            else { $labels[] = '$'.number_format($low).'–$'.number_format($high); }
            $counts[] = 0;
        }
        foreach ($amounts_for_hist as $a) {
            $placed = false;
            for ($i=0;$i<count($edges);$i++){
                $low = $edges[$i];
                $high = ($i+1<count($edges)) ? $edges[$i+1] : null;
                if ($high===null) { if ($a >= $low) { $counts[$i]++; $placed=true; break; } }
                else { if ($a >= $low && $a < $high) { $counts[$i]++; $placed=true; break; } }
            }
            if (!$placed) { $counts[0]++; }
        }
        return array(
            'active' => $active_count,
            'inactive' => $inactive_count,
            'suspended' => $suspended_count,
            'past_due' => $past_due_count,
            'total' => $total,
            'active_percent' => $total ? round(($active_count / $total) * 100, 2) : 0,
            'inactive_percent' => $total ? round(($inactive_count / $total) * 100, 2) : 0,
            'active_mrr' => round($active_mrr, 2),
            'refreshed_at' => current_time('mysql'),
            'hist_labels' => $labels,
            'hist_counts' => $counts,
            'interval_counts' => $interval_counts,
        );
    }
    public function get_stats() {
        $cache_key = 'ani_stats_cache';
        $ttl = (int) ($this->settings['cache_ttl'] ?? 900);
        $cached = get_transient($cache_key);
        if ($cached) { return $cached; }
        $segments = $this->get_segments();
        if ( is_wp_error($segments) ) { return $segments; }
        $stats = $this->compute_stats($segments);
        set_transient($cache_key, $stats, $ttl);
        return $stats;
    }
    public function refresh_all_caches() {
        delete_transient('ani_stats_cache');
        $stats = $this->get_stats();
        $series = get_option('ani_timeseries', array());
        $today = current_time('Y-m-d');
        $last = end($series);
        if (!$last || (isset($last['date']) ? $last['date'] : '') !== $today) {
            $series[] = array(
                'date' => $today,
                'active' => isset($stats['active']) ? intval($stats['active']) : 0,
                'inactive' => isset($stats['inactive']) ? intval($stats['inactive']) : 0,
                'suspended' => isset($stats['suspended']) ? intval($stats['suspended']) : 0,
                'past_due' => isset($stats['past_due']) ? intval($stats['past_due']) : 0,
                'total' => isset($stats['total']) ? intval($stats['total']) : 0,
            );
            if (count($series) > 400) { $series = array_slice($series, -400); }
            update_option('ani_timeseries', $series, false);
        }
    }
}
