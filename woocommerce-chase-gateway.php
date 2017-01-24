<?php

/*
  Plugin Name: Woocommerce Chase Payment Gateway
  Description: A Chase Paymentech payment gateway for Woocommerce
  Version: 1.0.0
  Author: Webby Scots
  Author URI: http://webbyscots.com/
  License: GPL 3.0
 */

if (isset($_REQUEST['x_response_code'])) {
    //add_action('plugins_loaded', 'run_chase_class', 20);

    function run_chase_class() {
        new WC_Gateway_Chase();
    }

}

function register_chase_method($methods) {
    $methods[] = 'WC_Gateway_Chase';
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'register_chase_method');

add_action('plugins_loaded', 'init_chase_class');

function init_chase_class() {
    class WC_Gateway_Chase extends WC_Payment_Gateway {

        var $notify_url;
        protected $textdomain = 'woocommerce-chase-gateway';

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {

            $this->id = 'chase';
            $this->has_fields = false;
            $this->supports = array('products', 'refunds');
            $this->method_title = __('Chase Paymentech', $this->textdomain);
            $this->notify_url = WC()->api_request_url('WC_Gateway_ChaseP');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->order_button_text = __($this->get_option('button_text'), $this->textdomain);
            $this->description = $this->get_option('description');

            $this->debug = $this->get_option('debug');
            $this->transaction_key = $this->get_option('transaction_key');
            $this->response_key = $this->get_option('response_key');
            $this->x_login = $this->get_option('x_login');
            $this->gateway_id = $this->get_option('api_gateway_id');
            $this->api_pass = $this->get_Option('api_password');
            // Logs
            if ('yes' == $this->debug) {
                $this->log = new WC_Logger();
            }
            $this->test_mode = $this->get_option('test_mode') === "yes";
            $this->chase_url = $this->test_mode ? 'https://rpm.demo.e-xact.com/payment' : 'https://checkout.e-xact.com/payment';
            // Actions
            add_action('woocommerce_receipt_chase', array($this, 'receipt_page'));
            add_action('valid-chase-chasept-request', array($this, 'successful_request'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_chase', array($this, 'check_chase_response'));
        }

        function successful_request($response) {
            $order = new WC_Order($response['x_invoice_num']);
            if ('yes' == $this->debug) {
                $this->log->add('chase', 'Found order #' . $response['x_invoice_num'] . ' From KEY: ' . $response['x_trans_id']);
            }
            else {
                unlink(dirname(__FILE__) . "/log.php");
            }
            if ($response['x_response_code'] == "1") {
                if ($response['x_amount'] == $order->order_total) {
                    $order->payment_complete((int) $response['x_invoice_num']);
                    update_post_meta($order->id,'_transaction_type',$response['Transaction_Type']);
                    update_post_meta($order->id,'_transaction_tag',$response['Transaction_Tag']);
                    update_post_meta($order->id,'_authorization_num',$response['Authorization_Num']);
                    $order->add_order_note(__('Order marked as processing, successful payment via Chase', $this->textdomain));
                } else {
                    if ('yes' == $this->debug) {
                        $this->log->add('chase', 'Payment error: Amounts do not match (gross ' . $response['x_amount'] . ')');
                    }
                    $order->update_status('on-hold', __('Validation error: Upay/Woocommerce amounts do not match: Order Total = ' . $order->order_total . ' Amount Paid = ' . $response['payment_amt'] . ' Order marked on-hold', $this->textdomain));
                }
            } else {
                $order->update_status('on-hold', __('Chase payment failed - ' . $response['x_response_reason_text'], $this->textdomain));
                wc_add_notice('Payment failed - ' . $response['x_response_reason_text']);
            }
        }

        function check_chase_response() {
            if (!isset($_REQUEST['x_response_code']))
                return;
            if ('yes' == $this->debug) {
                $this->log->add('chase', 'Processing Response' . print_r($_REQUEST, true));
            }
            $chasept_response = $_REQUEST;
            if ($this->check_chasept_request_is_valid($chasept_response)) {
                header('HTTP/1.1 200 OK');
                do_action("valid-chase-chasept-request", $chasept_response);
            } else {
                echo "FAILED";
                if ('yes' == $this->debug) {
                    $this->log->add('chase', 'Invalid Response from Chase ' . print_r($chasept_response, true));
                }
                wc_add_notice("Chase Request Failure", "Chase Response" . print_r($chasept_response, true), array('response' => 200));
            }
        }

        function check_chasept_request_is_valid($response) {
            $hash_should_be = md5($this->response_key . $this->x_login . $response['x_trans_id'] . number_format($response['x_amount'], 2));
            return ($response['x_MD5_Hash'] === $hash_should_be);
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', $this->textdomain),
                    'type' => 'checkbox',
                    'label' => __('Enable Chase Gateway', $this->textdomain),
                    'default' => 'yes'
                ),
                'debug' => array(
                    'title' => __('Debug Mode', $this->textdomain),
                    'type' => 'checkbox',
                    'default' => 'no'
                ),
                'test_mode' => array(
                    'title' => __('Test Mode', $this->textdomain),
                    'type' => 'checkbox',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', $this->textdomain),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', $this->textdomain),
                    'default' => __('Pay Via Chase', $this->textdomain),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Customer Message', $this->textdomain),
                    'type' => 'textarea',
                    'default' => ''
                ),
                'button_text' => array(
                    'title' => __('Order button text', $this->textdomain),
                    'type' => 'text',
                    'default' => __('Proceed to Chase', $this->textdomain)
                ),
                'x_login' => array(
                    'title' => __('Login (x_login)', $this->textdomain),
                    'type' => 'text',
                    'description' => __('Enter value given in page settings', $this->textdomain),
                    'desc_tip' => true
                ),
                'transaction_key' => array(
                    'title' => __('Transaction Key', $this->textdomain),
                    'type' => 'password'
                ),
                'response_key' => array(
                    'title' => __('Transaction Response Key', $this->textdomain),
                    'type' => 'password'
                ),
                'api_gateway_id' => array(
                    'title' => __('API Gateway ID', $this->textdomain),
                    'type' => 'password'
                ),
                'api_password' => array(
                    'title' => __('API Password', $this->textdomain),
                    'type' => 'password'
                ),
            );
        }

        function receipt_page($order) {
            echo '<p>' . __('Thank you - your order is now pending payment. You should be automatically redirected to Chase to make payment.', $this->textdomain) . '</p>';

            echo $this->generate_chase_form($order);
        }

        function generate_chase_form($order_id) {

            $order = new WC_Order($order_id);

            $_adr = $this->chase_url;

            $chase_args = $this->get_chase_args($order);
            $chase_args_array = array();


            foreach ($chase_args as $key => $value) {
                $chase_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }

            wc_enqueue_js('
			$.blockUI({
					message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Chase to make payment.', $this->textdomain)) . '",
					baseZ: 99999,
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
						padding:        "20px",
						zindex:         "9999999",
						textAlign:      "center",
						color:          "#555",
						border:         "3px solid #aaa",
						backgroundColor:"#fff",
						cursor:         "wait",
						lineHeight:		"24px",
					}
				});
			jQuery("#submit_chase_payment_form").click();
		');

            return '<form action="' . $this->chase_url . '" method="post" id="chase_payment_form" target="_top">
				' . implode('', $chase_args_array) . '
				<!-- Button Fallback -->
				<div class="payment_buttons">
					<input type="submit" class="button alt" id="submit_chase_payment_form" value="' . __('Pay via Chase', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', $this->textdomain) . '</a>
				</div>
				<script type="text/javascript">
					//jQuery(".payment_buttons").hide();
				</script>
			</form>';
        }

        function get_chase_args($order) {

            $order_id = $order->id;

            if ('yes' == $this->debug) {
                $this->log->add('chase', 'Generating Chase payment form for order ' . $order->get_order_number() . '. Notify URL: ' . $this->notify_url);
            }

            $x_login = $this->x_login;  //  Take from Payment Page ID in Payment Pages interface
            $transaction_key = $this->transaction_key; // Take from Payment Pages configuration interface
            $x_amount = $order->order_total;
            $x_currency_code = get_woocommerce_currency(); // Needs to agree with the currency of the payment page
            srand(time()); // initialize random generator for x_fp_sequence
            $x_fp_sequence = rand(1000, 100000) + 123456;
            $x_fp_timestamp = time(); // needs to be in UTC. Make sure webserver produces UTC
            // The values that contribute to x_fp_hash
            $hmac_data = $x_login . "^" . $x_fp_sequence . "^" . $x_fp_timestamp . "^" . $x_amount . "^" . $x_currency_code;
            $x_fp_hash = hash_hmac('MD5', $hmac_data, $transaction_key);
            // Chase Args
            $chase_args = array(
                'x_login' => $x_login,
                'x_amount' => $x_amount,
                //'x_type' => 'PURCHASE_TOKEN',
                'x_fp_sequence' => $x_fp_sequence,
                'x_fp_timestamp' => $x_fp_timestamp,
                'x_currency_code' => $x_currency_code,
                'x_fp_hash' => $x_fp_hash,
                'x_show_form' => 'PAYMENT_FORM',
                'x_tax' => $order->order_tax,
                'x_freight' => $order->order_shipping,
                'x_invoice_num' => $order->id,
                'x_receipt_link_url' => add_query_arg(array('wc-api' => 'wc_gateway_chase'),site_url())
            );
            foreach (WC()->countries->get_address_fields($order->billing_country, 'billing_') as $field_key => $field) {
                $key = str_replace('billing_', '', $field_key);
                $chase_args['x_' . $key] = $order->$field_key;
            }
            foreach (WC()->countries->get_address_fields($order->billing_country, 'shipping_') as $field_key => $field) {
                $key = str_replace('shipping_', '', $field_key);
                $chase_args['x_ship_to_' . $key] = $order->$field_key;
            }
            foreach ($order->get_items() as $item_id => $item) {
                $item_tax_status = wc_get_product((int) $item['variation_id'] > (int) $item['product_id'] ? $item['variation_id'] : $item['product_id'])->is_taxable() ? "YES" : "NO";
                $chase_args[] = "{$item_id}<|>{$item['name']}<|>{$item['name']}<|>{$item['qty']}<|>" . $order->get_item_subtotal($item) . "<|>{$item_tax_status}";
            }
            /*
             * 'BILL_NAME' => $order->billing_first_name . " " . $order->billing_last_name,
              'BILL_EMAIL_ADDRESS' => $order->billing_email,
              'BILL_STREET1' => $order->billing_address_1,
              'BILL_STREET2' => $order->billing_address_2,
              'BILL_CITY' => $order->billing_city,
              'BILL_STATE' => $order->billing_state,
              'BILL_POSTAL_CODE' => $order->billing_postcode,
              'BILL_COUNTRY' => $order->billing_country,
              'EXT_TRANS_ID' => $order->order_key,
              'EXT_TRANS_ID_LABEL' => __('Eco Tone Magazine Order Key', $this->textdomain),
              'VALIDATION_KEY' => $this->get_validation_key($this->validation_key, $order->order_key, $order->order_total)
             */
            $chase_args = apply_filters('woocommerce_chase_args', $chase_args);

            return $chase_args;
        }

        public function can_refund_order( $order ) {
		return $order && get_post_meta($order->id,'_transaction_tag',true)!=="";
	}

        function process_refund($order_id, $amount = null, $reason = '') {
            $order = wc_get_order( $order_id );

		if ( ! $this->can_refund_order( $order ) ) {
			$this->log( 'Refund Failed: No transaction ID' );
			return new WP_Error( 'error', __( 'Refund Failed: No transaction ID', 'woocommerce' ) );
		}

             $data = array(
                 'transaction_type' => (get_post_meta($order_id,'_transaction_type',true) == 50) ? 35 : 34,
                 'transaction_tag' => get_post_meta($order_id,'_transaction_tag',true),
                 'authorization_num' => get_post_meta($order_id,'_authorization_num',true),
                 'amount' => number_format( $amount, 2, '.', '' )
             );
             $response = $this->_make_api_call($data);
                 $order->add_order_note( sprintf( __( 'Refund attempt %s <p><p> %s <p><p>', 'woocommerce' ), print_r($data,true),print_r($response,true)) );
             if ($response['transaction_approved'] == 1) {
                 global $wpdb;
                 foreach($order->get_items('line_item') as $item_id => $item) {

                    $post_id = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '%s' AND order_item_id = %d",'_woo_mgm_purchased_post',$item_id));
                    $sql = $wpdb->prepare("DELETE FROM `" . TBL_MGM_POST_PURCHASES . "` WHERE user_id = %d AND post_id = %d",$order->customer_user,$post_id );

                    $order->add_order_note("Setting revoked for " . $post_id );
                    $wpdb->query($sql);
                }


                 $order->add_order_note( sprintf( __( 'Refunded %s - Refund ID: %s', 'woocommerce' ), $response['amount'], $response['retrieval_ref_no'] ) );
                 return true;
             }
             else {
                 ob_start();
                 var_dump($response);
                 $c = ob_get_contents();
                 ob_end_clean();
                 $order->add_order_note( sprintf( __( 'Refund failed %s - Refund ID: %s', 'woocommerce' ), $this->full_response,  $response['retrieval_ref_no'] ) );
             }

        }

        public function _make_api_call($data, $endpoint = 'transaction') {
            $curl = curl_init();
            $base_url = $this->test_mode ? "https://api.demo.e-xact.com/" : "https://api.e-xact.com/";
            curl_setopt_array($curl, array(
                CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $base_url . $endpoint,
                CURLOPT_POST => 1,
                CURLOPT_USERPWD => $this->gateway_id . ":" . $this->api_pass,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HEADER => false,
                CURLOPT_VERBOSE => 1
            ));
            $exec = curl_exec($curl);
            $this->full_response = print_r($exec,true);
            $return = json_decode($exec, true);
            curl_close($curl);
            return $return;
        }

        function get_validation_key($validation_key, $order_number, $amount) {
            // Create key by joining the $validation_key, $order_number and $amount.
            $formatted_amt = number_format($amount, 2, '.', '');

            // Concatenate the values together
            $validation_key = $validation_key . $order_number . $formatted_amt;

            // Return the encoded validation key
            return base64_encode(md5($validation_key, true));
        }

        function process_payment($order_id) {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

    }
}
