<?php
/**
 * ZipTax Product TIC (Taxability Information Code) support.
 *
 * Adds a "Taxability Code" meta field to WooCommerce product editor screens
 * so merchants can assign a TIC code per product. The code is sent to the
 * v60 API as the `taxabilityCode` parameter for product-specific tax rules.
 *
 * @package ZipTax_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ZipTax_Product_TIC {

	/**
	 * Meta key used to store the TIC code on products.
	 */
	const META_KEY = '_ziptax_tic_code';

	/**
	 * Singleton instance.
	 *
	 * @var ZipTax_Product_TIC|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton.
	 *
	 * @return ZipTax_Product_TIC
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		// Simple product — add field to the General tab.
		add_action( 'woocommerce_product_options_tax', array( $this, 'add_tic_field' ) );

		// Save simple product.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_tic_field' ) );

		// Variable product — add field to each variation.
		add_action( 'woocommerce_variation_options_tax', array( $this, 'add_variation_tic_field' ), 10, 3 );

		// Save variation.
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_tic_field' ), 10, 2 );
	}

	/**
	 * Add the TIC code field on the product General tab (next to Tax class).
	 */
	public function add_tic_field() {
		woocommerce_wp_text_input( array(
			'id'          => self::META_KEY,
			'label'       => __( 'Taxability Code (TIC)', 'ziptax-woocommerce' ),
			'placeholder' => __( 'e.g. 20010', 'ziptax-woocommerce' ),
			'desc_tip'    => true,
			'description' => __( 'Optional Taxability Information Code for product-specific tax rules. Leave blank to use the standard sales tax rate. See zip.tax for available TIC codes.', 'ziptax-woocommerce' ),
			'type'        => 'number',
			'custom_attributes' => array(
				'min'  => '0',
				'step' => '1',
			),
		) );
	}

	/**
	 * Save the TIC code for simple products.
	 *
	 * @param int $post_id Product (post) ID.
	 */
	public function save_tic_field( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST[ self::META_KEY ] ) ) {
			$tic = absint( $_POST[ self::META_KEY ] );
			update_post_meta( $post_id, self::META_KEY, $tic > 0 ? $tic : '' );
		}
	}

	/**
	 * Add the TIC code field to a product variation.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post object.
	 */
	public function add_variation_tic_field( $loop, $variation_data, $variation ) {
		$value = get_post_meta( $variation->ID, self::META_KEY, true );

		woocommerce_wp_text_input( array(
			'id'            => self::META_KEY . '_' . $loop,
			'name'          => self::META_KEY . '[' . $loop . ']',
			'label'         => __( 'Taxability Code (TIC)', 'ziptax-woocommerce' ),
			'placeholder'   => __( 'e.g. 20010', 'ziptax-woocommerce' ),
			'desc_tip'      => true,
			'description'   => __( 'Optional TIC code for this variation. Leave blank to inherit from parent.', 'ziptax-woocommerce' ),
			'value'         => $value,
			'type'          => 'number',
			'wrapper_class' => 'form-row form-row-first',
			'custom_attributes' => array(
				'min'  => '0',
				'step' => '1',
			),
		) );
	}

	/**
	 * Save the TIC code for a product variation.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation loop index.
	 */
	public function save_variation_tic_field( $variation_id, $loop ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST[ self::META_KEY ][ $loop ] ) ) {
			$tic = absint( $_POST[ self::META_KEY ][ $loop ] );
			update_post_meta( $variation_id, self::META_KEY, $tic > 0 ? $tic : '' );
		}
	}
}
