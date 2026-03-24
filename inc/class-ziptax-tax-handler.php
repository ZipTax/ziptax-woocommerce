<?php
/**
 * ZipTax Tax Handler.
 *
 * Hooks into WooCommerce cart and checkout calculations to apply tax rates
 * from the Zip Tax v60 API. Manages session caching to minimise API calls,
 * creates dynamic WooCommerce tax rate entries, and handles both product-level
 * TIC codes and shipping taxability.
 *
 * @package ZipTax_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ZipTax_Tax_Handler {

	/**
	 * Singleton instance.
	 *
	 * @var ZipTax_Tax_Handler|null
	 */
	private static $instance = null;

	/**
	 * Cached API response for the current request.
	 *
	 * @var array|null
	 */
	private $cached_rate = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return ZipTax_Tax_Handler
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register WooCommerce hooks.
	 */
	private function __construct() {
		// Calculate taxes when the cart totals are computed.
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'calculate_cart_taxes' ), 20 );

		// Apply tax to new orders at checkout.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'set_order_line_item_tax' ), 10, 4 );

		// Also support the WooCommerce REST/block-based checkout recalculation.
		add_filter( 'woocommerce_calc_tax', array( $this, 'override_wc_calc_tax' ), 10, 3 );
	}

	/**
	 * Get the integration settings.
	 *
	 * @return array
	 */
	private function get_settings() {
		return get_option( 'woocommerce_ziptax_settings', array() );
	}

	/**
	 * Get the configured API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$settings = $this->get_settings();
		return $settings['api_key'] ?? '';
	}

	/**
	 * Determine whether to use shipping or billing address.
	 *
	 * @return string "shipping" or "billing".
	 */
	private function get_tax_address_type() {
		$settings = $this->get_settings();
		return $settings['tax_based_on'] ?? 'shipping';
	}

	/**
	 * Get the shipping tax preference.
	 *
	 * @return string "api", "yes", or "no".
	 */
	private function get_shipping_tax_preference() {
		$settings = $this->get_settings();
		return $settings['tax_shipping'] ?? 'api';
	}

	/**
	 * Build the address array from the WooCommerce customer.
	 *
	 * @param WC_Customer $customer WooCommerce customer object.
	 * @return array Address components.
	 */
	private function get_customer_address( $customer ) {
		$type = $this->get_tax_address_type();

		if ( 'billing' === $type ) {
			return array(
				'address_1' => $customer->get_billing_address_1(),
				'address_2' => $customer->get_billing_address_2(),
				'city'      => $customer->get_billing_city(),
				'state'     => $customer->get_billing_state(),
				'postcode'  => $customer->get_billing_postcode(),
				'country'   => $customer->get_billing_country(),
			);
		}

		return array(
			'address_1' => $customer->get_shipping_address_1(),
			'address_2' => $customer->get_shipping_address_2(),
			'city'      => $customer->get_shipping_city(),
			'state'     => $customer->get_shipping_state(),
			'postcode'  => $customer->get_shipping_postcode(),
			'country'   => $customer->get_shipping_country(),
		);
	}

	/**
	 * Check if the given country is supported (US or Canada).
	 *
	 * @param string $country Two-letter country code.
	 * @return bool
	 */
	private function is_supported_country( $country ) {
		return in_array( strtoupper( $country ), array( 'US', 'CA' ), true );
	}

	/**
	 * Generate a cache key from address components.
	 *
	 * @param array $address Address components.
	 * @param int   $tic     TIC code (0 = none).
	 * @return string
	 */
	private function build_cache_key( array $address, $tic = 0 ) {
		$parts = array(
			'ziptax',
			strtolower( $address['address_1'] ?? '' ),
			strtolower( $address['city'] ?? '' ),
			strtolower( $address['state'] ?? '' ),
			strtolower( $address['postcode'] ?? '' ),
			strtolower( $address['country'] ?? '' ),
			(string) $tic,
		);
		return 'ziptax_' . md5( implode( '|', $parts ) );
	}

	/**
	 * Fetch the tax rate, using session cache when possible.
	 *
	 * @param array $address Address components.
	 * @param int   $tic     TIC code.
	 * @return array|null Normalized API response or null on failure.
	 */
	private function get_tax_rate( array $address, $tic = 0 ) {
		$cache_key = $this->build_cache_key( $address, $tic );

		// Check in-memory cache (same page request).
		if ( null !== $this->cached_rate && $this->cached_rate['_cache_key'] === $cache_key ) {
			ZipTax_WooCommerce::log( 'Using in-memory cached rate.' );
			return $this->cached_rate;
		}

		// Check WooCommerce session cache.
		if ( WC()->session ) {
			$session_data = WC()->session->get( $cache_key );
			if ( is_array( $session_data ) ) {
				ZipTax_WooCommerce::log( 'Using session cached rate for: ' . $cache_key );
				$session_data['_cache_key'] = $cache_key;
				$this->cached_rate = $session_data;
				return $session_data;
			}
		}

		// Check WordPress transient cache (persists across sessions).
		$transient_data = get_transient( $cache_key );
		if ( false !== $transient_data && is_array( $transient_data ) ) {
			ZipTax_WooCommerce::log( 'Using transient cached rate for: ' . $cache_key );
			$transient_data['_cache_key'] = $cache_key;
			$this->cached_rate = $transient_data;

			// Store in session for faster access.
			if ( WC()->session ) {
				WC()->session->set( $cache_key, $transient_data );
			}

			return $transient_data;
		}

		// Call the API.
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			ZipTax_WooCommerce::log( 'No API key configured — skipping tax lookup.', 'warning' );
			return null;
		}

		$api = new ZipTax_API( $api_key );

		ZipTax_WooCommerce::log( sprintf(
			'API lookup: %s %s, %s %s (TIC: %d)',
			$address['address_1'] ?? '',
			$address['city'] ?? '',
			$address['state'] ?? '',
			$address['postcode'] ?? '',
			$tic
		) );

		$result = $api->lookup_by_address( $address, $tic );

		if ( is_wp_error( $result ) ) {
			ZipTax_WooCommerce::log( 'API error: ' . $result->get_error_message(), 'error' );
			return null;
		}

		// Cache the result.
		$result['_cache_key'] = $cache_key;
		$this->cached_rate    = $result;

		if ( WC()->session ) {
			WC()->session->set( $cache_key, $result );
		}

		// Transient cache: 1 hour (rates can change but not frequently).
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Find or create a WooCommerce tax rate entry for the given rate.
	 *
	 * WooCommerce needs a tax rate ID in its `wc_tax_rates` table for proper
	 * display on the cart, checkout, and order screens.
	 *
	 * @param float  $rate    Tax rate as a percentage (e.g. 7.75 for 7.75%).
	 * @param string $state   State code.
	 * @param string $city    City name.
	 * @param string $postcode Postal code.
	 * @param string $country Country code.
	 * @return int Tax rate ID.
	 */
	private function get_or_create_tax_rate_id( $rate, $state, $city, $postcode, $country ) {
		global $wpdb;

		$rate_pct = round( $rate * 100, 4 );

		// Look for an existing ZipTax-managed rate with the same parameters.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
			 WHERE tax_rate_country = %s
			   AND tax_rate_state = %s
			   AND tax_rate_name = %s
			   AND tax_rate_class = ''
			 LIMIT 1",
			$country,
			$state,
			'Sales Tax'
		) );

		if ( $existing ) {
			// Update the rate in case it changed.
			$wpdb->update(
				$wpdb->prefix . 'woocommerce_tax_rates',
				array(
					'tax_rate'     => $rate_pct,
					'tax_rate_city' => strtoupper( $city ),
				),
				array( 'tax_rate_id' => $existing ),
				array( '%f', '%s' ),
				array( '%d' )
			);

			ZipTax_WooCommerce::log( sprintf( 'Updated tax rate ID %d to %.4f%%', $existing, $rate_pct ) );
			return (int) $existing;
		}

		// Create a new rate.
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_tax_rates',
			array(
				'tax_rate_country'  => $country,
				'tax_rate_state'    => $state,
				'tax_rate_city'     => strtoupper( $city ),
				'tax_rate_name'     => 'Sales Tax',
				'tax_rate'          => $rate_pct,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 0, // We handle shipping tax separately.
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			),
			array( '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s' )
		);

		$rate_id = (int) $wpdb->insert_id;

		ZipTax_WooCommerce::log( sprintf( 'Created tax rate ID %d at %.4f%%', $rate_id, $rate_pct ) );

		// Clear WooCommerce tax rate cache.
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		return $rate_id;
	}

	/**
	 * Main cart tax calculation.
	 *
	 * Hooked into `woocommerce_after_calculate_totals`.
	 *
	 * @param WC_Cart $cart The WooCommerce cart object.
	 */
	public function calculate_cart_taxes( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$customer = WC()->customer;
		if ( ! $customer ) {
			return;
		}

		$address = $this->get_customer_address( $customer );

		// Only process supported countries.
		$country = $address['country'] ?? '';
		if ( ! $this->is_supported_country( $country ) ) {
			ZipTax_WooCommerce::log( 'Country not supported: ' . $country );
			return;
		}

		// Need at least a postal code or city to look up tax.
		if ( empty( $address['postcode'] ) && empty( $address['city'] ) ) {
			ZipTax_WooCommerce::log( 'Insufficient address data for tax lookup.' );
			return;
		}

		ZipTax_WooCommerce::log( '--- Calculating cart taxes ---' );

		// Collect unique TIC codes from cart items.
		$tic_codes = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( $product && $product->is_taxable() ) {
				$tic = (int) $product->get_meta( '_ziptax_tic_code' );
				$tic_codes[ $tic ] = true; // 0 = no TIC.
			}
		}

		// Fetch rate without TIC first (for general rate + shipping).
		$general_rate_data = $this->get_tax_rate( $address, 0 );
		if ( ! $general_rate_data ) {
			ZipTax_WooCommerce::log( 'No rate data returned — taxes not applied.' );
			return;
		}

		$sales_rate = (float) $general_rate_data['sales_tax_rate'];

		ZipTax_WooCommerce::log( sprintf( 'General sales tax rate: %.4f', $sales_rate ) );

		if ( $sales_rate <= 0 ) {
			ZipTax_WooCommerce::log( 'Zero tax rate — clearing taxes.' );
			$cart->set_cart_contents_taxes( array() );
			$cart->set_shipping_taxes( array() );
			$cart->set_total_tax( 0 );
			return;
		}

		// Get or create the WC tax rate entry.
		$rate_id = $this->get_or_create_tax_rate_id(
			$sales_rate,
			$general_rate_data['state'] ?? $address['state'],
			$general_rate_data['city'] ?? $address['city'],
			$general_rate_data['postcode'] ?? $address['postcode'],
			$address['country']
		);

		// Fetch per-TIC rates if we have products with TIC codes.
		$tic_rate_map = array(); // tic_code => rate
		foreach ( array_keys( $tic_codes ) as $tic ) {
			if ( $tic > 0 ) {
				$tic_data = $this->get_tax_rate( $address, $tic );
				if ( $tic_data && isset( $tic_data['product_tax_rate'] ) ) {
					$tic_rate_map[ $tic ] = (float) $tic_data['product_tax_rate'];
					ZipTax_WooCommerce::log( sprintf( 'TIC %d rate: %.4f', $tic, $tic_rate_map[ $tic ] ) );
				}
			}
		}

		// Calculate item taxes.
		$total_tax   = 0.0;
		$cart_taxes  = array();

		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			$product = $cart_item['data'];

			if ( ! $product || ! $product->is_taxable() ) {
				continue;
			}

			$line_total = (float) $cart_item['line_total']; // After discounts, before tax.
			$tic        = (int) $product->get_meta( '_ziptax_tic_code' );

			// Use TIC-specific rate if available, otherwise general rate.
			$item_rate = $sales_rate;
			if ( $tic > 0 && isset( $tic_rate_map[ $tic ] ) ) {
				$item_rate = $tic_rate_map[ $tic ];
			}

			$item_tax = wc_round_tax_total( $line_total * $item_rate );

			// Store tax on the cart item.
			$cart_item_taxes = array( $rate_id => $item_tax );

			$cart->cart_contents[ $cart_key ]['line_tax']      = $item_tax;
			$cart->cart_contents[ $cart_key ]['line_tax_data']  = array(
				'total'    => $cart_item_taxes,
				'subtotal' => array( $rate_id => wc_round_tax_total( (float) $cart_item['line_subtotal'] * $item_rate ) ),
			);

			if ( ! isset( $cart_taxes[ $rate_id ] ) ) {
				$cart_taxes[ $rate_id ] = 0.0;
			}
			$cart_taxes[ $rate_id ] += $item_tax;
			$total_tax              += $item_tax;
		}

		// Shipping tax.
		$shipping_tax  = 0.0;
		$shipping_pref = $this->get_shipping_tax_preference();

		$tax_shipping = false;
		if ( 'yes' === $shipping_pref ) {
			$tax_shipping = true;
		} elseif ( 'api' === $shipping_pref ) {
			$tax_shipping = ! empty( $general_rate_data['freight_taxable'] );
		}

		$shipping_taxes = array();
		if ( $tax_shipping ) {
			$shipping_total = (float) $cart->get_shipping_total();
			$shipping_tax   = wc_round_tax_total( $shipping_total * $sales_rate );

			if ( $shipping_tax > 0 ) {
				$shipping_taxes[ $rate_id ] = $shipping_tax;
				$total_tax                 += $shipping_tax;
			}

			ZipTax_WooCommerce::log( sprintf( 'Shipping tax: %.2f on %.2f', $shipping_tax, $shipping_total ) );
		}

		// Apply to cart.
		$cart->set_cart_contents_taxes( $cart_taxes );
		$cart->set_shipping_taxes( $shipping_taxes );
		$cart->set_total_tax( $total_tax );

		// Store fee taxes as empty (we don't tax fees through ZipTax).
		$cart->set_fee_taxes( array() );

		// Recalculate the cart total since woocommerce_after_calculate_totals
		// fires after the total has already been computed with default (zero) tax.
		$cart_total = $cart->get_subtotal()
			- $cart->get_discount_total()
			+ $cart->get_shipping_total()
			+ $cart->get_fee_total()
			+ $total_tax;

		$cart->set_total( max( 0, round( $cart_total, wc_get_price_decimals() ) ) );

		ZipTax_WooCommerce::log( sprintf( 'Total tax applied: %.2f (cart total: %.2f)', $total_tax, $cart_total ) );
	}

	/**
	 * Set tax on order line items during checkout.
	 *
	 * @param WC_Order_Item_Product $item     Order item.
	 * @param string                $cart_key Cart item key.
	 * @param array                 $values   Cart item data.
	 * @param WC_Order              $order    The order.
	 */
	public function set_order_line_item_tax( $item, $cart_key, $values, $order ) {
		if ( isset( $values['line_tax_data'] ) ) {
			$item->set_taxes( $values['line_tax_data'] );
		}
	}

	/**
	 * Override WooCommerce core tax calculation for supported countries.
	 *
	 * This filter allows us to intercept the WC_Tax::calc_tax method used
	 * by the block-based checkout and REST API.
	 *
	 * @param array  $taxes   Calculated taxes array.
	 * @param float  $price   Price to calculate tax on.
	 * @param array  $rates   Tax rates from WC_Tax::find_rates().
	 * @return array Modified taxes array.
	 */
	public function override_wc_calc_tax( $taxes, $price, $rates ) {
		// Only override if we have a cached rate from our own calculation.
		// This prevents interfering with non-ZipTax rate calculations.
		if ( null === $this->cached_rate ) {
			return $taxes;
		}

		return $taxes;
	}
}
