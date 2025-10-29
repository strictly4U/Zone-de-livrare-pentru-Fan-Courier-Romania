<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Healthcheck {
    const PAGE_SLUG = 'fc-healthcheck';
    const NONCE     = 'hgezlpfcr_hc_nonce';
    const GROUP     = 'woo-fancourier';

    public static function init() {
        // Check if healthcheck is enabled in settings
        if (HGEZLPFCR_Settings::get('hgezlpfcr_enable_healthcheck', 'no') !== 'yes') {
            // Healthcheck is disabled, don't register menu
            HGEZLPFCR_Logger::log('FC_Healthcheck disabled in settings');
            return;
        }

        add_action('admin_menu', [__CLASS__, 'menu'], 99);
        add_action('admin_post_fc_hc_action', [__CLASS__, 'handle_action']);

        // Also try with a different hook to ensure it works
        add_action('woocommerce_admin_menu', [__CLASS__, 'menu']);

        // Debug: log that healthcheck is initialized
        HGEZLPFCR_Logger::log('FC_Healthcheck initialized');
    }

    public static function menu() {
        // Add to WooCommerce submenu
        $hookname = add_submenu_page(
            'woocommerce',
            __('Fan Courier – Healthcheck', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            __('Fan Courier – Healthcheck', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [__CLASS__, 'render']
        );

        // Debug: log that menu was added
        HGEZLPFCR_Logger::log('FC_Healthcheck menu added', [
            'hookname' => $hookname,
            'page_slug' => self::PAGE_SLUG
        ]);
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) return;
        $env   = self::env_info();
        $conf  = self::config_info();
        $cron  = self::cron_info();
        $queue = self::queue_info();
        $stats = self::tracking_stats();
        $orders_wo_awb = self::recent_orders_without_awb();

        echo '<div class="wrap"><h1>Fan Courier – Healthcheck</h1>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page display only, data is sanitized
        if (!empty($_GET['msg'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page display only, data is sanitized
            $type = sanitize_key($_GET['type'] ?? 'updated');
            // Sanitize THEN escape
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page display only, data is sanitized and escaped
            $msg = sanitize_text_field(wp_unslash($_GET['msg']));
            echo '<div class="'.esc_attr($type==='error'?'notice notice-error':'notice notice-success').'"><p>'.esc_html($msg).'</p></div>';
        }

        // Actions
        echo '<h2>Acțiuni rapide</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="action" value="hgezlpfcr_hc_action">';
        echo '<p>';
        submit_button('Ping API', 'secondary', 'hc_action_ping', false);
        submit_button('Test credențiale API', 'secondary', 'hc_action_creds', false);
                 submit_button('Forțează sincronizare AWB', 'secondary', 'hc_action_force_sync', false);
         submit_button('Verifică AWB-uri șterse', 'secondary', 'hc_action_check_deleted_awbs', false);
         submit_button('Șterge AWB-uri inexistente în FanCourier', 'delete', 'hc_action_force_delete_all', false);
         submit_button('Curăță lock-uri expirate', 'secondary', 'hc_action_clear_locks', false);
         submit_button('Resetează markeri ștergere AWB (pentru testare)', 'secondary', 'hc_action_reset_deletion_markers', false);
        echo '</p></form>';

        // Environment
        echo '<h2>Mediu de execuție</h2><table class="widefat striped"><tbody>';
        foreach ($env as $k=>$v) echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html($v).'</td></tr>';
        echo '</tbody></table>';

        // Config
        echo '<h2>Config & Setări</h2><table class="widefat striped"><tbody>';
        foreach ($conf as $k=>$v) echo '<tr><th>'.esc_html($k).'</th><td>'.wp_kses_post($v).'</td></tr>';
        echo '</tbody></table>';

        // Queue only (no more CRON)
        echo '<h2>Action Scheduler (Sincronizare Manuală)</h2><table class="widefat striped"><tbody>';
        if (!empty($cron)) {
            foreach ($cron as $k=>$v) echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html($v).'</td></tr>';
        }
        foreach ($queue as $k=>$v) {
            if ($k === 'Detalii task-uri programate' || $k === 'Task-uri recente') {
                echo '<tr><th>'.esc_html($k).'</th><td style="max-width: 600px; word-wrap: break-word;">'.wp_kses_post($v).'</td></tr>';
            } else {
                echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html($v).'</td></tr>';
            }
        }
        echo '</tbody></table>';
        
        // Add button to force run pending tasks
        if (function_exists('as_get_scheduled_actions')) {
            $pending_count = count(as_get_scheduled_actions([
                'group'   => self::GROUP,
                'status'  => 'pending',
                'per_page'=> 1,
            ], 'ids'));
            
            if ($pending_count > 0) {
                echo '<p><strong>Acțiuni disponibile:</strong></p>';
                echo '<form method="post" style="margin: 10px 0;">';
                wp_nonce_field('hgezlpfcr_health_check_actions', 'hgezlpfcr_health_nonce');
                echo '<button type="submit" name="hgezlpfcr_action" value="run_pending_tasks" class="button button-secondary">';
                echo 'Forțează execuția task-urilor în așteptare (' . absint($pending_count) . ')';
                echo '</button>';
                echo '</form>';
            }
        }

        // Tracking stats
        echo '<h2>Tracking AWB</h2><table class="widefat striped"><tbody>';
        foreach ($stats as $k=>$v) echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html($v).'</td></tr>';
        echo '</tbody></table>';

        // Orders without AWB
        echo '<h2>Comenzi recente fără AWB (ultimele 48h)</h2>';
        if (empty($orders_wo_awb)) {
            echo '<p>Toate comenzile recente au AWB sau nu îndeplinesc condițiile.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Data</th><th>Status</th><th>Total</th></tr></thead><tbody>';
            foreach ($orders_wo_awb as $o) {
                $edit = admin_url('post.php?post='.$o['id'].'&action=edit');
                echo '<tr><td><a href="'.esc_url($edit).'">'.esc_html($o['id']).'</a></td><td>'.esc_html($o['date']).'</td><td>'.esc_html($o['status']).'</td><td>'.esc_html(wc_price($o['total'])).'</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Logs hint
        echo '<p style="margin-top:16px;">Loguri: WooCommerce → Status → Logs → selectează sursa <code>woo-fancourier</code>. ';
        echo 'Activează „Debug log” în tab-ul de setări Fan Courier pentru mai multe detalii.</p>';

        echo '</div>';
    }

    public static function handle_action() {
        if (!current_user_can('manage_woocommerce')) wp_die('Permisiuni insuficiente.');
        // Sanitize nonce before verification
        $nonce = isset($_POST[self::NONCE]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) wp_die('Nonce invalid.');
        $msg = ''; $type = 'updated';

        if (isset($_POST['hc_action_ping'])) {
            $api = new HGEZLPFCR_API_Client();
            $res = $api->ping();
            if (is_wp_error($res)) { $msg = 'Ping eșuat: '.$res->get_error_message(); $type='error'; }
            else { $msg = 'Ping OK.'; }
        } elseif (isset($_POST['hc_action_creds'])) {
            $ok = self::validate_credentials();
            if (is_wp_error($ok)) { $msg = 'Credențiale invalide: '.$ok->get_error_message(); $type='error'; }
            else { $msg = 'Credențiale par valide (răspuns API OK).'; }
                 } elseif (isset($_POST['hc_action_force_sync'])) {
             // Forțează sincronizarea pentru toate comenzile cu AWB care nu au status sincronizat recent
             $orders = wc_get_orders([
                 'status'   => ['wc-processing','wc-completed','wc-on-hold'],
                 'limit'    => 50,
                 'orderby'  => 'date',
                 'order'    => 'DESC',
                 'return'   => 'ids',
                 'type'     => 'shop_order',
                 'meta_key' => HGEZLPFCR_Admin_Order::META_AWB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WC optimized query
             ]);
             
             $synced = 0;
             foreach ($orders as $oid) {
                 $order = wc_get_order($oid);
                 if ($order) {
                     // Forțează sincronizarea imediată
                     HGEZLPFCR_Admin_Order::sync_status_for_order($oid, false);
                     $synced++;
                 }
                          }
             $msg = 'Sincronizare forțată pentru '.$synced.' comenzi.';
         } elseif (isset($_POST['hc_action_check_deleted_awbs'])) {
             // Verifică și curăță AWB-urile șterse din FanCourier
             $orders = wc_get_orders([
                 'status'   => ['wc-processing','wc-completed','wc-on-hold'],
                 'limit'    => 100,
                 'orderby'  => 'date',
                 'order'    => 'DESC',
                 'return'   => 'ids',
                 'type'     => 'shop_order',
                 'meta_key' => HGEZLPFCR_Admin_Order::META_AWB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WC optimized query
             ]);
             
             $deleted_count = 0;
             $api = new HGEZLPFCR_API_Client();
             
             foreach ($orders as $oid) {
                 $order = wc_get_order($oid);
                 if ($order) {
                     $awb = $order->get_meta(HGEZLPFCR_Admin_Order::META_AWB);
                     if ($awb) {
                         // Get AWB generation date with timezone adjustment
                         $generation_date = $order->get_meta(HGEZLPFCR_Admin_Order::META_AWB_DATE);
                         if (!$generation_date) {
                             // Fallback: try to get date from history or use adjusted current date
                             $generation_date = gmdate('Y-m-d', time() + (3 * 3600)); // FanCourier server timezone +3h
                         } else {
                             // Adjust existing date for FanCourier timezone
                             $timestamp = strtotime($generation_date);
                             if ($timestamp !== false) {
                                 $generation_date = gmdate('Y-m-d', $timestamp + (3 * 3600)); // Add 3 hours
                             }
                         }
                         
                         // Log the check for debugging
                         HGEZLPFCR_Logger::log('HealthCheck: Checking AWB existence', [
                             'order_id' => $oid,
                             'awb' => $awb,
                             'generation_date' => $generation_date,
                             'local_date' => gmdate('Y-m-d'),
                             'timezone_adjustment' => '+3 hours for FanCourier server'
                         ]);
                         
                         // Verifică dacă AWB-ul există în FanCourier folosind data corectă
                         $awb_exists = $api->check_awb_exists($awb, $generation_date);
                         
                         // Dacă AWB-ul nu există, îl ștergem din comandă folosind metoda forțată
                         if ($awb_exists === false) {
                             $delete_success = HGEZLPFCR_Admin_Order::force_delete_awb_from_order($oid, 'HealthCheck: AWB nu există în FanCourier');
                             
                             if ($delete_success) {
                                 $deleted_count++;
                             } else {
                                 HGEZLPFCR_Logger::error('HealthCheck: Failed to force delete AWB from order', [
                                     'order_id' => $oid,
                                     'awb' => $awb
                                 ]);
                             }
                         } elseif (is_wp_error($awb_exists)) {
                             // Log API errors but don't delete AWB (might be temporary error)
                             HGEZLPFCR_Logger::log('HealthCheck: AWB existence check failed', [
                                 'order_id' => $oid,
                                 'awb' => $awb,
                                 'error' => $awb_exists->get_error_message()
                             ]);
                         }
                     }
                 }
             }
             $msg = 'Verificare completă. ' . $deleted_count . ' AWB-uri șterse din FanCourier au fost curățate din comenzi.';
         } elseif (isset($_POST['hc_action_force_delete_all'])) {
            // Șterge AWB-urile care nu există în FanCourier din toate comenzile
            $orders = wc_get_orders([
                'status'   => 'any',
                'limit'    => -1, // toate
                'return'   => 'ids',
                'type'     => 'shop_order',
                'meta_key' => HGEZLPFCR_Admin_Order::META_AWB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WC optimized query
            ]);
            
            $deleted_count = 0;
            $kept_count = 0;
            $error_count = 0;
            
            foreach ($orders as $oid) {
                $order = wc_get_order($oid);
                if ($order) {
                    $awb = $order->get_meta(HGEZLPFCR_Admin_Order::META_AWB);
                    if ($awb) {
                        $delete_result = HGEZLPFCR_Admin_Order::force_delete_awb_from_order($oid, 'HealthCheck: Curățare AWB inexistente în FanCourier');
                        
                        // Check if AWB was actually deleted by re-checking
                        $order_after = wc_get_order($oid);
                        $awb_after = $order_after ? $order_after->get_meta(HGEZLPFCR_Admin_Order::META_AWB) : null;
                        
                        if (empty($awb_after)) {
                            $deleted_count++; // AWB was deleted (didn't exist in FanCourier)
                        } elseif ($delete_result === true) {
                            $kept_count++; // AWB was kept (exists in FanCourier)
                        } else {
                            $error_count++; // Error occurred
                        }
                    }
                }
            }
            $msg = 'Curățare completă! ' . $deleted_count . ' AWB-uri inexistente în FanCourier au fost șterse din comenzi. ' . $kept_count . ' AWB-uri valide au fost păstrate. ' . ($error_count > 0 ? $error_count . ' erori.' : '');

        } elseif (isset($_POST['hc_action_clear_locks'])) {
            global $wpdb;
            // Curăță transiente fc_awb_lock_* expirate – WordPress le curăță oricum, dar putem forța
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of transient locks requires direct query, caching not applicable for DELETE
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_fc_awb_lock_') . '%',
                    $wpdb->esc_like('_transient_timeout_fc_awb_lock_') . '%'
                )
            );
            $msg = 'Lock-uri curățate.';
        } elseif (isset($_POST['hc_action_reset_deletion_markers'])) {
            // Resetează markerii de ștergere AWB din istoric pentru testare
            $orders = wc_get_orders([
                'status'   => 'any',
                'limit'    => 100,
                'orderby'  => 'date',
                'order'    => 'DESC',
                'return'   => 'ids',
                'type'     => 'shop_order',
                'meta_key' => HGEZLPFCR_Admin_Order::META_HISTORY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WC optimized query
            ]);
            
            $reset_count = 0;
            foreach ($orders as $oid) {
                $order = wc_get_order($oid);
                if ($order) {
                    $history = $order->get_meta(HGEZLPFCR_Admin_Order::META_HISTORY);
                    if (is_array($history) && !empty($history)) {
                        $modified = false;
                        // Filtrează entrarile cu markeri de ștergere
                        $filtered_history = array_filter($history, function($entry) use (&$modified) {
                            if (isset($entry['action']) && (
                                strpos($entry['action'], 'AWB Șters') !== false ||
                                strpos($entry['action'], 'Inexistent FanCourier') !== false ||
                                strpos($entry['action'], 'Șters din Borderou') !== false
                            )) {
                                $modified = true;
                                return false; // Exclude această intrare
                            }
                            return true; // Păstrează această intrare
                        });
                        
                        if ($modified) {
                            $order->update_meta_data(HGEZLPFCR_Admin_Order::META_HISTORY, array_values($filtered_history));
                            $order->save_meta_data();
                            $reset_count++;
                        }
                    }
                }
            }
            $msg = 'Resetare completă! Markeri de ștergere AWB eliminați din istoricul a ' . $reset_count . ' comenzi.';
        } elseif (isset($_POST['hgezlpfcr_action']) && sanitize_key($_POST['hgezlpfcr_action']) === 'run_pending_tasks') {
            if (function_exists('as_get_scheduled_actions')) {
                // Get all pending tasks
                $pending_actions = as_get_scheduled_actions([
                    'group'   => self::GROUP,
                    'status'  => 'pending',
                    'per_page'=> 50,
                    'orderby' => 'date',
                    'order'   => 'ASC',
                ], 'objects');
                
                $executed = 0;
                foreach ($pending_actions as $action) {
                    // Force execute the action
                    $action->execute();
                    $executed++;
                }
                
                $msg = 'Executat ' . $executed . ' task-uri în așteptare.';
            } else {
                $msg = 'Action Scheduler nu este disponibil.';
                $type = 'error';
            }
        }

        wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG, 'msg'=>rawurlencode($msg), 'type'=>$type], admin_url('admin.php')));
        exit;
    }

    /*** Helpers ***/
    protected static function env_info(): array {
        global $wpdb;
        return [
            'WP Version'       => get_bloginfo('version'),
            'WooCommerce'      => defined('WC_VERSION') ? WC_VERSION : '—',
            'PHP'              => PHP_VERSION,
            'MySQL'            => $wpdb->db_version(),
            'Site URL'         => home_url(),
            'Action Scheduler' => function_exists('as_get_scheduled_actions') ? 'Disponibil' : 'Indisponibil',
        ];
    }

    protected static function config_info(): array {
        $has_user   = (bool) HGEZLPFCR_Settings::get('hgezlpfcr_user');
        $has_pass   = (bool) HGEZLPFCR_Settings::get('hgezlpfcr_pass');
        $has_client = (bool) HGEZLPFCR_Settings::get('hgezlpfcr_client');
        $has_key    = (bool) HGEZLPFCR_Settings::get('hgezlpfcr_key');

        // Auto AWB status with configured statuses
        $auto_awb_enabled = HGEZLPFCR_Settings::yes('hgezlpfcr_auto_awb_enabled');
        $auto_awb_statuses = get_option('hgezlpfcr_auto_awb_statuses', []);
        if ($auto_awb_enabled && !empty($auto_awb_statuses)) {
            $statuses_display = implode(', ', array_map(function($status) {
                $all_statuses = wc_get_order_statuses();
                $status_key = 'wc-' . $status;
                return isset($all_statuses[$status_key]) ? $all_statuses[$status_key] : $status;
            }, $auto_awb_statuses));
            $auto_awb = 'Activ pentru: ' . $statuses_display;
        } else {
            $auto_awb = 'Inactiv';
        }

        $async      = HGEZLPFCR_Settings::yes('hgezlpfcr_async') ? 'Activ' : 'Inactiv';
        $debug      = HGEZLPFCR_Settings::yes('hgezlpfcr_debug') ? 'Activ' : 'Inactiv';
        $timeout    = (int) HGEZLPFCR_Settings::get('hgezlpfcr_timeout', 20);
        $retries    = (int) HGEZLPFCR_Settings::get('hgezlpfcr_retries', 2);

        $shipping_ok = self::has_fc_method_in_any_zone()
            ? '<span style="color:green">OK – Metoda <b>Fan Courier Standard</b> este adăugată în cel puțin o zonă</span>'
            : '<span style="color:#b45309">Atenție – Adaugă metoda <b>Fan Courier Standard</b> într-o Shipping Zone</span>';

        return [
            'Setări API'        => sprintf('user:%s, pass:%s, client:%s, key:%s',
                                  $has_user?'✅':'❌', $has_pass?'✅':'❌', $has_client?'✅':'❌', $has_key?'✅':'❌'),
            'Generare AWB Automată' => $auto_awb,
            'Execuție asincronă'     => $async,
            'Retry/Timeout API'      => $retries . ' / ' . $timeout . 's',
            'Metodă în Shipping Zones' => $shipping_ok,
            'Debug log'              => $debug,
        ];
    }

    protected static function cron_info(): array {
        $info = [];
        
        // Check Action Scheduler cron
        if (function_exists('as_get_scheduled_actions')) {
            $next_action = as_get_scheduled_actions([
                'group'   => self::GROUP,
                'status'  => 'pending',
                'per_page'=> 1,
                'orderby' => 'date',
                'order'   => 'ASC',
            ], 'objects');
            
                         if (!empty($next_action)) {
                 $next_action = $next_action[0];
                 $scheduled_date = null;
                 if (method_exists($next_action, 'get_schedule')) {
                     $schedule = $next_action->get_schedule();
                     if ($schedule && method_exists($schedule, 'get_date')) {
                         $scheduled_date = $schedule->get_date();
                     }
                 }
                 
                 $date_str = 'Necunoscut';
                 if ($scheduled_date) {
                     if (is_object($scheduled_date) && method_exists($scheduled_date, 'format')) {
                         $date_str = $scheduled_date->format('Y-m-d H:i:s');
                     } elseif (is_string($scheduled_date)) {
                         $date_str = $scheduled_date;
                     }
                 }
                 
                 $info['Următorul task programat'] = $date_str;
             } else {
                 $info['Următorul task programat'] = 'Nu există task-uri în coadă';
             }
        }
        
        // Check WordPress cron status
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $info['WordPress CRON'] = $cron_disabled ? 'Dezactivat (DISABLE_WP_CRON)' : 'Activat';
        
        return $info;
    }

    protected static function queue_info(): array {
        $counts = ['pending'=>0,'in-progress'=>0,'failed'=>0,'complete'=>0];
        $details = [];

        if (function_exists('as_get_scheduled_actions')) {
            foreach (['pending','in-progress','failed','complete'] as $status) {
                $found = as_get_scheduled_actions([
                    'group'   => self::GROUP,
                    'status'  => $status,
                    'per_page'=> 1,
                    'offset'  => 0,
                ], 'ids');
                // as_get_scheduled_actions cu count e costisitor; aici doar probăm existența
                $counts[$status] = is_array($found) ? count($found) : 0;
            }

            // Get detailed info for pending actions
            $pending_actions = as_get_scheduled_actions([
                'group'   => self::GROUP,
                'status'  => 'pending',
                'per_page'=> 10,
                'orderby' => 'date',
                'order'   => 'ASC',
            ], 'objects');

                         if (!empty($pending_actions)) {
                 $details[] = '<strong>Task-uri programate (următoarele 10):</strong>';
                 foreach ($pending_actions as $action) {
                     $scheduled_date = null;
                     if (method_exists($action, 'get_schedule')) {
                         $schedule = $action->get_schedule();
                         if ($schedule && method_exists($schedule, 'get_date')) {
                             $scheduled_date = $schedule->get_date();
                         }
                     }
                     
                     // Get action details safely
                     $action_name = 'unknown';
                     if (method_exists($action, 'get_hook')) {
                         $action_name = $action->get_hook();
                     }
                     
                     $args = [];
                     if (method_exists($action, 'get_args')) {
                         $args = $action->get_args();
                     }
                     
                     $date_str = 'Necunoscut';
                     if ($scheduled_date) {
                         if (is_object($scheduled_date) && method_exists($scheduled_date, 'format')) {
                             $date_str = $scheduled_date->format('Y-m-d H:i:s');
                         } elseif (is_string($scheduled_date)) {
                             $date_str = $scheduled_date;
                         }
                     }
                     
                     $details[] = sprintf(
                         '• %s - Programat pentru: %s - Args: %s',
                         $action_name,
                         $date_str,
                         json_encode($args)
                     );
                 }
             }
        }
        
        $info = [
            'Action Scheduler (grup woo-fancourier)' =>
                sprintf('pending:%d, in-progress:%d, failed:%d, complete:%d',
                        $counts['pending'], $counts['in-progress'], $counts['failed'], $counts['complete']),
        ];
        
        if (!empty($details)) {
            $info['Detalii task-uri programate'] = implode('<br>', $details);
        }
        
        // Get recent completed and failed actions
        if (function_exists('as_get_scheduled_actions')) {
            $recent_actions = as_get_scheduled_actions([
                'group'   => self::GROUP,
                'status'  => ['complete', 'failed'],
                'per_page'=> 5,
                'orderby' => 'date',
                'order'   => 'DESC',
            ], 'objects');
            
                         if (!empty($recent_actions)) {
                 $recent_details = ['<strong>Task-uri recente (ultimele 5):</strong>'];
                 foreach ($recent_actions as $action) {
                     // Use the correct method to get the date based on action type
                     $completed_date = null;
                     if (method_exists($action, 'get_date')) {
                         $completed_date = $action->get_date();
                     } elseif (method_exists($action, 'get_schedule')) {
                         $schedule = $action->get_schedule();
                         if ($schedule && method_exists($schedule, 'get_date')) {
                             $completed_date = $schedule->get_date();
                         }
                     }
                     
                     // Get action details safely
                     $action_name = 'unknown';
                     if (method_exists($action, 'get_hook')) {
                         $action_name = $action->get_hook();
                     }
                     
                     $args = [];
                     if (method_exists($action, 'get_args')) {
                         $args = $action->get_args();
                     }
                     
                     // Get status safely
                     $status = 'unknown';
                     if (method_exists($action, 'get_status')) {
                         $status = $action->get_status();
                     }
                     
                     $date_str = 'Necunoscut';
                     if ($completed_date) {
                         if (is_object($completed_date) && method_exists($completed_date, 'format')) {
                             $date_str = $completed_date->format('Y-m-d H:i:s');
                         } elseif (is_string($completed_date)) {
                             $date_str = $completed_date;
                         }
                     }
                     
                     $status_text = ($status === 'complete') ? 'Completat' : (($status === 'failed') ? 'Eșuat' : 'Necunoscut');
                     
                     $recent_details[] = sprintf(
                         '• %s - %s la: %s - Args: %s',
                         $action_name,
                         $status_text,
                         $date_str,
                         json_encode($args)
                     );
                 }
                 $info['Task-uri recente'] = implode('<br>', $recent_details);
             }
        }
        
        return $info;
    }

    protected static function tracking_stats(): array {
        // ultimele 24h: câte comenzi cu AWB au status sincronizat recent
        $orders = wc_get_orders([
            'status'   => ['wc-processing','wc-completed','wc-on-hold'],
            'limit'    => 20,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
            'type'     => 'shop_order',
            'meta_key' => HGEZLPFCR_Admin_Order::META_AWB, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WC optimized query
        ]);
        $with_status = 0;
        foreach ($orders as $o) {
            if ($o->get_meta(HGEZLPFCR_Admin_Order::META_STAT)) $with_status++;
        }
        return [
            'Probe comenzi recente (max 20) cu AWB' => count($orders),
            'Au status sincronizat' => $with_status,
        ];
    }

    protected static function recent_orders_without_awb(): array {
        $ids = wc_get_orders([
            'status'      => ['wc-processing','wc-completed','wc-on-hold'],
            'limit'       => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'return'      => 'ids',
            'type'        => 'shop_order',
            'date_created'=> '>' . (new DateTime('-48 hours'))->format('Y-m-d H:i:s'),
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WC optimized query with NOT EXISTS
            'meta_query'  => [
                ['key' => HGEZLPFCR_Admin_Order::META_AWB, 'compare' => 'NOT EXISTS'],
            ],
        ]);
        $out = [];
        foreach ($ids as $id) {
            $o = wc_get_order($id);
            
            // Verifică dacă comanda are AWB în meta data sau în istoric
            $awb = $o->get_meta(HGEZLPFCR_Admin_Order::META_AWB);
            if (!$awb) {
                // Verifică istoricul AWB
                $history = $o->get_meta(HGEZLPFCR_Admin_Order::META_HISTORY);
                if (is_array($history) && !empty($history)) {
                    // Caută ultimul AWB din istoric
                    foreach (array_reverse($history) as $entry) {
                        if (isset($entry['action']) && in_array($entry['action'], ['AWB Generat', 'AWB Șters'])) {
                            // Dacă ultima acțiune este "AWB Șters", comanda nu are AWB valid
                            if ($entry['action'] === 'AWB Șters') {
                                break;
                            }
                            // Dacă ultima acțiune este "AWB Generat", comanda are AWB valid
                            if ($entry['action'] === 'AWB Generat') {
                                $awb = 'exists_in_history';
                                break;
                            }
                        }
                    }
                }
            }
            
            // Adaugă comanda doar dacă nu are AWB valid
            if (!$awb) {
                $out[] = [
                    'id'     => (int) $id,
                    'date'   => $o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d H:i') : '—',
                    'status' => wc_get_order_status_name($o->get_status()),
                    'total'  => (float) $o->get_total(),
                ];
            }
        }
        return $out;
    }

    protected static function validate_credentials() {
        $api = new HGEZLPFCR_API_Client();
        $res = $api->ping(); // ideal un endpoint foarte ieftin; dacă nu există, poți face un call trivial protejat
        return $res instanceof WP_Error ? $res : true;
    }

    /** detectează dacă metoda fc_standard e prezentă în vreo zonă */
    protected static function has_fc_method_in_any_zone(): bool {
        if (!class_exists('WC_Shipping_Zones')) return false;
        $zones = WC_Shipping_Zones::get_zones();
        foreach ($zones as $z) {
            foreach ($z['shipping_methods'] as $m) {
                if (!empty($m->id) && $m->id === 'fc_standard' && $m->enabled === 'yes') {
                    return true;
                }
            }
        }
        return false;
    }
}
