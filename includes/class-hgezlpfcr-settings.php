<?php
if (!defined('ABSPATH')) exit;

class HGEZLPFCR_Settings {
    public static function init() {
        add_filter('woocommerce_get_settings_pages', function ($pages) {
            $pages[] = new class extends WC_Settings_Page {
                public function __construct() {
                    $this->id    = 'hgezlpfcr';
                    $this->label = __('Fan Courier', 'hge-zone-de-livrare-pentru-fan-courier-romania');
                    parent::__construct();
                }

                /**
                 * Get sections for Fan Courier settings
                 *
                 * @return array Sections
                 */
                public function get_sections() {
                    $sections = [
                        '' => __('Standard', 'hge-zone-de-livrare-pentru-fan-courier-romania'), // Empty string = default section
                    ];

                    // Allow other plugins (like FC PRO) to add their sections
                    return apply_filters('woocommerce_get_sections_' . $this->id, $sections);
                }

                public function get_settings($current_section = '') {
                    $settings = [
                        ['title' => __('Fan Courier Settings', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 'type' => 'title', 'id' => 'hgezlpfcr_section'],
                        ['title' => 'Username',  'id' => 'hgezlpfcr_user',     'type' => 'text',     'desc' => 'Username for selfAWB application', 'css'=>'min-width:300px'],
                        ['title' => 'Password',    'id' => 'hgezlpfcr_pass',     'type' => 'password', 'desc' => 'Password for selfAWB application', 'css'=>'min-width:300px'],
                        ['title' => 'Client ID', 'id' => 'hgezlpfcr_client',   'type' => 'text',     'desc' => 'Client ID for API (required for AWB generation)', 'css'=>'min-width:300px'],
                        ['title' => 'Asynchronous Execution (Action Scheduler)', 'id' => 'hgezlpfcr_async', 'type' => 'checkbox', 'default' => 'yes', 'desc'=>'Recommended for high traffic'],
                        ['title' => 'API Retry (max)', 'id' => 'hgezlpfcr_retries', 'type' => 'number', 'default' => 2, 'custom_attributes' => ['min'=>0,'max'=>5,'step'=>1]],
                        ['title' => 'API Timeout (sec)', 'id' => 'hgezlpfcr_timeout', 'type' => 'number', 'default' => 20, 'custom_attributes' => ['min'=>5, 'step'=>1]],
                        ['title' => 'Debug log', 'id' => 'hgezlpfcr_debug', 'type' => 'checkbox', 'default' => 'no', 'desc'=>'Logs in WooCommerce > Status > Logs'],
                        ['title' => 'Enable Healthcheck', 'id' => 'hgezlpfcr_enable_healthcheck', 'type' => 'checkbox', 'default' => 'no', 'desc'=>'Enable diagnostics and plugin settings verification page'],
                        ['type' => 'sectionend', 'id' => 'hgezlpfcr_section'],
                        
                        ['title' => __('Sender Settings', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 'type' => 'title', 'id' => 'hgezlpfcr_sender_section'],
                        ['title' => 'Sender name', 'id' => 'hgezlpfcr_sender_name', 'type' => 'text', 'desc' => 'Auto-populated from WooCommerce Point of Sale > Store name', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_name()],
                        ['title' => 'Sender phone', 'id' => 'hgezlpfcr_sender_phone', 'type' => 'text', 'desc' => 'Auto-populated from WooCommerce Point of Sale > Phone number', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_phone()],
                        ['title' => 'Sender email', 'id' => 'hgezlpfcr_sender_email', 'type' => 'email', 'desc' => 'Auto-populated from WooCommerce Point of Sale > Email', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_email()],
                        ['title' => 'Sender address', 'id' => 'hgezlpfcr_sender_address', 'type' => 'text', 'desc' => 'Auto-populated from WooCommerce Point of Sale > Physical Address', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_address()],
                        ['title' => 'Sender city', 'id' => 'hgezlpfcr_sender_city', 'type' => 'text', 'desc' => 'Auto-populated from WooCommerce General > City', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_city()],
                        ['title' => 'Sender county', 'id' => 'hgezlpfcr_sender_county', 'type' => 'text', 'desc' => 'Auto-populated from WooCommerce General > Country/State', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_state()],
                        ['title' => 'Sender postal code', 'id' => 'hgezlpfcr_sender_zip', 'type' => 'text', 'desc' => 'Auto-populated from WooCommerce General > Postcode/ZIP', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_postcode()],
                        ['title' => 'Contact person', 'id' => 'woocommerce_fan_courier_FAN_contactPerson', 'type' => 'text', 'desc' => 'Contact person for shipping', 'css'=>'min-width:300px'],
                        ['title' => 'Notes (printed on AWB)', 'id' => 'woocommerce_fan_courier_FAN_obsOnAWB', 'type' => 'textarea', 'desc' => 'Notes that will be printed on AWB', 'css'=>'min-width:300px;height:60px'],
                        ['title' => 'Parcel shipping', 'id' => 'woocommerce_fan_courier_FAN_parcelShipping', 'type' => 'select', 'desc' => 'Parcel shipping service', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'default' => 'yes'],
                                                 ['title' => 'Number of parcels/AWB', 'id' => 'woocommerce_fan_courier_FAN_numberOfParcels', 'type' => 'number', 'desc' => 'Number of parcels per AWB', 'default' => 1, 'custom_attributes' => ['min'=>1,'max'=>50,'step'=>1], 'css'=>'min-width:300px'],
                         ['title' => 'Return payment', 'id' => 'hgezlpfcr_return_payment', 'type' => 'select', 'desc' => 'Who pays for parcel return', 'options' => ['sender' => 'Sender', 'recipient' => 'Recipient'], 'default' => 'recipient'],
                         ['title' => 'Payment document', 'id' => 'hgezlpfcr_document_type', 'type' => 'select', 'desc' => 'Payment document type for AWB', 'options' => ['document' => 'INVOICE', 'non document' => 'Receipt'], 'default' => 'document'],
                         ['type' => 'sectionend', 'id' => 'hgezlpfcr_sender_section'],
                        
                        
                        ['title' => __('Cash on Delivery Options', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 'type' => 'title', 'id' => 'hgezlpfcr_cod_section'],
                        ['title' => 'Request COD for goods value', 'id' => 'woocommerce_fan_courier_FAN_askForRbsGoodsValue', 'type' => 'select', 'desc' => 'Request cash on delivery for goods value', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'default' => 'yes'],
                        ['title' => 'Add shipping cost to COD', 'id' => 'woocommerce_fan_courier_FAN_addShipTaxToRbs', 'type' => 'select', 'desc' => 'Add shipping cost to cash on delivery amount', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'default' => 'no'],
                        ['title' => 'Request COD to bank account', 'id' => 'woocommerce_fan_courier_FAN_askForRbsInBankAccount', 'type' => 'select', 'desc' => 'COD amount to be transferred to bank account', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'default' => 'no'],
                        ['title' => 'Collection account IBAN', 'id' => 'hgezlpfcr_cont_iban_ramburs', 'type' => 'text', 'desc' => 'IBAN of bank account for COD collection (used for cash on delivery orders)', 'css'=>'min-width:300px'],
                        ['title' => 'COD payment at destination', 'id' => 'woocommerce_fan_courier_FAN_rbsPaymentAtDestination', 'type' => 'select', 'desc' => 'COD payment at destination', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'default' => 'yes'],
                        ['type' => 'sectionend', 'id' => 'hgezlpfcr_cod_section'],
                    ];

                    // Show PRO info section only if PRO plugin is NOT active
                    if (!class_exists('FC_Pro_Settings')) {
                        $settings[] = [
                            'title' => __('🚀 Advanced Automations', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                            'type' => 'title',
                            'desc' => '<div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 15px; margin: 10px 0;">
                                <h3 style="margin-top: 0;">Features available in FanCourier PRO</h3>
                                <p>For advanced automations (automatic AWB generation and automatic order completion), please install and activate the <strong>FanCourier PRO</strong> plugin.</p>
                                <p><strong>FanCourier PRO Benefits:</strong></p>
                                <ul style="margin-left: 20px;">
                                    <li>✅ Automatic AWB generation for configurable statuses</li>
                                    <li>✅ Automatic order completion after AWB generation</li>
                                    <li>✅ Full control over order workflow</li>
                                    <li>✅ Sequential execution: AWB → Order completion</li>
                                </ul>
                                </div>',
                            'id' => 'hgezlpfcr_pro_info_section'
                        ];
                        $settings[] = ['type' => 'sectionend', 'id' => 'hgezlpfcr_pro_info_section'];
                    }

                    // Allow other plugins (like FC PRO) to add/modify settings
                    return apply_filters('woocommerce_get_settings_fc', $settings, $current_section);
                }
            };
            return $pages;
        });
    }

    /**
     * Get all WooCommerce order statuses for dropdown
     */
    public static function get_order_statuses_options() {
        $statuses = wc_get_order_statuses();
        $options = [];
        foreach ($statuses as $slug => $label) {
            // Remove "wc-" prefix from slug
            $slug = str_replace('wc-', '', $slug);
            $options[$slug] = $label;
        }
        return $options;
    }
    public static function get($key, $default = '') {
        return get_option($key, $default);
    }
    public static function yes($key) {
        return 'yes' === get_option($key, 'no');
    }
    
    // Auto-populate methods from WooCommerce settings
    public static function get_wc_store_name() {
        return get_option('woocommerce_store_name', get_bloginfo('name'));
    }
    
    public static function get_wc_store_phone() {
        return get_option('woocommerce_store_phone', '');
    }
    
    public static function get_wc_store_email() {
        return get_option('woocommerce_store_email', get_option('admin_email'));
    }
    
    public static function get_wc_store_address() {
        return get_option('woocommerce_store_address', '');
    }
    
    public static function get_wc_store_city() {
        return get_option('woocommerce_store_city', '');
    }
    
    public static function get_wc_store_state() {
        $country = get_option('woocommerce_default_country', '');
        if (strpos($country, ':') !== false) {
            list($country_code, $state_code) = explode(':', $country);
            return $state_code;
        }
        return get_option('woocommerce_store_state', '');
    }
    
    public static function get_wc_store_postcode() {
        return get_option('woocommerce_store_postcode', '');
    }
}
