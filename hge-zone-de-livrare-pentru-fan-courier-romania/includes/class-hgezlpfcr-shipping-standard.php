<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Shipping_Standard extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id                 = 'fc_standard';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('FAN Courier: Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        $this->method_description = __('Livrare standard FAN Courier (cost fix configurabil).', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        $this->enabled            = 'yes';
        $this->title              = __('FAN Courier Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania');
        $this->init();
    }
    public function init() {
        $this->instance_form_fields = [
            'title' => [
                'title'       => __('Titlu la checkout', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'text',
                'default'     => __('FAN Courier Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'enable_dynamic_pricing' => [
                'title'       => __('Tarifare dinamică', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'checkbox',
                'label'       => __('Activează calculul în timp real prin API', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'default'     => 'yes',
                'description' => __('Dacă este bifat, costul va fi calculat dinamic prin API FAN Courier.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'free_shipping_min' => [
                'title'       => __('Transport gratuit minim', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Valoarea minimă pentru transport gratuit. Lăsați 0 pentru a dezactiva.', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'cost_bucharest' => [
                'title'       => __('Cost Fix București', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Cost fix pentru București și Ilfov (când tarifare dinamică este dezactivată).', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
            ],
            'cost_country' => [
                'title'       => __('Cost Fix Țară', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                'type'        => 'price',
                'default'     => '0',
                'description' => __('Cost fix pentru restul țării, în afara București și Ilfov (când tarifare dinamică este dezactivată).', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
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
            
            // First check if service is available for destination
            $service_check = [
                'service' => 'Standard',
                'county' => $destination['state'] ?? '',
                'locality' => $destination['city'] ?? '',
                'weight' => $this->calculate_package_weight($package),
                'length' => 30,
                'width' => 20,
                'height' => 10,
            ];

            $availability = $api->check_service($service_check);
            if (is_wp_error($availability) || empty($availability['available'])) {
                HGEZLPFCR_Logger::log('Service not available for destination', $destination);
                return 0;
            }
            
            $params = [
                'service' => 'Standard',
                'county' => $destination['state'] ?? '',
                'locality' => $destination['city'] ?? '',
                'weight' => $this->calculate_package_weight($package),
                'length' => 30,
                'width' => 20,
                'height' => 10,
                'declared_value' => WC()->cart ? WC()->cart->get_total('edit') : 0, // Folosește total-ul cu TVA
            ];
            
            $response = $api->get_tariff($params);
            
            if (is_wp_error($response)) {
                HGEZLPFCR_Logger::error('API tariff calculation failed', ['error' => $response->get_error_message()]);
                return 0;
            }
            
            return isset($response['price']) ? (float) $response['price'] : 0;
            
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
        
        // Check if we have required settings for API
        $user = HGEZLPFCR_Settings::get('hgezlpfcr_user', '');
        $client = HGEZLPFCR_Settings::get('hgezlpfcr_client', '');
        if (empty($user) || empty($client)) {
            // If no API credentials, allow fixed pricing only
            $enable_dynamic = $this->get_instance_option('enable_dynamic_pricing', 'yes') === 'yes';
            if ($enable_dynamic) {
                return false; // Can't do dynamic pricing without credentials
            }
        }
        
        // If dynamic pricing is enabled and we have destination data, check service availability
        $enable_dynamic = $this->get_instance_option('enable_dynamic_pricing', 'yes') === 'yes';
        if ($enable_dynamic && !empty($package['destination']['city']) && !empty($user) && !empty($client)) {
            try {
                $api = new HGEZLPFCR_API_Client();
                $check = $api->check_service([
                    'service' => 'Standard',
                    'county' => $package['destination']['state'] ?? '',
                    'locality' => $package['destination']['city'] ?? '',
                    'weight' => $this->calculate_package_weight($package),
                    'length' => 30,
                    'width' => 20,
                    'height' => 10,
                ]);

                if (is_wp_error($check) || empty($check['available'])) {
                    HGEZLPFCR_Logger::log('Service not available for destination', $package['destination']);
                    return false;
                }
            } catch (Exception $e) {
                HGEZLPFCR_Logger::error('Service availability check failed', ['exception' => $e->getMessage()]);
                // Fall back to fixed pricing if API check fails
            }
        }
        
        return true;
    }
    
    protected function get_location_based_cost($package) {
        $destination = $package['destination'] ?? [];
        $city = trim($destination['city'] ?? '');
        $state = trim($destination['state'] ?? '');
        
        // Check if destination is București (B) or Ilfov (IF) based on WooCommerce values
        $is_bucharest_area = false;
        
        // Check by state/county code (most reliable)
        if ($state === 'B' || $state === 'IF') {
            $is_bucharest_area = true;
        }
        // Additional checks for city names (case-insensitive)
        elseif (!empty($city)) {
            $city_lower = strtolower($city);
            // București sectors
            if (strpos($city_lower, 'sector') !== false && 
                (strpos($city_lower, '1') !== false || 
                 strpos($city_lower, '2') !== false || 
                 strpos($city_lower, '3') !== false || 
                 strpos($city_lower, '4') !== false || 
                 strpos($city_lower, '5') !== false || 
                 strpos($city_lower, '6') !== false)) {
                $is_bucharest_area = true;
            }
            // General București variations
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
            // Use București cost from instance settings
            return (float) $this->get_instance_option('cost_bucharest', 0);
        } else {
            // Use country cost from instance settings
            return (float) $this->get_instance_option('cost_country', 0);
        }
    }
    
    protected function calculate_package_weight($package) {
        $weight = 0;
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $product_weight = (float) $product->get_weight();
            $weight += $product_weight * $item['quantity'];
        }
        return max($weight, 0.1); // minimum 0.1 kg
    }
}
