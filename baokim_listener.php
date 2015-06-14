<?php
/**
 * BAOKIM PAYMENT NOTIFICATION
 * Script này có chức năng như sau:
 * - Nhận thông báo giao dịch từ Bảo Kim (BPN)
 * - Gọi ngược thông tin nhận được trên BPN về Bảo Kim để xác minh thông tin
 * - Ghi log BPN nhận được
 * - Nếu xác minh thông tin trên BPN thành công, cập nhật (hoàn thành) đơn hàng
 *
 * Copy Right by BaoKim, Jsc 2013
 * @author hieunn
 */

/**
 * CẤU HÌNH HỆ THỐNG
 * @const DIR_LOG   Đường dẫn file log. Thư mục mặc định là baokim_listener
 * @const FILE_NAME Tên file log mặc định.
 *
 */
define('DIR_LOG', 'logs/');
define('FILE_NAME', 'bpn'); //Phần mở rộng của file là .log

//trạng thái giao dịch trên bảo kim: hoàn thành
define('BAOKIM_TRANSACTION_STATUS_COMPLETED', 4);

//trạng thái giao dịch trên bảo kim: đang tạm giữ
define('BAOKIM_TRANSACTION_STATUS_TEMP_HOLDING', 13);

class WC_Baokim_listener extends WC_Gateway_Baokim
{
	private $file_log_name = FILE_NAME;
	private $test_bpn = 'http://kiemthu.baokim.vn/bpn/verify';
	private $live_bpn = 'https://www.baokim.vn/bpn/verify';
	private $myFile;
	private $testmode;

	public function __construct($testmode)
	{
		$bpn_file_log = $this->getBPNFileLog();
		$this->myFile = DIR_LOG . $bpn_file_log . "-" . date("d-m") . ".log";
		$this->isFileORDirExist(DIR_LOG, $this->myFile);
		$this->testmode = $testmode;
	}

	function index()
	{
		@ob_clean();
		if ($this->check_bpn_request_is_valid()) {
			$this->successful_request();
		}
	}

	/**
	 * Hàm thực hiện nhận và kiểm tra dữ liệu từ Bảo Kim
	 * @return bool
	 */
	private function check_bpn_request_is_valid()
	{
		global $woocommerce;
		$req = '';

		//Kiểm tra sự tồn tại dữ liệu nhận từ BaoKim
		if (empty($_POST)) {
			$this->writeLog("Khong nhan duoc du lieu tu BaoKim");
			return false;
		}

		//Lấy url verify BPN
		if ($this->testmode == 'yes'):
			$baokim_url = $this->test_bpn;
		else :
			$baokim_url = $this->live_bpn;
		endif;

		//Kiểm tra thư viện cURL
		if ($this->_isCurl()) {
			foreach ($_POST as $key => $value) {
				$value = urlencode(stripslashes($value));
				$req .= "&$key=$value";
			}
			$this->writeLog('Gui du lieu den '.$baokim_url.'. Thuc hien kiem tra tinh hop le cua BPN...', true);
		} else {
			$this->writeLog('Kiem tra cURL tren may chu');
			return false;
		}
		$this->writeLog('BPN Data: ' . print_r($_POST, true));

		/**
		 * Gửi dữ liệu về Bảo Kim. Kiểm tra tính chính xác của dữ liệu
		 * @param $result Kết quả xác thực thông tin trả về.
		 * @paran $status Mã trạng thái trả về.
		 * @error $error  Lỗi được ghi vào file bpn.log
		 */
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $baokim_url);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		$result = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		if ($result != '' && strstr($result, 'VERIFIED') && $status == 200) {
			$this->writeLog(' => VERIFIED');
			return true;
		} else {
			$this->writeLog(' => INVALID');
		}
		if ($error)
			$this->writeLog(' => | ERROR: ' . $error);

		return false;
	}

	/**
	 * Thực hiện cập nhập trạng thái đon hàng sau khi hoàn thiện kiểm tra thông tin thanh toán
	 */
	private function successful_request()
	{
		//global $woocommerce;
		$str_id = explode("-", $_POST['order_id']);
		$order_id = $str_id[1];
		$transaction_id = isset($_POST['transaction_id']) ? $_POST['transaction_id'] : '';
		$transaction_status = isset($_POST['transaction_status']) ? $_POST['transaction_status'] : '';
		$total_amount = isset($_POST['total_amount']) ? $_POST['total_amount'] : '';
		$currency_rate = isset($_POST['usd_vnd_exchange_rate']) ? $_POST['usd_vnd_exchange_rate'] : '';
		$net_amount = isset($_POST['net_amount']) ? $_POST['net_amount'] : '';
		$fee_amount = isset($_POST['fee_amount']) ? $_POST['fee_amount'] : '';
		$customer_name = isset($_POST['customer_name']) ? $_POST['customer_name'] : '';
		$customer_address = isset($_POST['customer_address']) ? $_POST['customer_address'] : '';

		if (get_woocommerce_currency() == 'USD') {
			$total_amount = (int)($total_amount / $currency_rate);
		}

		$isVaild = $this->isValidOrderInfo($transaction_status, $total_amount, $order_id);
		if ($isVaild) {
			//TODO: trường hợp đối soát thông tin BPN thành công => hoàn thành đơn hàng (website merchant có thể edit lại phần này theo yêu cầu)
			$order = new WC_Order($order_id);
			$comment_status = '';
			$order_status = '';
			switch ($transaction_status) {
				case 4:
					$comment_status = 'Nhận BPN : Thực hiện thanh toán thành công với đơn hàng ' . $order_id . '. Giao dịch hoàn thành. Cập nhật trạng thái cho đơn hàng thành công';
					$order->add_order_note(__($comment_status, 'woocommerce'));
					$order->payment_complete();
					$order_status = 'complete';
					break;
				case 13:
					$comment_status = 'Nhận BPN : Thực hiện thanh toán thành công với đơn hàng ' . $order_id . '.Giao dịch đang tạm giữ. Cập nhật trạng thái cho đơn hàng thành công';
					$order->update_status('on-hold', sprintf(__('Payment pending: %s', 'woocommerce'), $comment_status));
					$order_status = 'pending';
					break;
			}
			$comments = 'Bao Kim xac nhan don hang [' . $order_status . ']';
			$this->writeLog($comments);
		}
	}

	/**
	 * Kiểm tra thông tin đơn hàng và đối soát với thông tin trên BPN gồm:
	 *          - Trạng thái giao dịch.
	 *          - Mã đơn hàng.
	 *          - Số tiền giao dịch.
	 *
	 * @param $transaction_status Trạng thái giao dịch từ BaoKim
	 *                           4 : giao dịch hoàn thành
	 *                          13 : Giao dịch tạm giữ
	 *
	 * @param $total_amount     Số tiền thanh toán ở BaoKim
	 * @param $order_id         Mã đơn hàng thanh toán từ BaoKim
	 * @return bool             True : Không xảy ra lỗi trong quá trình kiểm thông tin.
	 *                          False : Có lỗi trong quá trình kiểm tra thông tin. Tiến hành ghi log.
	 */
	private function isValidOrderInfo($transaction_status, $total_amount, $order_id)
	{
		$confirm = '';

		//Danh sách các trạng thái giao dịch có thể coi là thành công (có thể giao hàng)
		$success_transaction_status = array(BAOKIM_TRANSACTION_STATUS_COMPLETED, BAOKIM_TRANSACTION_STATUS_TEMP_HOLDING);

		//Kiểm tra trạng thái giao dịch
		if (in_array($transaction_status, $success_transaction_status)) {

			//Lấy thông tin order
			if (!is_numeric($order_id) && ($order_id == 0)) {
				$confirm .= "\r\n" . ' Khong ton tai ma don hang : ' . $order_id;
			}

			//Kiểm tra sự tồn tại của đơn hàng
			$order_info = new WC_Order($order_id);
			if (empty($order_info)) {
				$confirm .= "\r\n" . ' Don hang khong ton tai voi ma don hang : ' . $order_id;
				$order_info->update_status('on-hold', sprintf(__('Thanh toán tạm giữ: %s', 'woocommerce'), $confirm));
			}

			//Kiểm tra số tiền đã thanh toán phải >= giá trị đơn hàng
			//Lấy giá trị đơn hàng
			if ($total_amount < $order_info->order_total) {
				$confirm .= "\r\n" . ' So tien thanh toan: ' . $total_amount . ' nho hon gia tri don hang ung voi ma don hang: ' . $order_id;
				$order_info->update_status('on-hold', sprintf(__('Thanh toán tạm giữ: %s', 'woocommerce'), $confirm));
			}

		} else {
			$confirm .= "\r\n" . ' Trang thai giao dich:' . $transaction_status . ' chua thanh cong ung voi ma don hang : ' . $order_id;
		}

		if ($confirm == '') {
			return true;
		}
		$this->writeLog($confirm);
		return false;
	}

	/**
	 * Hàm thực hiện việc ghi log vào file log
	 * @param $mess        Nội dung thông báo log
	 * @param bool $begin  Bắt đầu của một file log
	 */
	private function writeLog($mess, $begin = false)
	{
		$file_log = $this->myFile;
		$fh = fopen($file_log, 'a') or die("can't open file");
		if ($begin) {
			fwrite($fh, "\r\n" . "---------------------------------------------------");
			fwrite($fh, "\r\n" . date("Y-m-d H:i:s") . " --- | --- " . $mess);
		} else {
			fwrite($fh, "\r\n" . $mess);
		}
	}

	/**
	 * Hàm lấy lấy và kiểm tra tên file log do người dùng cấu hình trong trang quản trị.
	 * Loại bỏ ký tự đặc biệt, nếu rỗng hoặc có dấu cách tên file mặc định là bpn
	 * @return mixed
	 */
	private function getBPNFileLog()
	{
		$bpn_file_log = preg_replace('/[^a-zA-Z0-9\_-]/', '', $this->bpn_file);
		if (!empty($bpn_file_log)) {
			$this->file_log_name = $bpn_file_log;
		}
		return $this->file_log_name;
	}

	/**
	 * Hàm kiểm tra sự tồn tại của file log. Thực hiện tạo mới nếu file không tồn tại
	 * @param $dir      Tên thư mục
	 * @param $fileName Tên file
	 */
	private function isFileORDirExist($dir, $fileName)
	{
		if ($dir != '') {
			if (!is_dir($dir)) {

				mkdir($dir);
			}
		}
		if ($fileName != '') {
			if (!file_exists($fileName)) {
				$ourFileHandle = fopen($fileName, 'w') or die("can't open file");
				fclose($ourFileHandle);
			}
		} else {
			die;
		}
	}

	/**
	 * Kiểm tra thư viện cURL chắc chắn được cài đặt trên máy chủ
	 * @return bool
	 */
	private function _isCurl()
	{
		return function_exists('curl_version');
	}

}