<?php
/**
 * Plugin Name: WC Payment Gateway
 * Plugin URI: https://www.skyverge.com/blog/how-to-create-a-simple-woocommerce-payment-gateway/
 * Description: custom wc payment gateway
 * Author: Pawel Kalisz
 * Author URI: https://websitecreator.cba.pl
 * Version: 1.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}


/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 * @since 1.0.0
 */
function wc_offline_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Offline';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_offline_add_to_gateways');


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 */

function wc_offline_gateway_plugin_links($links)
{

    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=offline_gateway') . '">' . __('Configure', 'wc-gateway-offline') . '</a>'
    );

    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_offline_gateway_plugin_links');


/**
 * Offline Payment Gateway
 *
 * Provides an Offline Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class        WC_Gateway_Offline
 * @extends        WC_Payment_Gateway
 * @version        1.0.0
 * @package        WooCommerce/Classes/Payment
 * @author        SkyVerge
 */
add_action('plugins_loaded', 'wc_offline_gateway_init', 11);

function wc_offline_gateway_init()
{

    class WC_Gateway_Offline extends WC_Payment_Gateway
    {
        /**
         * @var string
         */
        private $plugin_dir;

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->plugin_dir = plugin_dir_url(__FILE__);
            $this->id = 'offline_gateway';

            
            //https://stackoverflow.com/questions/40723517/add-an-icon-to-custom-woocommerce-payment-gateway
            $this->icon = apply_filters('woocommerce_offline_icon', '' . $this->plugin_dir . '/assets/ico/payment-ico.png');
            //ponizsza implemetacja ikonki rowniez zadziala , nalezy zakomentowac powyzsza
            //$this->icon =  $this->plugin_dir . '/assets/ico/payment-ico.png';
            $this->has_fields = false;
            $this->method_title = __('Offline', 'wc-gateway-offline');
            $this->method_description = __('Allows offline payments. Very handy if you use your cheque gateway for another payment method, and can help with testing. Orders are marked as "on-hold" when received.', 'wc-gateway-offline');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions', $this->description);

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

            // Customer Emails

            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }


        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            $this->form_fields = apply_filters('wc_offline_form_fields', array(

                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-gateway-offline'),
                    'type' => 'checkbox',
                    'label' => __('Enable Offline Payment', 'wc-gateway-offline'),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title' => __('Title', 'wc-gateway-offline'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-offline'),
                    'default' => __('Offline Payment', 'wc-gateway-offline'),
                    'desc_tip' => true,
                ),

                'description' => array(
                    'title' => __('Description', 'wc-gateway-offline'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'wc-gateway-offline'),
                    'default' => __('Please remit payment to Store Name upon pickup or delivery.', 'wc-gateway-offline'),
                    'desc_tip' => true,
                ),

                'instructions' => array(
                    'title' => __('Instructions', 'wc-gateway-offline'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-gateway-offline'),
                    'default' => '',
                    'desc_tip' => true,
                ),
            ));
        }


        /**
         * Output for the order received page.
         */
        public function thankyou_page()
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }


        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false)
        {

            if ($this->instructions && !$sent_to_admin && $this->id === $order->payment_method && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {

            $order = wc_get_order($order_id);

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting offline payment', 'wc-gateway-offline'));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

    } // end \WC_Gateway_Offline class
}
