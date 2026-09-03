<?php

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Cybs_REST extends WC_Payment_Gateway {
	public $environment;
	public $enable_3ds;
	public $capture;
	public $logging;
	private $logger;
	private static $request_device_fingerprint_id = '';

	public function __construct() {
		$this->id                 = 'cybersource_rest';
		$this->method_title       = __( 'CyberSource REST', 'cybersource-rest-woocommerce' );
		$this->method_description = __( 'Pagos con CyberSource REST, 3-D Secure y device fingerprint. Los datos completos de tarjeta nunca se guardan ni se escriben en logs.', 'cybersource-rest-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = '';

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title', __( 'Tarjeta de credito o debito', 'cybersource-rest-woocommerce' ) );
		$this->description  = $this->get_option( 'description', __( 'Paga de forma segura con tu tarjeta.', 'cybersource-rest-woocommerce' ) );
		$this->enabled      = $this->get_option( 'enabled', 'no' );
		$this->environment  = $this->get_option( 'environment', 'test' );
		$this->enable_3ds   = $this->get_option( 'enable_3ds', 'yes' );
		$this->capture      = $this->get_option( 'capture', 'yes' );
		$this->logging      = $this->get_option( 'logging', 'yes' );
		$this->logger       = new WC_Cybs_REST_Logger( $this->logging );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'render_device_fingerprint_noscript' ) );
		add_filter( 'woocommerce_gateway_icon', array( $this, 'gateway_icon' ), 10, 2 );
	}

	public function init_form_fields() {
		$log_url = admin_url( 'admin.php?page=wc-status&tab=logs&source=cybersource-rest' );
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Activar', 'cybersource-rest-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activar CyberSource REST', 'cybersource-rest-woocommerce' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Titulo', 'cybersource-rest-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Tarjeta de credito o debito', 'cybersource-rest-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => __( 'Descripcion', 'cybersource-rest-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Paga de forma segura con tu tarjeta.', 'cybersource-rest-woocommerce' ),
			),
			'environment' => array(
				'title'   => __( 'Ambiente activo', 'cybersource-rest-woocommerce' ),
				'type'    => 'select',
				'default' => 'test',
				'options' => array(
					'test'       => __( 'Desarrollo / pruebas', 'cybersource-rest-woocommerce' ),
					'production' => __( 'Produccion', 'cybersource-rest-woocommerce' ),
				),
			),
			'test_credentials' => array(
				'title'       => __( 'Credenciales de desarrollo', 'cybersource-rest-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Use las llaves HTTP Signature generadas en CyberSource Business Center para el ambiente de pruebas.', 'cybersource-rest-woocommerce' ),
			),
			'test_merchant_id' => array( 'title' => __( 'Merchant ID de pruebas', 'cybersource-rest-woocommerce' ), 'type' => 'text' ),
			'test_key_id'      => array( 'title' => __( 'Key ID de pruebas', 'cybersource-rest-woocommerce' ), 'type' => 'text' ),
			'test_secret_key'  => array( 'title' => __( 'Shared Secret de pruebas', 'cybersource-rest-woocommerce' ), 'type' => 'password' ),
			'test_org_id'      => array( 'title' => __( 'Org ID device fingerprint', 'cybersource-rest-woocommerce' ), 'type' => 'text', 'default' => '1snn5n9w' ),
			'production_credentials' => array(
				'title'       => __( 'Credenciales de produccion', 'cybersource-rest-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Las llaves de produccion deben ser distintas de las llaves de pruebas.', 'cybersource-rest-woocommerce' ),
			),
			'production_merchant_id' => array( 'title' => __( 'Merchant ID de produccion', 'cybersource-rest-woocommerce' ), 'type' => 'text' ),
			'production_key_id'      => array( 'title' => __( 'Key ID de produccion', 'cybersource-rest-woocommerce' ), 'type' => 'text' ),
			'production_secret_key'  => array( 'title' => __( 'Shared Secret de produccion', 'cybersource-rest-woocommerce' ), 'type' => 'password' ),
			'production_org_id'      => array( 'title' => __( 'Org ID device fingerprint', 'cybersource-rest-woocommerce' ), 'type' => 'text', 'default' => 'k8vif92e' ),
			'processing' => array( 'title' => __( 'Procesamiento y seguridad', 'cybersource-rest-woocommerce' ), 'type' => 'title' ),
			'enable_3ds' => array(
				'title'   => __( 'Payer Authentication', 'cybersource-rest-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Exigir 3-D Secure antes de autorizar', 'cybersource-rest-woocommerce' ),
				'default' => 'yes',
			),
			'capture' => array(
				'title'   => __( 'Captura', 'cybersource-rest-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Autorizar y capturar en una sola solicitud', 'cybersource-rest-woocommerce' ),
				'default' => 'yes',
			),
			'logging' => array(
				'title'       => __( 'Registro tecnico', 'cybersource-rest-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Registrar solicitudes y respuestas sanitizadas', 'cybersource-rest-woocommerce' ),
				'default'     => 'yes',
				'description' => sprintf( wp_kses_post( __( 'Nunca se registran PAN, CVV, JWT ni secretos. <a href="%s">Ver logs de WooCommerce</a>.', 'cybersource-rest-woocommerce' ) ), esc_url( $log_url ) ),
			),
		);
	}

	public function admin_options() {
		parent::admin_options();
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Seguridad:', 'cybersource-rest-woocommerce' ) . '</strong> ' . esc_html__( 'Esta integracion directa requiere HTTPS y un entorno con cumplimiento PCI DSS. HTTP Signature continua disponible, pero CyberSource anuncia su retiro para marzo de 2027; planifique la migracion a JWT con MLE.', 'cybersource-rest-woocommerce' ) . '</p></div>';
	}

	public function is_available() {
		if ( ! parent::is_available() || ! $this->credentials_complete() ) {
			return false;
		}
		if ( 'production' === $this->environment && ! is_ssl() ) {
			return false;
		}
		return true;
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
			<p class="form-row form-row-wide"><label for="cybs-card-number"><?php esc_html_e( 'Numero de tarjeta', 'cybersource-rest-woocommerce' ); ?> <span class="required">*</span></label><input id="cybs-card-number" name="cybs_card_number" class="input-text" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="•••• •••• •••• ••••" /></p>
			<p class="form-row form-row-first"><label for="cybs-card-expiry"><?php esc_html_e( 'Vencimiento', 'cybersource-rest-woocommerce' ); ?> <span class="required">*</span></label><input id="cybs-card-expiry" name="cybs_card_expiry" class="input-text" type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="7" pattern="[0-9 /]*" placeholder="MM / AA" aria-describedby="cybs-card-expiry-help" /><small id="cybs-card-expiry-help"><?php esc_html_e( 'Ejemplo: 01 / 29', 'cybersource-rest-woocommerce' ); ?></small></p>
			<p class="form-row form-row-last"><label for="cybs-card-cvv"><?php esc_html_e( 'CVV', 'cybersource-rest-woocommerce' ); ?> <span class="required">*</span></label><input id="cybs-card-cvv" name="cybs_card_cvv" class="input-text cybs-card-cvv" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="&bull;&bull;&bull;&bull;" aria-describedby="cybs-card-cvv-help" /><small id="cybs-card-cvv-help"><?php esc_html_e( 'Se oculta mientras lo escribes.', 'cybersource-rest-woocommerce' ); ?></small></p>
			<input type="hidden" name="cybs_auth_token" id="cybs-auth-token" value="" />
			<input type="hidden" name="cybs_checkout_binding" id="cybs-checkout-binding" value="<?php echo esc_attr( $this->checkout_binding() ); ?>" />
			<input type="hidden" name="cybs_device_fingerprint_id" id="cybs-device-fingerprint-id" value="<?php echo esc_attr( $this->device_fingerprint_id() ); ?>" />
			<div class="clear"></div><div id="cybs-rest-status" role="status" aria-live="polite"></div>
		</fieldset>
		<?php
	}

	public function validate_fields() {
		$card = $this->posted_card();
		if ( is_wp_error( $card ) ) {
			wc_add_notice( $card->get_error_message(), 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$card  = $this->posted_card();
		if ( ! $order || is_wp_error( $card ) ) {
			wc_add_notice( is_wp_error( $card ) ? $card->get_error_message() : __( 'No se encontro la orden.', 'cybersource-rest-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! is_ssl() && 'production' === $this->environment ) {
			wc_add_notice( __( 'El pago con tarjeta requiere HTTPS.', 'cybersource-rest-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$existing_transaction_id = $order->get_transaction_id() ?: $order->get_meta( '_cybs_request_id' );
		$existing_status         = strtoupper( (string) $order->get_meta( '_cybs_status' ) );
		$locked_statuses         = array( 'ACCEPTED', 'AUTHORIZED', 'AUTHORIZED_PENDING_REVIEW', 'CAPTURED', 'PENDING', 'TRANSMITTED' );
		if ( $existing_transaction_id && in_array( $existing_status, $locked_statuses, true ) ) {
			$order->add_order_note( __( 'CyberSource: se evito un reintento porque la orden ya tiene un identificador de transaccion.', 'cybersource-rest-woocommerce' ) );
			wc_add_notice( __( 'Este pedido ya fue enviado a CyberSource y esta pendiente de confirmacion. No vuelvas a intentar el cobro.', 'cybersource-rest-woocommerce' ), 'notice' );
			WC()->cart->empty_cart();
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		$auth = $this->consume_authentication( $order, $card['number'] );
		if ( is_wp_error( $auth ) ) {
			wc_add_notice( $auth->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$correlation_id = 'order-' . $order->get_id() . '-' . wp_generate_uuid4();
		$payload        = $this->build_payment_payload( $order, $card, $auth );
		if ( is_wp_error( $payload ) ) {
			unset( $card );
			$order->add_order_note( sprintf( __( 'CyberSource no recibio el pago: %s', 'cybersource-rest-woocommerce' ), $payload->get_error_message() ) );
			wc_add_notice( $payload->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}
		$response       = $this->client()->request( 'POST', '/pts/v2/payments', $payload, $correlation_id );
		unset( $card, $payload );

		if ( is_wp_error( $response ) ) {
			$error_data   = $response->get_error_data();
			$api_response = is_array( $error_data ) && is_array( $error_data['response'] ?? null ) ? $error_data['response'] : array();
			if ( $api_response ) {
				$this->record_transaction_details( $order, $api_response, $auth );
			}
			$order->add_order_note( sprintf( __( 'CyberSource rechazo el pago: %s', 'cybersource-rest-woocommerce' ), $response->get_error_message() ) );
			wc_add_notice( __( 'No fue posible autorizar el pago. Verifica los datos o utiliza otra tarjeta.', 'cybersource-rest-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$status          = strtoupper( (string) ( $response['status'] ?? '' ) );
		$transaction_id = sanitize_text_field( (string) ( $response['id'] ?? '' ) );
		$this->record_transaction_details( $order, $response, $auth );
		if ( '' === $transaction_id ) {
			$order->add_order_note( sprintf( __( 'Respuesta CyberSource no aprobada. Estado: %s', 'cybersource-rest-woocommerce' ), $status ?: 'UNKNOWN' ) );
			wc_add_notice( __( 'La tarjeta no fue aprobada. Utiliza otra forma de pago.', 'cybersource-rest-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$paid_statuses    = array( 'ACCEPTED', 'AUTHORIZED', 'CAPTURED', 'TRANSMITTED' );
		$pending_statuses = array( 'AUTHORIZED_PENDING_REVIEW', 'PENDING' );
		$is_paid_status   = in_array( $status, $paid_statuses, true );
		$is_pending       = in_array( $status, $pending_statuses, true );
		$is_accepted      = 'ACCEPTED' === $status && $this->processor_approved( $response );
		if ( ! $is_paid_status && ! $is_pending && $this->processor_approved( $response ) ) {
			$is_pending = true;
		}

		if ( $is_paid_status && 'ACCEPTED' === $status && ! $is_accepted ) {
			$is_paid_status = false;
			$is_pending     = true;
		}

		if ( $is_paid_status || $is_pending ) {
			$amount_check = $this->validate_response_amount( $order, $response );
			if ( is_wp_error( $amount_check ) ) {
				$promotion = $this->apply_cybersource_promotion( $order, $response );
				if ( true === $promotion ) {
					$amount_check = $this->validate_response_amount( $order, $response );
				}
			}
			if ( is_wp_error( $amount_check ) ) {
				$void = $this->void_mismatched_payment( $order, $transaction_id );
				if ( is_wp_error( $void ) ) {
					$order->update_status( 'on-hold', sprintf( __( 'CyberSource requiere revision manual: %1$s No se pudo confirmar automaticamente la anulacion.', 'cybersource-rest-woocommerce' ), $amount_check->get_error_message() ) );
					wc_add_notice( __( 'CyberSource recibio el pago, pero no fue posible confirmar su anulacion. No vuelvas a intentarlo; revisaremos el pedido.', 'cybersource-rest-woocommerce' ), 'notice' );
					WC()->cart->empty_cart();
					return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
				}
				$order->update_status( 'failed', sprintf( __( 'CyberSource anulo el pago por diferencia de importe: %s', 'cybersource-rest-woocommerce' ), $amount_check->get_error_message() ) );
				wc_add_notice( __( 'El pago fue anulado porque el importe no coincidio con el pedido. No se realizo el cobro; puedes intentarlo nuevamente.', 'cybersource-rest-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		if ( $is_pending ) {
			$order->update_status( 'on-hold', sprintf( __( 'CyberSource dejo la transaccion pendiente de revision. Estado: %s.', 'cybersource-rest-woocommerce' ), $status ) );
			wc_add_notice( __( 'El pago fue recibido y esta pendiente de confirmacion. No vuelvas a intentarlo.', 'cybersource-rest-woocommerce' ), 'notice' );
			WC()->cart->empty_cart();
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		if ( ! $is_paid_status ) {
			$order->add_order_note( sprintf( __( 'Respuesta CyberSource no aprobada. Estado: %s', 'cybersource-rest-woocommerce' ), $status ?: 'UNKNOWN' ) );
			wc_add_notice( __( 'La tarjeta no fue aprobada. Utiliza otra forma de pago.', 'cybersource-rest-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->payment_complete( $transaction_id );
		$order->add_order_note( sprintf( __( 'Pago CyberSource aprobado. ID: %1$s. Estado: %2$s.', 'cybersource-rest-woocommerce' ), $transaction_id, $status ) );
		WC()->cart->empty_cart();

		return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_transaction_id() ) {
			return new WP_Error( 'cybs_refund_missing_transaction', __( 'La orden no tiene ID de transaccion CyberSource.', 'cybersource-rest-woocommerce' ) );
		}
		$payload = array(
			'clientReferenceInformation' => array( 'code' => 'refund-' . $order_id . '-' . time() ),
			'orderInformation'           => array(
				'amountDetails' => array(
					'totalAmount' => wc_format_decimal( $amount, wc_get_price_decimals() ),
					'currency'    => $order->get_currency(),
				),
			),
		);
		$order_environment = $order->get_meta( '_cybs_environment' ) ?: $this->environment;
		$response = $this->client_for_environment( $order_environment )->request( 'POST', '/pts/v2/payments/' . rawurlencode( $order->get_transaction_id() ) . '/refunds', $payload, 'refund-order-' . $order_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$refund_id = sanitize_text_field( (string) ( $response['id'] ?? '' ) );
		$order->add_order_note( sprintf( __( 'Reembolso CyberSource solicitado. ID: %1$s. Motivo: %2$s', 'cybersource-rest-woocommerce' ), $refund_id ?: 'N/D', sanitize_text_field( $reason ) ) );
		return true;
	}

	private function void_mismatched_payment( WC_Order $order, $transaction_id ) {
		if ( ! $transaction_id ) {
			return new WP_Error( 'cybs_void_missing_transaction', __( 'Falta el ID de la transaccion que debe anularse.', 'cybersource-rest-woocommerce' ) );
		}
		$response = $this->client()->request(
			'POST',
			'/pts/v2/payments/' . rawurlencode( $transaction_id ) . '/voids',
			array( 'clientReferenceInformation' => array( 'code' => 'void-mismatch-' . $order->get_id() ) ),
			'void-mismatch-order-' . $order->get_id()
		);
		if ( is_wp_error( $response ) ) {
			$order->add_order_note( sprintf( __( 'CyberSource: fallo la anulacion automatica del importe incorrecto: %s', 'cybersource-rest-woocommerce' ), $response->get_error_message() ) );
			return $response;
		}
		$void_id     = sanitize_text_field( (string) ( $response['id'] ?? '' ) );
		$void_status = sanitize_text_field( (string) ( $response['status'] ?? '' ) );
		$original_status = sanitize_text_field( (string) $order->get_meta( '_cybs_status' ) );
		if ( $original_status ) {
			$order->update_meta_data( '_cybs_original_status', $original_status );
		}
		$order->update_meta_data( '_cybs_void_id', $void_id );
		$order->update_meta_data( '_cybs_void_status', $void_status );
		if ( in_array( strtoupper( $void_status ), array( 'VOIDED', 'REVERSED' ), true ) ) {
			$order->update_meta_data( '_cybs_status', strtoupper( $void_status ) );
		}
		$order->save();
		$order->add_order_note( sprintf( __( 'CyberSource: anulacion automatica solicitada por diferencia de importe. Void ID: %1$s. Estado: %2$s.', 'cybersource-rest-woocommerce' ), $void_id ?: 'N/D', $void_status ?: 'N/D' ) );
		return $response;
	}

	public function client() {
		return $this->client_for_environment( $this->environment );
	}

	public function client_for_environment( $environment ) {
		$environment = 'production' === $environment ? 'production' : 'test';
		$prefix = 'production' === $environment ? 'production_' : 'test_';
		return new WC_Cybs_REST_Client(
			$environment,
			$this->get_option( $prefix . 'merchant_id' ),
			$this->get_option( $prefix . 'key_id' ),
			$this->get_option( $prefix . 'secret_key' ),
			$this->logger
		);
	}

	public function merchant_id() {
		return (string) $this->get_option( ( 'production' === $this->environment ? 'production_' : 'test_' ) . 'merchant_id' );
	}

	public function org_id() {
		return (string) $this->get_option( ( 'production' === $this->environment ? 'production_' : 'test_' ) . 'org_id' );
	}

	public function enqueue_checkout_assets() {
		if ( ! is_checkout() || is_order_received_page() || 'yes' !== $this->enabled ) {
			return;
		}
		wp_enqueue_style( 'wc-cybs-rest', WC_CYBS_REST_URL . 'assets/css/checkout.css', array(), WC_CYBS_REST_VERSION );
		wp_enqueue_script( 'wc-cybs-rest', WC_CYBS_REST_URL . 'assets/js/checkout.js', array( 'jquery' ), WC_CYBS_REST_VERSION, true );
		wp_localize_script( 'wc-cybs-rest', 'wcCybsRest', $this->frontend_data() );

		$session_id = rawurlencode( $this->merchant_id() . $this->device_fingerprint_id() );
		$src        = 'https://h.online-metrix.net/fp/tags.js?org_id=' . rawurlencode( $this->org_id() ) . '&session_id=' . $session_id;
		wp_enqueue_script( 'wc-cybs-device-fingerprint', $src, array(), null, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	public function frontend_data() {
		return array(
			'gatewayId'       => $this->id,
			'apiBase'         => esc_url_raw( rest_url( 'cybersource-rest/v1' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'enable3ds'       => 'yes' === $this->enable_3ds,
			'environment'     => $this->environment,
			'deviceId'        => $this->device_fingerprint_id(),
			'checkoutBinding' => $this->checkout_binding(),
			'checkoutAmount'  => WC()->cart ? wc_format_decimal( WC()->cart->get_total( 'edit' ), wc_get_price_decimals() ) : '',
			'currency'        => get_woocommerce_currency(),
			'decimalSeparator'=> wc_get_price_decimal_separator(),
			'thousandSeparator'=> wc_get_price_thousand_separator(),
			'priceDecimals'   => wc_get_price_decimals(),
			'ddcOrigin'       => 'production' === $this->environment ? 'https://centinelapi.cardinalcommerce.com' : 'https://centinelapistag.cardinalcommerce.com',
			'messages'        => array(
				'authenticating' => __( 'Verificando tu tarjeta de forma segura...', 'cybersource-rest-woocommerce' ),
				'challenge'      => __( 'Completa la verificacion de tu banco.', 'cybersource-rest-woocommerce' ),
				'failed'         => __( 'No fue posible autenticar la tarjeta. Utiliza otra tarjeta.', 'cybersource-rest-woocommerce' ),
			),
		);
	}

	public function render_device_fingerprint_noscript() {
		if ( 'yes' !== $this->enabled || ! $this->merchant_id() || ! $this->org_id() ) {
			return;
		}
		$url = add_query_arg(
			array( 'org_id' => $this->org_id(), 'session_id' => $this->merchant_id() . $this->device_fingerprint_id() ),
			'https://h.online-metrix.net/fp/tags'
		);
		echo '<noscript><iframe title="Device fingerprint" style="width:1px;height:1px;border:0;position:absolute;left:-9999px" src="' . esc_url( $url ) . '"></iframe></noscript>';
	}

	public function gateway_icon( $icon, $gateway_id ) {
		if ( $gateway_id !== $this->id ) {
			return $icon;
		}
		return '<span class="cybs-secure-label" aria-label="' . esc_attr__( 'Pago seguro 3-D Secure', 'cybersource-rest-woocommerce' ) . '">3-D Secure</span>';
	}

	public function device_fingerprint_id() {
		if ( self::$request_device_fingerprint_id ) {
			return self::$request_device_fingerprint_id;
		}

		if ( WC()->session ) {
			$id                  = WC()->session->get( 'cybs_device_fingerprint_id' );
			$is_checkout_reload  = ! wp_doing_ajax()
				&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
				&& is_checkout()
				&& ! is_order_received_page()
				&& 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
			if ( ! $id || $is_checkout_reload ) {
				$id = str_replace( '-', '', wp_generate_uuid4() );
				WC()->session->set( 'cybs_device_fingerprint_id', $id );
			}
			self::$request_device_fingerprint_id = sanitize_key( $id );
			return self::$request_device_fingerprint_id;
		}
		self::$request_device_fingerprint_id = sanitize_key( str_replace( '-', '', wp_generate_uuid4() ) );
		return self::$request_device_fingerprint_id;
	}

	public function checkout_binding() {
		if ( WC()->session ) {
			$binding = WC()->session->get( 'cybs_checkout_binding' );
			if ( ! $binding ) {
				$binding = wp_generate_password( 48, false, false );
				WC()->session->set( 'cybs_checkout_binding', $binding );
			}
			return sanitize_text_field( (string) $binding );
		}
		return wp_generate_password( 48, false, false );
	}

	public static function card_type( $number ) {
		$number = preg_replace( '/\D+/', '', (string) $number );
		if ( preg_match( '/^4/', $number ) ) return '001';
		if ( preg_match( '/^(5[1-5]|2[2-7])/', $number ) ) return '002';
		if ( preg_match( '/^3[47]/', $number ) ) return '003';
		if ( preg_match( '/^35/', $number ) ) return '007';
		if ( preg_match( '/^(6011|65|64[4-9])/', $number ) ) return '004';
		return '';
	}

	public static function card_fingerprint( $number ) {
		return hash_hmac( 'sha256', preg_replace( '/\D+/', '', (string) $number ), wp_salt( 'auth' ) );
	}

	private function credentials_complete() {
		$prefix = 'production' === $this->environment ? 'production_' : 'test_';
		return $this->get_option( $prefix . 'merchant_id' ) && $this->get_option( $prefix . 'key_id' ) && $this->get_option( $prefix . 'secret_key' );
	}

	private function posted_card() {
		$number = isset( $_POST['cybs_card_number'] ) ? preg_replace( '/\D+/', '', wc_clean( wp_unslash( $_POST['cybs_card_number'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$expiry = isset( $_POST['cybs_card_expiry'] ) ? preg_replace( '/\D+/', '', wc_clean( wp_unslash( $_POST['cybs_card_expiry'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cvv_in = isset( $_POST['cybs_card_cvv'] ) ? $_POST['cybs_card_cvv'] : ( isset( $_POST['cybs_card_cvc'] ) ? $_POST['cybs_card_cvc'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cvv    = preg_replace( '/\D+/', '', wc_clean( wp_unslash( $cvv_in ) ) );
		if ( ! in_array( strlen( $expiry ), array( 4, 6 ), true ) ) {
			return new WP_Error( 'cybs_expiry', __( 'Fecha de vencimiento no valida.', 'cybersource-rest-woocommerce' ) );
		}
		$month = substr( $expiry, 0, 2 );
		$year  = 4 === strlen( $expiry ) ? '20' . substr( $expiry, 2, 2 ) : substr( $expiry, 2, 4 );
		if ( ! self::luhn_valid( $number ) || ! self::card_type( $number ) ) {
			return new WP_Error( 'cybs_card', __( 'Numero de tarjeta no valido o marca no admitida.', 'cybersource-rest-woocommerce' ) );
		}
		if ( (int) $month < 1 || (int) $month > 12 || strlen( $year ) !== 4 || ( (int) $year * 100 + (int) $month ) < ( (int) gmdate( 'Y' ) * 100 + (int) gmdate( 'm' ) ) ) {
			return new WP_Error( 'cybs_expiry', __( 'La tarjeta esta vencida o la fecha no es valida.', 'cybersource-rest-woocommerce' ) );
		}
		if ( strlen( $cvv ) < 3 || strlen( $cvv ) > 4 ) {
			return new WP_Error( 'cybs_cvv', __( 'CVV no valido.', 'cybersource-rest-woocommerce' ) );
		}
		return array( 'number' => $number, 'expirationMonth' => $month, 'expirationYear' => $year, 'securityCode' => $cvv, 'type' => self::card_type( $number ) );
	}

	public static function luhn_valid( $number ) {
		$sum = 0; $alt = false;
		for ( $i = strlen( $number ) - 1; $i >= 0; $i-- ) {
			$n = (int) $number[ $i ];
			if ( $alt ) { $n *= 2; if ( $n > 9 ) $n -= 9; }
			$sum += $n; $alt = ! $alt;
		}
		return strlen( $number ) >= 12 && 0 === $sum % 10;
	}

	private function consume_authentication( WC_Order $order, $card_number ) {
		$posted_device_id = isset( $_POST['cybs_device_fingerprint_id'] ) ? sanitize_key( wp_unslash( $_POST['cybs_device_fingerprint_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'yes' !== $this->enable_3ds ) {
			return array( 'status' => 'DISABLED', 'eci' => '', 'deviceFingerprintId' => $posted_device_id ?: $this->device_fingerprint_id() );
		}
		$token = isset( $_POST['cybs_auth_token'] ) ? sanitize_key( wp_unslash( $_POST['cybs_auth_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$binding = isset( $_POST['cybs_checkout_binding'] ) ? sanitize_text_field( wp_unslash( $_POST['cybs_checkout_binding'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_data = $token && WC()->session ? WC()->session->get( 'cybs_auth_' . $token ) : null;
		$transient_data = $token ? get_transient( 'cybs_auth_' . $token ) : null;
		$data = is_array( $session_data ) ? $session_data : $transient_data;
		$this->logger->log(
			'info',
			'3-D Secure token lookup',
			array(
				'order_id'        => $order->get_id(),
				'token_present'   => '' !== $token,
				'binding_present' => '' !== $binding,
				'session_hit'     => is_array( $session_data ),
				'transient_hit'   => is_array( $transient_data ),
			)
		);
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cybs_auth_missing', __( 'La autenticacion 3-D Secure expiro. Intenta nuevamente.', 'cybersource-rest-woocommerce' ) );
		}
		if ( WC()->session ) WC()->session->set( 'cybs_auth_' . $token, null );
		delete_transient( 'cybs_auth_' . $token );
		$binding_hash = hash_hmac( 'sha256', $binding, wp_salt( 'secure_auth' ) );
		$valid = ( $data['expires'] ?? 0 ) >= time()
			&& '' !== $binding
			&& hash_equals( (string) ( $data['checkoutBinding'] ?? '' ), $binding_hash )
			&& '' !== $posted_device_id
			&& hash_equals( (string) ( $data['deviceFingerprintId'] ?? '' ), $posted_device_id )
			&& hash_equals( (string) ( $data['cardFingerprint'] ?? '' ), self::card_fingerprint( $card_number ) )
			&& (string) ( $data['currency'] ?? '' ) === (string) $order->get_currency()
			&& abs( (float) ( $data['amount'] ?? 0 ) - (float) $order->get_total() ) < 0.01;
		if ( ! $valid ) {
			return new WP_Error( 'cybs_auth_invalid', __( 'La autenticacion no corresponde a esta compra.', 'cybersource-rest-woocommerce' ) );
		}
		$eci = ltrim( (string) ( $data['eci'] ?? $data['eciRaw'] ?? '' ), '0' );
		if ( '' === $eci ) $eci = '0';
		if ( in_array( $eci, array( '0', '7' ), true ) ) {
			return new WP_Error( 'cybs_eci_blocked', __( 'La autenticacion devolvio ECI 0 o 7; por seguridad no se envio la tarjeta a autorizar.', 'cybersource-rest-woocommerce' ) );
		}
		if ( 'AUTHENTICATION_SUCCESSFUL' !== strtoupper( (string) ( $data['status'] ?? '' ) ) || ! in_array( strtoupper( (string) ( $data['paresStatus'] ?? '' ) ), array( 'Y', 'A', 'I' ), true ) ) {
			return new WP_Error( 'cybs_auth_failed', __( 'El banco no autentico la tarjeta. Utiliza otra forma de pago.', 'cybersource-rest-woocommerce' ) );
		}
		return $data;
	}

	private function build_payment_payload( WC_Order $order, array $card, array $auth ) {
		$bill_to = $this->bill_to( $order );
		$missing = $this->missing_bill_to_fields( $bill_to );
		if ( $missing ) {
			return new WP_Error(
				'cybs_bill_to_missing',
				sprintf(
					/* translators: %s: comma-separated CyberSource field names. */
					__( 'Completa la direccion de facturacion. Faltan: %s.', 'cybersource-rest-woocommerce' ),
					implode( ', ', $missing )
				)
			);
		}
		$line_items = $this->build_line_items( $order );
		if ( is_wp_error( $line_items ) ) {
			return $line_items;
		}
		$device_fingerprint_id = sanitize_key( (string) ( $auth['deviceFingerprintId'] ?? '' ) );
		if ( ! $device_fingerprint_id ) {
			return new WP_Error( 'cybs_device_fingerprint_missing', __( 'No se encontro el Device Fingerprint vinculado a esta autenticacion.', 'cybersource-rest-woocommerce' ) );
		}
		$payload = array(
			'clientReferenceInformation' => array( 'code' => (string) $order->get_order_number() ),
			'processingInformation'      => array(
				'capture'           => 'yes' === $this->capture,
				'commerceIndicator' => $this->commerce_indicator( $card['type'], $auth ),
				'authorizationOptions' => array( 'partialAuthIndicator' => false ),
			),
			'paymentInformation' => array( 'card' => $card ),
			'orderInformation'   => array(
				'amountDetails' => array(
					'totalAmount' => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
					'currency'    => $order->get_currency(),
				),
				'billTo'        => $bill_to,
				'lineItems'     => $line_items,
			),
			'deviceInformation' => array(
				'fingerprintSessionId'       => $device_fingerprint_id,
				'useRawFingerprintSessionId' => false,
				'ipAddress'                  => WC_Geolocation::get_ip_address(),
				'userAgent'                  => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			),
			// Compatibility field used by legacy Decision Manager/Visanet integrations.
			'riskInformation' => array(
				'deviceFingerprintId' => $device_fingerprint_id,
			),
		);
		if ( 'yes' === $this->enable_3ds ) {
			$authentication_information = $this->consumer_authentication_information( $card['type'], $auth );
			if ( $authentication_information ) {
				$payload['consumerAuthenticationInformation'] = $authentication_information;
			}
		}
		return $payload;
	}

	private function consumer_authentication_information( $card_type, array $auth ) {
		$info = array();
		$mapping = array(
			'xid'                              => 'xid',
			'eciRaw'                           => 'eciRaw',
			'directoryServerTransactionId'     => 'directoryServerTransactionId',
			'authenticationTransactionId'      => 'authenticationTransactionId',
			'specificationVersion'             => 'paSpecificationVersion',
		);
		foreach ( $mapping as $source => $target ) {
			if ( ! empty( $auth[ $source ] ) ) {
				$info[ $target ] = $auth[ $source ];
			}
		}

		if ( '002' === $card_type ) {
			$aav = $auth['ucafAuthenticationData'] ?? $auth['cavv'] ?? '';
			if ( '' !== (string) $aav ) {
				$info['ucafAuthenticationData'] = $aav;
			}
			if ( ! empty( $auth['ucafCollectionIndicator'] ) ) {
				$info['ucafCollectionIndicator'] = $auth['ucafCollectionIndicator'];
			} else {
				$indicator = $this->mastercard_ucaf_collection_indicator( $auth );
				if ( '' !== $indicator ) {
					$info['ucafCollectionIndicator'] = $indicator;
				}
			}
			return $info;
		}

		if ( ! empty( $auth['cavv'] ) ) {
			$info['cavv'] = $auth['cavv'];
		}
		return $info;
	}

	private function mastercard_ucaf_collection_indicator( array $auth ) {
		$eci = ltrim( (string) ( $auth['eci'] ?? $auth['eciRaw'] ?? '' ), '0' );
		if ( '2' === $eci ) {
			return '2';
		}
		if ( '1' === $eci ) {
			return '1';
		}
		return '';
	}

	private function build_line_items( WC_Order $order ) {
		$decimals   = wc_get_price_decimals();
		$factor     = 10 ** $decimals;
		$line_items = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$amount = (float) $item->get_total() + (float) $item->get_total_tax();
			$this->append_money_line_items(
				$line_items,
				$amount,
				max( 1, (int) $item->get_quantity() ),
				$this->truncate_field( $item->get_name(), 255 ),
				$this->truncate_field( $item->get_product() ? $item->get_product()->get_sku() : '', 255 ),
				'default',
				$factor,
				$decimals
			);
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$this->append_money_line_items(
				$line_items,
				(float) $item->get_total() + (float) $item->get_total_tax(),
				1,
				$this->truncate_field( $item->get_method_title() ?: __( 'Envio', 'cybersource-rest-woocommerce' ), 255 ),
				'shipping',
				'shipping_only',
				$factor,
				$decimals
			);
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			$amount = (float) $item->get_total() + (float) $item->get_total_tax();
			if ( $amount < 0 ) {
				return new WP_Error( 'cybs_negative_fee', __( 'CyberSource no admite cargos negativos como lineas de detalle. Revisa los descuentos del pedido.', 'cybersource-rest-woocommerce' ) );
			}
			$this->append_money_line_items(
				$line_items,
				$amount,
				1,
				$this->truncate_field( $item->get_name(), 255 ),
				'fee',
				'handling_only',
				$factor,
				$decimals
			);
		}

		$expected_minor = (int) round( (float) $order->get_total() * $factor );
		$actual_minor   = 0;
		foreach ( $line_items as $line_item ) {
			$actual_minor += (int) round( (float) $line_item['unitPrice'] * $factor ) * (int) $line_item['quantity'];
		}
		if ( $expected_minor > $actual_minor ) {
			$this->append_money_line_items(
				$line_items,
				( $expected_minor - $actual_minor ) / $factor,
				1,
				__( 'Ajuste del pedido', 'cybersource-rest-woocommerce' ),
				'adjustment',
				'default',
				$factor,
				$decimals
			);
			$actual_minor = $expected_minor;
		}
		if ( empty( $line_items ) || $actual_minor !== $expected_minor ) {
			return new WP_Error( 'cybs_line_item_total', __( 'El detalle del pedido no coincide exactamente con el total a cobrar. No se envio la transaccion.', 'cybersource-rest-woocommerce' ) );
		}
		return $line_items;
	}

	private function append_money_line_items( array &$line_items, $amount, $quantity, $name, $sku, $product_code, $factor, $decimals ) {
		$minor_amount = (int) round( (float) $amount * $factor );
		$quantity     = max( 1, (int) $quantity );
		if ( $minor_amount <= 0 ) {
			return;
		}
		$base      = intdiv( $minor_amount, $quantity );
		$remainder = $minor_amount % $quantity;
		$groups    = array();
		if ( $remainder > 0 ) {
			$groups[] = array( 'minor' => $base + 1, 'quantity' => $remainder );
		}
		if ( $quantity - $remainder > 0 && $base > 0 ) {
			$groups[] = array( 'minor' => $base, 'quantity' => $quantity - $remainder );
		}
		foreach ( $groups as $group ) {
			$line_item = array(
				'unitPrice'   => number_format( $group['minor'] / $factor, $decimals, '.', '' ),
				'quantity'    => (string) $group['quantity'],
				'productCode' => $product_code,
				'productName' => $name,
			);
			if ( '' !== $sku ) {
				$line_item['productSku'] = $sku;
			}
			$line_items[] = $line_item;
		}
	}

	private function truncate_field( $value, $length ) {
		$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
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

	private function processor_approved( array $response ) {
		$code     = trim( (string) ( $response['processorInformation']['responseCode'] ?? '' ) );
		$approval = trim( (string) ( $response['processorInformation']['approvalCode'] ?? '' ) );
		return '' !== $approval && in_array( $code, array( '0', '00', '000' ), true );
	}

	private function validate_response_amount( WC_Order $order, array $response ) {
		$details  = $response['orderInformation']['amountDetails'] ?? array();
		$amount   = $details['authorizedAmount'] ?? $details['totalAmount'] ?? null;
		$currency = strtoupper( (string) ( $details['currency'] ?? '' ) );
		$expected = (float) $order->get_total();
		if ( null === $amount || '' === $currency ) {
			return new WP_Error( 'cybs_response_amount_missing', __( 'la respuesta no incluyo importe y moneda verificables.', 'cybersource-rest-woocommerce' ) );
		}
		if ( $currency !== strtoupper( (string) $order->get_currency() ) || abs( (float) $amount - $expected ) >= 0.01 ) {
			return new WP_Error(
				'cybs_response_amount_mismatch',
				sprintf(
					/* translators: 1: expected amount/currency, 2: returned amount/currency. */
					__( 'se solicitaron %1$s %2$s y CyberSource informo %3$s %4$s.', 'cybersource-rest-woocommerce' ),
					wc_format_decimal( $expected, wc_get_price_decimals() ),
					$order->get_currency(),
					wc_format_decimal( $amount, wc_get_price_decimals() ),
					$currency
				)
			);
		}
		return true;
	}

	private function apply_cybersource_promotion( WC_Order $order, array $response ) {
		$promotion = is_array( $response['promotionInformation'] ?? null ) ? $response['promotionInformation'] : array();
		$details   = is_array( $response['orderInformation']['amountDetails'] ?? null ) ? $response['orderInformation']['amountDetails'] : array();
		$code      = sanitize_text_field( (string) ( $promotion['code'] ?? '' ) );
		$description = sanitize_text_field( (string) ( $promotion['description'] ?? '' ) );
		$type      = sanitize_text_field( (string) ( $promotion['type'] ?? '' ) );
		$receipt   = sanitize_text_field( (string) ( $promotion['receiptData'] ?? '' ) );
		$amount    = (float) ( $details['authorizedAmount'] ?? $details['totalAmount'] ?? 0 );
		$currency  = strtoupper( sanitize_text_field( (string) ( $details['currency'] ?? '' ) ) );
		$expected  = (float) $order->get_total();

		if ( ! $code || ! $description || ! $type || $amount <= 0 || $amount >= $expected || $currency !== strtoupper( (string) $order->get_currency() ) ) {
			return new WP_Error( 'cybs_promotion_invalid', __( 'CyberSource no devolvio una promocion verificable para justificar la diferencia.', 'cybersource-rest-woocommerce' ) );
		}

		$discount = round( $expected - $amount, wc_get_price_decimals() );
		if ( $discount <= 0 ) {
			return new WP_Error( 'cybs_promotion_amount', __( 'El descuento informado por CyberSource no es valido.', 'cybersource-rest-woocommerce' ) );
		}

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( sprintf( __( 'Descuento CyberSource (%s)', 'cybersource-rest-woocommerce' ), $code ) );
		$fee->set_amount( -$discount );
		$fee->set_total( -$discount );
		$fee->set_tax_status( 'none' );
		$fee->add_meta_data( '_cybs_promotion_code', $code, true );
		$item_id = $order->add_item( $fee );
		$order->calculate_totals( false );

		if ( abs( (float) $order->get_total() - $amount ) >= 0.01 ) {
			if ( $item_id ) {
				$order->remove_item( $item_id );
				$order->calculate_totals( false );
			}
			return new WP_Error( 'cybs_promotion_total', __( 'No fue posible reconciliar el descuento de CyberSource con el total del pedido.', 'cybersource-rest-woocommerce' ) );
		}

		$order->update_meta_data( '_cybs_promotion_code', $code );
		$order->update_meta_data( '_cybs_promotion_type', $type );
		$order->update_meta_data( '_cybs_promotion_description', $description );
		$order->update_meta_data( '_cybs_promotion_receipt_data', $receipt );
		$order->update_meta_data( '_cybs_promotion_discount', wc_format_decimal( $discount, wc_get_price_decimals() ) );
		$order->save();
		$order->add_order_note(
			sprintf(
				__( 'CyberSource aplico una promocion verificada. Codigo: %1$s. Descuento: %2$s %3$s. Detalle: %4$s.', 'cybersource-rest-woocommerce' ),
				$code,
				wc_format_decimal( $discount, wc_get_price_decimals() ),
				$currency,
				$description
			)
		);
		return true;
	}

	private function record_transaction_details( WC_Order $order, array $response, array $auth ) {
		$status          = sanitize_text_field( (string) ( $response['status'] ?? '' ) );
		$request_id      = sanitize_text_field( (string) ( $response['id'] ?? '' ) );
		$processor       = is_array( $response['processorInformation'] ?? null ) ? $response['processorInformation'] : array();
		$amount_details  = is_array( $response['orderInformation']['amountDetails'] ?? null ) ? $response['orderInformation']['amountDetails'] : array();
		$device_id       = sanitize_key( (string) ( $auth['deviceFingerprintId'] ?? '' ) );
		$meta = array(
			'_cybs_environment'                     => $this->environment,
			'_cybs_status'                          => $status,
			'_cybs_request_id'                      => $request_id,
			'_cybs_network_transaction_id'          => sanitize_text_field( (string) ( $processor['networkTransactionId'] ?? '' ) ),
			'_cybs_processor_transaction_id'        => sanitize_text_field( (string) ( $processor['transactionId'] ?? '' ) ),
			'_cybs_reconciliation_id'               => sanitize_text_field( (string) ( $response['reconciliationId'] ?? '' ) ),
			'_cybs_approval_code'                   => sanitize_text_field( (string) ( $processor['approvalCode'] ?? '' ) ),
			'_cybs_processor_response_code'         => sanitize_text_field( (string) ( $processor['responseCode'] ?? '' ) ),
			'_cybs_retrieval_reference_number'       => sanitize_text_field( (string) ( $processor['retrievalReferenceNumber'] ?? '' ) ),
			'_cybs_system_trace_audit_number'        => sanitize_text_field( (string) ( $processor['systemTraceAuditNumber'] ?? '' ) ),
			'_cybs_submit_time_utc'                  => sanitize_text_field( (string) ( $response['submitTimeUtc'] ?? '' ) ),
			'_cybs_authorized_amount'               => sanitize_text_field( (string) ( $amount_details['authorizedAmount'] ?? $amount_details['totalAmount'] ?? '' ) ),
			'_cybs_requested_amount'                => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'_cybs_currency'                        => sanitize_text_field( (string) ( $amount_details['currency'] ?? $order->get_currency() ) ),
			'_cybs_client_reference_code'            => sanitize_text_field( (string) $order->get_order_number() ),
			'_cybs_device_fingerprint_id'           => $device_id,
			'_cybs_eci'                             => sanitize_text_field( (string) ( $auth['eci'] ?? $auth['eciRaw'] ?? '' ) ),
			'_cybs_pares_status'                    => sanitize_text_field( (string) ( $auth['paresStatus'] ?? '' ) ),
			'_cybs_authentication_transaction_id'   => sanitize_text_field( (string) ( $auth['authenticationTransactionId'] ?? '' ) ),
			'_cybs_directory_server_transaction_id' => sanitize_text_field( (string) ( $auth['directoryServerTransactionId'] ?? '' ) ),
		);
		foreach ( $meta as $key => $value ) {
			if ( '' !== (string) $value ) {
				$order->update_meta_data( $key, $value );
			}
		}
		$tracked_statuses = array( 'ACCEPTED', 'AUTHORIZED', 'AUTHORIZED_PENDING_REVIEW', 'CAPTURED', 'PENDING', 'TRANSMITTED' );
		if ( $request_id && ( in_array( strtoupper( $status ), $tracked_statuses, true ) || $this->processor_approved( $response ) ) ) {
			$order->set_transaction_id( $request_id );
		}
		$order->save();

		$labels = array(
			'Estado'                    => $status,
			'Referencia WooCommerce'    => $meta['_cybs_client_reference_code'],
			'Request ID'                => $request_id,
			'Network Transaction ID'    => $meta['_cybs_network_transaction_id'],
			'Processor Transaction ID'  => $meta['_cybs_processor_transaction_id'],
			'Reconciliation ID'         => $meta['_cybs_reconciliation_id'],
			'Approval Code'             => $meta['_cybs_approval_code'],
			'Processor Response'        => $meta['_cybs_processor_response_code'],
			'Retrieval Reference Number' => $meta['_cybs_retrieval_reference_number'],
			'System Trace Audit Number' => $meta['_cybs_system_trace_audit_number'],
			'Fecha UTC CyberSource'     => $meta['_cybs_submit_time_utc'],
			'Authentication Transaction ID' => $meta['_cybs_authentication_transaction_id'],
			'Directory Server Transaction ID' => $meta['_cybs_directory_server_transaction_id'],
			'Device Fingerprint ID'     => $device_id,
			'ECI'                       => $meta['_cybs_eci'],
			'PARes'                     => $meta['_cybs_pares_status'],
			'Importe CyberSource'       => trim( $meta['_cybs_authorized_amount'] . ' ' . $meta['_cybs_currency'] ),
		);
		$parts = array();
		foreach ( $labels as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$parts[] = $label . ': ' . $value;
			}
		}
		$order->add_order_note( 'CyberSource - ' . implode( ' | ', $parts ) );
	}

	public function bill_to( WC_Order $order ) {
		return array_filter(
			array(
				'firstName'          => $order->get_billing_first_name() ?: $order->get_shipping_first_name(),
				'lastName'           => $order->get_billing_last_name() ?: $order->get_shipping_last_name(),
				'address1'           => $order->get_billing_address_1() ?: $order->get_shipping_address_1(),
				'address2'           => $order->get_billing_address_2() ?: $order->get_shipping_address_2(),
				'locality'           => $order->get_billing_city() ?: $order->get_shipping_city(),
				'administrativeArea' => $order->get_billing_state() ?: $order->get_shipping_state(),
				'postalCode'         => $order->get_billing_postcode() ?: $order->get_shipping_postcode(),
				'country'            => $order->get_billing_country() ?: $order->get_shipping_country(),
				'email'              => $order->get_billing_email(),
				'phoneNumber'        => preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() ),
			),
			static function ( $value ) { return '' !== (string) $value; }
		);
	}

	private function commerce_indicator( $card_type, array $auth ) {
		if ( 'yes' !== $this->enable_3ds || empty( $auth ) ) return 'internet';
		return array( '001' => 'vbv', '002' => 'spa', '003' => 'aesk', '007' => 'js' )[ $card_type ] ?? 'internet';
	}
}
