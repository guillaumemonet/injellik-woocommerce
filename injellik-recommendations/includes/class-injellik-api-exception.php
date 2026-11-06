<?php
/**
 * Error of the Injellik API.
 *
 * @package Injellik
 */

defined( 'ABSPATH' ) || exit;

/**
 * Error of the Injellik API: HTTP status (0 when the API could not be reached) and error code.
 */
class Injellik_Api_Exception extends Exception {

	/** Error code of the API when the subscription of the account is suspended (HTTP 403). */
	const ERROR_SUSPENDED = 'tenant_suspended';
	/** Licence key unknown or revoked (HTTP 401 in licence key mode). */
	const ERROR_INVALID_KEY = 'invalid_licence_key';

	/** @var int */
	private $status;
	/** @var string|null */
	private $error_code;

	/**
	 * @param string      $message    Message.
	 * @param int         $status     HTTP status, 0 when the API could not be reached.
	 * @param string|null $error_code Error code of the API.
	 */
	public function __construct( $message, $status = 0, $error_code = null ) {
		parent::__construct( (string) $message, (int) $status );
		$this->status     = (int) $status;
		$this->error_code = null === $error_code ? null : (string) $error_code;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Network error, timeout or server error: the same request may succeed later.
	 */
	public function is_retryable() {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}

	public function is_suspended() {
		return self::ERROR_SUSPENDED === $this->error_code;
	}

	public function is_invalid_key() {
		return self::ERROR_INVALID_KEY === $this->error_code;
	}

	/**
	 * The API refuses the shop as a whole (suspended subscription, invalid licence key):
	 * no request can succeed until the merchant acts.
	 */
	public function is_access_denied() {
		return $this->is_suspended() || $this->is_invalid_key();
	}
}
