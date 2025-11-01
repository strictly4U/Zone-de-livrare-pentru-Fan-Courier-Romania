<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Admin_Order {
    const META_AWB      = '_fc_awb_number';
    const META_STAT     = '_fc_awb_status';
    const META_LOCK     = '_fc_awb_lock'; // idempotency lock
    const META_HISTORY  = '_fc_awb_history'; // AWB action history
    const META_AWB_DATE = '_fc_awb_generation_date'; // AWB generation date
    const LOCK_TTL      = 300; // 5 min
    
    /**
     * Get current date adjusted for FanCourier server timezone (+3 hours)
     * FanCourier server is 3 hours ahead of our server
     */
    protected static function get_fancourier_date(): string {
        return gmdate('Y-m-d', time() + (3 * 3600)); // Add 3 hours
    }
    
    /**
     * Adjust any date to FanCourier server timezone
     */
    protected static function adjust_date_for_fancourier(string $date): string {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return self::get_fancourier_date(); // Fallback to current date
        }
        return gmdate('Y-m-d', $timestamp + (3 * 3600)); // Add 3 hours
    }

    public static function init() {
        // Meta boxes pe ecranul clasic al comenzii
        add_action('add_meta_boxes_shop_order', [__CLASS__, 'add_awb_meta_boxes'], 35);

        // Meta boxes pe ecranul HPOS (dacă e activ)
        if (function_exists('wc_get_page_screen_id')) {
            add_action('add_meta_boxes_' . wc_get_page_screen_id('shop-order'), [__CLASS__, 'add_awb_meta_boxes'], 35);
        }

        // Add meta boxes with safer implementation and priority
        // add_action('add_meta_boxes', [__CLASS__, 'add_awb_meta_boxes'], 35);

        // Actions via POST - keep these active
        add_action('admin_post_fc_generate_awb', [__CLASS__, 'handle_generate_awb']);
        add_action('admin_post_fc_download_awb', [__CLASS__, 'handle_download_pdf']);
        add_action('admin_post_fc_sync_awb',     [__CLASS__, 'handle_sync_status']);
        
        // Add AWB column and actions to orders list (both classic and HPOS)
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_awb_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'populate_awb_column'], 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', [__CLASS__, 'add_awb_column']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'populate_awb_column_hpos'], 10, 2);
        
        // Bulk actions for both classic and HPOS
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'add_bulk_awb_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [__CLASS__, 'handle_bulk_awb_action'], 10, 3);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'add_bulk_awb_action']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'handle_bulk_awb_action'], 10, 3);
        
        // AJAX handlers
        add_action('wp_ajax_hgezlpfcr_generate_awb_ajax', [__CLASS__, 'handle_generate_awb_ajax']);
        add_action('wp_ajax_hgezlpfcr_sync_awb', [__CLASS__, 'handle_sync_awb_ajax']);
        
        // Async action handlers
        add_action('hgezlpfcr_generate_awb_async', [__CLASS__, 'create_awb_for_order_async']);
        add_action('hgezlpfcr_sync_awb_async', [__CLASS__, 'sync_status_for_order_async']);
        add_action('hgezlpfcr_restore_awb_async', [__CLASS__, 'restore_awb_from_history_async']);
        
        // Admin notices
        add_action('admin_notices', [__CLASS__, 'bulk_awb_admin_notice']);
        add_action('admin_notices', [__CLASS__, 'individual_awb_admin_notice']);
        
        // Enqueue scripts for AJAX
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        
                 // Add test actions for debugging
         add_action('admin_post_fc_clear_token', [__CLASS__, 'handle_clear_token']);
         add_action('admin_post_fc_reset_order_markers', [__CLASS__, 'handle_reset_order_markers']);
    }

    public static function add_awb_meta_boxes() {
        $screen = get_current_screen();
        if (!$screen) return;
        
        // Only add meta boxes on order edit pages
        if (in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'])) {
            add_meta_box(
                'hgezlpfcr_awb_actions',
                'Fan Courier – AWB & Acțiuni',
                [__CLASS__, 'render_awb_actions_box'],
                $screen->id,
                'side',
                'high'
            );

            add_meta_box(
                'hgezlpfcr_awb_history',
                'Fan Courier – Istoric AWB',
                [__CLASS__, 'render_awb_history_box'],
                $screen->id,
                'normal',
                'default'
            );
        }
    }

    public static function render_box($post) {
        $order = wc_get_order($post->ID);
        $awb   = $order->get_meta(self::META_AWB);
        $stat  = $order->get_meta(self::META_STAT);
        $order_status = $order->get_status();

        // Check if AWB generation is allowed for this status
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $can_generate_awb = in_array($order_status, $allowed_statuses);

        echo '<p><strong>AWB:</strong> '.esc_html($awb ?: '—').'</p>';
        echo '<p><strong>Status:</strong> '.esc_html($stat ?: '—').'</p>';

        if (!$can_generate_awb && !$awb) {
            echo '<p><em>Generarea AWB este disponibilă pentru status-urile:</em></p>';
            echo '<ul style="font-size:11px;margin-left:15px;">';
            echo '<li>Comandă nouă</li>';
            echo '<li>Completed</li>';
            echo '<li>Plată confirmată</li>';
            echo '<li>Emite factură Avans</li>';
            echo '</ul>';
        } elseif (!$awb) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('hgezlpfcr_awb_actions', 'hgezlpfcr_awb_nonce');
            echo '<input type="hidden" name="post_id" value="'.esc_attr($post->ID).'">';
            echo '<input type="hidden" name="action" value="fc_generate_awb">';
            echo '<p><button class="button button-primary" type="submit">Generează AWB</button></p>';
            echo '</form>';
        }

        if ($awb) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('hgezlpfcr_awb_actions', 'hgezlpfcr_awb_nonce');
            echo '<input type="hidden" name="post_id" value="'.esc_attr($post->ID).'">';
            echo '<input type="hidden" name="action" value="fc_download_awb">';
            echo '<p><button class="button" type="submit">Descarcă PDF</button></p>';
            echo '</form>';

        }
    }

        public static function render_awb_actions_box($post) {
        // Force fresh data - clear any cached order data safely
        self::clear_order_caches($post->ID);
        
        $order = wc_get_order($post->ID);
        if (!$order) return;
        
        $awb = $order->get_meta(self::META_AWB);
        $status = $order->get_meta(self::META_STAT);
        $order_status = $order->get_status();
        
        // Debug: Log metabox render data
        if ($awb) {
            self::debug_awb_data($post->ID, 'Metabox render - AWB exists');
        }
        
        // Verifică istoricul pentru a determina dacă AWB-ul a fost șters din FanCourier
        $history = $order->get_meta(self::META_HISTORY);
        $awb_was_deleted = false;
        
        // Verifică dacă există o ștergere forțată recentă (în ultimele 5 minute)
        if (is_array($history) && !empty($history)) {
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && strpos($entry['action'], 'AWB Șters') !== false) {
                    $entry_time = isset($entry['timestamp']) ? $entry['timestamp'] : (isset($entry['date']) ? strtotime($entry['date']) : 0);
                    // Dacă ștergerea a fost în ultimele 5 minute și încă există AWB în meta, forțează ștergerea din nou
                    if ($entry_time && (time() - $entry_time) < 300 && $awb) { // 5 minute = 300 secunde
                        HGEZLPFCR_Logger::log('Force cleaning AWB after recent deletion', [
                            'order_id' => $post->ID,
                            'awb' => $awb,
                            'entry_time' => $entry_time,
                            'current_time' => time()
                        ]);
                        self::force_delete_awb_from_order($post->ID, 'Metabox: Curățare după ștergere recentă');
                        // Re-fetch fresh data after force delete
                        self::clear_order_caches($post->ID);
                        $order = wc_get_order($post->ID);
                        $awb = $order->get_meta(self::META_AWB);
                        $status = $order->get_meta(self::META_STAT);
                    }
                    break;
                }
            }
        }
        
        if (is_array($history) && !empty($history)) {
            // Caută ultima acțiune din istoric
            $latest_action = null;
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && in_array($entry['action'], ['AWB Generat', 'AWB Șters'])) {
                    $latest_action = $entry['action'];
                    break;
                }
            }
            
            // Dacă ultima acțiune este "AWB Șters", AWB-ul a fost șters din FanCourier
            if ($latest_action === 'AWB Șters') {
                $awb_was_deleted = true;
                // Șterge AWB-ul din meta data dacă există folosind metoda forțată
                if ($awb) {
                    self::force_delete_awb_from_order($post->ID, 'Metabox cleanup: AWB șters din istoric');
                    $awb = null;
                    $status = null;
                }
            }
        }
        
        // Check if AWB exists in history but not in meta data (doar dacă nu a fost șters)
        if (!$awb && !$awb_was_deleted) {
            $should_restore = true;
            
            if (is_array($history) && !empty($history)) {
                // Caută ultima acțiune din istoric - verifică și ștergerea forțată
                $latest_action = null;
                foreach (array_reverse($history) as $entry) {
                    if (isset($entry['action'])) {
                        // Verifică pentru orice tip de ștergere sau marcaj de ne-restaurare
                        if (in_array($entry['action'], ['AWB Generat', 'AWB Șters', 'AWB Șters (Forțat)']) ||
                            strpos($entry['action'], 'NU RESTAURA') !== false ||
                            strpos($entry['action'], 'Șters Forțat') !== false) {
                            $latest_action = $entry['action'];
                            break;
                        }
                    }
                }
                
                // Dacă ultima acțiune este orice tip de ștergere sau marcaj "NU RESTAURA", nu restaura AWB-ul
                if ($latest_action && (
                    strpos($latest_action, 'AWB Șters') !== false ||
                    strpos($latest_action, 'NU RESTAURA') !== false ||
                    strpos($latest_action, 'Șters Forțat') !== false
                )) {
                    $should_restore = false;
                    HGEZLPFCR_Logger::log('AWB restore blocked due to deletion marker', [
                        'order_id' => $post->ID,
                        'latest_action' => $latest_action
                    ]);
                }
            }
            
            if ($should_restore) {
                // Schedule async AWB restoration instead of blocking UI
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('hgezlpfcr_restore_awb_async', [$order->get_id()], 'woo-fancourier');
                    HGEZLPFCR_Logger::log('AWB restoration scheduled asynchronously', ['order_id' => $order->get_id()]);
                } else {
                    // Fallback: only restore if we have cached result (no API calls)
                    $awb = self::restore_awb_from_history_cached($order);
                }
            }
        }
        
        // Dacă AWB-ul a fost șters din FanCourier, nu îl afișa și permite regenerarea
        if ($awb_was_deleted) {
            $awb = null;
            $status = null;
        }
        
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $can_generate = in_array($order_status, $allowed_statuses);
        
        echo '<div class="fc-awb-actions" data-order-id="' . esc_attr($post->ID) . '">';
        
        if ($awb) {
            echo '<p><strong>AWB:</strong> <code>' . esc_html($awb) . '</code></p>';
            if ($status) {
                echo '<p><strong>Status:</strong> ' . esc_html($status) . '</p>';
            }
            
            echo '<div class="fc-awb-buttons" style="margin: 10px 0;">';
            
            // Download PDF link (GET with nonce)
            $download_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_download_awb',
                'post_id' => $post->ID,
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($download_url) . '" class="button">📄 Descarcă PDF</a> ';
            
            // Sync status button (AJAX)
            $nonce = wp_create_nonce('hgezlpfcr_awb_ajax');
            echo '<button type="button" class="button fc-sync-awb-btn" data-order-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">🔄 Verifică AWB</button>';
            
            echo '</div>';
            
            // Status area for AJAX responses
            echo '<div class="fc-awb-status" style="margin-top: 10px; display: none;"></div>';
            
        } elseif ($can_generate) {
            echo '<p><strong>Status comandă:</strong> ' . esc_html($order_status) . '</p>';
            echo '<p><em>AWB nu a fost generat încă.</em></p>';
            
                                        // Generate AWB button (AJAX)
               echo '<div class="fc-awb-buttons" style="margin: 10px 0;">';
               echo '<button type="button" class="button button-primary fc-generate-awb-btn" data-order-id="' . esc_attr($post->ID) . '">🚚 Generează AWB</button>';
               echo '<div class="fc-awb-status" style="margin-top: 10px; display: none;"></div>';
               echo '</div>';
            
        } else {
            echo '<p><strong>Status comandă:</strong> ' . esc_html($order_status) . '</p>';
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>Generarea AWB nu este disponibilă pentru acest status.</strong><br>';
            echo 'Status-uri permise: Comandă nouă, Completed, Plată confirmată, Emite factură Avans';
            echo '</p></div>';
        }
        
        echo '</div>';
    }

    public static function render_awb_history_box($post) {
        $order = wc_get_order($post->ID);
        if (!$order) return;

        $history = $order->get_meta(self::META_HISTORY);
        if (!$history || !is_array($history)) {
            echo '<p><em>Nu există istoric pentru această comandă.</em></p>';
            return;
        }

        // Check for recent errors (last 24 hours)
        $recent_errors = [];
        $cutoff_time = time() - (24 * 3600);
        foreach ($history as $entry) {
            if (strpos($entry['action'], 'Eroare') !== false && $entry['timestamp'] > $cutoff_time) {
                $recent_errors[] = $entry;
            }
        }

        // Display prominent error notice if there are recent errors
        if (!empty($recent_errors)) {
            echo '<div class="notice notice-error inline" style="margin: 0 0 15px 0; padding: 10px;">';
            echo '<p><strong>⚠️ Atenție: Au fost detectate ' . count($recent_errors) . ' erori la generarea AWB în ultimele 24h</strong></p>';
            echo '<ul style="margin: 5px 0 0 20px;">';
            foreach ($recent_errors as $error) {
                echo '<li><strong>' . esc_html($error['action']) . ':</strong> ' . esc_html($error['details'] ?? 'Fără detalii') . ' <em>(' . esc_html(gmdate('Y-m-d H:i:s', $error['timestamp'])) . ')</em></li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '<div class="fc-awb-history">';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Data & Ora</th>';
        echo '<th>Utilizator</th>';
        echo '<th>Acțiune</th>';
        echo '<th>Status Comandă</th>';
        echo '<th>Detalii</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        // Sort history by timestamp (newest first)
        usort($history, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        foreach ($history as $entry) {
            // Check if this is an error entry
            $is_error = strpos($entry['action'], 'Eroare') !== false;
            $row_class = $is_error ? 'hgezlpfcr-error-row' : '';

            echo '<tr class="' . esc_attr($row_class) . '">';
            echo '<td>' . esc_html(gmdate('Y-m-d H:i:s', $entry['timestamp'])) . '</td>';
            echo '<td>' . esc_html($entry['user']) . '</td>';

            // Add error icon for error entries
            if ($is_error) {
                echo '<td><strong style="color: #d32f2f;">❌ ' . esc_html($entry['action']) . '</strong></td>';
            } else {
                echo '<td><strong>' . esc_html($entry['action']) . '</strong></td>';
            }

            echo '<td>' . esc_html($entry['order_status'] ?? '-') . '</td>';

            // Make error details more prominent
            if ($is_error) {
                echo '<td><span style="color: #c62828; font-weight: 500;">' . esc_html($entry['details'] ?? '-') . '</span></td>';
            } else {
                echo '<td>' . esc_html($entry['details'] ?? '-') . '</td>';
            }

            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
    

    /** Add AWB column to orders list */
    public static function add_awb_column($columns) {
        // Insert AWB column before Actions column if it exists, otherwise at the end
        $new_columns = [];
        foreach ($columns as $key => $column) {
            if ($key === 'wc_actions') {
                $new_columns['hgezlpfcr_awb'] = 'Fan Courier AWB';
            }
            $new_columns[$key] = $column;
        }
        
        // If no actions column, add at the end
        if (!isset($new_columns['hgezlpfcr_awb'])) {
            $new_columns['hgezlpfcr_awb'] = 'Fan Courier AWB';
        }
        
        return $new_columns;
    }

    public static function populate_awb_column($column, $post_id) {
        if ($column !== 'hgezlpfcr_awb') return;
        
        $order = wc_get_order($post_id);
        if (!$order) return;
        
        $awb = $order->get_meta(self::META_AWB);
        $status = $order->get_meta(self::META_STAT);
        $order_status = $order->get_status();
        
        // Check if AWB was deleted from FanCourier before attempting to restore from history
        $history = $order->get_meta(self::META_HISTORY);
        $awb_was_deleted = false;
        
        if (is_array($history) && !empty($history)) {
            // Caută ultima acțiune din istoric
            $latest_action = null;
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && in_array($entry['action'], ['AWB Generat', 'AWB Șters'])) {
                    $latest_action = $entry['action'];
                    break;
                }
            }
            
            // Dacă ultima acțiune este "AWB Șters", AWB-ul a fost șters din FanCourier
            if ($latest_action === 'AWB Șters') {
                $awb_was_deleted = true;
            }
        }
        
        // Check if AWB exists in history but not in meta data (only if not deleted)
        if (!$awb && !$awb_was_deleted) {
            // Schedule async AWB restoration instead of blocking UI
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('hgezlpfcr_restore_awb_async', [$order->get_id()], 'woo-fancourier');
            } else {
                $awb = self::restore_awb_from_history_cached($order); // Only cached, no API calls
            }
        }
        
        // Dacă AWB-ul a fost șters din FanCourier, nu îl afișa
        if ($awb_was_deleted) {
            $awb = null;
            $status = null;
        }
        
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $can_generate = in_array($order_status, $allowed_statuses);
        
        if ($awb) {
            echo '<strong>AWB:</strong> ' . esc_html($awb) . '<br>';
            if ($status) {
                echo '<small>Status: ' . esc_html($status) . '</small><br>';
            }
            
            // Download PDF button
            $download_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_download_awb',
                'post_id' => $post_id,
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($download_url) . '" class="button button-small">PDF</a> ';
            
            // Sync status button  
            $sync_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_sync_awb',
                'post_id' => $post_id,
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($sync_url) . '" class="button button-small">Sync</a>';
            
        } elseif ($can_generate) {
            // Generate AWB button
            $generate_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_generate_awb',
                'post_id' => $post_id,
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($generate_url) . '" class="button button-primary button-small">Generează AWB</a>';
        } else {
            echo '<small style="color:#666;">Status nepermis pentru AWB</small>';
        }
    }

    public static function populate_awb_column_hpos($column, $order) {
        if ($column !== 'hgezlpfcr_awb') return;
        
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order);
        }
        if (!$order) return;
        
        $awb = $order->get_meta(self::META_AWB);
        $status = $order->get_meta(self::META_STAT);
        $order_status = $order->get_status();
        
        // Check if AWB was deleted from FanCourier before attempting to restore from history
        $history = $order->get_meta(self::META_HISTORY);
        $awb_was_deleted = false;
        
        if (is_array($history) && !empty($history)) {
            // Caută ultima acțiune din istoric
            $latest_action = null;
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && in_array($entry['action'], ['AWB Generat', 'AWB Șters'])) {
                    $latest_action = $entry['action'];
                    break;
                }
            }
            
            // Dacă ultima acțiune este "AWB Șters", AWB-ul a fost șters din FanCourier
            if ($latest_action === 'AWB Șters') {
                $awb_was_deleted = true;
            }
        }
        
        // Check if AWB exists in history but not in meta data (only if not deleted)
        if (!$awb && !$awb_was_deleted) {
            // Schedule async AWB restoration instead of blocking UI
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('hgezlpfcr_restore_awb_async', [$order->get_id()], 'woo-fancourier');
            } else {
                $awb = self::restore_awb_from_history_cached($order); // Only cached, no API calls
            }
        }
        
        // Dacă AWB-ul a fost șters din FanCourier, nu îl afișa
        if ($awb_was_deleted) {
            $awb = null;
            $status = null;
        }
        
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $can_generate = in_array($order_status, $allowed_statuses);
        
        if ($awb) {
            echo '<strong>AWB:</strong> ' . esc_html($awb) . '<br>';
            if ($status) {
                echo '<small>Status: ' . esc_html($status) . '</small><br>';
            }
            
            // Download PDF button
            $download_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_download_awb',
                'post_id' => $order->get_id(),
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($download_url) . '" class="button button-small">PDF</a> ';
            
            // Sync status button  
            $sync_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_sync_awb',
                'post_id' => $order->get_id(),
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($sync_url) . '" class="button button-small">Sync</a>';
            
        } elseif ($can_generate) {
            // Generate AWB button
            $generate_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_generate_awb',
                'post_id' => $order->get_id(),
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($generate_url) . '" class="button button-primary button-small">Generează AWB</a>';
        } else {
            echo '<small style="color:#666;">Status nepermis pentru AWB</small>';
        }
    }

    /** Alternative AWB generation methods (since meta boxes cause conflicts) */
    public static function add_awb_menu() {
        add_submenu_page(
            'woocommerce',
            'Fan Courier AWB',
            'Fan Courier AWB',
            'manage_woocommerce',
            'fc-awb-manager',
            [__CLASS__, 'awb_manager_page']
        );
    }

    public static function awb_manager_page() {
        echo '<div class="wrap">';
        echo '<h1>Fan Courier - Gestionare AWB</h1>';
        echo '<p>Pentru a genera AWB pentru o comandă:</p>';
        echo '<ol>';
        echo '<li>Du-te la <a href="'.esc_url(admin_url('edit.php?post_type=shop_order')).'">WooCommerce → Orders</a></li>';
        echo '<li>Selectează comenzile dorite</li>';
        echo '<li>Alege "Generează AWB Fan Courier" din Bulk Actions</li>';
        echo '</ol>';
        echo '<p><em>Generarea AWB este disponibilă pentru: Comandă nouă, Completed, Plată confirmată, Emite factură Avans</em></p>';
        echo '</div>';
    }

    public static function add_bulk_awb_action($actions) {
        $actions['hgezlpfcr_generate_awb_bulk'] = 'Generează AWB Fan Courier';
        return $actions;
    }

    public static function handle_bulk_awb_action($redirect_to, $action, $post_ids) {
        if ($action !== 'hgezlpfcr_generate_awb_bulk') {
            return $redirect_to;
        }

        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $generated = 0;
        $errors = 0;

        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            if (!$order) continue;

            if (!in_array($order->get_status(), $allowed_statuses)) {
                $errors++;
                continue;
            }

            if ($order->get_meta(self::META_AWB)) {
                continue; // Already has AWB
            }

            // Generate AWB
            try {
                self::create_awb_for_order($post_id, false);
                $generated++;
            } catch (Exception $e) {
                $errors++;
            }
        }

        $redirect_to = add_query_arg([
            'hgezlpfcr_bulk_awb_generated' => $generated,
            'hgezlpfcr_bulk_awb_errors' => $errors
        ], $redirect_to);

        return $redirect_to;
    }

    public static function bulk_awb_admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display only, data is sanitized
        if (!isset($_REQUEST['hgezlpfcr_bulk_awb_generated'])) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Admin notice display only, data is sanitized with intval
        $generated = intval($_REQUEST['hgezlpfcr_bulk_awb_generated']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Admin notice display only, data is sanitized with intval
        $errors = intval($_REQUEST['hgezlpfcr_bulk_awb_errors']);
        
        if ($generated > 0) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>'.esc_html(sprintf('AWB generat pentru %d comenzi.', $generated)).'</p>';
            echo '</div>';
        }

        if ($errors > 0) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>'.esc_html(sprintf('Erori la %d comenzi (status nepermis sau AWB existent).', $errors)).'</p>';
            echo '</div>';
        }
    }

    public static function individual_awb_admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display only, data is sanitized
        if (!isset($_REQUEST['fc_notice'])) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Admin notice display only, data is sanitized
        $msg = sanitize_text_field(wp_unslash($_REQUEST['fc_notice']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display only, data is sanitized
        $type = sanitize_key($_REQUEST['fc_type'] ?? 'info');
        
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible">';
        echo '<p>' . esc_html($msg) . '</p>';
        echo '</div>';
    }

    /** AWB History logging */
    protected static function log_awb_action($order_id, $action, $details = '') {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $current_user = wp_get_current_user();
        $user_name = $current_user->display_name ?: $current_user->user_login ?: 'System';
        
        $history_entry = [
            'timestamp' => time(),
            'user' => $user_name,
            'user_id' => $current_user->ID ?: 0,
            'action' => $action,
            'order_status' => $order->get_status(),
            'details' => $details,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'Unknown'
        ];
        
        $history = $order->get_meta(self::META_HISTORY);
        if (!is_array($history)) {
            $history = [];
        }
        
        $history[] = $history_entry;
        $order->update_meta_data(self::META_HISTORY, $history);
        $order->save();
        
        // Log to HGEZLPFCR_Logger for debugging
        HGEZLPFCR_Logger::log('AWB action logged', [
            'order_id' => $order_id,
            'action' => $action,
            'details' => $details,
            'history_count' => count($history)
        ]);
        
        // Also log to WooCommerce order notes for extra tracking
        $order->add_order_note(sprintf(
            'Fan Courier: %s de către %s',
            $action,
            $user_name
        ));
    }

    /** Auto-gen AWB on processing, via async if enabled */
    public static function maybe_queue_autogenerate_awb($order_id) {
        // Verify order exists
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Check if auto AWB should be generated for this order status using new system
        if (!HGEZLPFCR_Settings::should_auto_generate_awb($order->get_status())) return;

        // Skip if AWB already exists
        if ($order->get_meta(self::META_AWB)) return;

        // Log automatic generation attempt
        self::log_awb_action($order_id, 'Generare AWB Automată', 'Declanșată la schimbarea status-ului în: ' . $order->get_status());

        // Always use async to avoid blocking status changes
        if (HGEZLPFCR_Settings::yes('hgezlpfcr_async') && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('hgezlpfcr_generate_awb_async', [$order_id], 'woo-fancourier');
        } else {
            // Delay execution to not interfere with status change process
            wp_schedule_single_event(time() + 5, 'fc_delayed_awb_generation', [$order_id]);
        }
    }

    /** Admin button: generate AWB (queues if async enabled) */
    public static function handle_generate_awb() {
        self::verify_nonce_and_caps();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce verified in verify_nonce_and_caps()
        $order_id = absint($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
        
        if (!$order_id) {
            HGEZLPFCR_Logger::error('Missing order ID in generate AWB request', [
                'user_id' => get_current_user_id(),
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified
                'post_id' => isset($_POST['post_id']) ? sanitize_text_field(wp_unslash($_POST['post_id'])) : 'not_set',
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified
                'get_id' => isset($_GET['post_id']) ? sanitize_text_field(wp_unslash($_GET['post_id'])) : 'not_set'
            ]);
            self::admin_notice('ID comandă lipsă.', 'error', 0);
            return;
        }
        
        // Log the attempt
        self::log_awb_action($order_id, 'Tentativă Generare AWB', 'Utilizatorul a apăsat butonul Generează AWB');
        
        // Check if order status allows AWB generation
        $order = wc_get_order($order_id);
        if (!$order) {
            HGEZLPFCR_Logger::error('Invalid order in generate AWB request', ['order_id' => $order_id]);
            self::admin_notice('Comandă invalidă.', 'error', $order_id);
            return;
        }
        
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $order_status = $order->get_status();
        if (!in_array($order_status, $allowed_statuses)) {
            HGEZLPFCR_Logger::error('Order status not allowed for AWB generation', [
                'order_id' => $order_id,
                'status' => $order_status,
                'allowed' => $allowed_statuses
            ]);
            self::log_awb_action($order_id, 'Eroare Generare AWB', 'Status nepermis: ' . $order_status);
            self::admin_notice('Generarea AWB nu este permisă pentru acest status de comandă.', 'error', $order_id);
            return;
        }
        
        if (HGEZLPFCR_Settings::yes('hgezlpfcr_async') && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('hgezlpfcr_generate_awb_async', [$order_id], 'woo-fancourier');
            self::admin_notice('Task AWB programat în coadă.', 'info', $order_id);
        } else {
            self::create_awb_for_order($order_id, true);
        }
    }

    /** Async handler */
    public static function create_awb_for_order_async($order_id) {
        self::create_awb_for_order($order_id, false);
    }

    /** Core: create AWB with idempotent lock */
    protected static function create_awb_for_order(int $order_id, bool $redirect) {
        HGEZLPFCR_Logger::log('create_awb_for_order started', ['order_id' => $order_id, 'redirect' => $redirect]);
        
        $order = wc_get_order($order_id);
        if (!$order) { 
            HGEZLPFCR_Logger::error('Order not found in create_awb_for_order', ['order_id' => $order_id]);
            if ($redirect) self::admin_notice('Comandă invalidă.', 'error', $order_id); 
            return false; 
        }

        // Check if AWB was deleted from FanCourier before attempting to restore from history
        $history = $order->get_meta(self::META_HISTORY);
        $awb_was_deleted = false;
        
        if (is_array($history) && !empty($history)) {
            // Caută ultima acțiune din istoric
            $latest_action = null;
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && in_array($entry['action'], ['AWB Generat', 'AWB Șters'])) {
                    $latest_action = $entry['action'];
                    break;
                }
            }
            
            // Dacă ultima acțiune este "AWB Șters", AWB-ul a fost șters din FanCourier
            if ($latest_action === 'AWB Șters') {
                $awb_was_deleted = true;
                HGEZLPFCR_Logger::log('AWB was deleted from FanCourier, allowing regeneration', ['order_id' => $order_id]);
            }
        }
        
        // If already has AWB and it wasn't deleted, skip
        if ($order->get_meta(self::META_AWB) && !$awb_was_deleted) {
            HGEZLPFCR_Logger::log('AWB already exists, skipping', ['order_id' => $order_id, 'awb' => $order->get_meta(self::META_AWB)]);
            if ($redirect) self::admin_notice('AWB deja existent.', 'warning', $order_id);
            return true; // AWB already exists
        }
        
        // Check if AWB exists in history but not in meta data (only if not deleted)
        if (!$awb_was_deleted) {
            $awb_from_history = self::restore_awb_from_history($order, true); // Sync status when restoring
            if ($awb_from_history) {
                if ($redirect) self::admin_notice('AWB restaurat din istoric: ' . $awb_from_history, 'success', $order_id);
                return true; // AWB restored from history
            }
        }

        // Acquire lock (transient per order)
        $lock_key = 'fc_awb_lock_' . $order_id;
        $token = wp_generate_password(12, false);
        if (!self::acquire_lock($lock_key, $token)) {
            HGEZLPFCR_Logger::log('Could not acquire lock', ['order_id' => $order_id, 'lock_key' => $lock_key]);
            if ($redirect) self::admin_notice('Generare AWB deja în curs.', 'warning', $order_id);
            return false;
        }

        HGEZLPFCR_Logger::log('Lock acquired, proceeding with AWB generation', ['order_id' => $order_id]);

        try {
            // Check if API client exists
            if (!class_exists('HGEZLPFCR_API_Client')) {
                HGEZLPFCR_Logger::error('HGEZLPFCR_API_Client class not found', ['order'=>$order->get_id()]);
                if ($redirect) self::admin_notice('API Client nu este disponibil.', 'error', $order_id);
                return false;
            }
            
            HGEZLPFCR_Logger::log('Building payload for order', ['order_id' => $order_id]);
            $api = new HGEZLPFCR_API_Client();
            $payload = self::build_payload_from_order($order);
            $idem_key = 'order-'.$order->get_id().'-'.wp_hash((string)$order->get_date_created()->getTimestamp());

            HGEZLPFCR_Logger::log('Create AWB request', ['order'=>$order->get_id(), 'payload'=>$payload, 'idem_key' => $idem_key]);
            $res = $api->create_awb($payload, $idem_key);

            HGEZLPFCR_Logger::log('API response received', ['order_id' => $order_id, 'response' => $res, 'is_wp_error' => is_wp_error($res)]);

            if (is_wp_error($res) || empty($res['response']) || empty($res['response'][0]['awbNumber'])) {
                $msg = is_wp_error($res) ? $res->get_error_message() : 'Răspuns invalid';

                // Extract detailed error messages from API response
                $error_details = '';
                if (!is_wp_error($res) && isset($res['response'][0]['errors']) && is_array($res['response'][0]['errors'])) {
                    $errors_array = [];
                    foreach ($res['response'][0]['errors'] as $field => $messages) {
                        if (is_array($messages)) {
                            foreach ($messages as $message) {
                                $errors_array[] = $field . ': ' . $message;
                            }
                        }
                    }
                    if (!empty($errors_array)) {
                        $error_details = implode(' | ', $errors_array);
                        $msg = 'Erori validare: ' . $error_details;
                    }
                }

                HGEZLPFCR_Logger::error('Create AWB failed', ['order'=>$order->get_id(), 'err'=>$msg, 'response'=>$res]);

                // Log error to AWB history
                self::log_awb_action($order_id, 'Eroare Generare AWB', $msg);

                if ($redirect) self::admin_notice('Eroare generare AWB: '.$msg, 'error', $order_id);
                return false;
            }

            $awb = sanitize_text_field($res['response'][0]['awbNumber']);
            HGEZLPFCR_Logger::log('AWB received from API', ['order_id' => $order_id, 'awb' => $awb]);
            
            $order->update_meta_data(self::META_AWB, $awb);
            $order->update_meta_data(self::META_AWB_DATE, self::get_fancourier_date()); // Store generation date adjusted for FanCourier timezone
            if (!empty($res['status'])) {
                $order->update_meta_data(self::META_STAT, sanitize_text_field($res['status']));
            }
            
            HGEZLPFCR_Logger::log('Saving order with AWB', ['order_id' => $order_id, 'awb' => $awb]);
            $order->save();

            // Optional: enqueue immediate status sync
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('hgezlpfcr_sync_awb_async', [$order_id], 'woo-fancourier');
            }

            // Log the AWB generation
            self::log_awb_action($order_id, 'AWB Generat', 'AWB: ' . $awb);

            HGEZLPFCR_Logger::log('AWB generation completed successfully', ['order_id' => $order_id, 'awb' => $awb]);

            // Fire hook for external integrations (e.g., FC PRO plugin)
            do_action('fc_awb_generated_successfully', $order_id, $awb);

            if ($redirect) self::admin_notice('AWB generat: '.$awb, 'success', $order_id);
            return true;
        } catch (Exception $e) {
            HGEZLPFCR_Logger::error('Exception in create_awb_for_order', ['order'=>$order->get_id(), 'exception'=>$e->getMessage(), 'trace' => $e->getTraceAsString()]);
            if ($redirect) {
                self::admin_notice('Excepție la generarea AWB: '.$e->getMessage(), 'error', $order_id);
                return false;
            } else {
                // Re-throw exception for AJAX handlers to catch
                throw $e;
            }
        } finally {
            HGEZLPFCR_Logger::log('Releasing lock', ['order_id' => $order_id, 'lock_key' => $lock_key]);
            self::release_lock($lock_key, $token);
        }
    }

    /** Build payload safely */
    protected static function build_payload_from_order(WC_Order $order): array {
        HGEZLPFCR_Logger::log('Building payload for order', ['order_id' => $order->get_id()]);

        $shipping = $order->get_address('shipping');
        $billing = $order->get_address('billing');

        // Build recipient name - use company if available, otherwise personal name
        $billing_company = trim($billing['company'] ?? '');
        $billing_cui = $order->get_meta('_billing_cui');

        // Get contact person name - fallback from shipping to billing
        $contact_person = trim(($shipping['first_name'] ?? '').' '.($shipping['last_name'] ?? ''));
        if (empty(trim($contact_person))) {
            $contact_person = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));
        }

        // If company exists, use it as recipient name with CUI if available
        if (!empty($billing_company)) {
            $name = $billing_company;
            if (!empty($billing_cui)) {
                $name .= ' | CUI: ' . $billing_cui;
            }
        } else {
            $name = $contact_person;
        }

        // Get city - fallback from shipping to billing
        $city = trim($shipping['city'] ?? '');
        if (empty($city)) {
            $city = trim($billing['city'] ?? '');
        }

        // Get ZIP/postcode - fallback from shipping to billing
        $zip = preg_replace('/\s+/', '', (string)($shipping['postcode'] ?? ''));
        if (empty($zip)) {
            $zip = preg_replace('/\s+/', '', (string)($billing['postcode'] ?? ''));
        }

        // Try to get phone from multiple sources
        $phone = '';
        if (!empty($shipping['phone'])) {
            $phone = $shipping['phone'];
        } elseif (!empty($billing['phone'])) {
            $phone = $billing['phone'];
        } elseif (!empty($order->get_billing_phone())) {
            $phone = $order->get_billing_phone();
        }

        $phone = preg_replace('/[^0-9+]/', '', (string)$phone);

        HGEZLPFCR_Logger::log('Recipient data extracted', [
            'order_id' => $order->get_id(),
            'name' => $name,
            'contact_person' => $contact_person,
            'company' => $billing_company,
            'cui' => $billing_cui,
            'phone' => $phone,
            'phone_source' => !empty($shipping['phone']) ? 'shipping' : (!empty($billing['phone']) ? 'billing' : (!empty($order->get_billing_phone()) ? 'order_billing' : 'default')),
            'city' => $city,
            'city_source' => !empty($shipping['city']) ? 'shipping' : 'billing',
            'zip' => $zip,
            'zip_source' => !empty($shipping['postcode']) ? 'shipping' : 'billing',
            'address' => $shipping['address_1'] ?? ''
        ]);

        // Validate required recipient data with specific error messages
        $errors = [];

        if (empty($name)) {
            $errors[] = 'Numele destinatarului lipsește (verifică Prenume și Nume la Facturare)';
        }

        if (empty($city)) {
            $errors[] = 'Orașul destinatarului lipsește (verifică Oraș la Facturare și Livrare)';
        }

        if (empty($zip)) {
            $errors[] = 'Codul poștal lipsește (verifică Cod poștal la Facturare și Livrare)';
        }

        if (!empty($errors)) {
            HGEZLPFCR_Logger::error('Recipient data validation failed', [
                'order_id' => $order->get_id(),
                'name_empty' => empty($name),
                'phone_empty' => empty($phone),
                'city_empty' => empty($city),
                'zip_empty' => empty($zip),
                'errors' => $errors
            ]);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message with internally generated error strings, no user input
            throw new Exception('AWB nu poate fi generat: ' . implode(', ', $errors));
        }
        
        // If phone is empty, use a default one
        if (empty($phone)) {
            $phone = '0000000000';
            HGEZLPFCR_Logger::log('Using default phone number', ['order_id' => $order->get_id()]);
        }

        $weight = self::calculate_order_weight($order);
        $declared = 0; // VAL DECLARATA va fi 0 conform cerințelor
        
        // Logica pentru COD: doar pentru COD se aplică valoarea totală
        $cod = 0.0;
        if ($order->get_payment_method() === 'cod') {
            $cod = (float) $order->get_total(); // Valoarea totală a comenzii pentru COD
        }

        // Build detailed payload matching FAN Courier API structure
        $number_of_parcels = max(1, (int) HGEZLPFCR_Settings::get('woocommerce_fan_courier_FAN_numberOfParcels', 1));
        
        // Validate sender settings
        $sender_name = HGEZLPFCR_Settings::get('hgezlpfcr_sender_name', get_bloginfo('name'));
        $sender_phone = HGEZLPFCR_Settings::get('hgezlpfcr_sender_phone', '');
        $sender_address = HGEZLPFCR_Settings::get('hgezlpfcr_sender_address', '');
        $sender_city = HGEZLPFCR_Settings::get('hgezlpfcr_sender_city', '');
        $sender_zip = HGEZLPFCR_Settings::get('hgezlpfcr_sender_zip', '');
        
        HGEZLPFCR_Logger::log('Sender settings extracted', [
            'order_id' => $order->get_id(),
            'sender_name' => $sender_name,
            'sender_phone' => $sender_phone,
            'sender_address' => $sender_address,
            'sender_city' => $sender_city,
            'sender_zip' => $sender_zip
        ]);
        
        if (empty($sender_name) || empty($sender_phone) || empty($sender_address) || empty($sender_city) || empty($sender_zip)) {
            HGEZLPFCR_Logger::error('Sender settings validation failed', [
                'order_id' => $order->get_id(),
                'name_empty' => empty($sender_name),
                'phone_empty' => empty($sender_phone),
                'address_empty' => empty($sender_address),
                'city_empty' => empty($sender_city),
                'zip_empty' => empty($sender_zip)
            ]);
            throw new Exception('Setările expeditorului sunt incomplete. Verifică configurația plugin-ului.');
        }
        
        // Determinarea serviciului în funcție de plata
        $service = 'Standard';

        // Pentru Standard, verificăm plata
        if ($order->get_payment_method() === 'cod') {
            $service = 'Cont Colector'; // Pentru plata la livrare folosim "Cont Colector"
        }
        
        // Cine plătește transportul
        $payment = 'sender';

        // IBAN pentru ramburs COD
        $iban = HGEZLPFCR_Settings::get('hgezlpfcr_cont_iban_ramburs', '');
        
        // Build payload according to Fan Courier API documentation
        $payload = [
            'clientId' => (int) HGEZLPFCR_Settings::get('hgezlpfcr_client', 0), // We need clientId for intern-awb
            'shipments' => [
                [
                    'info' => [
                        'service' => $service,
                        'packages' => [
                            'parcel' => $number_of_parcels,
                            'envelope' => 0
                        ],
                        'weight' => max($weight, 0.1),
                        'cod' => $cod,
                        'declaredValue' => $declared, // VAL DECLARATA = 0
                        'payment' => $payment,
                        'returnPayment' => HGEZLPFCR_Settings::get('hgezlpfcr_return_payment', 'recipient'), // Cine plătește pentru retur
                        'documentType' => HGEZLPFCR_Settings::get('hgezlpfcr_document_type', 'document'), // Tipul documentului de plată
                        'rbsPaymentAtDestination' => HGEZLPFCR_Settings::get('woocommerce_fan_courier_FAN_rbsPaymentAtDestination', 'yes') === 'yes', // Plata ramburs la destinație
                        'observation' => self::build_observation_text($order),
                        'content' => self::build_content_text($order), // Conținut cu ID-ul comenzii
                        'dimensions' => [
                            'length' => 30,
                            'width' => 20,
                            'height' => 10
                        ],
                        'bank' => 'Unicredit Bank SA', // Banca pentru plata rambursului
                        'bankAccount' => $iban // IBAN pentru contul bancar
                    ],
                    'recipient' => [
                        'name' => mb_substr($name ?: 'Client', 0, 100), // Increased to 100 for company + CUI
                        'phone' => mb_substr($phone ?: '0000000000', 0, 16),
                        'email' => $order->get_billing_email(),
                        'contactPerson' => !empty($billing_company) ? mb_substr($contact_person, 0, 50) : '', // Contact person only if company exists
                        'address' => self::build_recipient_address($order, $shipping, $billing, $city, $zip)
                    ]
                ]
            ]
        ];

        HGEZLPFCR_Logger::log('Payload built successfully', [
            'order_id' => $order->get_id(),
            'payload_keys' => array_keys($payload),
            'service' => $service,
            'payment' => $payment,
            'cod' => $cod,
            'declared_value' => $declared
        ]);
        
        return $payload;
    }
    
    /**
     * Map WooCommerce state codes to FanCourier county names
     */
    protected static function map_county_code_to_name(string $code): string {
        $county_map = [
            'AB' => 'Alba', 'AR' => 'Arad', 'AG' => 'Arges', 'BC' => 'Bacau',
            'BH' => 'Bihor', 'BN' => 'Bistrita-Nasaud', 'BT' => 'Botosani', 'BV' => 'Brasov',
            'BR' => 'Braila', 'B' => 'Bucuresti', 'BZ' => 'Buzau', 'CS' => 'Caras-Severin',
            'CL' => 'Calarasi', 'CJ' => 'Cluj', 'CT' => 'Constanta', 'CV' => 'Covasna',
            'DB' => 'Dambovita', 'DJ' => 'Dolj', 'GL' => 'Galati', 'GR' => 'Giurgiu',
            'GJ' => 'Gorj', 'HR' => 'Harghita', 'HD' => 'Hunedoara', 'IL' => 'Ialomita',
            'IS' => 'Iasi', 'IF' => 'Ilfov', 'MM' => 'Maramures', 'MH' => 'Mehedinti',
            'MS' => 'Mures', 'NT' => 'Neamt', 'OT' => 'Olt', 'PH' => 'Prahova',
            'SM' => 'Satu Mare', 'SJ' => 'Salaj', 'SB' => 'Sibiu', 'SV' => 'Suceava',
            'TR' => 'Teleorman', 'TM' => 'Timis', 'TL' => 'Tulcea', 'VS' => 'Vaslui',
            'VL' => 'Valcea', 'VN' => 'Vrancea'
        ];

        $code = strtoupper(trim($code));
        return $county_map[$code] ?? $code;
    }

    /**
     * Build recipient address for Standard service
     */
    protected static function build_recipient_address(WC_Order $order, array $shipping, array $billing, string $city, string $zip): array {
        // Get county/state - fallback from shipping to billing
        $county_code = trim($shipping['state'] ?? '');
        if (empty($county_code)) {
            $county_code = trim($billing['state'] ?? 'B');
        }

        // Map county code to full name for FanCourier API
        $county = self::map_county_code_to_name($county_code);

        HGEZLPFCR_Logger::log('County code mapped for FanCourier', [
            'order_id' => $order->get_id(),
            'county_code' => $county_code,
            'county_name' => $county,
            'source' => !empty($shipping['state']) ? 'shipping' : 'billing'
        ]);

        return [
            'county' => $county,
            'locality' => $city,
            'street' => trim(($shipping['address_1'] ?? '') . ' ' . ($shipping['address_2'] ?? '')),
            'streetNo' => '',
            'zipCode' => $zip
        ];
    }
    
    /** Helper to build full product list text */
    protected static function build_full_product_list(WC_Order $order): string {
        $content_parts = [];

        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) continue;

            $product = $item->get_product();
            if (!$product) continue;

            $parts = [];

            // 1. Nume produs (sau SKU dacă numele nu există)
            $product_name = $product->get_name();

            if (!empty($product_name)) {
                // Truncăm numele dacă depășește 30 caractere
                if (mb_strlen($product_name) > 30) {
                    $product_name = mb_substr($product_name, 0, 27) . '...';
                }
                $parts[] = $product_name;
            } else {
                // Fallback la SKU dacă numele nu există
                $product_sku = $product->get_sku();
                if (!empty($product_sku)) {
                    $parts[] = $product_sku;
                } else {
                    $parts[] = 'Produs #' . $product->get_id();
                }
            }

            // 2. Varianta (pentru produse variabile)
            if ($product->is_type('variation')) {
                $variation_attributes = [];
                foreach ($item->get_meta_data() as $meta) {
                    $meta_key = $meta->get_data()['key'];
                    // Doar atributele de variație (încep cu pa_ sau attribute_)
                    if (strpos($meta_key, 'pa_') === 0 || strpos($meta_key, 'attribute_') === 0) {
                        $attribute_name = str_replace(['pa_', 'attribute_'], '', $meta_key);
                        $attribute_name = ucfirst(str_replace(['_', '-'], ' ', $attribute_name));
                        $variation_attributes[] = $attribute_name . ': ' . $meta->get_data()['value'];
                    }
                }
                if (!empty($variation_attributes)) {
                    $parts[] = '(' . implode(', ', $variation_attributes) . ')';
                }
            }

            // 3. Custom Fields de la produs
            $field_mapping = [
                'Numar si Nume' => 'Nr și Nume',
                'Nume si Numar' => 'Nr și Nume',
                'Sponsori spate' => 'Spons',
                'Semnatura 1 jucator' => 'Semn 1 juc',
                'Semnatura 2 - 5 jucatori' => 'Semn 2-5 juc',
                'Nume jucatori' => 'Juc',
            ];

            // Skip acest câmp complet (este un wrapper pentru alte câmpuri)
            $skip_fields = ['Personalizare'];

            $custom_fields = [];
            foreach ($item->get_meta_data() as $meta) {
                $meta_key = $meta->get_data()['key'];
                $meta_value = $meta->get_data()['value'];

                // Skip internal meta fields, atributele de variație și câmpurile din skip list
                if (strpos($meta_key, '_') === 0 ||
                    strpos($meta_key, 'pa_') === 0 ||
                    strpos($meta_key, 'attribute_') === 0 ||
                    in_array($meta_key, $skip_fields)) {
                    continue;
                }

                if (!empty($meta_value)) {
                    $display_key = isset($field_mapping[$meta_key]) ? $field_mapping[$meta_key] : $meta_key;

                    if (strtolower($meta_value) === 'yes' || strtolower($meta_value) === 'no') {
                        if (strtolower($meta_value) === 'yes') {
                            $custom_fields[] = $display_key;
                        }
                    } else {
                        $custom_fields[] = $display_key . ': ' . $meta_value;
                    }
                }
            }

            if (!empty($custom_fields)) {
                $parts[] = implode(', ', $custom_fields);
            }

            // Combinăm toate părțile pentru acest produs (fără paranteze pătrate pentru custom fields)
            $qty = $item->get_quantity();
            $item_text = ($qty > 1 ? $qty . 'x ' : '') . implode(' ', $parts);
            $content_parts[] = $item_text;
        }

        // Returnăm lista completă de produse
        return implode('; ', $content_parts);
    }

    protected static function build_content_text(WC_Order $order): string {
        // Construim textul complet: "Comanda #96165 - [produse]"
        $order_prefix = '#' . $order->get_order_number() . ' - ';
        $products_text = self::build_full_product_list($order);

        $full_text = $order_prefix . $products_text;

        // Dacă textul complet încape în 113 caractere, returnăm string gol
        // (totul va fi pe rândul 1 - observation)
        if (mb_strlen($full_text) <= 113) {
            return '';
        }

        // Dacă textul depășește 113 caractere, extragem partea care continuă după primele 110 caractere
        // (primele 110 + "..." sunt pe observation - rândul 1)
        $remaining_text = mb_substr($full_text, 110);

        // Limităm restul la 113 caractere pentru câmpul Content (rândul 2)
        if (mb_strlen($remaining_text) > 113) {
            return mb_substr($remaining_text, 0, 110) . '...';
        }

        return $remaining_text;
    }

    protected static function get_order_content_description(WC_Order $order): string {
        $items = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $product = $item->get_product();
                if ($product) {
                    // Folosim SKU dacă există, altfel numele produsului
                    $product_identifier = $product->get_sku();
                    if (empty($product_identifier)) {
                        $product_identifier = $product->get_name();
                    }
                    $items[] = $item->get_quantity() . 'x ' . $product_identifier;
                }
            }
        }
        $content = implode(', ', array_slice($items, 0, 3));
        if (count($items) > 3) {
            $content .= '...';
        }
        return mb_substr($content ?: 'Produse diverse', 0, 200);
    }

    protected static function calculate_order_weight(WC_Order $order): float {
        $w = 0.0;
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) continue;
            $product = $item->get_product();
            if ($product) {
                $pw = (float) $product->get_weight();
                $qty = max(1, (int) $item->get_quantity());
                
                // Pentru câmpul Kg de pe AWB valoarea default să fie 1 dacă la produs nu este trecută valoarea în câmpul Weight
                if ($pw > 0) {
                    $w += $pw * $qty;
                } else {
                    // Dacă produsul nu are greutate setată, folosim 1 kg ca default
                    $w += 1.0 * $qty;
                }
            }
        }
        
        // fallback total dacă nu s-a putut calcula
        if ($w <= 0) {
            $w = (float) $order->get_meta('_cart_weight');
            if ($w <= 0) $w = 1.0;
        }
        
        return round($w, 2);
    }

    /** PDF download */
    public static function handle_download_pdf() {
        self::verify_nonce_and_caps();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce verified in verify_nonce_and_caps()
        $order_id = absint($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
        
        if (!$order_id) {
            self::admin_notice('ID comandă lipsă.', 'error', 0);
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            self::admin_notice('Comandă invalidă.', 'error', $order_id);
            return;
        }
        
        $awb = $order->get_meta(self::META_AWB);
        if (!$awb) {
            // Log the failed download attempt
            self::log_awb_action($order_id, 'Eroare Descărcare PDF', 'AWB inexistent pentru descărcare');
            self::admin_notice('AWB inexistent pentru această comandă.', 'error', $order_id);
            return;
        }
        
        // Log the download attempt
        self::log_awb_action($order_id, 'Descărcare PDF AWB', 'Încercare descărcare PDF pentru AWB: ' . $awb);
        
        try {
            $api = new HGEZLPFCR_API_Client();
            $res = $api->get_awb_pdf($awb);
            
            if (is_wp_error($res)) {
                $error_message = $res->get_error_message();
                HGEZLPFCR_Logger::error('PDF download failed', [
                    'order_id' => $order_id,
                    'awb' => $awb,
                    'error' => $error_message
                ]);
                
                // Check if AWB was deleted from FanCourier
                if (strpos(strtolower($error_message), 'not found') !== false ||
                    strpos(strtolower($error_message), 'nu a fost găsit') !== false ||
                    strpos(strtolower($error_message), 'awb inexistent') !== false ||
                    strpos(strtolower($error_message), 'awb not found') !== false ||
                    strpos(strtolower($error_message), 'invalid awb') !== false ||
                    strpos(strtolower($error_message), 'awb invalid') !== false ||
                    strpos(strtolower($error_message), 'does not exist') !== false ||
                    strpos(strtolower($error_message), 'nu există') !== false ||
                    strpos(strtolower($error_message), 'not exist') !== false ||
                    strpos(strtolower($error_message), 'eroare api fancourier') !== false ||
                    strpos(strtolower($error_message), 'api fancourier') !== false) {
                    
                    // Delete the AWB from the order
                    $old_awb = $awb;
                    $order->delete_meta_data(self::META_AWB);
                    $order->delete_meta_data(self::META_STAT);
                    $order->delete_meta_data(self::META_AWB_DATE);
                    $order->save();
                    
                    // Log the deletion
                    self::log_awb_action($order_id, 'AWB Șters', 'AWB șters din FanCourier: ' . $old_awb . ' - Poate fi regenerat');
                    
                    self::admin_notice('AWB-ul a fost șters din FanCourier și poate fi regenerat.', 'warning', $order_id);
                } else {
                    self::log_awb_action($order_id, 'Eroare Descărcare PDF', 'Nu s-a putut descărca PDF: ' . $error_message);
                    self::admin_notice('Nu s-a putut descărca PDF: ' . $error_message, 'error', $order_id);
                }
                return;
            }
            
            // Check if we have raw response (PDF content)
            if (isset($res['raw_response'])) {
                $pdf = $res['raw_response'];
                $content_type = $res['content_type'] ?? '';
                
                // Verify it's actually a PDF
                if (empty($pdf) || strpos($content_type, 'application/pdf') === false) {
                    self::log_awb_action($order_id, 'Eroare Descărcare PDF', 'Răspuns invalid de la API - nu este PDF');
                    self::admin_notice('Nu s-a putut descărca PDF - răspuns invalid de la API.', 'error', $order_id);
                    return;
                }
            } else {
                // Check for base64 encoded PDF (fallback)
                if (empty($res['pdf_base64'])) {
                    self::log_awb_action($order_id, 'Eroare Descărcare PDF', 'Răspuns invalid de la API - PDF gol');
                    self::admin_notice('Nu s-a putut descărca PDF - răspuns invalid de la API.', 'error', $order_id);
                    return;
                }
                
                $pdf = base64_decode($res['pdf_base64']);
                if ($pdf === false) {
                    self::log_awb_action($order_id, 'Eroare Descărcare PDF', 'PDF corupt - decodare base64 eșuată');
                    self::admin_notice('PDF corupt - nu s-a putut decoda conținutul.', 'error', $order_id);
                    return;
                }
            }
            
            // Log successful download
            self::log_awb_action($order_id, 'PDF Descărcat', 'PDF descărcat cu succes pentru AWB: ' . $awb);
            
            // Output PDF
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="AWB-'.preg_replace('/[^A-Za-z0-9\-]/','',$awb).'.pdf"');
            header('Content-Length: '.strlen($pdf));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF content must not be escaped
            echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
            
        } catch (Exception $e) {
            HGEZLPFCR_Logger::error('Exception in PDF download', [
                'order_id' => $order_id,
                'awb' => $awb,
                'exception' => $e->getMessage()
            ]);
            
            self::log_awb_action($order_id, 'Excepție Descărcare PDF', 'Excepție: ' . $e->getMessage());
            self::admin_notice('Eroare la descărcarea PDF: ' . $e->getMessage(), 'error', $order_id);
        }
    }

    /** Manual sync */
    public static function handle_sync_status() {
        self::verify_nonce_and_caps();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce verified in verify_nonce_and_caps()
        $order_id = absint($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
        if (HGEZLPFCR_Settings::yes('hgezlpfcr_async') && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('hgezlpfcr_sync_awb_async', [$order_id], 'woo-fancourier');
            self::admin_notice('Task verificare AWB programat.', 'info', $order_id);
        } else {
            self::sync_status_for_order($order_id, true);
        }
    }
    public static function sync_status_for_order_async($order_id) {
        self::sync_status_for_order($order_id, false);
    }

    /**
     * Sync AWB status from FanCourier API
     * If AWB was deleted from FanCourier, it will be automatically deleted from the order
     * and can be regenerated
     */
    public static function sync_status_for_order(int $order_id, bool $redirect) {
        // Sanitize $_GET['action'] once at the beginning for all redirect checks in this function
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in handle_sync_status
        $get_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        $order = wc_get_order($order_id);
        if (!$order) { if ($redirect) self::admin_notice('Comandă invalidă.', 'error', $order_id); return; }

        // Skip auto-sync for completed orders (manual sync still allowed via button)
        $order_status = $order->get_status();
        if ($order_status === 'completed' && !$redirect) {
            HGEZLPFCR_Logger::log('Auto-sync skipped for completed order', ['order_id' => $order_id]);
            return;
        }

        $awb = $order->get_meta(self::META_AWB);
        if (!$awb) { if ($redirect) self::admin_notice('Fără AWB.', 'warning', $order_id); return; }

        // Get AWB generation date
        $generation_date = $order->get_meta(self::META_AWB_DATE);
        if (!$generation_date) {
            // Fallback: try to get date from history if not stored in meta
            $awb_data = self::get_awb_from_history($order);
            $generation_date = $awb_data ? $awb_data['date'] : self::get_fancourier_date();
        }
        
        // Ensure the date is adjusted for FanCourier timezone if it was stored before the timezone fix
        $generation_date = self::adjust_date_for_fancourier($generation_date);

        $api = new HGEZLPFCR_API_Client();
        
        // Log the sync attempt
        self::log_awb_action($order_id, 'Verificare Status AWB', 'Încercare verificare AWB: ' . $awb . ' (Data FanCourier: ' . $generation_date . ')');
        
        // First, check if AWB exists in FanCourier Borderou for specific date
        HGEZLPFCR_Logger::log('Sync Status: Starting AWB borderou check', [
            'order_id' => $order_id,
            'awb' => $awb,
            'generation_date' => $generation_date,
            'local_date' => gmdate('Y-m-d'),
            'timezone_adjustment' => '+3 hours for FanCourier server',
            'redirect' => $redirect
        ]);
        
        $awb_exists = $api->check_awb_exists($awb, $generation_date);
        
        HGEZLPFCR_Logger::log('Sync Status: AWB borderou check result', [
            'order_id' => $order_id,
            'awb' => $awb,
            'generation_date' => $generation_date,
            'exists_result' => $awb_exists,
            'is_wp_error' => is_wp_error($awb_exists),
            'result_type' => gettype($awb_exists)
        ]);
        
        // Handle API errors for existence check
        if (is_wp_error($awb_exists)) {
            $error_message = $awb_exists->get_error_message();
            self::log_awb_action($order_id, 'Eroare Verificare AWB', 'Nu s-a putut verifica existența AWB: ' . $error_message);
            if ($redirect) {
                self::admin_notice('Eroare la verificarea AWB: ' . $error_message, 'error', $order_id);
                if ($get_action === 'hgezlpfcr_sync_awb') {
                    wp_safe_redirect(add_query_arg(['fc_refresh' => '1'], remove_query_arg(['action', 'post_id', 'hgezlpfcr_awb_nonce'])));
                    exit;
                }
            }
            return;
        }
        
        // If AWB doesn't exist in FanCourier Borderou, delete it from order using force delete
        if ($awb_exists === false) {
            $old_awb = $awb;
            HGEZLPFCR_Logger::log('Sync Status: AWB not found in borderou, proceeding with deletion', [
                'order_id' => $order_id,
                'awb' => $awb,
                'generation_date' => $generation_date
            ]);
            
            $delete_success = self::force_delete_awb_from_order($order_id, 'AWB nu există în Borderou FanCourier pentru data ' . $generation_date);
            
            if ($delete_success) {
                if ($redirect) {
                    self::admin_notice('AWB-ul nu există în Borderou FanCourier pentru data ' . $generation_date . ' și a fost șters. Poate fi regenerat.', 'warning', $order_id);
                    if ($get_action === 'hgezlpfcr_sync_awb') {
                        wp_safe_redirect(add_query_arg(['fc_refresh' => '1'], remove_query_arg(['action', 'post_id', 'hgezlpfcr_awb_nonce'])));
                        exit;
                    }
                }
            } else {
                if ($redirect) {
                    self::admin_notice('Eroare la ștergerea AWB-ului din comandă.', 'error', $order_id);
                    if ($get_action === 'hgezlpfcr_sync_awb') {
                        wp_safe_redirect(add_query_arg(['fc_refresh' => '1'], remove_query_arg(['action', 'post_id', 'hgezlpfcr_awb_nonce'])));
                        exit;
                    }
                }
            }
            return;
        }
        
        // AWB exists, now get its status
        HGEZLPFCR_Logger::log('Sync Status: AWB exists, getting status', [
            'order_id' => $order_id,
            'awb' => $awb
        ]);
        

        
        $res = $api->get_status($awb);
        
        if (!is_wp_error($res) && !empty($res['data']) && is_array($res['data'])) {
            // Extract status from tracking data
            $tracking_data = $res['data'];
            $latest_status = '';
            
            // Find the latest status from tracking events
            if (!empty($tracking_data[0]['events']) && is_array($tracking_data[0]['events'])) {
                $events = $tracking_data[0]['events'];
                if (!empty($events)) {
                    $latest_event = end($events);
                    $latest_status = $latest_event['status'] ?? $latest_event['description'] ?? '';
                }
            }
            
            if (!empty($latest_status)) {
                $order->update_meta_data(self::META_STAT, sanitize_text_field($latest_status));
                $order->save();
                // Log the successful sync
                self::log_awb_action($order_id, 'AWB Verificat', 'AWB găsit în Borderou pentru data ' . $generation_date . '. Status actualizat: ' . $latest_status);
                if ($redirect) {
                    self::admin_notice('AWB găsit în Borderou pentru data ' . $generation_date . '. Status actualizat: ' . $latest_status, 'success', $order_id);
                    // Force page refresh to update the interface
                    if ($get_action === 'hgezlpfcr_sync_awb') {
                        wp_safe_redirect(add_query_arg(['fc_refresh' => '1'], remove_query_arg(['action', 'post_id', 'hgezlpfcr_awb_nonce'])));
                        exit;
                    }
                }
            } else {
                self::log_awb_action($order_id, 'AWB Verificat', 'AWB găsit în Borderou pentru data ' . $generation_date . ', dar nu s-a găsit status în datele de tracking');
                if ($redirect) {
                    self::admin_notice('AWB găsit în Borderou pentru data ' . $generation_date . ', dar nu s-a găsit status în datele de tracking.', 'warning', $order_id);
                    // Force page refresh to update the interface
                    if ($get_action === 'hgezlpfcr_sync_awb') {
                        wp_safe_redirect(add_query_arg(['fc_refresh' => '1'], remove_query_arg(['action', 'post_id', 'hgezlpfcr_awb_nonce'])));
                        exit;
                    }
                }
            }
        } else {
            // Status API call failed, but we already verified AWB exists
            $error_message = is_wp_error($res) ? $res->get_error_message() : 'Răspuns invalid la obținerea status-ului';
            
            // Log the error details for debugging
            HGEZLPFCR_Logger::log('Sync status error details (AWB exists but status failed)', [
                'order_id' => $order_id,
                'awb' => $awb,
                'error_message' => $error_message,
                'is_wp_error' => is_wp_error($res)
            ]);
            
            // Since we already verified the AWB exists in borderou, this is just a status retrieval error
            self::log_awb_action($order_id, 'AWB Verificat', 'AWB găsit în Borderou pentru data ' . $generation_date . ', dar eroare la obținerea status-ului: ' . $error_message);
            
            if ($redirect) {
                self::admin_notice('AWB găsit în Borderou pentru data ' . $generation_date . ' dar nu s-a putut obține status-ul: ' . $error_message, 'warning', $order_id);
                if ($get_action === 'hgezlpfcr_sync_awb') {
                    wp_safe_redirect(add_query_arg(['fc_refresh' => '1'], remove_query_arg(['action', 'post_id', 'hgezlpfcr_awb_nonce'])));
                    exit;
                }
            }
        }
    }

    /**
     * Conditionally delete AWB data from order - only if AWB doesn't exist in FanCourier
     * Returns true if AWB was deleted or doesn't need deletion, false on error
     */
    public static function force_delete_awb_from_order($order_id, $reason = 'Conditional delete') {
        $order = wc_get_order($order_id);
        if (!$order) {
            HGEZLPFCR_Logger::error('Invalid order for AWB deletion', ['order_id' => $order_id]);
            return false;
        }
        
        $awb = $order->get_meta(self::META_AWB);
        if (!$awb) {
            HGEZLPFCR_Logger::log('No AWB to delete from order', ['order_id' => $order_id]);
            return true; // No AWB exists, consider it success
        }
        
        // FIRST: Check if AWB exists in FanCourier - only delete if it doesn't exist there
        $api = new HGEZLPFCR_API_Client();
        $awb_exists = $api->check_awb_exists($awb);
        
        HGEZLPFCR_Logger::log('Conditional AWB deletion - checking FanCourier existence', [
            'order_id' => $order_id,
            'awb' => $awb,
            'exists_in_fancourier' => $awb_exists,
            'reason' => $reason
        ]);
        
        // Handle API errors
        if (is_wp_error($awb_exists)) {
            $error_message = $awb_exists->get_error_message();
            HGEZLPFCR_Logger::error('Could not verify AWB existence in FanCourier', [
                'order_id' => $order_id,
                'awb' => $awb,
                'error' => $error_message
            ]);
            return false; // Don't delete if we can't verify
        }
        
        // If AWB EXISTS in FanCourier, do NOT delete it from order
        if ($awb_exists === true) {
            HGEZLPFCR_Logger::log('AWB exists in FanCourier - NOT deleting from order', [
                'order_id' => $order_id,
                'awb' => $awb,
                'reason' => $reason
            ]);
            return true; // AWB is valid, keep it
        }
        
        // AWB does NOT exist in FanCourier - proceed with deletion
        HGEZLPFCR_Logger::log('AWB does not exist in FanCourier - proceeding with deletion', [
            'order_id' => $order_id,
            'awb' => $awb,
            'reason' => $reason
        ]);
        
        // Method 1: WooCommerce standard way
        $order->delete_meta_data(self::META_AWB);
        $order->delete_meta_data(self::META_STAT);
        $order->delete_meta_data(self::META_AWB_DATE);
        $order->save();
        
        // Method 2: WordPress meta deletion (backup method)
        delete_post_meta($order_id, self::META_AWB);
        delete_post_meta($order_id, self::META_STAT);
        delete_post_meta($order_id, self::META_AWB_DATE);
        
        // Method 3: Direct database deletion (last resort)
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Direct deletion needed when WC meta functions fail, post_id index used
        $wpdb->delete(
            $wpdb->postmeta,
            [
                'post_id' => $order_id,
                'meta_key' => self::META_AWB // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ],
            ['%d', '%s']
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Direct deletion needed when WC meta functions fail, post_id index used
        $wpdb->delete(
            $wpdb->postmeta,
            [
                'post_id' => $order_id,
                'meta_key' => self::META_STAT // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ],
            ['%d', '%s']
        );
        
        // Clear caches safely - only use functions that exist
        self::clear_order_caches($order_id);
        
        // Force WordPress to reload order data by clearing all possible caches
        global $wpdb;
        wp_cache_delete($order_id, 'posts');
        wp_cache_delete($order_id, 'post_meta');  
        wp_cache_delete('wc_order_' . $order_id);
        
        // Clear any persistent caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_delete('order_' . $order_id);
            wp_cache_delete($order_id . '_meta');
        }
        
        // Verify deletion was successful with fresh data
        clean_post_cache($order_id); // WordPress core cache clean
        $order_fresh = wc_get_order($order_id);
        $awb_after = $order_fresh ? $order_fresh->get_meta(self::META_AWB) : null;
        $stat_after = $order_fresh ? $order_fresh->get_meta(self::META_STAT) : null;
        
        $success = empty($awb_after) && empty($stat_after);
        
        if ($success) {
            // Mark in history as deleted because AWB doesn't exist in FanCourier
            self::log_awb_action($order_id, 'AWB Șters (Inexistent FanCourier)', 'AWB șters din comandă (nu există în FanCourier): ' . $awb . ' - Motiv: ' . $reason);
            
            HGEZLPFCR_Logger::log('AWB deleted from order (not found in FanCourier)', [
                'order_id' => $order_id,
                'old_awb' => $awb,
                'reason' => $reason
            ]);
            
            // Debug verification
            self::debug_awb_data($order_id, 'After conditional AWB delete');
        } else {
            HGEZLPFCR_Logger::error('Failed to delete AWB from order', [
                'order_id' => $order_id,
                'awb' => $awb,
                'awb_after' => $awb_after,
                'stat_after' => $stat_after,
                'reason' => $reason
            ]);
            
            // Debug failed deletion
            self::debug_awb_data($order_id, 'After failed conditional delete');
        }
        
        return $success;
    }

    /**
     * Safely clear order caches - only use functions that exist
     */
    private static function clear_order_caches($order_id) {
        // Core WordPress cache clearing
        if (function_exists('clean_post_cache')) {
            clean_post_cache($order_id);
        }
        
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($order_id, 'posts');
            wp_cache_delete($order_id, 'post_meta');
            wp_cache_delete('wc_order_' . $order_id);
            wp_cache_delete($order_id, 'wc_order');
            wp_cache_delete('wc_order_data_' . $order_id);
        }
        
        // WooCommerce specific cache clearing
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients($order_id);
        }
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush_group') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            // Only use advanced cache functions if external object cache is active
            wp_cache_flush_group('woocommerce');
        }
    }

    /**
     * Test specific AWB existence for troubleshooting
     */
    public static function test_awb_existence($awb_number) {
        HGEZLPFCR_Logger::log("TESTING AWB EXISTENCE", [
            'awb' => $awb_number,
            'timestamp' => gmdate('Y-m-d H:i:s')
        ]);
        
        $api = new HGEZLPFCR_API_Client();
        
        // Test 1: Direct API call
        $direct_result = $api->get_status($awb_number);
        HGEZLPFCR_Logger::log("TEST 1 - Direct get_status call", [
            'awb' => $awb_number,
            'result' => $direct_result,
            'is_wp_error' => is_wp_error($direct_result),
            'result_type' => gettype($direct_result)
        ]);
        
        // Test 2: Our existence check
        $exists_result = $api->check_awb_exists($awb_number);
        HGEZLPFCR_Logger::log("TEST 2 - check_awb_exists call", [
            'awb' => $awb_number,
            'result' => $exists_result,
            'is_wp_error' => is_wp_error($exists_result),
            'result_type' => gettype($exists_result)
        ]);
        
        return [
            'direct' => $direct_result,
            'exists_check' => $exists_result
        ];
    }

    /**
     * Debug function to check AWB data state for troubleshooting
     */
    public static function debug_awb_data($order_id, $context = '') {
        HGEZLPFCR_Logger::log("DEBUG AWB DATA - $context", [
            'order_id' => $order_id,
            'context' => $context,
            'timestamp' => gmdate('Y-m-d H:i:s')
        ]);
        
        // Test direct database query
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Debug function for troubleshooting AWB data issues, caching not needed
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s)",
            $order_id,
            self::META_AWB,
            self::META_STAT
        ), ARRAY_A);
        
        HGEZLPFCR_Logger::log("DEBUG - Direct DB query results", [
            'order_id' => $order_id,
            'db_results' => $meta_results
        ]);
        
        // Test WooCommerce order object
        clean_post_cache($order_id);
        $order = wc_get_order($order_id);
        if ($order) {
            $awb_meta = $order->get_meta(self::META_AWB);
            $stat_meta = $order->get_meta(self::META_STAT);
            
            HGEZLPFCR_Logger::log("DEBUG - WooCommerce object results", [
                'order_id' => $order_id,
                'awb_meta' => $awb_meta,
                'stat_meta' => $stat_meta,
                'order_exists' => true
            ]);
        } else {
            HGEZLPFCR_Logger::log("DEBUG - WooCommerce object not found", [
                'order_id' => $order_id
            ]);
        }
    }

    protected static function verify_nonce_and_caps() {
        if (!current_user_can('manage_woocommerce')) {
            HGEZLPFCR_Logger::error('Permission denied for AWB action', [
                'user_id' => get_current_user_id(),
                'capability' => 'manage_woocommerce',
                'user_can' => current_user_can('manage_woocommerce')
            ]);
            wp_die('Permisiuni insuficiente.');
        }
        
        // Check nonce from POST or GET
        // Get nonce from POST or GET, sanitize it properly
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- This IS the nonce verification function
        $nonce = '';
        if (isset($_POST['hgezlpfcr_awb_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['hgezlpfcr_awb_nonce']));
        } elseif (isset($_GET['hgezlpfcr_awb_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_GET['hgezlpfcr_awb_nonce']));
        }

        if (empty($nonce)) {
            HGEZLPFCR_Logger::error('Missing nonce for AWB action', [
                'user_id' => get_current_user_id()
            ]);
            wp_die('Nonce lipsă.');
        }

        if (!wp_verify_nonce($nonce, 'hgezlpfcr_awb_actions')) {
            HGEZLPFCR_Logger::error('Invalid nonce for AWB action', [
                'user_id' => get_current_user_id()
            ]);
            wp_die('Nonce invalid.');
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
    }

    protected static function admin_notice(string $msg, string $type, int $order_id) {
        // If we're in AJAX or automated process, don't redirect
        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        
        // For manual actions from orders list, redirect back to orders list with notice
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified before calling this function
        $get_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if (in_array($get_action, ['hgezlpfcr_generate_awb', 'hgezlpfcr_download_awb', 'hgezlpfcr_sync_awb'], true)) {
            wp_safe_redirect(add_query_arg([
                'post_type' => 'shop_order',
                'fc_notice' => rawurlencode($msg),
                'fc_type' => $type
            ], admin_url('edit.php')));
            exit;
        }

        // For form submissions, redirect to individual order page
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified before calling this function
        $post_action = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';
        if (in_array($post_action, ['hgezlpfcr_generate_awb', 'hgezlpfcr_download_awb', 'hgezlpfcr_sync_awb'], true)) {
            wp_safe_redirect(add_query_arg(['post' => $order_id, 'action' => 'edit', 'fc_notice' => rawurlencode($msg), 'fc_type' => $type], admin_url('post.php')));
            exit;
        }
    }

    /** simple transient-based lock */
    protected static function acquire_lock(string $key, string $token): bool {
        if (get_transient($key)) return false;
        return set_transient($key, $token, self::LOCK_TTL);
    }
    protected static function release_lock(string $key, string $token): void {
        $val = get_transient($key);
        if ($val === $token) delete_transient($key);
    }



         /** AJAX handler for generating AWB */
     public static function handle_generate_awb_ajax() {

        // Verify nonce and capabilities - sanitize nonce before verification
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'hgezlpfcr_awb_ajax') || !current_user_can('manage_woocommerce')) {
            HGEZLPFCR_Logger::error('AJAX permission denied', ['user_can' => current_user_can('manage_woocommerce')]);
            wp_die('Permisiuni insuficiente.');
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitized with absint
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Logging only
            HGEZLPFCR_Logger::error('Invalid order ID in AJAX request', [
                'order_id' => isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : 'not_set'
            ]);
            wp_send_json_error('ID comandă invalid.');
        }
        
        HGEZLPFCR_Logger::log('AJAX AWB generation started', ['order_id' => $order_id]);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            HGEZLPFCR_Logger::error('Order not found', ['order_id' => $order_id]);
            wp_send_json_error('Comandă invalidă.');
        }
        
        // Check if order status allows AWB generation
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $order_status = $order->get_status();
        if (!in_array($order_status, $allowed_statuses)) {
            HGEZLPFCR_Logger::error('Order status not allowed for AWB generation', ['order_id' => $order_id, 'status' => $order_status, 'allowed' => $allowed_statuses]);
            wp_send_json_error('Generarea AWB nu este permisă pentru acest status de comandă.');
        }
        
        // Check if AWB was deleted from FanCourier before attempting to restore from history
        $history = $order->get_meta(self::META_HISTORY);
        $awb_was_deleted = false;
        
        if (is_array($history) && !empty($history)) {
            // Caută ultima acțiune din istoric
            $latest_action = null;
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && in_array($entry['action'], ['AWB Generat', 'AWB Șters'])) {
                    $latest_action = $entry['action'];
                    break;
                }
            }
            
            // Dacă ultima acțiune este "AWB Șters", AWB-ul a fost șters din FanCourier
            if ($latest_action === 'AWB Șters') {
                $awb_was_deleted = true;
                HGEZLPFCR_Logger::log('AWB was deleted from FanCourier, allowing regeneration via AJAX', ['order_id' => $order_id]);
            }
        }
        
        // Check if AWB already exists and it wasn't deleted
        if ($order->get_meta(self::META_AWB) && !$awb_was_deleted) {
            HGEZLPFCR_Logger::log('AWB already exists', ['order_id' => $order_id, 'awb' => $order->get_meta(self::META_AWB)]);
            wp_send_json_error('AWB deja existent pentru această comandă.');
        }
        
        // Check if AWB exists in history but not in meta data (only if not deleted)
        if (!$awb_was_deleted) {
            $awb_from_history = self::restore_awb_from_history($order, true); // Sync status when restoring
            if ($awb_from_history) {
                wp_send_json_success([
                    'message' => 'AWB restaurat din istoric: ' . $awb_from_history,
                    'awb' => $awb_from_history,
                    'status' => $order->get_meta(self::META_STAT),
                    'html' => self::get_awb_actions_html($order_id)
                ]);
            }
        }
        
        // Log the attempt
        self::log_awb_action($order_id, 'Tentativă Generare AWB', 'Prin AJAX');
        
        try {
            // Check if API client exists
            if (!class_exists('HGEZLPFCR_API_Client')) {
                HGEZLPFCR_Logger::error('HGEZLPFCR_API_Client class not found');
                wp_send_json_error('API Client nu este disponibil.');
            }
            
            // Check API credentials
            $user = HGEZLPFCR_Settings::get('hgezlpfcr_user', '');
            $pass = HGEZLPFCR_Settings::get('hgezlpfcr_pass', '');
            $client = HGEZLPFCR_Settings::get('hgezlpfcr_client', '');
            
            HGEZLPFCR_Logger::log('API credentials check', [
                'order_id' => $order_id,
                'has_user' => !empty($user),
                'has_pass' => !empty($pass),
                'has_client' => !empty($client)
            ]);
            
            if (empty($user) || empty($pass) || empty($client)) {
                HGEZLPFCR_Logger::error('Missing API credentials', ['order_id' => $order_id, 'missing' => [
                    'user' => empty($user),
                    'pass' => empty($pass),
                    'client' => empty($client)
                ]]);
                wp_send_json_error('Credențialele API nu sunt complete. Verifică setările plugin-ului - username, parola și clientId sunt obligatorii.');
            }
            
            // Generate AWB
            HGEZLPFCR_Logger::log('Calling create_awb_for_order', ['order_id' => $order_id]);

            try {
                $result = self::create_awb_for_order($order_id, false);

                HGEZLPFCR_Logger::log('create_awb_for_order result', ['order_id' => $order_id, 'result' => $result]);

                if ($result === false) {
                    HGEZLPFCR_Logger::error('create_awb_for_order returned false', ['order_id' => $order_id]);
                    wp_send_json_error('Nu s-a putut genera AWB-ul. Verifică log-urile pentru detalii.');
                }
            } catch (Exception $e) {
                HGEZLPFCR_Logger::error('Exception caught in AJAX handler', ['order_id' => $order_id, 'exception' => $e->getMessage()]);
                wp_send_json_error($e->getMessage());
            }
            
            // Get updated order data
            $order = wc_get_order($order_id);
            $awb = $order->get_meta(self::META_AWB);
            $status = $order->get_meta(self::META_STAT);
            
            HGEZLPFCR_Logger::log('Order meta after AWB generation', ['order_id' => $order_id, 'awb' => $awb, 'status' => $status]);
            
            if ($awb) {
                HGEZLPFCR_Logger::log('AWB generated successfully', ['order_id' => $order_id, 'awb' => $awb]);
                wp_send_json_success([
                    'message' => 'AWB generat cu succes: ' . $awb,
                    'awb' => $awb,
                    'status' => $status,
                    'html' => self::get_awb_actions_html($order_id)
                ]);
                         } else {
                 HGEZLPFCR_Logger::error('AWB not found after generation', ['order_id' => $order_id, 'result' => $result]);
                self::log_awb_action($order_id, 'Eroare Generare AWB', 'AWB nu a fost generat după apelul create_awb_for_order');
                wp_send_json_error('Nu s-a putut genera AWB-ul. Verifică log-urile pentru detalii.');
            }
            
        } catch (Exception $e) {
            // Log the exception
            HGEZLPFCR_Logger::error('Exception in AJAX AWB generation', ['order_id' => $order_id, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            self::log_awb_action($order_id, 'Excepție Generare AWB', 'Excepție: ' . $e->getMessage());
            wp_send_json_error('Eroare la generarea AWB: ' . $e->getMessage());
        }
    }

    public static function handle_sync_awb_ajax() {
        // Verify nonce and capabilities - sanitize nonce before verification
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'hgezlpfcr_awb_ajax') || !current_user_can('manage_woocommerce')) {
            HGEZLPFCR_Logger::error('AJAX sync AWB permission denied', ['user_can' => current_user_can('manage_woocommerce')]);
            wp_die('Permisiuni insuficiente.');
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Sanitized with absint
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Logging only
            HGEZLPFCR_Logger::error('Invalid order ID in sync AWB AJAX request', [
                'order_id' => isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : 'not_set'
            ]);
            wp_send_json_error('ID comandă invalid.');
        }

        HGEZLPFCR_Logger::log('AJAX AWB sync started', ['order_id' => $order_id]);

        $order = wc_get_order($order_id);
        if (!$order) {
            HGEZLPFCR_Logger::error('Order not found for sync', ['order_id' => $order_id]);
            wp_send_json_error('Comandă invalidă.');
        }

        $awb_before_sync = $order->get_meta(self::META_AWB);
        if (!$awb_before_sync) {
            HGEZLPFCR_Logger::error('No AWB found to sync', ['order_id' => $order_id]);
            wp_send_json_error('Nu există AWB pentru această comandă.');
        }

        try {
            // Call existing sync function
            self::sync_status_for_order($order_id, false); // No redirect for AJAX
            
            // Refresh order data to get updated status
            $order = wc_get_order($order_id);
            $awb_after_sync = $order->get_meta(self::META_AWB);
            $status = $order->get_meta(self::META_STAT);
            
            if ($awb_after_sync) {
                // AWB still exists - sync was successful
                wp_send_json_success([
                    'message' => 'AWB găsit în Borderou FanCourier - Verificat cu succes',
                    'awb' => $awb_after_sync,
                    'status' => $status ?: 'activ',
                    'html' => self::get_awb_actions_html($order_id)
                ]);
            } else {
                // AWB was removed - it didn't exist in FanCourier
                wp_send_json_success([
                    'message' => 'AWB șters - nu există în Borderou FanCourier pentru ziua respectivă',
                    'awb' => '',
                    'status' => '',
                    'html' => self::get_awb_actions_html($order_id)
                ]);
            }

        } catch (Exception $e) {
            HGEZLPFCR_Logger::error('Exception in AJAX AWB sync', [
                'order_id' => $order_id,
                'awb' => $awb_before_sync,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error('Eroare la verificare AWB: ' . $e->getMessage());
        }
    }
    
    /** Get updated HTML for AWB actions box */
    private static function get_awb_actions_html($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return '';
        
        $awb = $order->get_meta(self::META_AWB);
        $status = $order->get_meta(self::META_STAT);
        $order_status = $order->get_status();
        $allowed_statuses = ['processing', 'comanda-noua', 'completed', 'plata-confirmata', 'emite-factura-avans'];
        $can_generate = in_array($order_status, $allowed_statuses);
        
        ob_start();
        
        if ($awb) {
            // Show AWB info and actions
            echo '<p><strong>AWB:</strong> <code>' . esc_html($awb) . '</code></p>';
            if ($status) {
                echo '<p><strong>Status:</strong> ' . esc_html($status) . '</p>';
            }
            
            echo '<div class="fc-awb-buttons" style="margin: 10px 0;">';
            
            // Download PDF link
            $download_url = admin_url('admin-post.php?' . http_build_query([
                'action' => 'hgezlpfcr_download_awb',
                'post_id' => $order_id,
                'hgezlpfcr_awb_nonce' => wp_create_nonce('hgezlpfcr_awb_actions')
            ]));
            echo '<a href="' . esc_url($download_url) . '" class="button">📄 Descarcă PDF</a> ';
            
            // Sync status button (AJAX)
            $nonce = wp_create_nonce('hgezlpfcr_awb_ajax');
            echo '<button type="button" class="button fc-sync-awb-btn" data-order-id="' . esc_attr($order_id) . '" data-nonce="' . esc_attr($nonce) . '">🔄 Verifică AWB</button>';
            
            echo '</div>';
            
        } elseif ($can_generate) {
            // Show generate AWB button
            echo '<p><strong>Status comandă:</strong> ' . esc_html($order_status) . '</p>';
            echo '<p><em>AWB nu a fost generat încă.</em></p>';
            echo '<div class="fc-awb-buttons" style="margin: 10px 0;">';
            echo '<button type="button" class="button button-primary fc-generate-awb-btn" data-order-id="' . esc_attr($order_id) . '">🚚 Generează AWB</button>';
            echo '</div>';
            
        } else {
            // Order status doesn't allow AWB generation
            echo '<p><strong>Status comandă:</strong> ' . esc_html($order_status) . '</p>';
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>Generarea AWB nu este disponibilă pentru acest status.</strong><br>';
            echo 'Status-uri permise: Comandă nouă, Completed, Plată confirmată, Emite factură Avans';
            echo '</p></div>';
        }
        
        return ob_get_clean();
    }
    
    /** Enqueue admin scripts */
    public static function enqueue_admin_scripts($hook) {
        global $post_type;
        
        // Only on order edit pages
        if (!in_array($hook, ['post.php', 'post-new.php']) || !in_array($post_type, ['shop_order'])) {
            return;
        }
        
        wp_enqueue_script(
            'fc-admin-awb',
            plugin_dir_url(HGEZLPFCR_PLUGIN_FILE) . 'assets/js/fc-admin-awb.js',
            ['jquery'],
            HGEZLPFCR_PLUGIN_VER,
            true
        );
        
        wp_localize_script('fc-admin-awb', 'hgezlpfcr_awb_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hgezlpfcr_awb_ajax'),
            'generating_text' => 'Se generează AWB...',
            'error_text' => 'Eroare la generarea AWB',
            'success_text' => 'AWB generat cu succes!'
        ]);

        // Add inline CSS for error rows
        wp_add_inline_style('wp-admin', '
            .hgezlpfcr-error-row {
                background-color: #ffebee !important;
            }
        ');
    }
    
    
    
    /** Clear cached API token */
    public static function clear_api_token() {
        delete_option('hgezlpfcr_api_token');
        delete_option('hgezlpfcr_api_token_expires');
        HGEZLPFCR_Logger::log('API token manually cleared');
    }
    
    /** Handle clear token action */
    public static function handle_clear_token() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permisiuni insuficiente.');
        }
        
        self::clear_api_token();
        
        $message = 'Token-ul API a fost șters. Va fi regenerat automat la următoarea cerere.';
        $type = 'success';
        
        wp_safe_redirect(add_query_arg([
            'fc_notice' => rawurlencode($message),
            'fc_type' => $type
        ], admin_url('admin.php?page=wc-settings&tab=hgezlpfcr')));
        exit;
    }

    /** Helper to build observation text for AWB */
    protected static function build_observation_text(WC_Order $order): string {
        // Construim textul complet: "Comanda #96165 - [produse]"
        $order_prefix = 'Comanda #' . $order->get_order_number() . ' - ';
        $products_text = self::build_full_product_list($order);

        $full_text = $order_prefix . $products_text;

        // Limităm la 113 caractere pentru câmpul Observation (rândul 1)
        if (mb_strlen($full_text) > 113) {
            return mb_substr($full_text, 0, 110) . '...';
        }

        return $full_text;
    }
    
    /** Extract AWB number and generation date from history if it exists */
    protected static function get_awb_from_history(WC_Order $order): ?array {
        $history = $order->get_meta(self::META_HISTORY);
        if (!$history || !is_array($history)) {
            return null;
        }
        
        // Sort history by timestamp (newest first)
        usort($history, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        // Look for AWB generation entries
        foreach ($history as $entry) {
            if ($entry['action'] === 'AWB Generat' && !empty($entry['details'])) {
                // Extract AWB number from details (format: "AWB: 123456789")
                if (preg_match('/AWB:\s*([A-Z0-9]+)/i', $entry['details'], $matches)) {
                    $awb = $matches[1];
                    $generation_date = isset($entry['timestamp']) ?
                        gmdate('Y-m-d', $entry['timestamp'] + (3 * 3600)) : // Add 3 hours to historic timestamp
                        self::get_fancourier_date(); // Use current FanCourier date as fallback
                    
                    return [
                        'awb' => $awb,
                        'date' => $generation_date
                    ];
                }
            }
        }
        
        return null;
    }
    
    /** Restore AWB from history and optionally sync status - only if AWB exists in FanCourier */
    protected static function restore_awb_from_history(WC_Order $order, bool $sync_status = true): ?string {
        // Check if restore is blocked by deletion markers
        $history = $order->get_meta(self::META_HISTORY);
        if (is_array($history) && !empty($history)) {
            foreach (array_reverse($history) as $entry) {
                if (isset($entry['action']) && (
                    strpos($entry['action'], 'AWB Șters') !== false ||
                    strpos($entry['action'], 'Inexistent FanCourier') !== false
                )) {
                    HGEZLPFCR_Logger::log('AWB restore blocked by deletion marker', [
                        'order_id' => $order->get_id(),
                        'blocking_action' => $entry['action']
                    ]);
                    return null;
                }
            }
        }
        
        $awb_data = self::get_awb_from_history($order);
        if (!$awb_data) {
            return null;
        }
        
        $awb = $awb_data['awb'];
        $generation_date = $awb_data['date'];
        
        // VERIFY AWB exists in FanCourier before restoring
        $api = new HGEZLPFCR_API_Client();
        $awb_exists = $api->check_awb_exists($awb, $generation_date);
        
        HGEZLPFCR_Logger::log('AWB restore - checking FanCourier existence', [
            'order_id' => $order->get_id(),
            'awb' => $awb,
            'exists_in_fancourier' => $awb_exists
        ]);
        
        // Cache the result for performance
        $cache_key = 'fc_awb_exists_' . $order->get_id();
        $cache_duration = 30 * MINUTE_IN_SECONDS; // 30 minutes cache
        
        // Don't restore if API error or AWB doesn't exist
        if (is_wp_error($awb_exists) || $awb_exists === false) {
            set_transient($cache_key, 'not_exists', $cache_duration);
            HGEZLPFCR_Logger::log('AWB restore blocked - does not exist in FanCourier', [
                'order_id' => $order->get_id(),
                'awb' => $awb,
                'api_result' => $awb_exists
            ]);
            return null;
        }
        
        // Cache positive result
        set_transient($cache_key, 'exists', $cache_duration);
        
        // AWB exists in FanCourier - safe to restore
        $order->update_meta_data(self::META_AWB, $awb);
        $order->update_meta_data(self::META_AWB_DATE, $generation_date); // Restore generation date (already adjusted)
        $order->save();
        
        HGEZLPFCR_Logger::log('AWB restored from history (exists in FanCourier)', [
            'order_id' => $order->get_id(),
            'awb' => $awb,
            'sync_status' => $sync_status,
            'order_status' => $order->get_status()
        ]);

        // Optionally sync status from FanCourier (skip for completed orders)
        if ($sync_status && function_exists('as_enqueue_async_action') && $order->get_status() !== 'completed') {
            as_enqueue_async_action('hgezlpfcr_sync_awb_async', [$order->get_id()], 'woo-fancourier');
        }

        return $awb;
    }
    
    public static function handle_reset_order_markers() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Nu ai permisiuni suficiente.');
        }
        
        // Reset markers for orders 96157 and 96158
        $order_ids = [96157, 96158];
        $reset_count = 0;
        
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if ($order) {
                $history = $order->get_meta(self::META_HISTORY);
                if (is_array($history) && !empty($history)) {
                    // Filter out deletion markers
                    $filtered_history = array_filter($history, function($entry) {
                        return !(isset($entry['action']) && (
                            strpos($entry['action'], 'AWB Șters') !== false ||
                            strpos($entry['action'], 'Inexistent FanCourier') !== false ||
                            strpos($entry['action'], 'Șters din Borderou') !== false
                        ));
                    });
                    
                    if (count($filtered_history) !== count($history)) {
                        $order->update_meta_data(self::META_HISTORY, array_values($filtered_history));
                        $order->save_meta_data();
                        $reset_count++;
                        
                        HGEZLPFCR_Logger::log('Order markers reset', [
                            'order_id' => $oid,
                            'original_entries' => count($history),
                            'filtered_entries' => count($filtered_history)
                        ]);
                    }
                }
            }
        }
        
        $redirect_url = admin_url('edit.php?post_type=shop_order&reset_markers_done=' . $reset_count);
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /** Async handler for AWB restoration */
    public static function restore_awb_from_history_async($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            HGEZLPFCR_Logger::error('Async AWB restoration failed - order not found', ['order_id' => $order_id]);
            return;
        }
        
        HGEZLPFCR_Logger::log('Starting async AWB restoration', ['order_id' => $order_id]);
        
        // Perform the full restoration with API calls
        $awb = self::restore_awb_from_history($order, true); // Include sync status
        
        if ($awb) {
            HGEZLPFCR_Logger::log('Async AWB restoration successful', [
                'order_id' => $order_id,
                'awb' => $awb
            ]);
        } else {
            HGEZLPFCR_Logger::log('Async AWB restoration completed - no AWB to restore', ['order_id' => $order_id]);
        }
    }
    
    /** Fast cached AWB restoration - no API calls */
    protected static function restore_awb_from_history_cached(WC_Order $order): ?string {
        // Check cache first
        $cache_key = 'fc_awb_exists_' . $order->get_id();
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            if ($cached_result === 'exists') {
                // AWB exists in FanCourier, safe to restore from history
                $awb_data = self::get_awb_from_history($order);
                if ($awb_data) {
                    $order->update_meta_data(self::META_AWB, $awb_data['awb']);
                    $order->update_meta_data(self::META_AWB_DATE, $awb_data['generation_date']);
                    $order->save_meta_data();
                    
                    HGEZLPFCR_Logger::log('AWB restored from cache', [
                        'order_id' => $order->get_id(),
                        'awb' => $awb_data['awb']
                    ]);
                    
                    return $awb_data['awb'];
                }
            }
            // If cached result is 'not_exists', don't restore
            return null;
        }
        
        // No cache available, don't make API calls in sync context
        // Schedule async restoration instead
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('hgezlpfcr_restore_awb_async', [$order->get_id()], 'woo-fancourier');
            HGEZLPFCR_Logger::log('AWB restoration scheduled (no cache)', ['order_id' => $order->get_id()]);
        }

        return null;
    }

}
