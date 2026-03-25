<?php
/**
 * Plugin Name: ZipTax WooCommerce Sales Tax
 * Plugin URI:  https://zip.tax
 * Description: Automated sales tax calculation for WooCommerce using the Zip Tax API v60 with address-level geocoding, product taxability codes (TIC), and US + Canada support.
 * Version:     3.0.2
 * Author:      Zip Tax
 * Author URI:  https://zip.tax
 * Requires at least: 6.0
 * Tested up to: 6.7
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ziptax-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ZIPTAX_VERSION', '3.0.2' );
define( 'ZIPTAX_PLUGIN_FILE', __FILE__ );
define( 'ZIPTAX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIPTAX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZIPTAX_API_BASE', 'https://api.zip-tax.com/request/v60' );

/**
 * Main ZipTax WooCommerce plugin class.
 */
final class ZipTax_WooCommerce {

	/**
	 * Single instance.
	 *
	 * @var ZipTax_WooCommerce|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return ZipTax_WooCommerce
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_classes();

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Load class files that do not depend on WooCommerce.
	 *
	 * Classes that extend WC_Integration are loaded later in init()
	 * to avoid fatal errors when WooCommerce has not loaded yet.
	 */
	private function load_classes() {
		require_once ZIPTAX_PLUGIN_DIR . 'inc/class-ziptax-api.php';
		require_once ZIPTAX_PLUGIN_DIR . 'inc/class-ziptax-tax-handler.php';
		require_once ZIPTAX_PLUGIN_DIR . 'inc/class-ziptax-product-tic.php';
	}

	/**
	 * Initialize the plugin after WooCommerce has loaded.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Load the integration class now that WC_Integration is available.
		require_once ZIPTAX_PLUGIN_DIR . 'inc/class-ziptax-wc-integration.php';

		// Register the WooCommerce integration (settings page).
		add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );

		// Add settings link on the plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		// Initialize tax handler and product TIC support.
		ZipTax_Tax_Handler::instance();
		ZipTax_Product_TIC::instance();
	}

	/**
	 * Register the WooCommerce integration.
	 *
	 * @param array $integrations Existing integrations.
	 * @return array
	 */
	public function add_integration( $integrations ) {
		$integrations[] = 'ZipTax_WC_Integration';
		return $integrations;
	}

	/**
	 * Add a "Settings" link on the Plugins page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=integration&section=ziptax' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'ziptax-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is not active.
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="error"><p><strong>';
		echo esc_html__( 'ZipTax WooCommerce Sales Tax requires WooCommerce to be installed and active.', 'ziptax-woocommerce' );
		echo '</strong></p></div>';
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'ZipTax WooCommerce requires PHP 7.4 or higher.', 'ziptax-woocommerce' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Deactivation hook — clean up transients, orphaned tax rate rows, cron, and options.
	 */
	public static function deactivate() {
		global $wpdb;

		// Remove cached transients.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_ziptax_%'
			    OR option_name LIKE '_transient_timeout_ziptax_%'"
		);

		// Remove ZipTax-generated tax rate rows not referenced by any order.
		// Rows still referenced by orders are kept for reporting accuracy.
		if ( class_exists( 'ZipTax_Tax_Handler' ) ) {
			ZipTax_Tax_Handler::delete_orphaned_rate_rows();

			// Unschedule the cleanup cron.
			$timestamp = wp_next_scheduled( ZipTax_Tax_Handler::CLEANUP_CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, ZipTax_Tax_Handler::CLEANUP_CRON_HOOK );
			}
		}

		// Allow initial WooCommerce tax configuration to run again on reactivation.
		delete_option( 'ziptax_wc_tax_configured' );
	}

	/**
	 * Log a message using the WooCommerce logger.
	 *
	 * Debug-level messages are only logged when debug mode is enabled in settings.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (debug, info, warning, error, critical).
	 */
	public static function log( $message, $level = 'debug' ) {
		$settings = get_option( 'woocommerce_ziptax_settings', array() );
		$debug    = isset( $settings['debug'] ) && 'yes' === $settings['debug'];

		if ( ! $debug && 'debug' === $level ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log( $level, $message, array( 'source' => 'ziptax' ) );
		}
	}
}

// Boot the plugin.
ZipTax_WooCommerce::instance();
