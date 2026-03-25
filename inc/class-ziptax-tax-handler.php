<?php
/**
 * ZipTax Tax Handler.
 *
 * Injects Zip Tax API rates into WooCommerce's native tax system via the
 * `woocommerce_find_rates` filter. This lets WooCommerce handle all cart,
 * checkout, shipping, and fee tax calculations internally — including
 * the block-based checkout and REST API.
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
	 * The current (general) tax rate data from the API.
	 *
	 * @var array|null
	 */
	private $current_rate_data = null;

	/**
	 * In-memory cache of all API rate lookups during this request.
	 *
	 * Keyed by cache key string, values are normalized API responses.
	 *
	 * @var array
	 */
	private $rate_memory_cache = array();

	/**
	 * The WooCommerce tax rate ID for the current rate.
	 *
	 * @var int|null
	 */
	private $current_rate_id = null;

	/**
	 * Whether shipping should be taxed for the current request.
	 *
	 * @var bool
	 */
	private $tax_shipping = false;

	/**
	 * Guard against re-entrant calls.
	 *
	 * @var bool
	 */
	private static $is_calculating = false;

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
	 * Cron hook name for periodic cleanup of orphaned tax rate rows.
	 *
	 * @var string
	 */
	const CLEANUP_CRON_HOOK = 'ziptax_cleanup_orphaned_rates';

	/**
	 * Constructor — register WooCommerce hooks.
	 */
	private function __construct() {
		// Pre-fetch the rate before WooCommerce calculates totals.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'prefetch_rate' ), 10 );

		// Inject our rate into WooCommerce's native tax lookup.
		add_filter( 'woocommerce_find_rates', array( $this, 'inject_tax_rate' ), 10, 2 );

		// Apply TIC-specific rates per cart item.
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'apply_tic_rates' ), 20 );

		// Transfer per-item tax data to order line items at checkout.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'set_order_line_item_tax' ), 10, 4 );

		// Schedule daily cleanup of orphaned tax rate rows.
		add_action( self::CLEANUP_CRON_HOOK, array( $this, 'cleanup_orphaned_rates' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_CRON_HOOK );
		}
	}

	// ------------------------------------------------------------------
	// Settings helpers.
	// ------------------------------------------------------------------

	/**
	 * @return array
	 */
	private function get_settings() {
		return get_option( 'woocommerce_ziptax_settings', array() );
	}

	/**
	 * @return string
	 */
	private function get_api_key() {
		$settings = $this->get_settings();
		return $settings['api_key'] ?? '';
	}

	/**
	 * @return string "shipping" or "billing".
	 */
	private function get_tax_address_type() {
		$settings = $this->get_settings();
		return $settings['tax_based_on'] ?? 'shipping';
	}

	/**
	 * @return string "api", "yes", or "no".
	 */
	private function get_shipping_tax_preference() {
		$settings = $this->get_settings();
		return $settings['tax_shipping'] ?? 'api';
	}

	// ------------------------------------------------------------------
	// Address helpers.
	// ------------------------------------------------------------------

	/**
	 * Build address array from the WooCommerce customer.
	 *
	 * @param WC_Customer $customer
	 * @return array
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
	 * @param string $country Two-letter country code.
	 * @return bool
	 */
	private function is_supported_country( $country ) {
		return in_array( strtoupper( $country ), array( 'US', 'CA' ), true );
	}

	// ------------------------------------------------------------------
	// Caching.
	// ------------------------------------------------------------------

	/**
	 * Generate a cache key from address components.
	 *
	 * @param array $address
	 * @param int   $tic
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
	 * Fetch the tax rate with three-tier caching (memory, session, transient).
	 *
	 * @param array $address
	 * @param int   $tic
	 * @return array|null Normalized API response or null on failure.
	 */
	private function get_tax_rate( array $address, $tic = 0 ) {
		$cache_key = $this->build_cache_key( $address, $tic );

		// In-memory cache (same page request) — works for both general and TIC lookups.
		if ( isset( $this->rate_memory_cache[ $cache_key ] ) ) {
			ZipTax_WooCommerce::log( 'Using in-memory cached rate.' );
			return $this->rate_memory_cache[ $cache_key ];
		}

		// WooCommerce session cache.
		if ( WC()->session ) {
			$session_data = WC()->session->get( $cache_key );
			if ( is_array( $session_data ) ) {
				ZipTax_WooCommerce::log( 'Using session cached rate.' );
				$session_data['_cache_key'] = $cache_key;
				$this->rate_memory_cache[ $cache_key ] = $session_data;
				return $session_data;
			}
		}

		// WordPress transient cache.
		$transient_data = get_transient( $cache_key );
		if ( false !== $transient_data && is_array( $transient_data ) ) {
			ZipTax_WooCommerce::log( 'Using transient cached rate.' );
			$transient_data['_cache_key'] = $cache_key;

			$this->rate_memory_cache[ $cache_key ] = $transient_data;
			if ( WC()->session ) {
				WC()->session->set( $cache_key, $transient_data );
			}
			return $transient_data;
		}

		// Call the API.
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			ZipTax_WooCommerce::log( 'No API key configured.', 'warning' );
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

		// Cache the result at all three tiers.
		$result['_cache_key'] = $cache_key;

		$this->rate_memory_cache[ $cache_key ] = $result;
		if ( WC()->session ) {
			WC()->session->set( $cache_key, $result );
		}
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	// ------------------------------------------------------------------
	// WooCommerce tax rate table management.
	// ------------------------------------------------------------------

	/**
	 * Unique tax rate name used to identify ZipTax-managed rate rows.
	 *
	 * All ZipTax-generated rows use this name so they can be identified
	 * for cleanup purposes and distinguished from manually created rates.
	 *
	 * @var string
	 */
	const RATE_NAME = 'ZipTax Sales Tax';

	/**
	 * Find or create a per-jurisdiction WooCommerce tax rate row.
	 *
	 * Each unique country+state+city combination gets its own row so that
	 * WooCommerce tax reports, admin order views, and third-party tools
	 * can display accurate jurisdiction details for historical orders.
	 *
	 * The rate percentage is updated on each request to stay current.
	 * Orphaned rows (not referenced by any order) are cleaned up daily
	 * by the cleanup_orphaned_rates cron.
	 *
	 * @param float  $rate     Decimal rate (e.g. 0.0775).
	 * @param string $state    State code.
	 * @param string $city     City name.
	 * @param string $country  Country code.
	 * @param bool   $shipping Whether this rate applies to shipping.
	 * @return int Tax rate ID.
	 */
	private function get_or_create_tax_rate_id( $rate, $state, $city, $country, $shipping = false ) {
		global $wpdb;

		$rate_pct   = round( $rate * 100, 4 );
		$city_upper = strtoupper( $city );
		$ship_flag  = $shipping ? 1 : 0;

		// Look for an existing row matching this jurisdiction.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
			 WHERE tax_rate_name = %s
			   AND tax_rate_country = %s
			   AND tax_rate_state = %s
			   AND tax_rate_city = %s
			   AND tax_rate_class = ''
			 LIMIT 1",
			self::RATE_NAME,
			$country,
			$state,
			$city_upper
		) );

		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'woocommerce_tax_rates',
				array(
					'tax_rate'          => $rate_pct,
					'tax_rate_shipping' => $ship_flag,
				),
				array( 'tax_rate_id' => $existing ),
				array( '%f', '%d' ),
				array( '%d' )
			);
			ZipTax_WooCommerce::log( sprintf( 'Updated tax rate ID %d to %.4f%%', $existing, $rate_pct ) );
			return (int) $existing;
		}

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_tax_rates',
			array(
				'tax_rate_country'  => $country,
				'tax_rate_state'    => $state,
				'tax_rate_city'     => $city_upper,
				'tax_rate_name'     => self::RATE_NAME,
				'tax_rate'          => $rate_pct,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => $ship_flag,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			),
			array( '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s' )
		);

		$rate_id = (int) $wpdb->insert_id;
		ZipTax_WooCommerce::log( sprintf( 'Created tax rate ID %d at %.4f%%', $rate_id, $rate_pct ) );

		WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		return $rate_id;
	}

	/**
	 * Remove ZipTax-managed tax rate rows not referenced by any order.
	 *
	 * Called daily via WP-Cron. Keeps the wc_tax_rates table clean while
	 * preserving rows that orders still reference for reporting accuracy.
	 */
	public function cleanup_orphaned_rates() {
		global $wpdb;

		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE tr FROM {$wpdb->prefix}woocommerce_tax_rates tr
			 WHERE tr.tax_rate_name = %s
			   AND tr.tax_rate_id NOT IN (
			       SELECT DISTINCT rate_id
			       FROM {$wpdb->prefix}woocommerce_order_items oi
			       INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
			           ON oi.order_item_id = oim.order_item_id
			       WHERE oi.order_item_type = 'tax'
			         AND oim.meta_key = 'rate_id'
			   )",
			self::RATE_NAME
		) );

		if ( $deleted > 0 ) {
			ZipTax_WooCommerce::log( sprintf( 'Cleaned up %d orphaned tax rate rows.', $deleted ), 'info' );
			WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		}
	}

	// ------------------------------------------------------------------
	// Hook: woocommerce_before_calculate_totals
	// ------------------------------------------------------------------

	/**
	 * Pre-fetch the tax rate and ensure a matching WC tax rate row exists
	 * before WooCommerce runs its internal tax calculations.
	 *
	 * @param WC_Cart $cart
	 */
	public function prefetch_rate( $cart ) {
		if ( self::$is_calculating ) {
			return;
		}

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
		$country = $address['country'] ?? '';

		if ( ! $this->is_supported_country( $country ) ) {
			$this->current_rate_data = null;
			$this->current_rate_id   = null;
			return;
		}

		if ( empty( $address['postcode'] ) && empty( $address['city'] ) ) {
			$this->current_rate_data = null;
			$this->current_rate_id   = null;
			return;
		}

		ZipTax_WooCommerce::log( '--- Pre-fetching tax rate ---' );

		$rate_data = $this->get_tax_rate( $address, 0 );
		if ( ! $rate_data ) {
			$this->current_rate_data = null;
			$this->current_rate_id   = null;
			return;
		}

		$this->current_rate_data = $rate_data;
		$sales_rate = (float) $rate_data['sales_tax_rate'];

		if ( $sales_rate <= 0 ) {
			$this->current_rate_id = null;
			return;
		}

		// Determine shipping taxability.
		$shipping_pref    = $this->get_shipping_tax_preference();
		$this->tax_shipping = false;

		if ( 'yes' === $shipping_pref ) {
			$this->tax_shipping = true;
		} elseif ( 'api' === $shipping_pref ) {
			$this->tax_shipping = ! empty( $rate_data['freight_taxable'] );
		}

		// Ensure a matching tax rate row exists in the DB.
		$this->current_rate_id = $this->get_or_create_tax_rate_id(
			$sales_rate,
			$rate_data['state'] ?? $address['state'],
			$rate_data['city'] ?? $address['city'],
			$address['country'],
			$this->tax_shipping
		);

		ZipTax_WooCommerce::log( sprintf(
			'Rate ready: %.4f%% (ID %d, ship_tax=%s)',
			$sales_rate * 100,
			$this->current_rate_id,
			$this->tax_shipping ? 'yes' : 'no'
		) );
	}

	// ------------------------------------------------------------------
	// Hook: woocommerce_find_rates
	// ------------------------------------------------------------------

	/**
	 * Inject the ZipTax rate into WooCommerce's native tax rate lookup.
	 *
	 * WC_Tax::find_rates() calls this filter with the location arguments.
	 * If we have a pre-fetched rate for a supported country, we return it
	 * so WooCommerce uses it for items, shipping, and fees.
	 *
	 * @param array $matched_tax_rates Existing matched rates.
	 * @param array $args              Location arguments (country, state, postcode, city, tax_class).
	 * @return array
	 */
	public function inject_tax_rate( $matched_tax_rates, $args ) {
		// Only inject if we have a pre-fetched rate.
		if ( null === $this->current_rate_id || null === $this->current_rate_data ) {
			return $matched_tax_rates;
		}

		$country = $args['country'] ?? '';
		if ( ! $this->is_supported_country( $country ) ) {
			return $matched_tax_rates;
		}

		// Respect WooCommerce tax classes — only override the standard class.
		// Products assigned "Zero Rate", "Reduced Rate", or other custom classes
		// should keep their WooCommerce-configured rates unless a TIC code is used.
		$tax_class = $args['tax_class'] ?? '';
		if ( '' !== $tax_class && 'standard' !== $tax_class ) {
			return $matched_tax_rates;
		}

		$sales_rate = (float) $this->current_rate_data['sales_tax_rate'];
		if ( $sales_rate <= 0 ) {
			return $matched_tax_rates;
		}

		$rate_pct = round( $sales_rate * 100, 4 );

		// Replace whatever WooCommerce found with our API rate.
		return array(
			$this->current_rate_id => array(
				'rate'     => $rate_pct,
				'label'    => __( 'Sales Tax', 'ziptax-woocommerce' ),
				'shipping' => $this->tax_shipping ? 'yes' : 'no',
				'compound' => 'no',
			),
		);
	}

	// ------------------------------------------------------------------
	// Hook: woocommerce_after_calculate_totals
	// ------------------------------------------------------------------

	/**
	 * Apply TIC-specific rates to individual cart items.
	 *
	 * If any product has a TIC code that results in a different rate than
	 * the general rate, we override the tax for those specific items.
	 * WooCommerce has already calculated taxes using the general rate;
	 * we only adjust items that need a different TIC rate.
	 *
	 * @param WC_Cart $cart
	 */
	public function apply_tic_rates( $cart ) {
		if ( self::$is_calculating ) {
			return;
		}

		if ( null === $this->current_rate_id || null === $this->current_rate_data ) {
			return;
		}

		$customer = WC()->customer;
		if ( ! $customer ) {
			return;
		}

		$address    = $this->get_customer_address( $customer );
		$sales_rate = (float) $this->current_rate_data['sales_tax_rate'];
		$rate_id    = $this->current_rate_id;

		// Collect TIC codes from cart items.
		$has_tic = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( $product && $product->is_taxable() ) {
				$tic = (int) $product->get_meta( '_ziptax_tic_code' );
				if ( $tic > 0 ) {
					$has_tic = true;
					break;
				}
			}
		}

		if ( ! $has_tic ) {
			return;
		}

		ZipTax_WooCommerce::log( 'Applying TIC-specific rates.' );

		self::$is_calculating = true;

		try {
			// Fetch TIC rates and adjust individual items.
			$tax_adjustment = 0.0;
			$tic_cache      = array(); // tic => rate|null

			foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
				$product = $cart_item['data'];
				if ( ! $product || ! $product->is_taxable() ) {
					continue;
				}

				$tic = (int) $product->get_meta( '_ziptax_tic_code' );
				if ( $tic <= 0 ) {
					continue;
				}

				// Fetch the TIC rate (cached per-TIC).
				if ( ! array_key_exists( $tic, $tic_cache ) ) {
					$tic_data = $this->get_tax_rate( $address, $tic );
					if ( $tic_data && isset( $tic_data['product_tax_rate'] ) ) {
						$tic_cache[ $tic ] = (float) $tic_data['product_tax_rate'];
					} else {
						$tic_cache[ $tic ] = null; // Use general rate.
					}
				}

				$tic_rate = $tic_cache[ $tic ];
				if ( null === $tic_rate || abs( $tic_rate - $sales_rate ) < 0.000001 ) {
					continue; // Same as general rate, no adjustment needed.
				}

				ZipTax_WooCommerce::log( sprintf( 'TIC %d: adjusting rate from %.4f to %.4f', $tic, $sales_rate, $tic_rate ) );

				$line_total    = (float) $cart_item['line_total'];
				$line_subtotal = (float) $cart_item['line_subtotal'];

				$old_tax     = wc_round_tax_total( $line_total * $sales_rate );
				$new_tax     = wc_round_tax_total( $line_total * $tic_rate );
				$new_sub_tax = wc_round_tax_total( $line_subtotal * $tic_rate );

				$cart->cart_contents[ $cart_key ]['line_tax']          = $new_tax;
				$cart->cart_contents[ $cart_key ]['line_subtotal_tax'] = $new_sub_tax;
				$cart->cart_contents[ $cart_key ]['line_tax_data']     = array(
					'total'    => array( $rate_id => $new_tax ),
					'subtotal' => array( $rate_id => $new_sub_tax ),
				);

				$tax_adjustment += ( $new_tax - $old_tax );
			}

			// If we adjusted any items, update the cart tax totals.
			if ( abs( $tax_adjustment ) > 0.001 ) {
				$cart_taxes = $cart->get_cart_contents_taxes();
				if ( isset( $cart_taxes[ $rate_id ] ) ) {
					$cart_taxes[ $rate_id ] += $tax_adjustment;
				}
				$cart->set_cart_contents_taxes( $cart_taxes );

				// Adjust the total tax by the delta rather than recomputing from scratch.
				$old_total_tax = (float) $cart->get_total_tax();
				$cart->set_total_tax( wc_round_tax_total( $old_total_tax + $tax_adjustment ) );

				// Adjust the cart total by the same delta so it stays consistent
				// with WooCommerce's internal calculations (coupons, fees, etc.).
				$old_total = (float) $cart->get_total( 'edit' );
				$cart->set_total( max( 0, round( $old_total + $tax_adjustment, wc_get_price_decimals() ) ) );

				ZipTax_WooCommerce::log( sprintf( 'TIC adjustment: %.2f', $tax_adjustment ) );
			}
		} finally {
			self::$is_calculating = false;
		}
	}

	// ------------------------------------------------------------------
	// Hook: woocommerce_checkout_create_order_line_item
	// ------------------------------------------------------------------

	/**
	 * Transfer per-item tax data to order line items during checkout.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 */
	public function set_order_line_item_tax( $item, $cart_key, $values, $order ) {
		if ( isset( $values['line_tax_data'] ) ) {
			$item->set_taxes( $values['line_tax_data'] );
		}
	}
}
