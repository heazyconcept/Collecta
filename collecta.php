<?php
/*
Plugin Name: collecta - WooCommerce Gateway
Description: Extends WooCommerce by Adding the collecta payment gateway.
Version: 1
Author: Ezekiel Fadipe, O'sigla Resources
Author URI: http://www.osigla.com.ng/
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'woocommerce_collecta_payment_init', 0);

function woocommerce_collecta_payment_init()
{

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_collecta_payment extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->collecta_payment_errors = new WP_Error();

            $this->id = 'collecta';
            $this->icon = apply_filters('woocommerce_collecta_payment_icon', plugins_url('images/logo.png', __FILE__));
            $this->method_title = 'collectapayment';
            $this->has_fields = false;
            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->secret_key = $this->get_option('secret_key');
            // $this->wema_tranx_curr = $this->settings['wema_tranx_curr'];
            // $this->hashkey = $this->settings['hashkey'];
            $this->redirect_url = WC()->api_request_url('WC_collecta_payment');

            $this->posturl = 'https://app.collecta.com.ng/Pay/Pay/';
            $this->confirmurl = "https://app.collecta.com.ng/api/Query/Status/";
            //                            $this->geturl = "https://apps.wemabank.com/wemamerchants/TransactionStatusService.asmx/GetTransactionDetailsJson";
            //Actions
            add_action('woocommerce_receipt_collecta', array(
                $this,
                'receipt_page',
            ));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options',
            ));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_collecta_payment', array(
                $this,
                'check_collecta_response',
            ));

            //Display Transaction Reference on checkout
            add_action('before_woocommerce_pay', array(
                $this,
                'display_transaction_id',
            ));

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }

        }
        public function is_valid_for_use()
        {

            if (!in_array(get_woocommerce_currency(), array(
                'NGN',
            ))) {
                $this->msg = 'Colleca Webpay doesn\'t support your store currency, set it to Nigerian Naira &#8358; <a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=general">here</a>';
                return false;
            }

            return true;
        }
        /**
         * Check if this gateway is enabled
         */
        public function is_available()
        {

            if ($this->enabled == "yes") {

                if (!($this->merchant_id && $this->secret_key)) {
                    return false;
                }
                return true;
            }

            return false;
        }
        public function admin_options()
        {
            echo '<h3>' . __('Collecta Payment Gateway', 'collecta') . '</h3>';
            echo '<p>' . __('Collecta is most popular payment gateway for online shopping in Nigeria') . '</p>';
            if ($this->is_valid_for_use()) {
                echo '<table class="form-table">';
                $this->generate_settings_html();
                echo '</table>';
            } else {
                ?>
         <div class="inline error"><p><strong>Collecta Payment Gateway Disabled</strong>: <?php
echo $this->msg;
                ?></p></div>

          <?php
}

            // wp_enqueue_script('gtpay_admin_option_js', plugin_dir_url(__FILE__) . 'assets/js/settings.js', array('jquery'), '1.0.1');
        }
        function init_form_fields()
        {
            $this->form_fields = array(

                'enabled' => array(
                    'title' => __('Enable/Disable', 'collecta'),
                    'type' => 'checkbox',
                    'label' => __('Enable Collecta Payment Module.', 'collecta'),
                    'default' => 'no',
                ),

                'title' => array(
                    'title' => __('Title:', 'collecta'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'collecta'),
                    'default' => __('Collecta Payment Gateway', 'collecta'),
                ),
                'description' => array(
                    'title' => __('Description:', 'collecta'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'collecta'),
                    'default' => __('Pay via Collecta', 'collecta'),
                ),
                'merchant_id' => array(
                    'title' => __('Merchant Id', 'collecta'),
                    'type' => 'text',
                    'description' => __('Enter your merchant Id', 'collecta'),
                ),
                'secret_key' => array(
                    'title' => __('Secret Key', 'collecta'),
                    'type' => 'text',
                    'description' => __('Enter your secret key here', 'collecta'),
                ),

            );
        }
        public function get_collecta_args($order)
        {

            $order_total = $order->get_total();
            $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
            $txnid = $order_id . '_' . uniqid();
            $redirect_url = $this->redirect_url;
            $email_address = method_exists($order, 'get_billing_email') ? $order->get_billing_email() : $order->billing_email;
            $first_name = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : $order->billing_first_name;
            $last_name = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : $order->billing_last_name;
            $customer_phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : $order->billing_phone;
            $collecta_hash = $order_total . $this->merchant_id . $this->secret_key;
            $hash = hash('sha256', $collecta_hash);
            // collecta Args
            $collecta_args = array(
                'Amount' => $order_total,
                'MerchantId' => $this->merchant_id,
                'Hash' => $hash,
                'EmailAddress' => $email_address,
                'ref' => $txnid,
                'Validity' => "600",
                'PhoneNumber' => $customer_phone,
                'SurName' => $first_name,
                'FirstName' => $last_name,
                'ReturnURL' => $redirect_url,
            );

            WC()->session->set('wc_collecta_txn_id', $txnid);

            $collecta_args = apply_filters('woocommerce_collecta_args', $collecta_args);

            return $collecta_args;
        }
        /**
         * Generate the Webpay Payment button link
         **/
        public function generate_collecta_form($order_id)
        {

            $order = wc_get_order($order_id);

            $collecta_args = $this->get_collecta_args($order);

            // before payment hook
            do_action('wc_collecta_before_payment', $collecta_args);

            $collecta_args_array = array();

            foreach ($collecta_args as $key => $value) {
                $collecta_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }

            wc_enqueue_js('
      function processcollectaJSPayment(){
      jQuery("body").block(
      {
          message: "<img src=\"' . plugins_url('assets/images/ajax-loader.gif', __FILE__) . '\" alt=\"redirecting...\" style=\"float:left; margin-right: 10px;\" />' . __('Thank you for your order. We are now redirecting you to Payment Gateway to make payment.', 'collecta') . '",
              overlayCSS:
      {
          background: "#fff",
              opacity: 0.6
      },
      css: {
      padding:        20,
          textAlign:      "center",
          color:          "#555",
          border:         "3px solid #aaa",
          backgroundColor:"#fff",
          cursor:         "wait",
          lineHeight:"32px"
      }
      });
      jQuery("#collecta_payment_form").submit();
      }
      jQuery("#submit_collecta_payment_form").click(function (e) {
      e.preventDefault();
      processcollectaJSPayment();
      });
      ');

            return '<form action="' . $this->posturl . '" method="post" id="collecta_payment_form">
          ' . implode('', $collecta_args_array) . '
          <!-- Button Fallback -->
        <input type="submit" class="button-alt" name="checkout" id="submit_collecta_payment_form" value="' . __('Pay via Collecta', 'collecta') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'collecta') . '</a>
        </form>';
        }
        function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            );
        }
        function receipt_page($order)
        {
            echo '<p>Thank you - your order is now pending payment. Click the button below to pay via collecta.</p>';
            echo $this->generate_collecta_form($order);
        }
        function check_collecta_response()
        {
            
            if (isset($_REQUEST["Ref"]) &&  isset($_REQUEST['MerchantRef'])) {
               
                $collecta_echo_data = $_REQUEST["MerchantRef"];
                $transactionReference = $_REQUEST["Ref"];
                $data = explode("_", $collecta_echo_data);
                $wc_order_id = $data[0];
                $wc_order_id = (int) $wc_order_id;
                $order = wc_get_order($wc_order_id);
                $order_total = $order->get_total();
                $collecta_hash = $order_total. $this->merchant_id . $this->secret_key;
                $hash = hash('sha256', $collecta_hash);
                try {
                    wc_print_notices();
                    //                  $mert_id = $this->wema_mert_id;
                    //                  $ch = curl_init();
                    //                  $url = $this->geturl. "?merchantcode={$mert_id}&transactionref={$wema_echo_data}";
                    //
                    //                  curl_setopt_array($ch, array(
                    //                      CURLOPT_URL => $url,
                    //                      CURLOPT_NOBODY => false,
                    //                      CURLOPT_RETURNTRANSFER => true,
                    //                      CURLOPT_SSL_VERIFYPEER => false
                    //                  ));
                    //                  $response = curl_exec($ch);
                    //                  $d1 = new SimpleXMLElement($response);
                    //                  $json = json_decode($d1, TRUE);
                    
                    $response = $this->QueryResponse($hash, $transactionReference);
                    $response = json_decode($response['body']);
                    if ($response->status == "success") {
                        #payment successful
                        if($response->data->IsSuccessful){
                        $respond_desc = $response->data->FriendlyMessage;
                        $message_resp = "Approved Successful." . "<br>" . $respond_desc . "<br>Transaction Reference: " . $collecta_echo_data;
                        $message_type = "success";
                        $order->payment_complete($collecta_echo_data);
                        // $order->update_status('completed');
                        $order->add_order_note('Collecta payment successful: ' . $message_resp, 1);
                        // Empty cart
                        wc_empty_cart();
                        $redirect_url = $this->get_return_url($order);

                        wc_add_notice($message_resp, "success");

                        }else{
                        #payment failed
                        $respond_desc = $response->data->FriendlyMessage;
                        $message_resp = "Your transaction was not successful." . "<br>Reason: " . $respond_desc . "<br>Transaction Reference: " . $collecta_echo_data;
                        $message_type = "error";
                        $order->add_order_note('collecta payment failed: ' . $message_resp);
                        $order->update_status('cancelled');
                        $redirect_url = $order->get_cancel_order_url();
                        wc_add_notice($message_resp, "error");
                        }
                        
                    } else {
                        #payment failed
                        $respond_desc = $response->data->FriendlyMessage;
                        $message_resp = "Your transaction was not successful." . "<br>Reason: " . $respond_desc . "<br>Transaction Reference: " . $collecta_echo_data;
                        $message_type = "error";
                        $order->add_order_note('collecta payment failed: ' . $message_resp);
                        $order->update_status('cancelled');
                        $redirect_url = $order->get_cancel_order_url();
                        wc_add_notice($message_resp, "error");
                    }

                    $notification_message = array(
                        'message' => $message_resp,
                        'message_type' => $message_type,
                    );

                    wp_redirect(html_entity_decode($redirect_url));
                    exit;
                } catch (Exception $e) {
                    $order->add_order_note('Error: ' . $e->getMessage());
                    wc_add_notice($e->getMessage(), "error");
                    $redirect_url = $order->get_cancel_order_url();
                    wp_redirect(html_entity_decode($redirect_url));
                    exit;
                }
            }

        }
        function QueryResponse($hash,$transactionRef){
            
                $url = $this->confirmurl.'?transactionRef='.$transactionRef;
                $args = array(
                    'headers'  => array(
                        'Hash' => $hash,
                        'Accept' => 'application/json',
                        )
                );
                // return $args;
                $response = wp_remote_get( $url, $args ); 
                return $response;
                // $ch = curl_init();
                // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                // curl_setopt($ch, CURLOPT_HEADER, 0);
                // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); 
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // $server_output = curl_exec($ch);
                // curl_close ($ch);
                // return $server_output;

            }

        public function display_transaction_id()
        {

            if (get_query_var('order-pay')) {

                $order_id = absint(get_query_var('order-pay'));
                $order = wc_get_order($order_id);

                $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : $order->payment_method;

                if (!isset($_GET['pay_for_order']) && ('collecta' == $payment_method)) {
                    $txn_ref = $order_id = WC()->session->get('wc_collecta_txn_id');
                    WC()->session->__unset('wc_collecta_txn_id');
                    echo '<h4>Transaction Reference: ' . $txn_ref . '</h4>';
                }

            }
        }

    }
    function wc_collecta_message()
    {

        if (get_query_var('order-received')) {

            $order_id = absint(get_query_var('order-received'));
            $order = wc_get_order($order_id);
            $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : $order->payment_method;

            if (is_order_received_page() && ('collecta' == $payment_method)) {

                $notification = get_post_meta($order_id, '_collecta_wc_message', true);

                $message = isset($notification['message']) ? $notification['message'] : '';
                $message_type = isset($notification['message_type']) ? $notification['message_type'] : '';

                delete_post_meta($order_id, '_collecta_wc_message');

                if (!empty($message)) {
                    wc_add_notice($message, $message_type);
                }
            }

        }
    }
    add_action('wp', 'wc_collecta_message', 0);
    function woocommerce_add_collecta_gateway($methods)
    {
        $methods[] = 'WC_collecta_payment';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_collecta_gateway');
    if (version_compare(WOOCOMMERCE_VERSION, "2.1") <= 0) {

        /**
         * Add NGN as a currency in WC
         **/
        add_filter('woocommerce_currencies', 'collecta_add_my_currency');

        if (!function_exists('collecta_add_my_currency')) {
            function collecta_add_my_currency($currencies)
            {
                $currencies['NGN'] = __('Naira', 'woocommerce');
                return $currencies;
            }
        }

        /**
         * Enable the naira currency symbol in WC
         **/
        add_filter('woocommerce_currency_symbol', 'collecta_add_my_currency_symbol', 10, 2);

        if (!function_exists('collecta_add_my_currency_symbol')) {
            function collecta_add_my_currency_symbol($currency_symbol, $currency)
            {
                switch ($currency) {
                    case 'NGN':
                        $currency_symbol = '&#8358; ';
                        break;
                }
                return $currency_symbol;
            }
        }
    }
    function collecta_plugin_action_links($links, $file)
    {
        static $this_plugin;

        if (!$this_plugin) {
            $this_plugin = plugin_basename(__FILE__);
        }

        if ($file == $this_plugin) {
            $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=collecta">Settings</a>';
            array_unshift($links, $settings_link);
        }
        return $links;
    }
    add_filter('plugin_action_links', 'collecta_plugin_action_links', 10, 2);

}
