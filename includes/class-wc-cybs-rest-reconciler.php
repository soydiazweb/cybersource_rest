<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reconciliacion de cobros indeterminados o en revision.
 *
 * Cada 15 minutos consulta en CyberSource los pedidos marcados con
 * _cybs_reconcile = yes (timeout, error 5xx, respuesta perdida o revision de
 * Decision Manager) y actualiza el pedido sin volver a cobrar. Tambien agrega
 * la accion manual "Consultar estado en CyberSource" en el pedido.
 */
final class WC_Cybs_REST_Reconciler {
	const HOOK     = 'wc_cybs_rest_reconcile';
	const SCHEDULE = 'wc_cybs_rest_15min';
	const BATCH    = 20;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_cybs_reconcile', array( __CLASS__, 'manual' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 15 minutos (CyberSource)', 'cybersource-rest-woocommerce' ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run() {
		$orders = wc_get_orders(
			array(
				'limit'          => self::BATCH,
				'payment_method' => 'cybersource_rest',
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_cybs_reconcile',
						'value' => 'yes',
					),
				),
			)
		);
		if ( ! $orders ) {
			return;
		}
		$gateway = new WC_Gateway_Cybs_REST();
		foreach ( $orders as $order ) {
			$result = $gateway->reconcile_order( $order, false );
			( new WC_Cybs_REST_Logger( $gateway->logging ) )->log( 'info', 'Reconciliacion', array( 'order_id' => $order->get_id(), 'result' => $result ) );
		}
	}

	public static function order_actions( $actions, $order = null ) {
		if ( $order instanceof WC_Order && 'cybersource_rest' === $order->get_payment_method() ) {
			$actions['cybs_reconcile'] = __( 'Consultar estado en CyberSource', 'cybersource-rest-woocommerce' );
		}
		return $actions;
	}

	public static function manual( $order ) {
		if ( ! $order instanceof WC_Order || 'cybersource_rest' !== $order->get_payment_method() ) {
			return;
		}
		$result = ( new WC_Gateway_Cybs_REST() )->reconcile_order( $order, true );
		$order->add_order_note( sprintf( __( 'Consulta manual de estado en CyberSource: %s.', 'cybersource-rest-woocommerce' ), $result ) );
	}
}
