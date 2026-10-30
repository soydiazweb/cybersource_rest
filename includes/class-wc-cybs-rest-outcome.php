<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reglas de decision puras (sin I/O) para respuestas de CyberSource.
 *
 * Replica el criterio del modulo de pagos de Colegio Interamericano:
 * - Ante cualquier duda el resultado es "unknown" (se reconcilia), nunca se
 *   asume aprobacion ni se permite reintentar el cobro.
 * - AUTHORIZED_RISK_DECLINED y PARTIAL_AUTHORIZED se liberan (reversa/void).
 * - 3-D Secure solo autoriza con ECI autenticado o intentado (05/06 Visa,
 *   Amex, JCB, Discover; 02/01 Mastercard). ECI 07/00 o ausente se rechaza.
 */
final class WC_Cybs_REST_Outcome {
	const PAID     = 'paid';
	const REVIEW   = 'review';
	const RELEASE  = 'release';
	const DECLINED = 'declined';
	const ERROR    = 'error';
	const UNKNOWN  = 'unknown';

	const AUTHENTICATED_ECI = array( '05', '06', '02', '01' );

	const COMMERCE_INDICATORS = array(
		'vbv', 'vbv_attempted', 'vbv_failure', 'spa', 'spa_failure', 'aesk', 'aesk_attempted',
		'js', 'js_attempted', 'dipb', 'pb_attempted', 'internet',
	);

	const FRIENDLY_BY_REASON = array(
		'INSUFFICIENT_FUND'                => 'La tarjeta no tiene fondos suficientes. Intenta con otra tarjeta.',
		'EXCEEDS_CREDIT_LIMIT'             => 'La tarjeta excedio su limite de credito. Intenta con otra tarjeta.',
		'DEBIT_CARD_USEAGE_EXCEEDD_LIMIT'  => 'La tarjeta supero su limite de uso. Comunicate con tu banco o intenta con otra tarjeta.',
		'EXPIRED_CARD'                     => 'La tarjeta esta vencida. Intenta con otra tarjeta.',
		'INVALID_ACCOUNT'                  => 'El numero de tarjeta no es valido. Verifica los datos e intentalo de nuevo.',
		'INVALID_CARD'                     => 'El numero de tarjeta no es valido. Verifica los datos e intentalo de nuevo.',
		'DECLINED_CHECK_CVN'               => 'El codigo de seguridad (CVV) no es correcto. Verificalo e intentalo de nuevo.',
		'CVN_NOT_MATCH'                    => 'El codigo de seguridad (CVV) no es correcto. Verificalo e intentalo de nuevo.',
		'INVALID_CVN'                      => 'El codigo de seguridad (CVV) no es correcto. Verificalo e intentalo de nuevo.',
		'CV_FAILED'                        => 'El codigo de seguridad (CVV) no es correcto. Verificalo e intentalo de nuevo.',
		'AVS_FAILED'                       => 'Los datos de facturacion no coinciden con los del banco. Verificalos e intentalo de nuevo.',
		'CARD_TYPE_NOT_ACCEPTED'           => 'Este tipo de tarjeta no es aceptado. Intenta con otra tarjeta.',
		'INVALID_CARD_TYPE'                => 'Este tipo de tarjeta no es aceptado. Intenta con otra tarjeta.',
		'PROCESSOR_DECLINED'               => 'El banco emisor rechazo la transaccion. Comunicate con tu banco e intentalo de nuevo.',
		'GENERAL_DECLINE'                  => 'El banco emisor rechazo la transaccion. Comunicate con tu banco e intentalo de nuevo.',
		'PAYMENT_REFUSED'                  => 'El banco emisor rechazo la transaccion. Comunicate con tu banco e intentalo de nuevo.',
		'CONTACT_PROCESSOR'                => 'Tu banco pide que te comuniques con el antes de aprobar este pago.',
		'UNAUTHORIZED_CARD'                => 'La tarjeta no esta habilitada para este tipo de compra. Comunicate con tu banco.',
		'SUSPENDED_ACCOUNT'                => 'La cuenta de la tarjeta esta suspendida. Comunicate con tu banco.',
		'STOLEN_LOST_CARD'                 => 'El banco emisor rechazo la transaccion. Comunicate con tu banco.',
		'ISSUER_UNAVAILABLE'               => 'El banco emisor no respondio. Intentalo de nuevo en unos minutos.',
		'ALLOWABLE_PIN_RETRIES_EXCEEDED'   => 'La tarjeta supero los intentos permitidos. Comunicate con tu banco.',
		'BLACKLISTED_CUSTOMER'             => 'No pudimos procesar el pago con esta tarjeta. Intenta con otra.',
		'CUSTOMER_WATCHLIST_MATCH'         => 'No pudimos procesar el pago con esta tarjeta. Intenta con otra.',
		'DECISION_PROFILE_REJECT'          => 'El cobro no fue aprobado por la validacion de seguridad de pagos. Intenta con otra tarjeta o comunicate con tu banco.',
		'SCORE_EXCEEDS_THRESHOLD'          => 'El cobro no fue aprobado por la validacion de seguridad de pagos. Intenta con otra tarjeta o comunicate con tu banco.',
		'CONSUMER_AUTHENTICATION_REQUIRED' => 'Tu banco exige verificar la tarjeta (3-D Secure). Vuelve a intentarlo y completa la verificacion.',
		'CONSUMER_AUTHENTICATION_FAILED'   => 'Tu banco no pudo autenticar la tarjeta. Intenta con otra tarjeta o comunicate con tu banco.',
		'MISSING_FIELD'                    => 'No pudimos procesar el pago por un problema tecnico. No se realizo ningun cobro. Intentalo de nuevo mas tarde.',
		'INVALID_DATA'                     => 'No pudimos procesar el pago por un problema tecnico. No se realizo ningun cobro. Intentalo de nuevo mas tarde.',
		'INVALID_MERCHANT_CONFIGURATION'   => 'No pudimos procesar el pago por un problema tecnico. No se realizo ningun cobro. Intentalo de nuevo mas tarde.',
		'PROCESSOR_UNAVAILABLE'            => 'El procesador de pagos no esta disponible en este momento. Intentalo de nuevo en unos minutos.',
		'DUPLICATE_REQUEST'                => 'Este pago ya se envio. Revisa su estado antes de intentarlo de nuevo.',
	);

	/**
	 * Clasifica la respuesta de /pts/v2/payments.
	 *
	 * @param string $status      Campo status de la respuesta (vacio si no vino).
	 * @param int    $http_status Codigo HTTP (0 = sin respuesta).
	 * @param array  $response    Cuerpo decodificado.
	 */
	public static function classify( $status, $http_status, array $response = array() ) {
		$status = strtoupper( trim( (string) $status ) );
		switch ( $status ) {
			case 'AUTHORIZED':
			case 'PENDING':
			case 'TRANSMITTED':
			case 'CAPTURED':
				return self::PAID;
			case 'ACCEPTED':
				return self::processor_approved( $response ) ? self::PAID : self::UNKNOWN;
			case 'AUTHORIZED_PENDING_REVIEW':
			case 'PENDING_REVIEW':
				return self::REVIEW;
			case 'AUTHORIZED_RISK_DECLINED':
			case 'PARTIAL_AUTHORIZED':
				return self::RELEASE;
			case 'DECLINED':
				return self::DECLINED;
			case 'INVALID_REQUEST':
				return self::ERROR;
		}
		// Sin status reconocido: timeout, 5xx o 2xx raro son indeterminados.
		$http_status = (int) $http_status;
		if ( 0 === $http_status || $http_status >= 500 ) {
			return self::UNKNOWN;
		}
		if ( $http_status >= 400 ) {
			return self::ERROR;
		}
		return self::UNKNOWN;
	}

	/**
	 * Clasifica el detalle de /tss/v2/transactions/{id} usado en la reconciliacion.
	 */
	public static function classify_transaction_detail( array $detail ) {
		$app    = is_array( $detail['applicationInformation'] ?? null ) ? $detail['applicationInformation'] : array();
		$status = strtoupper( trim( (string) ( $app['status'] ?? '' ) ) );
		if ( '' !== $status ) {
			$outcome = self::classify( $status, 200, $detail );
			if ( self::UNKNOWN !== $outcome ) {
				return $outcome;
			}
		}
		$reason = trim( (string) ( $app['reasonCode'] ?? '' ) );
		if ( '100' === $reason ) {
			// Solo es un cobro si la transaccion incluye la autorizacion (ics_auth) exitosa.
			foreach ( (array) ( $app['applications'] ?? array() ) as $application ) {
				if ( 'ics_auth' === ( $application['name'] ?? '' ) ) {
					return '1' === trim( (string) ( $application['rCode'] ?? '' ) ) ? self::PAID : self::DECLINED;
				}
			}
			return self::UNKNOWN;
		}
		if ( '480' === $reason ) {
			return self::REVIEW;
		}
		if ( '481' === $reason ) {
			return self::RELEASE;
		}
		if ( '' !== $reason && ctype_digit( $reason ) ) {
			return self::DECLINED;
		}
		return self::UNKNOWN;
	}

	public static function processor_approved( array $response ) {
		$code     = trim( (string) ( $response['processorInformation']['responseCode'] ?? '' ) );
		$approval = trim( (string) ( $response['processorInformation']['approvalCode'] ?? '' ) );
		return '' !== $approval && in_array( $code, array( '0', '00', '000' ), true );
	}

	/** Normaliza el ECI a dos digitos ('5' => '05'); vacio si no vino. */
	public static function normalize_eci( $eci ) {
		$eci = preg_replace( '/\D+/', '', (string) $eci );
		if ( '' === $eci ) {
			return '';
		}
		return str_pad( substr( $eci, -2 ), 2, '0', STR_PAD_LEFT );
	}

	public static function eci_allows_authorization( $eci ) {
		return in_array( self::normalize_eci( $eci ), self::AUTHENTICATED_ECI, true );
	}

	/**
	 * Indicador e-commerce para la autorizacion: el que devolvio 3-D Secure y,
	 * si no vino, el que corresponde a la marca y al ECI.
	 */
	public static function commerce_indicator( $card_type, array $auth, $three_ds_enabled ) {
		if ( ! $three_ds_enabled || empty( $auth ) ) {
			return 'internet';
		}
		$returned = strtolower( trim( (string) ( $auth['commerceIndicator'] ?? '' ) ) );
		if ( in_array( $returned, self::COMMERCE_INDICATORS, true ) && 'internet' !== $returned ) {
			return $returned;
		}
		$eci = self::normalize_eci( $auth['eci'] ?? $auth['eciRaw'] ?? '' );
		switch ( $card_type ) {
			case '001':
				return '06' === $eci ? 'vbv_attempted' : 'vbv';
			case '002':
				return 'spa';
			case '003':
				return '06' === $eci ? 'aesk_attempted' : 'aesk';
			case '007':
				return '06' === $eci ? 'js_attempted' : 'js';
			case '004':
				return '06' === $eci ? 'pb_attempted' : 'dipb';
		}
		return 'internet';
	}

	/** errorInformation.reason (201) o reason (4xx). */
	public static function reason( array $response ) {
		$reason = $response['errorInformation']['reason'] ?? $response['reason'] ?? '';
		return strtoupper( trim( (string) $reason ) );
	}

	public static function friendly_message( $outcome, array $response = array(), $status = '' ) {
		$reason = self::reason( $response );
		if ( '' !== $reason && isset( self::FRIENDLY_BY_REASON[ $reason ] ) ) {
			$message = self::FRIENDLY_BY_REASON[ $reason ];
		} else {
			switch ( $outcome ) {
				case self::DECLINED:
					$message = 'El pago fue rechazado por el banco emisor. Verifica los datos de tu tarjeta o comunicate con tu banco e intentalo de nuevo.';
					break;
				case self::RELEASE:
					$message = 'PARTIAL_AUTHORIZED' === strtoupper( (string) $status )
						? 'Tu banco aprobo solo una parte del importe, por lo que el pago no se completo.'
						: 'Tu banco aprobo la tarjeta, pero el cobro no fue aprobado por la validacion de seguridad de pagos.';
					break;
				case self::UNKNOWN:
				case self::REVIEW:
					$message = 'Estamos confirmando el estado de tu pago. No vuelvas a intentar el cobro; te avisaremos el resultado.';
					break;
				default:
					$message = 'No pudimos procesar el pago en este momento. No se realizo ningun cobro. Revisa los datos e intentalo nuevamente.';
			}
		}
		return __( $message, 'cybersource-rest-woocommerce' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	/** Las URL de recoleccion de datos y desafio 3-D Secure deben ser de Cardinal Commerce por https. */
	public static function is_cardinal_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return 'cardinalcommerce.com' === $host || '.cardinalcommerce.com' === substr( $host, -21 );
	}
}
