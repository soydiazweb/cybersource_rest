<?php

defined( 'ABSPATH' ) || exit;

/** CyberSource REST client using HTTP Signature authentication. */
final class WC_Cybs_REST_Client {
	private $host;
	private $merchant_id;
	private $key_id;
	private $secret_key;
	private $logger;

	public function __construct( $environment, $merchant_id, $key_id, $secret_key, WC_Cybs_REST_Logger $logger ) {
		$this->host        = 'production' === $environment ? 'api.cybersource.com' : 'apitest.cybersource.com';
		$this->merchant_id = trim( (string) $merchant_id );
		$this->key_id      = trim( (string) $key_id );
		$this->secret_key  = trim( (string) $secret_key );
		$this->logger      = $logger;
	}

	public function request( $method, $resource, array $payload = array(), $correlation_id = '' ) {
		$method   = strtoupper( $method );
		$resource = '/' . ltrim( $resource, '/' );
		$has_body = 'GET' !== $method && 'DELETE' !== $method;
		$body     = empty( $payload ) ? '{}' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$date     = gmdate( 'D, d M Y H:i:s \G\M\T' );
		// GET/DELETE se firman sin cuerpo ni Digest, como exige HTTP Signature.
		$digest   = $has_body ? 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) ) : '';
		$headers  = $this->signed_headers( $method, $resource, $date, $digest );
		// Transaction Search responde 406 con application/hal+json (Transaction Details si lo acepta).
		if ( 0 === strpos( $resource, '/tss/v2/searches' ) ) {
			$headers['Accept'] = 'application/json;charset=utf-8';
		}

		$this->logger->log(
			'info',
			'CyberSource request',
			array(
				'correlation_id' => $correlation_id,
				'method'         => $method,
				'resource'       => $resource,
				'environment'    => $this->host,
				'payload'        => $payload,
			)
		);

		$args = array(
			'method'      => $method,
			'timeout'     => 45,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( $has_body ) {
			$args['body']        = $body;
			$args['data_format'] = 'body';
		}
		$response = wp_remote_request( 'https://' . $this->host . $resource, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'error', 'CyberSource transport error', array( 'correlation_id' => $correlation_id, 'error' => $response->get_error_message() ) );
			// Sin respuesta (timeout/corte de red): el resultado es indeterminado, nunca un rechazo.
			return new WP_Error( 'cybs_transport', $response->get_error_message(), array( 'status' => 0, 'transport' => true, 'response' => array() ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array( 'raw' => $raw_body );
		}

		$this->logger->log(
			$status_code >= 400 ? 'error' : 'info',
			'CyberSource response',
			array(
				'correlation_id' => $correlation_id,
				'http_status'    => $status_code,
				'resource'       => $resource,
				'payload'        => $decoded,
			)
		);

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $decoded['message'] ?? $decoded['errorInformation']['message'] ?? __( 'CyberSource rechazo la solicitud.', 'cybersource-rest-woocommerce' );
			return new WP_Error( 'cybs_http_' . $status_code, sanitize_text_field( (string) $message ), array( 'status' => $status_code, 'response' => $decoded ) );
		}

		return $decoded;
	}

	private function signed_headers( $method, $resource, $date, $digest ) {
		$header_names = '' !== $digest ? 'host v-c-date (request-target) digest v-c-merchant-id' : 'host v-c-date (request-target) v-c-merchant-id';
		$canonical    = 'host: ' . $this->host . "\n";
		$canonical   .= 'v-c-date: ' . $date . "\n";
		$canonical   .= '(request-target): ' . strtolower( $method ) . ' ' . $resource . "\n";
		if ( '' !== $digest ) {
			$canonical .= 'digest: ' . $digest . "\n";
		}
		$canonical   .= 'v-c-merchant-id: ' . $this->merchant_id;

		$decoded_secret = base64_decode( $this->secret_key, true );
		if ( false === $decoded_secret ) {
			$decoded_secret = $this->secret_key;
		}
		$signature_hash = base64_encode( hash_hmac( 'sha256', $canonical, $decoded_secret, true ) );
		$signature      = sprintf(
			'keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"',
			$this->key_id,
			$header_names,
			$signature_hash
		);

		$headers = array(
			'Accept'          => 'application/hal+json;charset=utf-8',
			'Content-Type'    => 'application/json;charset=utf-8',
			'Host'            => $this->host,
			'v-c-date'        => $date,
			'v-c-merchant-id' => $this->merchant_id,
			'Signature'       => $signature,
			'User-Agent'      => 'WooCommerce-CyberSource-REST/' . WC_CYBS_REST_VERSION,
		);
		if ( '' !== $digest ) {
			$headers['Digest'] = $digest;
		}
		return $headers;
	}
}
