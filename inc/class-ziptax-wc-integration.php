<?php
/**
 * ZipTax WooCommerce Integration — Settings page.
 *
 * Provides the admin settings UI under WooCommerce > Settings > Integration > ZipTax.
 * Handles API key configuration, tax display options, and debug logging toggle.
 *
 * @package ZipTax_Sales_Tax
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZipTax_WC_Integration' ) ) :

class ZipTax_WC_Integration extends WC_Integration {

	/**
	 * Constructor — set up the integration.
	 */
	public function __construct() {
		$this->id                 = 'ziptax';
		$this->method_title       = __( 'Zip Tax', 'ziptax-sales-tax' );
		$this->method_description = __( 'Automated sales tax calculation using the Zip Tax API v60 with address-level geocoding, product taxability codes (TIC), and US + Canada support.', 'ziptax-sales-tax' );

		// Load stored settings.
		$this->init_form_fields();
		$this->init_settings();

		// User-configured values.
		$this->api_key = $this->get_option( 'api_key' );
		$this->debug   = 'yes' === $this->get_option( 'debug' );

		// Save settings action.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );

		// On first save, configure WooCommerce tax settings.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'maybe_configure_woocommerce_tax_settings' ) );

		// Display notice on the WooCommerce Tax settings tab.
		add_action( 'woocommerce_sections_tax', array( $this, 'tax_section_notice' ), 9 );
	}

	/**
	 * Define the settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'api_key' => array(
				'title'       => __( 'API Key', 'ziptax-sales-tax' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the Zip Tax registration page. */
					__( 'Enter your Zip Tax API key. <a href="%s" target="_blank">Get your API key here</a>.', 'ziptax-sales-tax' ),
					'https://zip.tax'
				),
				'default'     => '',
				'desc_tip'    => false,
			),
			'tax_based_on' => array(
				'title'       => __( 'Calculate tax based on', 'ziptax-sales-tax' ),
				'type'        => 'select',
				'description' => __( 'Which customer address to use for the tax rate lookup.', 'ziptax-sales-tax' ),
				'default'     => 'shipping',
				'desc_tip'    => true,
				'options'     => array(
					'shipping' => __( 'Shipping address', 'ziptax-sales-tax' ),
					'billing'  => __( 'Billing address', 'ziptax-sales-tax' ),
				),
			),
			'tax_shipping' => array(
				'title'       => __( 'Tax shipping', 'ziptax-sales-tax' ),
				'type'        => 'select',
				'description' => __( 'How to determine if shipping charges are taxable.', 'ziptax-sales-tax' ),
				'default'     => 'api',
				'desc_tip'    => true,
				'options'     => array(
					'api'  => __( 'Use API response (recommended)', 'ziptax-sales-tax' ),
					'yes'  => __( 'Always tax shipping', 'ziptax-sales-tax' ),
					'no'   => __( 'Never tax shipping', 'ziptax-sales-tax' ),
				),
			),
			'debug' => array(
				'title'       => __( 'Debug logging', 'ziptax-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'ziptax-sales-tax' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: Path to WooCommerce log viewer. */
					__( 'Log API requests and tax calculations. View logs in <a href="%s">WooCommerce > Status > Logs</a>.', 'ziptax-sales-tax' ),
					admin_url( 'admin.php?page=wc-status&tab=logs' )
				),
			),
		);
	}

	/**
	 * Configure WooCommerce core tax settings on first save only.
	 *
	 * Sets recommended WooCommerce tax options (enable taxes, display
	 * settings, rounding, etc.) the first time the integration settings
	 * are saved. Subsequent saves skip this so admins can customize
	 * WooCommerce tax display options without them being overwritten.
	 */
	public function maybe_configure_woocommerce_tax_settings() {
		if ( 'yes' === get_option( 'ziptax_wc_tax_configured' ) ) {
			return;
		}

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

		// Mark as configured so we don't override on future saves.
		update_option( 'ziptax_wc_tax_configured', 'yes' );
	}

	/**
	 * Display a notice on the WooCommerce Tax settings tab.
	 */
	public function tax_section_notice() {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=ziptax' );
		echo '<div class="notice notice-info inline"><p>';
		printf(
			/* translators: %s: URL to ZipTax integration settings. */
			esc_html__( 'Tax rates are managed automatically by Zip Tax. %s', 'ziptax-sales-tax' ),
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configure Zip Tax settings', 'ziptax-sales-tax' ) . '</a>'
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
			WC_Admin_Settings::add_error( __( 'Please enter your Zip Tax API key.', 'ziptax-sales-tax' ) );
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
