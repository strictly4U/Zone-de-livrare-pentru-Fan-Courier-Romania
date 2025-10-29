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
                        ['title' => __('Setări Fan Courier', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 'type' => 'title', 'id' => 'hgezlpfcr_section'],
                        ['title' => 'Username',  'id' => 'hgezlpfcr_user',     'type' => 'text',     'desc' => 'Username pentru aplicația selfAWB', 'css'=>'min-width:300px'],
                        ['title' => 'Parolă',    'id' => 'hgezlpfcr_pass',     'type' => 'password', 'desc' => 'Parolă pentru aplicația selfAWB', 'css'=>'min-width:300px'],
                        ['title' => 'Client ID', 'id' => 'hgezlpfcr_client',   'type' => 'text',     'desc' => 'Client ID pentru API (obligatoriu pentru generarea AWB)', 'css'=>'min-width:300px'],
                        ['title' => 'Execuție asincronă (Action Scheduler)', 'id' => 'hgezlpfcr_async', 'type' => 'checkbox', 'default' => 'yes', 'desc'=>'Recomandat pentru trafic mare'],
                        ['title' => 'Retry API (max)', 'id' => 'hgezlpfcr_retries', 'type' => 'number', 'default' => 2, 'custom_attributes' => ['min'=>0,'max'=>5,'step'=>1]],
                        ['title' => 'Timeout API (sec)', 'id' => 'hgezlpfcr_timeout', 'type' => 'number', 'default' => 20, 'custom_attributes' => ['min'=>5, 'step'=>1]],
                        ['title' => 'Debug log', 'id' => 'hgezlpfcr_debug', 'type' => 'checkbox', 'default' => 'no', 'desc'=>'Loguri în WooCommerce > Status > Logs'],
                        ['title' => 'Activează Healthcheck', 'id' => 'hgezlpfcr_enable_healthcheck', 'type' => 'checkbox', 'default' => 'no', 'desc'=>'Activează pagina de diagnosticare și verificare a setărilor plugin-ului'],
                        ['type' => 'sectionend', 'id' => 'hgezlpfcr_section'],
                        
                        ['title' => __('Setări Expeditor', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 'type' => 'title', 'id' => 'hgezlpfcr_sender_section'],
                        ['title' => 'Nume expeditor', 'id' => 'hgezlpfcr_sender_name', 'type' => 'text', 'desc' => 'Auto-completat din WooCommerce Point of Sale > Store name', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_name()],
                        ['title' => 'Telefon expeditor', 'id' => 'hgezlpfcr_sender_phone', 'type' => 'text', 'desc' => 'Auto-completat din WooCommerce Point of Sale > Phone number', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_phone()],
                        ['title' => 'Email expeditor', 'id' => 'hgezlpfcr_sender_email', 'type' => 'email', 'desc' => 'Auto-completat din WooCommerce Point of Sale > Email', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_email()],
                        ['title' => 'Adresă expeditor', 'id' => 'hgezlpfcr_sender_address', 'type' => 'text', 'desc' => 'Auto-completat din WooCommerce Point of Sale > Physical Address', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_address()],
                        ['title' => 'Oraș expeditor', 'id' => 'hgezlpfcr_sender_city', 'type' => 'text', 'desc' => 'Auto-completat din WooCommerce General > City', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_city()],
                        ['title' => 'Județ expeditor', 'id' => 'hgezlpfcr_sender_county', 'type' => 'text', 'desc' => 'Auto-completat din WooCommerce General > Country/State', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_state()],
                        ['title' => 'Cod poștal expeditor', 'id' => 'hgezlpfcr_sender_zip', 'type' => 'text', 'desc' => 'Auto-completat din WooCommerce General > Postcode/ZIP', 'css'=>'min-width:300px', 'default' => HGEZLPFCR_Settings::get_wc_store_postcode()],
                        ['title' => 'Persoană de contact', 'id' => 'woocommerce_fan_courier_FAN_contactPerson', 'type' => 'text', 'desc' => 'Persoana de contact pentru expediere', 'css'=>'min-width:300px'],
                        ['title' => 'Observații (imprimare pe AWB)', 'id' => 'woocommerce_fan_courier_FAN_obsOnAWB', 'type' => 'textarea', 'desc' => 'Observații care vor fi imprimate pe AWB', 'css'=>'min-width:300px;height:60px'],
                        ['title' => 'Expediere colete', 'id' => 'woocommerce_fan_courier_FAN_parcelShipping', 'type' => 'select', 'desc' => 'Serviciu de expediere colete', 'options' => ['no' => 'Nu', 'yes' => 'Da'], 'default' => 'yes'],
                                                 ['title' => 'Număr pachete/AWB', 'id' => 'woocommerce_fan_courier_FAN_numberOfParcels', 'type' => 'number', 'desc' => 'Numărul de pachete per AWB', 'default' => 1, 'custom_attributes' => ['min'=>1,'max'=>50,'step'=>1], 'css'=>'min-width:300px'],
                         ['title' => 'Plata retur', 'id' => 'hgezlpfcr_return_payment', 'type' => 'select', 'desc' => 'Cine plătește pentru returul coletului', 'options' => ['sender' => 'Expeditor', 'recipient' => 'Destinatar'], 'default' => 'recipient'],
                         ['title' => 'Document de plată', 'id' => 'hgezlpfcr_document_type', 'type' => 'select', 'desc' => 'Tipul documentului de plată pentru AWB', 'options' => ['document' => 'FACTURA', 'non document' => 'Chitanță'], 'default' => 'document'],
                         ['type' => 'sectionend', 'id' => 'hgezlpfcr_sender_section'],
                        
                        
                        ['title' => __('Opțiuni Ramburs', 'hge-zone-de-livrare-pentru-fan-courier-romania'), 'type' => 'title', 'id' => 'hgezlpfcr_cod_section'],
                        ['title' => 'Solicitare ramburs valoare marfă', 'id' => 'woocommerce_fan_courier_FAN_askForRbsGoodsValue', 'type' => 'select', 'desc' => 'Solicitare ramburs pentru valoarea mărfii', 'options' => ['no' => 'Nu', 'yes' => 'Da'], 'default' => 'yes'],
                        ['title' => 'Adăugare taxă transport la ramburs', 'id' => 'woocommerce_fan_courier_FAN_addShipTaxToRbs', 'type' => 'select', 'desc' => 'Adăugare taxa de transport la suma de ramburs', 'options' => ['no' => 'Nu', 'yes' => 'Da'], 'default' => 'no'],
                        ['title' => 'Solicitare ramburs în cont bancar', 'id' => 'woocommerce_fan_courier_FAN_askForRbsInBankAccount', 'type' => 'select', 'desc' => 'Rambursul să fie virat în cont bancar', 'options' => ['no' => 'Nu', 'yes' => 'Da'], 'default' => 'no'],
                        ['title' => 'IBAN cont colector', 'id' => 'hgezlpfcr_cont_iban_ramburs', 'type' => 'text', 'desc' => 'IBAN-ul contului bancar pentru colectarea rambursului (folosit pentru comenzi cu plata la livrare)', 'css'=>'min-width:300px'],
                        ['title' => 'Plata ramburs la destinație', 'id' => 'woocommerce_fan_courier_FAN_rbsPaymentAtDestination', 'type' => 'select', 'desc' => 'Plata rambursului la destinație', 'options' => ['no' => 'Nu', 'yes' => 'Da'], 'default' => 'yes'],
                        ['type' => 'sectionend', 'id' => 'hgezlpfcr_cod_section'],
                    ];

                    // Show PRO info section only if PRO plugin is NOT active
                    if (!class_exists('FC_Pro_Settings')) {
                        $settings[] = [
                            'title' => __('🚀 Automatizări Avansate', 'hge-zone-de-livrare-pentru-fan-courier-romania'),
                            'type' => 'title',
                            'desc' => '<div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 15px; margin: 10px 0;">
                                <h3 style="margin-top: 0;">Funcționalități disponibile în FanCourier PRO</h3>
                                <p>Pentru automatizări avansate (generare AWB automată și închidere automată comenzi), vă rugăm să instalați și să activați plugin-ul <strong>FanCourier PRO</strong>.</p>
                                <p><strong>Beneficii FanCourier PRO:</strong></p>
                                <ul style="margin-left: 20px;">
                                    <li>✅ Generare AWB automată pentru statusuri configurabile</li>
                                    <li>✅ Închidere automată comenzi după generare AWB</li>
                                    <li>✅ Control complet asupra workflow-ului comenzilor</li>
                                    <li>✅ Execuție în ordine: AWB → Închidere comandă</li>
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
