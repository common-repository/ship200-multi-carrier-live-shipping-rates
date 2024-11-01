<?php
/*
Plugin Name: Ship200 Multi-Carrier Live Shipping Rates
Plugin URI: http://blog.ship200.com/ship200-live-shipping-rates-service/
Description: This plugin adds Live Shipping Rates to your WooCommerce shopping cart.
Version: 1.0.0
Author: Ship200
Author URI: http://www.ship200.com/
*/

/**
 * Check if WooCommerce is active
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function ship200_live_shipping_rates_init()
    {
        if (!class_exists('WC_Ship200_Live_Shipping_Rates')) {
            class WC_Ship200_Live_Shipping_Rates extends WC_Shipping_Method
            {
                public function __construct()
                {
                    $this->id = 'ship200_shipping_rates';
                    $this->method_title = __('Ship200 Live Shipping Rates'); 
                    $this->method_description = __('Ship200 Multi-Carrier UPS, USPS (Stamps, Endicia), Fedex - Live Shipping Rates Addon');

                    $this->enabled = $this->get_option('enabled');
                    if ($this->get_option('secret_key') == '')
                        $this->enabled = false;

                    $this->availability = $this->get_option('availability');
                    $this->countries = $this->get_option('countries');

                    $this->init();
                }

                function init()
                {
                    $this->init_form_fields();
                    $this->init_settings();

                    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                }

                public function init_form_fields()
                {
                    $this->form_fields = array(
                        'enabled' => array(
                            'title' => __('Enable', 'ship200_shipping_rates'),
                            'type' => 'checkbox',
                            'label' => __('Enable Live Shipping Rates', 'ship200_shipping_rates'),
                            'default' => 'no'
                        ),
                        'secret_key' => array(
                            'title' => __('Ship200 Secret Key', 'ship200_shipping_rates'),
                            'type' => 'text',
                            'description' => __('1. Login to your Ship200.com Account<br/>2. On Top Menu click "Add/Remove Store", then "WooCommerce"<br/>3. Place the secret key from Ship200 setup page to this field', 'ship200_shipping_rates'),
                            'default' => __('', 'ship200_shipping_rates'),
                        ),
                        'account_type' => array(
                            'title' => __('Ship200 Account Type', 'ship200_shipping_rates'),
                            'id' => 'ship200_shipping_rates_account_type',
                            'default' => 'lite',
                            'type' => 'select',
                            'class' => 'wc-enhanced-select',
                            'desc_tip' => __('Do you have \'Ship200 Lite\' or \'Ship200 Premium\' Account', 'ship200_shipping_rates'),
                            'options' => array(
                                'premium' => __('Premium', 'ship200_shipping_rates'),
                                'lite' => __('Lite', 'ship200_shipping_rates'),
                            ),
                        ),
                        'weight_unit' => array(
                            'title' => __('Weight Unit', 'ship200_shipping_rates'),
                            'id' => 'ship200_shipping_rates_weight_unit',
                            'default' => 'lb',
                            'type' => 'select',
                            'class' => 'wc-enhanced-select',
                            'desc_tip' => __('Set to pound or ounce', 'ship200_shipping_rates'),
                            'options' => array(
                                'lb' => __('Pound', 'ship200_shipping_rates'),
                                'oz' => __('Ounce', 'ship200_shipping_rates'),
                            ),
                        ),
                        'fallback_methods' => array(
                            'title' => __('Fallback Methods', 'ship200_shipping_rates'),
                            'type' => 'textarea',
                            'desc_tip' => __('You can edit fallback methods here, if ship200 service is down', 'ship200_shipping_rates'),
                            'default' => '',
                            'description' => __('<a href="http://blog.ship200.com/ship200-live-shipping-rates-service/#fallback" target="_blank">http://blog.ship200.com/ship200-live-shipping-rates-service/</a>', 'ship200_shipping_rates'),
                            'placeholder' => 'sample method:2.50'
                        ),
                        'availability' => array(
                            'title' => __('Methods availability', 'ship200_shipping_rates'),
                            'type' => 'select',
                            'default' => 'all',
                            'class' => 'availability wc-enhanced-select',
                            'options' => array(
                                'all' => __('All allowed countries', 'ship200_shipping_rates'),
                                'specific' => __('Specific Countries', 'ship200_shipping_rates')
                            )
                        ),
                        'countries' => array(
                            'title' => __('Specific Countries', 'ship200_shipping_rates'),
                            'type' => 'multiselect',
                            'class' => 'wc-enhanced-select',
                            'css' => 'width: 450px;',
                            'default' => '',
                            'options' => WC()->countries->get_shipping_countries(),
                            'custom_attributes' => array(
                                'data-placeholder' => __('Select some countries', 'ship200_shipping_rates')
                            )
                        ),
                        'debug_mode' => array(
                            'title' => __('Debug Mode', 'ship200_shipping_rates'),
                            'id' => 'ship200_shipping_rates_debug_mode',
                            'default' => 0,
                            'type' => 'select',
                            'class' => 'wc-enhanced-select',
                            'desc_tip' => __('Saves send/received data to the system log', 'ship200_shipping_rates'),
                            'options' => array(
                                1 => __('Enabled', 'ship200_shipping_rates'),
                                2 => __('Enabled On Beta Server', 'ship200_shipping_rates'),
                                0 => __('Disabled', 'ship200_shipping_rates'),
                            ),
                        ),
                    );
                }

                public function calculate_shipping($package)
                {

                    $ship200_rates_fallback = $this->get_option('fallback_methods');
                    $ship200_rates_fallback = str_replace(PHP_EOL, ',', $ship200_rates_fallback);

                    $address = $package['destination'];

                    $weight = 0; // weight logic
                    foreach ($package['contents'] as $item_id => $values)
                        if ($values['quantity'] > 0 && $values['data']->needs_shipping())
                            $weight += $values['quantity'] * $values['data']->weight;

                    $weight_code = $this->get_option('weight_unit');

                    if ($weight_code !== 'oz')
                        $weight_code = 'lb';

                    $account_type = $this->get_option('account_type');
                    if (isset($account_type) && $account_type == 'login')
                        $domain = 'rates-premium';
                    else
                        $domain = 'rates-lite';

                    if ($this->get_option('debug_mode') == 2)
                        $domain = 'beta';

                    $ch = curl_init('https://' . $domain . '.ship200.com/api/rates/api.php');
                    $requestArray = array
                    (
                        'secret_key' => $this->get_option('secret_key'),
                        'shopping_cart' => 'woocommerce'
                    );

                    $requestArray['weight'] = $weight;
                    $requestArray['weight_units'] = $weight_code;

                    $requestArray['first_name'] = '';
                    $requestArray['last_name'] = '';
                    $requestArray['company'] = '';
                    $requestArray['address_line_1'] = $address['address'];
                    $requestArray['address_line_2'] = '';
                    $requestArray['city'] = $address['city'];
                    $requestArray['zip_code'] = $address['postcode'];
                    $requestArray['state'] = $address['state'];
                    $requestArray['country'] = $address['country'];

                    $fields = '';
                    foreach ($requestArray as $key => $value) $fields .= $key . '=' . urlencode($value) . '&';

                    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
                    curl_setopt($ch, CURLOPT_POST, TRUE);
                    curl_setopt($ch, CURLOPT_VERBOSE, 1);
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_ENCODING, '');
                    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
                    curl_setopt($ch, CURLOPT_REFERER, $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('RemoteAddr: ' . $_SERVER['REMOTE_ADDR']));
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $curl_response = curl_exec($ch);

                    $json_result = json_decode($curl_response, true);
                    $rates = array();

                    if ((!$json_result && $curl_response) || $this->get_option('debug_mode')) {
                        $log = new WC_Logger();
                        $log->add('ship200_shipping_rates', 'Ship 200 Response: ' . $curl_response);
                    }

                    if ($json_result) {
                        $rates = $json_result['shippingRates'];

                        if (isset($json_result['fallBackMethods']) && !empty($json_result['fallBackMethods'])) {
                            $fallBackMethodsToSave = '';
                            foreach ($json_result['fallBackMethods'] as $fallBackMethodArray)
                                $fallBackMethodsToSave .= $fallBackMethodArray['name'] . ':' . $fallBackMethodArray['cost'] . ',';

                            $fallBackMethodsToSave = rtrim($fallBackMethodsToSave, ',');
                            if ($ship200_rates_fallback !== $fallBackMethodsToSave) {
                                $settings_array = get_option('woocommerce_ship200_shipping_rates_settings');
                                if ($settings_array !== false) {
                                    $settings_array['fallback_methods'] = $fallBackMethodsToSave;
                                    update_option('woocommerce_ship200_shipping_rates_settings', $settings_array);
                                    wp_cache_delete('alloptions', 'options');
                                }
                            }

                            if (empty($json_result['shippingRates']))
                                $rates = array_merge($rates, $json_result['fallBackMethods']);
                        }
                    } else {
                        if ($ship200_rates_fallback !== '') {
                            $array = explode(',', $ship200_rates_fallback);
                            foreach ($array as $key => $value) {
                                $rateArray = explode(':', $value);
                                if (!isset($rateArray[0])) $rateArray[0] = 'wrong method';
                                if (!isset($rateArray[1])) $rateArray[1] = '0';
                                array_push($rates, array(
                                    'name' => $rateArray[0],
                                    'cost' => $rateArray[1]
                                ));
                            }
                        }
                    }

                    if (isset($rates) && !empty($rates)) {
                        foreach ($rates as $key => $rate) {
                            if (!isset($rate['code']))
                                $rate['code'] = 'fb_' . $key;

                            $rate = array(
                                'id' => $this->id . '.' . $rate['code'],
                                'label' => $rate['name'],
                                'cost' => $rate['cost'],
                                'calc_tax' => 'per_order'
                            );

                            $this->add_rate($rate);
                        }
                    }
                }
            }
        }
    }

    add_action('woocommerce_shipping_init', 'ship200_live_shipping_rates_init');

    function add_ship200_live_shipping_rates($methods)
    {
        $methods[] = 'WC_Ship200_Live_Shipping_Rates';
        return $methods;
    }

    add_filter('woocommerce_shipping_methods', 'add_ship200_live_shipping_rates');
}