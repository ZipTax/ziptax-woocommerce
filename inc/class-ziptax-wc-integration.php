<?php
/**
 * ZipTax WooCommerce Integration — Settings page.
 *
 * Provides the admin settings UI under WooCommerce > Settings > Integration > ZipTax.
 * Handles API key configuration, tax display options, and debug logging toggle.
 *
 * @package ZipTax_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZipTax_WC_Integration' ) ) :

class ZipTax_WC_Integration extends WC_Integration {

	/**
	 * Constructor — set up the integration.
	 */
	public function __construct() {
		$this->id                 = 'ziptax';
		$this->method_title       = __( 'Zip Tax', 'ziptax-woocommerce' );
		$this->method_description = __( 'Automated sales tax calculation using the Zip Tax API v60 with address-level geocoding, product taxability codes (TIC), and US + Canada support.', 'ziptax-woocommerce' );

		// Load stored settings.
		$this->init_form_fields();
		$this->init_settings();

		// User-configured values.
		$this->api_key = $this->get_option( 'api_key' );
		$this->debug   = 'yes' === $this->get_option( 'debug' );

		// Save settings action.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );

		// After saving, ensure WooCommerce tax settings are configured properly.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'configure_woocommerce_tax_settings' ) );

		// Display notice on the WooCommerce Tax settings tab.
		add_action( 'woocommerce_sections_tax', array( $this, 'tax_section_notice' ), 9 );
	}

	/**
	 * Define the settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'api_key' => array(
				'title'       => __( 'API Key', 'ziptax-woocommerce' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the Zip Tax registration page. */
					__( 'Enter your Zip Tax API key. <a href="%s" target="_blank">Get your API key here</a>.', 'ziptax-woocommerce' ),
					'https://zip.tax'
				),
				'default'     => '',
				'desc_tip'    => false,
			),
			'tax_based_on' => array(
				'title'       => __( 'Calculate tax based on', 'ziptax-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Which customer address to use for the tax rate lookup.', 'ziptax-woocommerce' ),
				'default'     => 'shipping',
				'desc_tip'    => true,
				'options'     => array(
					'shipping' => __( 'Shipping address', 'ziptax-woocommerce' ),
					'billing'  => __( 'Billing address', 'ziptax-woocommerce' ),
				),
			),
			'tax_shipping' => array(
				'title'       => __( 'Tax shipping', 'ziptax-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'How to determine if shipping charges are taxable.', 'ziptax-woocommerce' ),
				'default'     => 'api',
				'desc_tip'    => true,
				'options'     => array(
					'api'  => __( 'Use API response (recommended)', 'ziptax-woocommerce' ),
					'yes'  => __( 'Always tax shipping', 'ziptax-woocommerce' ),
					'no'   => __( 'Never tax shipping', 'ziptax-woocommerce' ),
				),
			),
			'debug' => array(
				'title'       => __( 'Debug logging', 'ziptax-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'ziptax-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: Path to WooCommerce log viewer. */
					__( 'Log API requests and tax calculations. View logs in <a href="%s">WooCommerce > Status > Logs</a>.', 'ziptax-woocommerce' ),
					admin_url( 'admin.php?page=wc-status&tab=logs' )
				),
			),
		);
	}

	/**
	 * Configure WooCommerce core tax settings to work with ZipTax.
	 *
	 * Called after our integration settings are saved.
	 */
	public function configure_woocommerce_tax_settings() {
		// Enable tax calculations.
		update_option( 'woocommerce_calc_taxes', 'yes' );

		// Set tax calculation basis.
		$tax_based_on = $this->get_option( 'tax_based_on', 'shipping' );
		update_option( 'woocommerce_tax_based_on', $tax_based_on );

		// Prices entered exclusive of tax.
		update_option( 'woocommerce_prices_include_tax', 'no' );

		// Don't pre-fill customer address (let checkout gather it).
		update_option( 'woocommerce_default_customer_address', '' );

		// Display taxes exclusive of price.
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );

		// Show a single tax total line.
		update_option( 'woocommerce_tax_total_display', 'single' );

		// Don't round tax at subtotal level — apply per-line for accuracy.
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
	}

	/**
	 * Display a notice on the WooCommerce Tax settings tab.
	 */
	public function tax_section_notice() {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=ziptax' );
		echo '<div class="notice notice-info inline"><p>';
		printf(
			/* translators: %s: URL to ZipTax integration settings. */
			esc_html__( 'Tax rates are managed automatically by Zip Tax. %s', 'ziptax-woocommerce' ),
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure Zip Tax settings', 'ziptax-woocommerce' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Validate the API key field — trim whitespace and verify non-empty.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string Sanitized value.
	 */
	public function validate_api_key_field( $key, $value ) {
		$value = trim( sanitize_text_field( $value ) );

		if ( empty( $value ) ) {
			WC_Admin_Settings::add_error( __( 'Please enter your Zip Tax API key.', 'ziptax-woocommerce' ) );
		}

		return $value;
	}

	/**
	 * Get the API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return $this->api_key;
	}

	/**
	 * Check whether debug mode is enabled.
	 *
	 * @return bool
	 */
	public function is_debug() {
		return $this->debug;
	}
}

endif;
