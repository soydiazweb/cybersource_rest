<?php

defined( 'ABSPATH' ) || exit;

final class WC_Cybs_REST_Controller {
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_nopriv_cybs_3ds_return', array( $this, 'challenge_return' ) );
		add_action( 'admin_post_cybs_3ds_return', array( $this, 'challenge_return' ) );
	}

	public function register_routes() {
		foreach ( array( 'setup', 'enroll', 'validate' ) as $action ) {
			register_rest_route(
				'cybersource-rest/v1',
				'/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'checkout_permission' ),
				)
			);
		}
	}

	public function checkout_permission( WP_REST_Request $request ) {
		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->session || ! WC()->cart ) ) {
			wc_load_cart();
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'cybs_nonce', __( 'La sesion de pago expiro. Recarga la pagina.', 'cybersource-rest-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( ! WC()->session || ! WC()->cart ) {
			return new WP_Error( 'cybs_session', __( 'No se encontro una sesion de compra activa.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );
		}

		$ip     = WC_Geolocation::get_ip_address();
		$key    = 'cybs_rate_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$count  = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return new WP_Error( 'cybs_rate_limit', __( 'Demasiados intentos de pago. Espera unos minutos.', 'cybersource-rest-woocommerce' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	public function setup( WP_REST_Request $request ) {
		$gateway = $this->gateway();
		$card    = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) return $card;

		$payload = array(
			'clientReferenceInformation' => array( 'code' => $this->reference_code() ),
			'paymentInformation'         => array( 'card' => $this->card_without_cvv( $card ) ),
		);
		$response = $gateway->client()->request( 'POST', '/risk/v1/authentication-setups', $payload, $this->reference_code() );
		unset( $card, $payload );
		if ( is_wp_error( $response ) ) return $this->public_error( $response );

		$info = $response['consumerAuthenticationInformation'] ?? array();
		if ( empty( $info['accessToken'] ) || empty( $info['deviceDataCollectionUrl'] ) || empty( $info['referenceId'] ) ) {
			return new WP_Error( 'cybs_setup_invalid', __( 'CyberSource no devolvio los datos para iniciar 3-D Secure.', 'cybersource-rest-woocommerce' ), array( 'status' => 502 ) );
		}
		return rest_ensure_response(
			array(
				'accessToken'             => $info['accessToken'],
				'deviceDataCollectionUrl' => esc_url_raw( $info['deviceDataCollectionUrl'] ),
				'referenceId'             => sanitize_text_field( $info['referenceId'] ),
			)
		);
	}

	public function enroll( WP_REST_Request $request ) {
		$gateway = $this->gateway();
		$card    = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) return $card;

		$reference_id = sanitize_text_field( (string) $request->get_param( 'referenceId' ) );
		if ( ! $reference_id ) return new WP_Error( 'cybs_reference', __( 'Falta la referencia de autenticacion.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );

		$amount   = $this->request_amount( $request );
		if ( is_wp_error( $amount ) ) {
			unset( $card );
			return $amount;
		}
		$currency = get_woocommerce_currency();
		$bill_to  = $this->bill_to_from_request( $request );
		$missing  = $this->missing_bill_to_fields( $bill_to );
		if ( $missing ) {
			unset( $card );
			return new WP_Error(
				'cybs_bill_to_missing',
				sprintf(
					/* translators: %s: comma-separated CyberSource field names. */
					__( 'Completa la direccion de facturacion antes de pagar. Faltan: %s.', 'cybersource-rest-woocommerce' ),
					implode( ', ', $missing )
				),
				array( 'status' => 400 )
			);
		}
		$payload  = array(
			'clientReferenceInformation' => array( 'code' => $this->reference_code() ),
			'consumerAuthenticationInformation' => array(
				'referenceId'  => $reference_id,
				'returnUrl'    => admin_url( 'admin-post.php?action=cybs_3ds_return' ),
				'acsWindowSize'=> '03',
			),
			'paymentInformation' => array( 'card' => $this->card_without_cvv( $card ) ),
			'orderInformation'   => array(
				'amountDetails' => array( 'totalAmount' => $amount, 'currency' => $currency ),
				'billTo'        => $bill_to,
			),
			'deviceInformation' => $this->device_information( $request ),
		);
		$response = $gateway->client()->request( 'POST', '/risk/v1/authentications', $payload, $this->reference_code() );
		unset( $payload );
		if ( is_wp_error( $response ) ) return $this->public_error( $response );

		$status = strtoupper( (string) ( $response['status'] ?? '' ) );
		$info   = $response['consumerAuthenticationInformation'] ?? array();
		if ( 'AUTHENTICATION_SUCCESSFUL' === $status ) {
			$token = $this->issue_auth_token( $card['number'], $amount, $currency, $status, $info, $request );
			unset( $card );
			if ( is_wp_error( $token ) ) return $token;
			return rest_ensure_response( array( 'status' => $status, 'authToken' => $token ) );
		}
		unset( $card );
		if ( 'PENDING_AUTHENTICATION' === $status && ! empty( $info['stepUpUrl'] ) && ! empty( $info['accessToken'] ) ) {
			return rest_ensure_response(
				array(
					'status'                      => $status,
					'stepUpUrl'                   => esc_url_raw( $info['stepUpUrl'] ),
					'accessToken'                 => $info['accessToken'],
					'authenticationTransactionId'=> sanitize_text_field( (string) ( $info['authenticationTransactionId'] ?? '' ) ),
				)
			);
		}
		return new WP_Error( 'cybs_auth_failed', $this->issuer_message( $response ), array( 'status' => 402 ) );
	}

	public function validate( WP_REST_Request $request ) {
		$gateway = $this->gateway();
		$card    = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) return $card;
		$transaction_id = sanitize_text_field( (string) $request->get_param( 'authenticationTransactionId' ) );
		if ( ! $transaction_id ) return new WP_Error( 'cybs_transaction', __( 'Falta el ID de autenticacion.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );

		$amount   = $this->request_amount( $request );
		if ( is_wp_error( $amount ) ) {
			unset( $card );
			return $amount;
		}
		$currency = get_woocommerce_currency();
		$payload  = array(
			'clientReferenceInformation' => array( 'code' => $this->reference_code() ),
			'consumerAuthenticationInformation' => array( 'authenticationTransactionId' => $transaction_id ),
			'paymentInformation' => array( 'card' => $this->card_without_cvv( $card ) ),
			'orderInformation'   => array( 'amountDetails' => array( 'totalAmount' => $amount, 'currency' => $currency ) ),
		);
		$response = $gateway->client()->request( 'POST', '/risk/v1/authentication-results', $payload, $this->reference_code() );
		unset( $payload );
		if ( is_wp_error( $response ) ) return $this->public_error( $response );
		$status = strtoupper( (string) ( $response['status'] ?? '' ) );
		$info   = $response['consumerAuthenticationInformation'] ?? array();
		$token  = $this->issue_auth_token( $card['number'], $amount, $currency, $status, $info, $request );
		unset( $card );
		if ( is_wp_error( $token ) ) return $token;
		return rest_ensure_response( array( 'status' => $status, 'authToken' => $token ) );
	}

	public function challenge_return() {
		$transaction_id = isset( $_REQUEST['TransactionId'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['TransactionId'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $transaction_id && isset( $_REQUEST['transactionId'] ) ) $transaction_id = sanitize_text_field( wp_unslash( $_REQUEST['transactionId'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payload        = wp_json_encode( array( 'type' => 'cybs-3ds-complete', 'transactionId' => $transaction_id ) );
		$html           = '<!doctype html><html><head><meta charset="utf-8"><title>3-D Secure</title></head><body><p>Verificacion completada.</p><script>if(window.parent){window.parent.postMessage(' . $payload . ', window.location.origin);}</script></body></html>';
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML and JSON-encoded sanitized value.
		exit;
	}

	private function issue_auth_token( $number, $amount, $currency, $status, array $info, WP_REST_Request $request ) {
		$eci       = (string) ( $info['eci'] ?? $info['eciRaw'] ?? '' );
		$eci_check = ltrim( $eci, '0' );
		if ( '' === $eci_check ) $eci_check = '0';
		if ( in_array( $eci_check, array( '0', '7' ), true ) ) {
			return new WP_Error( 'cybs_eci_blocked', __( 'La autenticacion devolvio ECI 0 o 7. La transaccion no sera enviada a autorizar.', 'cybersource-rest-woocommerce' ), array( 'status' => 402 ) );
		}
		$pares = strtoupper( (string) ( $info['paresStatus'] ?? '' ) );
		if ( 'AUTHENTICATION_SUCCESSFUL' !== $status || ! in_array( $pares, array( 'Y', 'A', 'I' ), true ) ) {
			return new WP_Error( 'cybs_auth_failed', __( 'El emisor no autentico la tarjeta. Utiliza otra forma de pago.', 'cybersource-rest-woocommerce' ), array( 'status' => 402 ) );
		}
		$binding = sanitize_text_field( (string) $request->get_param( 'checkoutBinding' ) );
		if ( strlen( $binding ) < 32 ) {
			return new WP_Error( 'cybs_binding_missing', __( 'No se pudo vincular la autenticacion con este checkout. Recarga la pagina.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );
		}
		$device_fingerprint_id = sanitize_key( (string) $request->get_param( 'deviceFingerprintId' ) );
		$session_device_id     = $this->gateway()->device_fingerprint_id();
		if ( ! $device_fingerprint_id || ! hash_equals( $session_device_id, $device_fingerprint_id ) ) {
			return new WP_Error( 'cybs_device_fingerprint', __( 'No se pudo vincular el Device Fingerprint con este checkout. Recarga la pagina.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );
		}
		$allowed = array( 'eci', 'eciRaw', 'paresStatus', 'cavv', 'xid', 'directoryServerTransactionId', 'authenticationTransactionId', 'specificationVersion', 'ucafAuthenticationData', 'ucafCollectionIndicator' );
		$data    = array(
			'status'           => $status,
			'amount'           => $amount,
			'currency'         => $currency,
			'cardFingerprint'  => WC_Gateway_Cybs_REST::card_fingerprint( $number ),
			'checkoutBinding'  => hash_hmac( 'sha256', $binding, wp_salt( 'secure_auth' ) ),
			'deviceFingerprintId' => $device_fingerprint_id,
			'expires'          => time() + 10 * MINUTE_IN_SECONDS,
		);
		foreach ( $allowed as $field ) if ( isset( $info[ $field ] ) && is_scalar( $info[ $field ] ) ) $data[ $field ] = sanitize_text_field( (string) $info[ $field ] );
		$token = str_replace( '-', '', wp_generate_uuid4() );
		WC()->session->set( 'cybs_auth_' . $token, $data );
		if ( ! set_transient( 'cybs_auth_' . $token, $data, 10 * MINUTE_IN_SECONDS ) ) {
			WC()->session->set( 'cybs_auth_' . $token, null );
			return new WP_Error( 'cybs_auth_store', __( 'No se pudo conservar temporalmente la autenticacion. Intenta nuevamente.', 'cybersource-rest-woocommerce' ), array( 'status' => 500 ) );
		}
		return $token;
	}

	private function card_from_request( WP_REST_Request $request ) {
		$number = preg_replace( '/\D+/', '', (string) $request->get_param( 'number' ) );
		$month  = str_pad( preg_replace( '/\D+/', '', (string) $request->get_param( 'expirationMonth' ) ), 2, '0', STR_PAD_LEFT );
		$year   = preg_replace( '/\D+/', '', (string) $request->get_param( 'expirationYear' ) );
		if ( 2 === strlen( $year ) ) $year = '20' . $year;
		$type = WC_Gateway_Cybs_REST::card_type( $number );
		$expired = 4 === strlen( $year ) && ( (int) $year * 100 + (int) $month ) < ( (int) gmdate( 'Y' ) * 100 + (int) gmdate( 'm' ) );
		if ( ! $number || ! $type || ! WC_Gateway_Cybs_REST::luhn_valid( $number ) || (int) $month < 1 || (int) $month > 12 || 4 !== strlen( $year ) || $expired ) {
			return new WP_Error( 'cybs_card_invalid', __( 'Datos de tarjeta no validos.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );
		}
		return array( 'number' => $number, 'expirationMonth' => $month, 'expirationYear' => $year, 'type' => $type );
	}

	private function card_without_cvv( array $card ) { return array_intersect_key( $card, array_flip( array( 'number', 'expirationMonth', 'expirationYear', 'type' ) ) ); }

	private function bill_to_from_request( WP_REST_Request $request ) {
		$billing = is_array( $request->get_param( 'billing' ) ) ? $request->get_param( 'billing' ) : array();
		$map = array(
			'firstName'          => array( 'first_name', 'firstName' ),
			'lastName'           => array( 'last_name', 'lastName' ),
			'address1'           => array( 'address_1', 'address1' ),
			'address2'           => array( 'address_2', 'address2' ),
			'locality'           => array( 'city', 'locality' ),
			'administrativeArea' => array( 'state', 'administrativeArea' ),
			'postalCode'         => array( 'postcode', 'postalCode' ),
			'country'            => array( 'country' ),
			'email'              => array( 'email' ),
			'phoneNumber'        => array( 'phone', 'phoneNumber' ),
		);
		$result = array();
		foreach ( $map as $target => $sources ) {
			foreach ( $sources as $source ) {
				if ( ! empty( $billing[ $source ] ) ) {
					$result[ $target ] = sanitize_text_field( (string) $billing[ $source ] );
					break;
				}
			}
		}
		return $result;
	}

	private function missing_bill_to_fields( array $bill_to ) {
		$required = array( 'firstName', 'lastName', 'address1', 'locality', 'country', 'email' );
		return array_values(
			array_filter(
				$required,
				static function ( $field ) use ( $bill_to ) {
					return empty( $bill_to[ $field ] );
				}
			)
		);
	}

	private function device_information( WP_REST_Request $request ) {
		$device = is_array( $request->get_param( 'device' ) ) ? $request->get_param( 'device' ) : array();
		$allowed = array( 'httpAcceptBrowserValue', 'httpAcceptContent', 'httpBrowserColorDepth', 'httpBrowserJavaEnabled', 'httpBrowserJavaScriptEnabled', 'httpBrowserLanguage', 'httpBrowserScreenHeight', 'httpBrowserScreenWidth', 'httpBrowserTimeDifference', 'userAgentBrowserValue' );
		$result = array();
		foreach ( $allowed as $field ) if ( isset( $device[ $field ] ) && '' !== (string) $device[ $field ] ) $result[ $field ] = sanitize_text_field( (string) $device[ $field ] );
		$result['ipAddress'] = WC_Geolocation::get_ip_address();
		return $result;
	}

	private function cart_amount() { return wc_format_decimal( WC()->cart->get_total( 'edit' ), wc_get_price_decimals() ); }
	private function request_amount( WP_REST_Request $request ) {
		$raw_amount = wc_format_decimal( (string) $request->get_param( 'amount' ), wc_get_price_decimals() );
		$amount     = (float) $raw_amount;
		if ( $amount <= 0 || $amount > 999999999.99 ) {
			return new WP_Error( 'cybs_amount_missing', __( 'No fue posible determinar el total actual de la compra. Actualiza el checkout e intenta nuevamente.', 'cybersource-rest-woocommerce' ), array( 'status' => 400 ) );
		}
		$cart_amount = WC()->cart ? (float) $this->cart_amount() : 0.0;
		if ( $cart_amount > 0 && abs( $cart_amount - $amount ) >= 0.01 ) {
			return new WP_Error( 'cybs_amount_changed', __( 'El total de la compra cambio. Revisa el pedido e intenta nuevamente.', 'cybersource-rest-woocommerce' ), array( 'status' => 409 ) );
		}
		return wc_format_decimal( $amount, wc_get_price_decimals() );
	}
	private function reference_code() { return 'wc-' . substr( hash( 'sha256', WC()->session->get_customer_id() . '|' . microtime( true ) ), 0, 24 ); }
	private function gateway() { return new WC_Gateway_Cybs_REST(); }
	private function public_error( WP_Error $error ) { return new WP_Error( 'cybs_gateway_error', __( 'CyberSource no pudo completar la operacion. Revisa los datos o intenta otra tarjeta.', 'cybersource-rest-woocommerce' ), array( 'status' => 502, 'internal_code' => $error->get_error_code() ) ); }
	private function issuer_message( array $response ) { return sanitize_text_field( (string) ( $response['consumerAuthenticationInformation']['cardholderMessage'] ?? __( 'El banco no autentico la tarjeta. Utiliza otra forma de pago.', 'cybersource-rest-woocommerce' ) ) ); }
}
