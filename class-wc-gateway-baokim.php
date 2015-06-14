<?php
/**
 * Plugin Name: BaoKim Payment Gateway
 * Plugin URI: developer.baokim.vn/module-php/18
 * Description: Thanh toán với Bảo Kim đảm bảo tuyệt đối cho mọi giao dịch
 * - Tích hợp thanh toán qua baokim.vn cho các merchant site có đăng ký API.
 * - Gửi thông tin thanh toán tới baokim.vn để xử lý việc thanh toán.
 * - Xác thực tính chính xác của thông tin được gửi về từ baokim.vn
 * Version: 1.0.1
 * Author: hieunn
 * Author URI: http://developer.baokim.vn/
 * License: BaoKim, Jsc 2013
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly
//Check ì WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

	//Create class after the plugins are loaded
	add_action('plugins_loaded', 'init_gateway_class');

	//Init payment gateway class
	function init_gateway_class()
	{

		/**
		 * BaoKim Payment Gateway
		 *
		 * Provides a BaoKim Payment Gateway.
		 *
		 * @class       WC_Baokim
		 * @extends     WC_Gateway_Baokim
		 * @version     2.0.0
		 * @package     WooCommerce/Classes/Payment
		 * @author      hieunn
		 */

		class WC_Gateway_Baokim extends WC_Payment_Gateway
		{
			var $notify_url;

			/**
			 * Constructor for the gateway.
			 *
			 * @access public
			 * @return \WC_Gateway_Baokim
			 */
			public function __construct()
			{
				global $woocommerce;

				$this->id = 'baokim';
				//$this->icon = apply_filters('woocommerce_baokim_icon', $woocommerce->plugin_url() . '/assets/images/icons/baokim.png');
				$this->has_fields = false;
				$this->method_title = __('BaoKim', 'woocommerce');
				$this->liveurl = 'https://www.baokim.vn/payment/order/version11';
				$this->testurl = 'http://kiemthu.baokim.vn/payment/order/version11';

				//load the setting
				$this->init_form_fields();
				$this->init_settings();

				//Define user set variables
				$this->title = $this->get_option('title');
				$this->description = $this->get_option('description');
				$this->email = $this->get_option('email');
				$this->merchant_id = $this->get_option('merchant_id');
				$this->secure_pass = $this->get_option('secure_pass');
				$this->testmode = $this->get_option('testmode');
				$this->bpn_file = $this->get_option('bpn_file');
				$this->form_submission_method = false;

				//Action
				add_action('valid-baokim-standard-ipn-request', array($this, 'successful_request'));
				add_action('woocommerce_receipt_baokim', array($this, 'receipt_page'));
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				add_action('woocommerce_api_wc_gateway_baokim', array($this, 'callback'));
				if (!$this->is_valid_for_use()) $this->enabled = false;
			}

			/**
			 * Check if this gateway is enabled and available in the user's country
			 *
			 * @access public
			 * @return bool
			 */
			function is_valid_for_use()
			{
				if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_baokim_supported_currencies', array('VND', 'VNĐ', 'USD'))))
					return false;
				return true;
			}

			/**
			 * Admin Panel Options
			 * - Options for bits like 'title' and availability on a country-by-country basis
			 *
			 * @since 1.0.0
			 */
			public function admin_options()
			{
				?>
				<h3><?php _e('Thanh toán Bảo Kim', 'woocommerce'); ?></h3>
				<strong><?php _e('Đảm bảo an toàn tuyệt đối cho mọi giao dịch.', 'woocommerce'); ?></strong>
				<?php if ($this->is_valid_for_use()) : ?>

				<table class="form-table">
					<?php
					// Generate the HTML For the settings form.
					$this->generate_settings_html();
					?>
				</table><!--/.form-table-->

			<?php else : ?>
				<div class="inline error"><p>
						<strong><?php _e('Gateway Disabled', 'woocommerce'); ?></strong>: <?php _e('Phương thức thanh toán Bảo Kim không hỗ trợ loại tiền tệ trên gian hàng của bạn.', 'woocommerce'); ?>
					</p></div>
			<?php
			endif;
			}

			/**
			 * Initialise Gateway Settings Form Fields
			 *
			 * @access public
			 * @return void
			 */
			function init_form_fields()
			{

				$this->form_fields = array(
					'enabled' => array(
						'title' => __('Sử dụng phương thức', 'woocommerce'),
						'type' => 'checkbox',
						'label' => __('Đồng ý', 'woocommerce'),
						'default' => 'yes'
					),
					'title' => array(
						'title' => __('Tiêu đề', 'woocommerce'),
						'type' => 'text',
						'description' => __('Tiêu đề của phương thức thanh toán bạn muốn hiển thị cho người dùng.', 'woocommerce'),
						'default' => __('Bảo Kim', 'woocommerce'),
						'desc_tip' => true,
					),
					'description' => array(
						'title' => __('Mô tả phương thức thanh toán', 'woocommerce'),
						'type' => 'textarea',
						'description' => __('Mô tả của phương thức thanh toán bạn muốn hiển thị cho người dùng.', 'woocommerce'),
						'default' => __('Thanh toán với Bảo Kim. Đảm bảo an toàn tuyệt đối cho mọi giao dịch', 'woocommerce')
					),
					'account_config' => array(
						'title' => __('Cấu hình tài khoản', 'woocommerce'),
						'type' => 'title',
						'description' => '',
					),
					'email' => array(
						'title' => __('E-mail Bảo Kim', 'woocommerce'),
						'type' => 'email',
						'description' => __('E-mail tài khoản bạn đăng ký với BaoKim.vn.', 'woocommerce'),
						'default' => '',
						'desc_tip' => true,
						'placeholder' => 'you@youremail.com'
					),
					'merchant_id' => array(
						'title' => __('Merchant ID', 'woocommerce'),
						'type' => 'text',
						'description' => __('“Mã website” được Baokim cấp khi bạn đăng ký tích hợp website.', 'woocommerce'),
						'default' => '',
						'desc_tip' => true,
					),
					'secure_pass' => array(
						'title' => __('Mã bảo mật', 'woocommerce'),
						'type' => 'text',
						'description' => __('“Mật khẩu” được Baokim cấp khi bạn đăng ký tích hợp website.', 'woocommerce'),
						'default' => '',
						'desc_tip' => true,
					),
					'testmode' => array(
						'title' => __('Bảo Kim kiểm thử', 'woocommerce'),
						'type' => 'checkbox',
						'label' => __('Sử dụng Bảo Kim kiểm thử', 'woocommerce'),
						'default' => 'yes',
						'description' => 'Bảo Kim kiểm thử được sử đụng kiểm tra phương thức thanh toán.',
					),
					'testing' => array(
						'title' => __('Cấu hình BPN(BaoKim Payment Notification)', 'woocommerce'),
						'type' => 'title',
						'description' => '',
					),
					'bpn_file' => array(
						'title' => __('Tên file lưu log', 'woocommerce'),
						'type' => 'text',
						'description' => sprintf(__('Tên file lưu trữ log trong quá trình thực hiện BPN, truy cập file log <code>woocommerce/logs/bpn-%s.log</code>', 'woocommerce'), date("d-m")),
						'default' => 'bpn',
						'desc_tip' => true,
					),
				);

			}

			/**
			 * Process the payment and return the result
			 *
			 * @access public
			 * @param int $order_id
			 * @return array
			 */
			function process_payment($order_id)
			{
				$order = new WC_Order($order_id);
				if (!$this->form_submission_method) {
					$baokim_args = $this->get_baokim_args($order);
					if ($this->testmode == 'yes'):
						$baokim_server = $this->testurl; else :
						$baokim_server = $this->liveurl;
					endif;
					$baokim_url = $this->createRequestUrl($baokim_args, $baokim_server);
					return array(
						'result' => 'success',
						'redirect' => $baokim_url
					);
				} else {
					return array(
						'result' => 'success',
						'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
					);
				}
			}


			/**
			 * Lấy thông tin đơn hàng
			 * @param mixed $order
			 * @internal param order_id             Mã đơn hàng
			 * @internal param business             Tài khoản người bán
			 * @internal param total_amount         Giá trị đơn hàng
			 * @internal param shipping_fee         Phí vận chuyển
			 * @internal param tax_fee              Thuế
			 * @internal param order_description    Mô tả đơn hàng
			 * @internal param url_success          Url trả về khi thanh toán thành công
			 * @internal param url_cancel           Url trả về khi hủy thanh toán
			 * @internal param url_detail           Url chi tiết đơn hàng
			 * @internal param payer_name           Thông tin thanh toán
			 * @internal param payer_email
			 * @internal param payer_phone_no
			 * @internal param shipping_address
			 * @access public
			 * @return array
			 */
			function get_baokim_args($order)
			{
				global $woocommerce;
				$order_id = $order->id;
				$url_success = get_bloginfo('wpurl') . "/?wc-api=WC_Gateway_Baokim&action=order_received";
				$url_cancel = $order->get_cancel_order_url();
				$baokim_args = array(
					'merchant_id' => strval($this->merchant_id),
					'order_id' => strval(time() . "-" . $order_id),
					'business' => strval($this->email),
					'order_description' => strval($order->customer_note),
					'url_success' => strtolower($url_success),
					'url_cancel' => strtolower($url_cancel),
					'url_detail' => '',
					'payer_name' => strval($order->billing_first_name . " " . $order->billing_last_name),
					'payer_email' => strval($order->billing_email),
					'payer_phone_no' => strval($order->billing_phone),
					'shipping_address' => strval($order->shipping_address_1),
				);

				$baokim_args['total_amount'] = $order->order_total;
				$baokim_args['tax_fee'] = $order->order_tax;
				$baokim_args['shipping_fee'] = $order->order_shipping;
				$baokim_args['currency'] = get_woocommerce_currency();

				return $baokim_args;
			}

			/**
			 * Điều hướng tác vụ xử lý cập nhật đơn hàng sau thanh toán hoặc nhận BPN từ Bảo Kim
			 */
			function callback()
			{
				if (!empty($_GET) && isset($_GET['action'])) {
					switch ($_GET['action']) {
						case 'order_received' :
							$this->order_received();
							break;
						case 'bpn' :
							$this->baokim_payment_notification();
							break;
					}
				}
			}

			/**
			 * Hàm thực hiện kiểm tra đơn hàng và cập nhập trạng thái đơn hàng sau khi thanh toán tại baokim.vn
			 */
			private function order_received()
			{
				if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
					$str_id = explode("-", $_GET['order_id']);
					$order_id = $str_id[1];
					if (is_numeric($order_id) && $order_id > 0) :
						$order = new WC_Order($order_id); else :
						die;
					endif;

					if (empty($order))
						die;
					unset($_GET['wc-api']);
					unset($_GET['action']);

					if ($this->verifyResponseUrl($_GET)) {
						wp_redirect(add_query_arg('utm_nooverride', '1', $this->get_return_url($order)));
					} else {
						$order->get_cancel_order_url();
					}
				}
			}

			/**
			 * BAOKIM PAYMENT NOTIFICATION
			 */
			private function baokim_payment_notification()
			{
				include(WP_PLUGIN_DIR . '/baokim/baokim_listener.php');
				$bpn = new WC_Baokim_listener($this->testmode);
				$bpn->index();
			}

			/**
			 * Hàm xây dựng url chuyển đến BaoKim.vn thực hiện thanh toán, trong đó có tham số mã hóa (còn gọi là public key)
			 * @param $data             Các tham số thông tin đơn hàng gửi đến BaoKim
			 * @param $baokim_server    URL Server xử lý đơn hàng của Bảo Kim.
			 * @return url cần tạo
			 */
			private function createRequestUrl($data, $baokim_server)
			{
				// Mảng các tham số chuyển tới baokim.vn
				$params = $data;
				ksort($params);

				$params['checksum'] = hash_hmac('SHA1', implode('', $params), $this->secure_pass);

				//Kiểm tra  biến $redirect_url xem có '?' không, nếu không có thì bổ sung vào
				$redirect_url = $baokim_server;
				if (strpos($redirect_url, '?') === false) {
					$redirect_url .= '?';
				} else if (substr($redirect_url, strlen($redirect_url) - 1, 1) != '?' && strpos($redirect_url, '&') === false) {
					// Nếu biến $redirect_url có '?' nhưng không kết thúc bằng '?' và có chứa dấu '&' thì bổ sung vào cuối
					$redirect_url .= '&';
				}

				// Tạo đoạn url chứa tham số
				$url_params = '';
				foreach ($params as $key => $value) {
					if ($url_params == '')
						$url_params .= $key . '=' . urlencode($value);
					else
						$url_params .= '&' . $key . '=' . urlencode($value);
				}
				return $redirect_url . $url_params;
			}

			/**
			 * Hàm thực hiện xác minh tính chính xác thông tin trả về từ BaoKim.vn
			 * @param array $url_params chứa tham số trả về trên url
			 * @return true nếu thông tin là chính xác, false nếu thông tin không chính xác
			 */
			private function verifyResponseUrl($url_params = array())
			{
				if (empty($url_params['checksum'])) {
					echo "invalid parameters: checksum is missing";
					return FALSE;
				}

				$checksum = $url_params['checksum'];
				unset($url_params['checksum']);

				ksort($url_params);

				if (strcasecmp($checksum, hash_hmac('SHA1', implode('', $url_params), $this->secure_pass)) === 0)
					return TRUE;
				else
					return FALSE;
			}

		}

		class WC_Baokim extends WC_Gateway_Baokim
		{
			public function __construct()
			{
				_deprecated_function('WC_Baokim', '1.4', 'WC_Gateway_Baokim');
				parent::__construct();
			}
		}

		//Defining class gateway
		function add_gateway_class( $methods ) {
			$methods[] = 'WC_Gateway_Baokim';
			return $methods;
		}

		add_filter( 'woocommerce_payment_gateways', 'add_gateway_class' );
	}
}
