<?php
/**
 * Plugin Name: CyberSource REST para WooCommerce
 * Plugin URI:  https://www.soydiaz.com
 * Description: Pasarela CyberSource REST con Payer Authentication 3-D Secure, device fingerprint, ambientes de pruebas/produccion y logs sanitizados.
 * Version:     1.1.4
 * Author:      www.soydiaz.com
 * Author URI:  https://www.soydiaz.com
 * Text Domain: cybersource-rest-woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.3
 * WC tested up to: 10.0
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_CYBS_REST_VERSION', '1.1.4' );
define( 'WC_CYBS_REST_FILE', __FILE__ );
define( 'WC_CYBS_REST_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_CYBS_REST_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'CyberSource REST requiere WooCommerce activo.', 'cybersource-rest-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}

		require_once WC_CYBS_REST_PATH . 'includes/class-wc-cybs-rest-sanitizer.php';
		require_once WC_CYBS_REST_PATH . 'includes/class-wc-cybs-rest-logger.php';
		require_once WC_CYBS_REST_PATH . 'includes/class-wc-cybs-rest-client.php';
		require_once WC_CYBS_REST_PATH . 'includes/class-wc-gateway-cybs-rest.php';
		require_once WC_CYBS_REST_PATH . 'includes/class-wc-cybs-rest-controller.php';

		WC_Cybs_REST_Controller::instance();
	},
	11
);

add_filter(
	'woocommerce_payment_gateways',
	static function ( $gateways ) {
		$gateways[] = 'WC_Gateway_Cybs_REST';
		return $gateways;
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once WC_CYBS_REST_PATH . 'includes/class-wc-cybs-rest-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new WC_Cybs_REST_Blocks() );
			}
		);
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( get_option( 'woocommerce_cybersource_rest_settings', false ) === false ) {
			add_option(
				'woocommerce_cybersource_rest_settings',
				array(
					'enabled'          => 'no',
					'environment'      => 'test',
					'enable_3ds'       => 'yes',
					'capture'          => 'yes',
					'test_org_id'      => '1snn5n9w',
					'production_org_id'=> 'k8vif92e',
					'logging'          => 'yes',
				)
			);
		}
	}
);
