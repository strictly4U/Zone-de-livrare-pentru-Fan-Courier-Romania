<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Shipping_Standard extends WC_Shipping_Method {

    /**
     * Romanian county code -> FC API name mapping. WooCommerce stores
     * counties as 2-letter state codes (B, CJ, IS, TM, etc.), but FAN
     * Courier's /check-service + /get-tariff endpoints expect the full
     * Romanian name without diacritics (Bucuresti, Cluj, Iasi, Timis).
     *
     * Sending the raw code returns HTTP 422 "Perechea judet-localitate
     * introdusa este incorecta" and the shipping method gets marked
     * unavailable in the cart — option disappears.
     *
     * Mirrors FC Pro's $county_map in class-hgezlpfcr-pro-shipping-base.php:244
     * for cross-plugin consistency.
     *
     * @since 1.0.13 (1nq follow-up to djo)
     */
    protected static $county_map = [
        'AB' => 'Alba',       'AR' => 'Arad',       'AG' => 'Arges',     'BC' => 'Bacau',
        'BH' => 'Bihor',      'BN' => 'Bistrita-Nasaud', 'BT' => 'Botosani', 'BV' => 'Brasov',
        'BR' => 'Braila',     'B'  => 'Bucuresti',  'BZ' => 'Buzau',     'CS' => 'Caras-Severin',
        'CL' => 'Calarasi',   'CJ' => 'Cluj',       'CT' => 'Constanta', 'CV' => 'Covasna',
        'DB' => 'Dambovita',  'DJ' => 'Dolj',       'GL' => 'Galati',    'GR' => 'Giurgiu',
        'GJ' => 'Gorj',       'HR' => 'Harghita',   'HD' => 'Hunedoara', 'IL' => 'Ialomita',
        'IS' => 'Iasi',       'IF' => 'Ilfov',      'MM' => 'Maramures', 'MH' => 'Mehedinti',
        'MS' => 'Mures',      'NT' => 'Neamt',      'OT' => 'Olt',       'PH' => 'Prahova',
        'SM' => 'Satu Mare',  'SJ' => 'Salaj',      'SB' => 'Sibiu',     'SV' => 'Suceava',
        'TR' => 'Teleorman',  'TM' => 'Timis',      'TL' => 'Tulcea',    'VS' => 'Vaslui',
        'VL' => 'Valcea',     'VN' => 'Vrancea',
    ];

    /**
     * Convert a 2-letter WC state code to the full Romanian county name
     * the FC API expects. Returns the input unchanged if not found in the
     * map (defensive — handles non-RO states or future codes).
     *
     * @since 1.0.13
     */
    protected function get_county_name($county_code) {
        $code = strtoupper(trim((string) $county_code));
        return self::$county_map[$code] ?? $county_code;
    }

    /**
     * Normalize Bucharest sector localities. FC API rejects "Sector N"
     * as a locality (returns HTTP 500 Server Error) when paired with
     * county "Bucuresti" — it expects the locality to also be "Bucuresti".
     * WooCommerce checkout stores the sector in the city/locality field
     * (common Romanian convention), so we collapse it here for the API
     * request only — the original sector is preserved on the order
     * record because we only change the value sent to FC.
     *
     * Pattern matched: "sector 1" .. "sector 6" (case/space-insensitive).
     *
     * @since 1.0.13
     *
     * @param string $county_name Already mapped via get_county_name()
     * @param string $locality    Raw locality from $destination['city']
     * @return string Normalized locality
     */
    protected function normalize_bucharest_locality($county_name, $locality) {
        if (strcasecmp(trim((string) $county_name), 'Bucuresti') !== 0) {
            return $locality;
        }
        if (preg_match('/^\s*sector\s*[1-6]\s*$/i', (string) $locality)) {
            return 'Bucuresti';
        }
        return $locality;
    }

    /**
     * Strip Romanian diacritics so the API receives ASCII (the FC API
     * is inconsistent about diacritic handling; safer to normalize).
     *
     * @since 1.0.13
     */
    protected function remove_diacritics($str) {
        if (function_exists('transliterator_transliterate')) {
            $out = @transliterator_transliterate('Any-Latin; Latin-ASCII', (string) $str);
            if (is_string($out)) {
                return $out;
            }
        }
        $search  = ['ă', 'â', 'î', 'ș', 'ț', 'Ă', 'Â', 'Î', 'Ș', 'Ț', 'ş', 'ţ', 'Ş', 'Ţ'];
        $replace = ['a', 'a', 'i', 's', 't', 'A', 'A', 'I', 'S', 'T', 's', 't', 'S', 'T'];
        return str_replace($search, $replace, (string) $str);
    }

    public function __construct($instance_id = 0) {
        $this->id                 = 'fc_standard';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('FAN Courier: Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        $this->method_description = __('FAN Courier standard delivery (configurable fixed cost).', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        $this->enabled            = 'yes';
        $this->title              = __('FAN Courier Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        $this->init();
    }
    public function init() {
        $this->instance_form_fields = [
            'title' => [
                'title'       => __('Checkout title', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'text',
                'default'     => __('FAN Courier Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'enable_dynamic_pricing' => [
                'title'       => __('Dynamic pricing', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'checkbox',
                'label'       => __('Enable real-time calculation via API', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'default'     => 'yes',
                'description' => __('If checked, cost will be calculated dynamically via FAN Courier API.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'free_shipping_min' => [
                'title'       => __('Free shipping minimum', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Minimum value for free shipping. Leave 0 to disable.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'cost_bucharest' => [
                'title'       => __('Fixed Cost Bucharest', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Fixed cost for Bucharest and Ilfov (when dynamic pricing is disabled).', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'cost_country' => [
                'title'       => __('Fixed Cost Country', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Fixed cost for the rest of the country, outside Bucharest and Ilfov (when dynamic pricing is disabled).', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
        ];
        $this->init_instance_settings();
        $this->title = $this->get_instance_option('title', $this->title);
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }
    public function calculate_shipping($package = []) {
        $enable_dynamic = $this->get_instance_option('enable_dynamic_pricing', 'yes') === 'yes';
        
        // Check for free shipping first
        $free_shipping_min = (float) $this->get_instance_option('free_shipping_min', 0);
        $cart_total = WC()->cart ? WC()->cart->get_cart_contents_total() : 0;
        
        if ($free_shipping_min > 0 && $cart_total >= $free_shipping_min) {
            $cost = 0;
        } elseif ($enable_dynamic) {
            // Calculate dynamic pricing
            $cost = $this->get_dynamic_cost($package);
            // If dynamic pricing fails, fallback to fixed cost
            if ($cost <= 0) {
                $cost = $this->get_location_based_cost($package);
            }
        } else {
            // Calculate location-based fixed cost
            $cost = $this->get_location_based_cost($package);
        }
        
        HGEZLPFCR_Logger::log('Shipping calculation', [
            'method' => 'fc_standard',
            'enable_dynamic' => $enable_dynamic,
            'free_shipping_min' => $free_shipping_min,
            'cart_total' => $cart_total,
            'calculated_cost' => $cost,
            'destination' => $package['destination'] ?? []
        ]);
        
        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $this->title,
            'cost'  => max(0, $cost), // Ensure cost is never negative
            'meta_data' => [
                'dynamic_pricing' => $enable_dynamic && $cost > 0 ? 'yes' : 'no'
            ],
        ]);
    }
    
    protected function get_dynamic_cost($package) {
        try {
            $api = new HGEZLPFCR_API_Client();

            // Build tariff request from package data
            $destination = $package['destination'] ?? [];
            if (empty($destination['city'])) {
                HGEZLPFCR_Logger::log('Insufficient destination data for dynamic pricing', $destination);
                return 0;
            }

            // WC stores county as 2-letter state code ('B', 'CJ', 'IS', ...);
            // FC API expects the full Romanian name without diacritics
            // ('Bucuresti', 'Cluj', 'Iasi', ...). Converting here (since
            // 1.0.13 / 1nq) — sending the raw code returns HTTP 422 and the
            // method gets marked unavailable. Diacritics also stripped from
            // the locality defensively.
            $county_name      = $this->get_county_name($destination['state'] ?? '');
            $locality_raw     = $this->remove_diacritics($destination['city'] ?? '');
            $locality_clean   = $this->normalize_bucharest_locality($county_name, $locality_raw);

            // Single cached call (since 1.0.13) — replaces the previous 2-step
            // check_service() + get_tariff() pattern. Cache HIT returns ~1ms;
            // cache MISS runs the same 2 HTTP calls as before and caches the
            // combined result (including "not available") for 5 minutes.
            // Cache key: hgezlpfcr_twa_ + md5(service|county|locality|weight_bucket_0.5kg).
            $params = [
                'service'  => 'Standard',
                'county'   => $county_name,
                'locality' => $locality_clean,
                'weight'   => $this->calculate_package_weight($package),
                'length'   => 30,
                'width'    => 20,
                'height'   => 10,
                'declared_value' => WC()->cart ? WC()->cart->get_total('edit') : 0, // Use total with VAT
            ];

            $cached = $api->get_tariff_with_availability_cached($params);

            if (!$cached['available']) {
                HGEZLPFCR_Logger::log('Service not available for destination', $destination + [
                    'error' => $cached['error'],
                ]);
                return 0;
            }

            return (float) $cached['price'];

        } catch (Exception $e) {
            HGEZLPFCR_Logger::error('Dynamic pricing calculation error', ['exception' => $e->getMessage()]);
            return 0;
        }
    }
    
    public function is_available($package) {
        // Basic availability check first
        if (!parent::is_available($package)) {
            return false;
        }

        // Credential gate for dynamic pricing — when dynamic is enabled but
        // credentials are missing, hide the method (we cannot calculate a
        // real tariff). Fixed pricing works without credentials.
        $user           = HGEZLPFCR_Settings::get('hgezlpfcr_user', '');
        $client         = HGEZLPFCR_Settings::get('hgezlpfcr_client', '');
        $enable_dynamic = $this->get_instance_option('enable_dynamic_pricing', 'yes') === 'yes';
        if ($enable_dynamic && (empty($user) || empty($client))) {
            return false;
        }

        // No live API check here — that was duplicating work from
        // calculate_shipping() and (until 1.0.13) bypassed the 1nq county
        // mapping + djo cache wrapper, causing the method to disappear
        // entirely on any FC API hiccup (HTTP 422/500/timeout). The
        // cached wrapper inside get_dynamic_cost() already handles the
        // availability decision (cost 0 -> fall back to fixed pricing in
        // calculate_shipping()). Matches FC Pro's lighter is_available()
        // pattern.
        return true;
    }
    
    protected function get_location_based_cost($package) {
        $destination = $package['destination'] ?? [];
        $city = trim($destination['city'] ?? '');
        $state = trim($destination['state'] ?? '');
        
        // Check if destination is Bucharest (B) or Ilfov (IF) based on WooCommerce values
        $is_bucharest_area = false;
        
        // Check by state/county code (most reliable)
        if ($state === 'B' || $state === 'IF') {
            $is_bucharest_area = true;
        }
        // Additional checks for city names (case-insensitive)
        elseif (!empty($city)) {
            $city_lower = strtolower($city);
            // Bucharest sectors
            if (strpos($city_lower, 'sector') !== false &&
                (strpos($city_lower, '1') !== false ||
                 strpos($city_lower, '2') !== false ||
                 strpos($city_lower, '3') !== false ||
                 strpos($city_lower, '4') !== false ||
                 strpos($city_lower, '5') !== false ||
                 strpos($city_lower, '6') !== false)) {
                $is_bucharest_area = true;
            }
            // General Bucharest variations
            elseif (strpos($city_lower, 'bucuresti') !== false ||
                    strpos($city_lower, 'bucharest') !== false ||
                    strpos($city_lower, 'bucureşti') !== false) {
                $is_bucharest_area = true;
            }
        }
        
        HGEZLPFCR_Logger::log('Location-based cost calculation', [
            'state' => $state,
            'city' => $city,
            'is_bucharest_area' => $is_bucharest_area,
            'bucharest_cost' => $this->get_instance_option('cost_bucharest', 0),
            'country_cost' => $this->get_instance_option('cost_country', 0)
        ]);
        
        if ($is_bucharest_area) {
            // Use Bucharest cost from instance settings
            return (float) $this->get_instance_option('cost_bucharest', 0);
        } else {
            // Use country cost from instance settings
            return (float) $this->get_instance_option('cost_country', 0);
        }
    }
    
    protected function calculate_package_weight($package) {
        // Match the official FC plugin: ignore virtual products, normalize
        // WC's configured weight unit (g/lb/oz) to kg via wc_get_weight(),
        // then round to integer with a 1 kg floor. FC's /get-tariff and
        // /get-tariff-new return HTTP 500 "Server Error" for sub-kg float
        // weights like 0.1 — they expect integer kilograms. Pre-1.0.13
        // we sent the raw float, which caused every cart calculation to
        // burn 3× retries before falling back to the configured fixed cost.
        $weight = 0;
        $contents = isset($package['contents']) && is_array($package['contents']) ? $package['contents'] : [];
        foreach ($contents as $item) {
            if (!isset($item['data']) || !is_object($item['data']) || $item['data']->is_virtual()) {
                continue;
            }
            $raw = (float) $item['data']->get_weight();
            if ($raw <= 0) {
                continue;
            }
            $kg = (float) wc_get_weight($raw, 'kg');
            $weight += $kg * (int) ($item['quantity'] ?? 1);
        }
        $rounded = (int) round($weight);
        return $rounded < 1 ? 1 : $rounded;
    }
}
