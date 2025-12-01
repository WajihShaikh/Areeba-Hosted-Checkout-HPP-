<?php
/**
 * Plugin Name: Areeba MPGS WooCommerce Gateway
 * Description: Fully functional WooCommerce payment gateway integration for Areeba MPGS Hosted Checkout.
 * Version: 1.3.0
 * Author: ChatGPT / Gemini
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'areeba_mpgs_gateway_init', 11);
function areeba_mpgs_gateway_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_Areeba_MPGS extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'areeba_mpgs';
            $this->method_title = __('Areeba MPGS HPP', 'areeba');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->api_password = $this->get_option('api_password');
            $this->webhook_secret = $this->get_option('webhook_secret');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_areeba_webhook', [$this, 'handle_webhook']);
            add_action('woocommerce_thankyou', [$this, 'empty_cart_after_payment'], 20);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => ['title'=>'Enable/Disable','type'=>'checkbox','label'=>'Enable Areeba MPGS HPP','default'=>'yes'],
                'title' => ['title'=>'Title','type'=>'text','default'=>'Credit Card'],
                'description' => ['title'=>'Description','type'=>'textarea','default'=>'Pay securely by Credit/Debit Card.'],
                'merchant_id' => ['title'=>'Merchant ID','type'=>'text','default'=>'YOUR_MERCHANT_ID'],
                'api_password' => ['title'=>'API Password','type'=>'text','default'=>'YOUR_API_PASSWORD'],
                'webhook_secret' => ['title'=>'Webhook Secret','type'=>'text','description'=>'Enter the Notification Secret from your Areeba merchant portal.']
            ];
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            $session_id = $this->init_checkout_session($order);
            if (!$session_id) {
                wc_add_notice('Payment error: could not initiate checkout session.', 'error');
                return;
            }

            $order->update_meta_data('_areeba_session_id', $session_id);
            $order->save();

            // Redirect to our intermediate page
            return ['result'=>'success','redirect'=>add_query_arg('areeba_order_id',$order_id,home_url('/areeba-redirect/'))];
        }

        private function init_checkout_session($order) {
            $url = "https://epayment.areeba.com/api/rest/version/100/merchant/{$this->merchant_id}/session";
            $auth = base64_encode("merchant.{$this->merchant_id}:{$this->api_password}");

            $body = [
                'apiOperation'=>'INITIATE_CHECKOUT',
                'interaction'=>['operation'=>'PURCHASE','merchant'=>['name'=>$this->title,'address'=>['line1'=>'Your Address']]],
                'order'=>['currency'=>$order->get_currency(),'amount'=>number_format($order->get_total(),2,'.',''),'id'=>$order->get_id(),'description'=>'Order Payment'],
                'transaction'=>['source'=>'INTERNET']
            ];

            $response = wp_remote_post($url,[
                'headers'=>['Authorization'=>'Basic '.$auth,'Content-Type'=>'application/json'],
                'body'=>json_encode($body),
                'timeout'=>30
            ]);

            if (is_wp_error($response)) return false;

            $data = json_decode(wp_remote_retrieve_body($response), true);
            return $data['session']['id'] ?? false;
        }

        public static function maybe_output_checkout() {
            if (!isset($_GET['areeba_order_id'])) return;
            $order_id = intval($_GET['areeba_order_id']);
            $order = wc_get_order($order_id);
            if (!$order) wp_die('Invalid order');

            $session_id = $order->get_meta('_areeba_session_id');
            if (!$session_id) wp_die('Session not found');
            ?>
            <!DOCTYPE html>
            <html><head><title>Redirecting to Payment</title>
            <script src="https://epayment.areeba.com/static/checkout/checkout.min.js"></script>
            <script>
            function errorCallback(error){console.log(JSON.stringify(error));}
            function cancelCallback(){console.log('Payment cancelled');}
            Checkout.configure({session:{id:'<?php echo esc_js($session_id); ?>'}});

            window.onload = function() {
                Checkout.showPaymentPage({
                    onComplete: function() {
                        // Redirect user to WooCommerce thank you page
                        window.location.href = "<?php echo esc_url(wc_get_endpoint_url('order-received', $order_id, wc_get_checkout_url())); ?>";
                    }
                });
            };
            </script></head><body>
            <p>Redirecting to secure payment page...</p>
            </body></html>
            <?php
            exit;
        }

        public function handle_webhook() {
            $payload = file_get_contents('php://input');
            $received_secret = $_SERVER['HTTP_X_NOTIFICATION_SECRET'] ?? '';

            if (empty($this->webhook_secret) || !hash_equals($this->webhook_secret, $received_secret)) {
                error_log('Areeba Webhook Error: Invalid secret.');
                wp_send_json_error('Invalid secret', 401);
                exit;
            }

            $data = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($data['order']['id'])) {
                error_log('Areeba Webhook Error: Invalid JSON or missing order ID.');
                wp_send_json_error('Invalid payload', 400);
                exit;
            }

            $order_id = $data['order']['id'];
            $order = wc_get_order($order_id);

            if (!$order) {
                error_log("Areeba Webhook Error: Order not found: $order_id");
                wp_send_json_error('Order not found', 404);
                exit;
            }

            if (isset($data['result']) && $data['result'] == 'SUCCESS') {
                if (!$order->is_paid()) {
                    $transaction_id = $data['transaction']['id'] ?? 'N/A';
                    $order->payment_complete($transaction_id);
                    $order->add_order_note("Areeba MPGS payment successful. Transaction ID: $transaction_id");

                    // Ensure stock is reduced
                    if (!$order->get_meta('_stock_reduced')) {
                        wc_reduce_stock_levels($order);
                        $order->update_meta_data('_stock_reduced', 'yes');
                        $order->save();
                    }
                }
            } else {
                $error_message = $data['error']['explanation'] ?? 'Payment failed or was not completed.';
                $order->update_status('failed', "Areeba payment failed. Reason: $error_message");
            }

            wp_send_json_success('Webhook received', 200);
            exit;
        }

        public function empty_cart_after_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) return;

            if ($order->get_payment_method() === 'areeba_mpgs' && $order->is_paid()) {
                WC()->cart->empty_cart();
            }
        }
    }

    function add_areeba_mpgs_gateway($methods) {
        $methods[] = 'WC_Gateway_Areeba_MPGS';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways','add_areeba_mpgs_gateway');

    add_action('template_redirect',['WC_Gateway_Areeba_MPGS','maybe_output_checkout']);
}