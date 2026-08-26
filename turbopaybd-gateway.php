<?php
/**
 * Plugin Name:       TurboPay BD Gateway for Woocommerce
 * Plugin URI:        https://turbopaybd.com
 * Description:       Accept local cards, mobile banking (bKash, Nagad, Rocket, Upay), and internet banking in Bangladesh via TurboPay BD Gateway for Woocommerce
 * Version:           1.0.2
 * Author:            TurboPay BD
 * Author URI:        https://profiles.wordpress.org/turbopaybd
 * Text Domain:       turbopay-bd-gateway-for-woocommerce
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// HPOS এবং আধুনিক কার্ট/চেকআউট ব্লকের সাথে সামঞ্জস্য ঘোষণা
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Initialize the TurboPay BD gateway class.
 */
add_action('plugins_loaded', 'tpbd_init_gateway_class', 0);

function tpbd_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * TPBD_WC_Gateway Class
     */
    class TPBD_WC_Gateway extends WC_Payment_Gateway
    {
        private $apikey;
        private $currency_rate;
        private $is_digital;
        private $payment_site;
        public $banner_url;
        private static $instance = null;

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function __construct()
        {
            $this->id                 = 'turbopaybd_gateway';
            $this->icon               = '';
            $this->has_fields         = false;
            $this->method_title       = __('TurboPay BD', 'turbopay-bd-gateway-for-woocommerce');
            $this->method_description = __('Pay securely with bKash, Nagad, Rocket, Upay, or Cards via TurboPay BD.', 'turbopay-bd-gateway-for-woocommerce');

            $this->supports = ['products'];

            // লোড সেটিংস
            $this->init_form_fields();
            $this->init_settings();

            // ভেরিয়েবল ডিফাইন
            $this->title         = $this->get_option('title');
            $this->enabled       = $this->get_option('enabled');
            $this->apikey        = $this->get_option('apikey');
            $this->currency_rate = $this->get_option('currency_rate');
            $this->is_digital    = $this->get_option('is_digital') === 'yes';
            $this->payment_site  = $this->get_option('payment_site', 'https://secure-pay.turbopaybd.com');

            // ইমেজ ইউআরএল জেনারেট (সংশোধিত প্রিফিক্স হুক)
            $this->banner_url    = apply_filters('tpbd_gateway_icon', plugins_url('assets/images/gateways-logo.png', __FILE__));
            
            // ক্লাসিক চেকআউটের জন্য ব্যানার
            $this->description   = '<p style="margin-bottom: 12px;">' . esc_html($this->get_option('description')) . '</p><img src="' . esc_url($this->banner_url) . '" alt="' . esc_attr__('TurboPay BD All Payment Methods', 'turbopay-bd-gateway-for-woocommerce') . '" style="display:block; width:100%; max-width: 600px; height:auto; margin-top: 12px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"/>';

            // অ্যাকশনসমূহ
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_gateway_' . $this->id, [$this, 'handle_webhook']);

            // সিকিউর রিডাইরেক্ট ও ননস চেক
            if (isset($_GET['turbopaybd_return'], $_GET['order_id'], $_GET['_wpnonce'])) {
                $nonce    = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
                $order_id = absint(wp_unslash($_GET['order_id']));

                if (wp_verify_nonce($nonce, 'tpbd_process_return_' . $order_id)) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $this->update_order_status($order);
                        wp_safe_redirect($this->get_return_url($order));
                        exit;
                    }
                }
            }
        }

        /**
         * Gateway সেটিংস ফর্ম ফিল্ডস
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title'       => __('Enable/Disable', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable TurboPay BD Payment Gateway', 'turbopay-bd-gateway-for-woocommerce'),
                    'description' => __('Enable or disable the TurboPay BD payment gateway.', 'turbopay-bd-gateway-for-woocommerce'),
                    'default'     => 'no',
                ],
                'title' => [
                    'title'       => __('Title', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'turbopay-bd-gateway-for-woocommerce'),
                    'default'     => __('TurboPay BD Gateway', 'turbopay-bd-gateway-for-woocommerce'),
                    'desc_tip'    => true,
                ],
                'description' => [
                    'title'       => __('Description', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'turbopay-bd-gateway-for-woocommerce'),
                    'default'     => __('Pay securely with bKash, Nagad, Rocket, Upay, or Cards via TurboPay BD.', 'turbopay-bd-gateway-for-woocommerce'),
                ],
                'apikey' => [
                    'title'       => __('Enter API Key', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Enter your TurboPay BD API Key.', 'turbopay-bd-gateway-for-woocommerce'),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'currency_rate' => [
                    'title'       => __('Enter USD Rate', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'number',
                    'description' => __('Enter the exchange rate for USD to BDT. Only applicable if your store currency is not BDT.', 'turbopay-bd-gateway-for-woocommerce'),
                    'default'     => '120',
                    'desc_tip'    => true,
                ],
                'is_digital' => [
                    'title'       => __('Enable Digital Product', 'turbopay-bd-gateway-for-woocommerce'),
                    'label'       => __('Enable this if you are primarily selling digital products.', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'checkbox',
                    'description' => __('If enabled, orders will be marked as completed immediately after successful payment.', 'turbopay-bd-gateway-for-woocommerce'),
                    'default'     => 'no',
                ],
                'payment_site' => [
                    'title'       => __('Payment Site URL', 'turbopay-bd-gateway-for-woocommerce'),
                    'type'        => 'hidden',
                    'default'     => 'https://secure-pay.turbopaybd.com',
                ],
            ];
        }

        /**
         * পেমেন্ট প্রসেসিং ফাংশন
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'turbopay-bd-gateway-for-woocommerce'), 'error');
                return ['result' => 'fail'];
            }

            // সিকিউর রিটার্ন ইউআরএল তৈরি
            $return_nonce = wp_create_nonce('tpbd_process_return_' . $order->get_id());
            $success_url = add_query_arg([
                'turbopaybd_return' => '1',
                'order_id'          => $order->get_id(),
                '_wpnonce'          => $return_nonce,
            ], wc_get_page_permalink('checkout'));

            $data = [
                'cus_name'    => sanitize_text_field($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'cus_email'   => sanitize_email($order->get_billing_email()),
                'amount'      => $order->get_total(),
                'metadata'    => ['phone' => sanitize_text_field($order->get_billing_phone())],
                'uid'         => $order->get_customer_id() ? $order->get_customer_id() : 1,
                'success_url' => $success_url,
                'cancel_url'  => wc_get_page_permalink('checkout'),
                'webhook_url' => WC()->api_request_url('TPBD_WC_Gateway')
            ];

            $header = [
                'url' => trailingslashit($this->payment_site) . 'api/payment/create',
            ];

            $response = $this->create_payment($data, $header);
            $response_data = json_decode($response, true);

            if (isset($response_data['payment_url'])) {
                return [
                    'result'   => 'success',
                    'redirect' => esc_url_raw($response_data['payment_url']),
                ];
            } else {
                $error_msg = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Payment could not be initiated.', 'turbopay-bd-gateway-for-woocommerce');
                wc_add_notice(__('TurboPay BD Error: ', 'turbopay-bd-gateway-for-woocommerce') . $error_msg, 'error');
                return ['result' => 'failure'];
            }
        }

        private function create_payment($data, $header)
        {
            $url = $header['url'];
            $args = [
                'body'      => wp_json_encode($data),
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'API-KEY'      => $this->apikey
                ],
                'method'    => 'POST',
                'timeout'   => 45,
                'sslverify' => true,
            ];

            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                return wp_json_encode(['message' => $response->get_error_message()]);
            }
            return wp_remote_retrieve_body($response);
        }

        public function handle_webhook()
        {
            status_header(200);
            echo wp_json_encode(['status' => 'acknowledged']);
            exit;
        }

        public function update_order_status($order)
        {
            if (isset($_GET['_wpnonce'], $_GET['transactionId'], $_GET['order_id'])) {
                $nonce    = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
                $order_id = absint(wp_unslash($_GET['order_id']));

                if (wp_verify_nonce($nonce, 'tpbd_process_return_' . $order_id)) {
                    $transactionId     = sanitize_text_field(wp_unslash($_GET['transactionId']));
                    $verification_data = ['transaction_id' => $transactionId];
                    $header            = ["url" => trailingslashit($this->payment_site) . 'api/payment/verify'];

                    $response = $this->create_payment($verification_data, $header);
                    $data     = json_decode($response, true);

                    if ($order->get_status() !== 'completed' && $order->get_status() !== 'processing') {
                        if (isset($data['status']) && ($data['status'] == "COMPLETED" || $data['status'] == "success")) {
                            if ($this->is_digital) {
                                $order->update_status('completed', __("Payment complete via TurboPay BD", 'turbopay-bd-gateway-for-woocommerce'));
                            } else {
                                $order->update_status('processing', __("Payment successful via TurboPay BD", 'turbopay-bd-gateway-for-woocommerce'));
                            }
                            $order->reduce_order_stock();
                            $order->payment_complete($transactionId);
                            return true;
                        } else {
                            $order->update_status('on-hold', __('Verification failed on TurboPay BD', 'turbopay-bd-gateway-for-woocommerce'));
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Blocks সাপোর্ট ইন্টিগ্রেশন
     */
    class TPBD_WC_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
        protected $name = 'turbopaybd_gateway';

        public function initialize()
        {
            $this->settings = get_option('woocommerce_turbopaybd_gateway_settings', []);
        }

        public function is_active()
        {
            return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
        }

        public function get_payment_method_script_handles()
        {
            wp_register_script(
                'turbopaybd-blocks',
                plugins_url('assets/js/turbopaybd-blocks.js', __FILE__),
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/turbopaybd-blocks.js'),
                true
            );
            return ['turbopaybd-blocks'];
        }

        public function get_payment_method_data()
        {
            $gateway = TPBD_WC_Gateway::get_instance();
            return [
                'title'       => $gateway->title,
                'description' => $gateway->get_option('description'),
                'icon'        => $gateway->banner_url,
                'supports'    => ['products'],
            ];
        }
    }

    // ওওকমার্স ব্লক রেজিস্ট্রেশন
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new TPBD_WC_Blocks());
    });

    // ওওকমার্সের মূল গেটওয়ে তালিকায় যুক্ত করা
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'TPBD_WC_Gateway';
        return $gateways;
    });
}

// প্লাগইন লিস্ট পেজে সরাসরি "Settings" অ্যাকশন লিঙ্ক যুক্ত করার কোড
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=turbopaybd_gateway')) . '">' . __('Settings', 'turbopay-bd-gateway-for-woocommerce') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});