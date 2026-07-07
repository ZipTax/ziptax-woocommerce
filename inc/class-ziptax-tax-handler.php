<?php
/**
 * ZipTax Tax Handler.
 *
 * Injects Zip Tax API rates into WooCommerce's native tax system via the
 * `woocommerce_find_rates` filter. This lets WooCommerce handle all cart,
 * checkout, shipping, and fee tax calculations internally — including
 * the block-based checkout and REST API.
 *
 * @package ZipTax_Sales_Tax
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
	 * Per-request cache of rate IDs known to be ZipTax-managed.
	 *
	 * Avoids re-querying the DB during the many woocommerce_find_rates
	 * calls that occur in a single page load.
	 *
	 * @var array<int,bool>
	 */
	private $ziptax_owned_rate_ids = array();

	/**
	 * The WooCommerce tax rate ID for the API rate, upserted into the
	 * Standard rates table on every cart calculation.
	 *
	 * @var int|null
	 */
	private $current_rate_id = null;

	/**
	 * Non-standard merchant rate row that overrides the API rate for the
	 * current customer's location, or null when no override applies.
	 *
	 * Computed once per request in prefetch_rate(). Keys:
	 *   - id        (int)    tax_rate_id
	 *   - rate_pct  (float)  percentage already in WC's "rate" format
	 *   - label     (string) tax_rate_name from the DB
	 *   - shipping  (string) "yes" / "no"
	 *   - compound  (string) "yes" / "no"
	 *   - class     (string) tax_rate_class slug, for logging only
	 *
	 * @var array|null
	 */
	private $merchant_override = null;

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

		// Force a fresh cart recalculation on cart and checkout page loads
		// and when the customer saves a new address. WooCommerce can serve
		// session-cached totals without re-firing woocommerce_before_calculate_totals,
		// which would leave the tax line stale until the cart contents change.
		// Hooked at both `wp` and `template_redirect` so we fire whichever
		// runs first on the active theme/template path.
		add_action( 'wp', array( $this, 'force_cart_recalculation' ), 100 );
		add_action( 'template_redirect', array( $this, 'force_cart_recalculation' ), 100 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'invalidate_and_recalculate' ), 10 );

		// Display plugin-owned rate rows as "Sales Tax" in cart/checkout
		// totals even though the DB stores them under the internal
		// RATE_NAME marker we use to identify our own rows.
		add_filter( 'woocommerce_rate_label', array( $this, 'filter_rate_label' ), 10, 2 );

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
	 * Build the shipping address array from the WooCommerce customer.
	 *
	 * Tax is always calculated against the shipping address.
	 *
	 * @param WC_Customer $customer
	 * @return array
	 */
	private function get_customer_address( $customer ) {
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

	/**
	 * Check whether a shipping address has all required fields populated.
	 *
	 * Tax calculation is skipped until every field listed here is non-empty.
	 * address_2 is intentionally excluded — it is optional.
	 *
	 * @param array $address
	 * @return bool
	 */
	private function is_address_complete( array $address ) {
		return ! empty( $address['address_1'] )
			&& ! empty( $address['city'] )
			&& ! empty( $address['state'] )
			&& ! empty( $address['postcode'] );
	}

	/**
	 * Clear the current rate from the instance state.
	 *
	 * Called when the address is incomplete or unsupported so that no
	 * stale tax is shown for the current request.
	 */
	private function clear_current_rate() {
		$this->current_rate_data = null;
		$this->current_rate_id   = null;
		$this->tax_shipping      = false;
		$this->merchant_override = null;
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
			strtolower( $address['address_2'] ?? '' ),
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
	 * Each unique country+state+postcode+city combination gets its own
	 * row. Including postcode is essential under concurrent checkout
	 * load — without it, two carts in different ZIPs of the same city
	 * race on the same row and overwrite each other's rates.
	 *
	 * Postcode and city are stored in wc_tax_rate_locations. The lookup
	 * matches by all four fields exactly so the plugin only ever updates
	 * a row it created for the same jurisdiction.
	 *
	 * The rate percentage is refreshed on each request to stay current.
	 * Orphaned rows (not referenced by any order) are cleaned up daily
	 * by the cleanup_orphaned_rates cron; rows still referenced by orders
	 * are kept for historical reporting.
	 *
	 * @param float  $rate     Decimal rate (e.g. 0.0775).
	 * @param string $state    State code.
	 * @param string $city     City name.
	 * @param string $postcode Postal / ZIP code.
	 * @param string $country  Country code.
	 * @param bool   $shipping Whether this rate applies to shipping.
	 * @return int Tax rate ID.
	 */
	private function get_or_create_tax_rate_id( $rate, $state, $city, $postcode, $country, $shipping = false ) {
		global $wpdb;

		$rate_pct   = round( $rate * 100, 4 );
		$city_upper = strtoupper( wc_clean( $city ) );
		$zip_upper  = strtoupper( wc_normalize_postcode( wc_clean( $postcode ) ) );
		$ship_flag  = $shipping ? 1 : 0;

		// Look for an existing ZipTax-owned row matching this exact jurisdiction.
		// City and postcode live in wc_tax_rate_locations, so we left-join both
		// and use IFNULL so rows missing one of the constraints still match
		// when the lookup value is empty.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT tr.tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates tr
			 LEFT JOIN {$wpdb->prefix}woocommerce_tax_rate_locations city_loc
			     ON tr.tax_rate_id = city_loc.tax_rate_id AND city_loc.location_type = 'city'
			 LEFT JOIN {$wpdb->prefix}woocommerce_tax_rate_locations zip_loc
			     ON tr.tax_rate_id = zip_loc.tax_rate_id AND zip_loc.location_type = 'postcode'
			 WHERE tr.tax_rate_name = %s
			   AND tr.tax_rate_country = %s
			   AND tr.tax_rate_state = %s
			   AND IFNULL( city_loc.location_code, '' ) = %s
			   AND IFNULL( zip_loc.location_code, '' ) = %s
			   AND tr.tax_rate_class = ''
			 LIMIT 1",
			self::RATE_NAME,
			$country,
			$state,
			$city_upper,
			$zip_upper
		) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

			// We deliberately do NOT invalidate WC's "taxes" cache here.
			// Invalidating mid-calculate_totals broke rate-id continuity in
			// 3.3.5 — inject_tax_rate() already overrides the rate value
			// with the freshly-fetched API result, so the cached rate value
			// for our row is irrelevant to what the customer is charged.

			ZipTax_WooCommerce::log( sprintf( 'Updated tax rate ID %d to %.4f%%', $existing, $rate_pct ) );
			return (int) $existing;
		}

		// Insert the rate row using WooCommerce's own helper.
		$rate_id = WC_Tax::_insert_tax_rate( array(
			'tax_rate_country'  => $country,
			'tax_rate_state'    => $state,
			'tax_rate_name'     => self::RATE_NAME,
			'tax_rate'          => $rate_pct,
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => $ship_flag,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		) );

		// Store city and postcode in wc_tax_rate_locations.
		if ( '' !== $city_upper ) {
			WC_Tax::_update_tax_rate_cities( $rate_id, $city_upper );
		}
		if ( '' !== $zip_upper ) {
			WC_Tax::_update_tax_rate_postcodes( $rate_id, $zip_upper );
		}

		ZipTax_WooCommerce::log( sprintf(
			'Created tax rate ID %d at %.4f%% (%s/%s/%s/%s)',
			$rate_id,
			$rate_pct,
			$country,
			$state,
			$zip_upper,
			$city_upper
		) );

		WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		return (int) $rate_id;
	}

	/**
	 * Remove ZipTax-managed tax rate rows not referenced by any order.
	 *
	 * Called daily via WP-Cron and on plugin deactivation. Keeps the
	 * wc_tax_rates table clean while preserving rows that orders still
	 * reference for reporting accuracy.
	 *
	 * Supports both legacy post-based order storage and HPOS
	 * (High-Performance Order Storage / Custom Order Tables).
	 */
	public function cleanup_orphaned_rates() {
		self::delete_orphaned_rate_rows();
	}

	/**
	 * Static helper to delete orphaned ZipTax rate rows.
	 *
	 * Can be called from both the cron callback and the deactivation hook.
	 */
	public static function delete_orphaned_rate_rows() {
		global $wpdb;

		// Build the subquery for rate IDs still referenced by orders.
		// Branch based on whether HPOS (Custom Order Tables) is active.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			// HPOS: use the wc_order_tax_lookup table which stores rate_id directly.
			$referenced_subquery = "SELECT DISTINCT tax_rate_id FROM {$wpdb->prefix}wc_order_tax_lookup";
		} else {
			// Legacy: rate_id is stored as meta_value in order item meta.
			$referenced_subquery =
				"SELECT DISTINCT CAST( oim.meta_value AS UNSIGNED )
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				     ON oi.order_item_id = oim.order_item_id
				 WHERE oi.order_item_type = 'tax'
				   AND oim.meta_key = 'rate_id'";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Remove orphaned location rows first (city/postcode entries in the locations table).
		$wpdb->query( $wpdb->prepare(
			"DELETE loc FROM {$wpdb->prefix}woocommerce_tax_rate_locations loc
			 INNER JOIN {$wpdb->prefix}woocommerce_tax_rates tr
			     ON loc.tax_rate_id = tr.tax_rate_id
			 WHERE tr.tax_rate_name = %s
			   AND tr.tax_rate_id NOT IN ( {$referenced_subquery} )",
			self::RATE_NAME
		) );

		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE tr FROM {$wpdb->prefix}woocommerce_tax_rates tr
			 WHERE tr.tax_rate_name = %s
			   AND tr.tax_rate_id NOT IN ( {$referenced_subquery} )",
			self::RATE_NAME
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $deleted > 0 ) {
			ZipTax_WooCommerce::log( sprintf( 'Cleaned up %d orphaned tax rate rows.', $deleted ), 'info' );
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::invalidate_cache_group( 'taxes' );
			}
		}
	}

	// ------------------------------------------------------------------
	// Hooks: cart-load and address-change recalculation triggers
	// ------------------------------------------------------------------

	/**
	 * Tracks whether force_cart_recalculation() already ran for this
	 * request, since it is hooked to both `wp` and `template_redirect`
	 * as a belt-and-braces measure.
	 *
	 * @var bool
	 */
	private $force_recalc_done = false;

	/**
	 * Force WooCommerce to recalculate cart totals on cart and checkout
	 * page loads.
	 *
	 * WooCommerce stores cart totals in the session and on a plain cart-
	 * or checkout-page revisit it can render those stored totals without
	 * firing woocommerce_before_calculate_totals. That leaves the tax
	 * line stale (e.g. after the customer changed their My Account
	 * address, or while cache entries refresh). Forcing calculate_totals()
	 * here re-runs the full pipeline — including prefetch_rate() and
	 * inject_tax_rate() — every time the customer lands on the cart or
	 * checkout page.
	 *
	 * Detects three layouts:
	 *   1. Pages set as the WC cart or checkout page (shortcodes).
	 *   2. Any singular page that contains the woocommerce/cart or
	 *      woocommerce/checkout block — covers block-based stores that
	 *      use a custom page outside the WC page settings.
	 *   3. Block templates that include cart/checkout blocks (best-effort
	 *      via has_block on the global $post when available).
	 *
	 * AJAX, REST, and admin contexts are skipped — those paths either
	 * already trigger calculate_totals() themselves (AJAX cart fragments,
	 * Store API) or do not render the cart.
	 */
	public function force_cart_recalculation() {
		if ( $this->force_recalc_done || self::$is_calculating ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || is_admin() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$is_cart_or_checkout = false;

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$is_cart_or_checkout = true;
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$is_cart_or_checkout = true;
		} elseif ( function_exists( 'has_block' ) ) {
			// Block-based stores — page may not be the configured WC cart/checkout
			// page, but contains the cart or checkout block.
			global $post;
			if ( $post && ( has_block( 'woocommerce/cart', $post ) || has_block( 'woocommerce/checkout', $post ) ) ) {
				$is_cart_or_checkout = true;
			}
		}

		if ( ! $is_cart_or_checkout ) {
			return;
		}

		$this->force_recalc_done = true;
		WC()->cart->calculate_totals();
	}

	/**
	 * Recalculate the cart after the customer saves a new address.
	 *
	 * Fires from the My Account "Addresses" form. The new address is
	 * already stored on WC()->customer; calling calculate_totals() runs
	 * prefetch_rate() against it so the next cart/checkout view shows
	 * the correct rate immediately, without waiting for the customer
	 * to change cart contents.
	 */
	public function invalidate_and_recalculate() {
		if ( self::$is_calculating ) {
			return;
		}
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Rewrite the displayed tax label so the customer sees "Sales Tax"
	 * for rates the plugin supplied.
	 *
	 *   - Plugin-owned rows (tax_rate_name = self::RATE_NAME) are
	 *     rewritten in every context (admin and front-end).
	 *   - A merchant Reduced rate / Zero rate / custom-class row is
	 *     rewritten ONLY when it is the row applied as this request's
	 *     location-based override on the standard tax line. When the
	 *     same row matches the normal WooCommerce way — because the
	 *     product is genuinely assigned to that tax class — the
	 *     merchant's configured label (e.g. "GST") is kept.
	 *
	 * The override is only computed during front-end cart calculation,
	 * so strictly-admin renders (Tax settings, tax reports, order edit)
	 * always keep the merchant's rate name.
	 *
	 * @param string $label Current label (the DB tax_rate_name).
	 * @param mixed  $rate  Rate ID (int) or rate object passed to get_rate_label().
	 * @return string
	 */
	public function filter_rate_label( $label, $rate ) {
		if ( self::RATE_NAME === $label ) {
			return __( 'Sales Tax', 'ziptax-sales-tax' );
		}

		if ( null === $this->merchant_override ) {
			return $label;
		}

		$rate_id = is_object( $rate )
			? (int) ( isset( $rate->tax_rate_id ) ? $rate->tax_rate_id : 0 )
			: (int) $rate;

		if ( $rate_id === $this->merchant_override['id'] ) {
			return __( 'Sales Tax', 'ziptax-sales-tax' );
		}

		return $label;
	}

	// ------------------------------------------------------------------
	// Hook: woocommerce_before_calculate_totals
	// ------------------------------------------------------------------

	/**
	 * Pre-fetch the API tax rate before WooCommerce calculates totals,
	 * and refresh the plugin's Standard Rate row to match.
	 *
	 * Tax is only calculated when the shipping address is fully populated
	 * (address_1, city, state, postcode). The three-tier cache inside
	 * get_tax_rate() (memory → session → transient) prevents redundant API
	 * calls when the address is unchanged; the rate is automatically
	 * refreshed whenever the address changes.
	 *
	 * On every lookup that yields a positive API rate the plugin's
	 * Standard Rate row for the resolved jurisdiction is upserted so its
	 * stored rate stays in sync with the API. The row is uniquely keyed
	 * on Country + State + Postcode + City, so concurrent carts in
	 * different jurisdictions never overwrite each other. The row is
	 * still ignored at runtime by inject_tax_rate() whenever the
	 * merchant has configured their own rate for the location, so the
	 * upsert never overrides merchant settings — it just keeps the
	 * fallback row current.
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

		// Unsupported country — clear any rate from the current request.
		if ( ! $this->is_supported_country( $country ) ) {
			$this->clear_current_rate();
			return;
		}

		// Wait until all required shipping fields are present.
		if ( ! $this->is_address_complete( $address ) ) {
			$this->clear_current_rate();
			return;
		}

		ZipTax_WooCommerce::log( '--- Pre-fetching tax rate ---' );

		$rate_data = $this->get_tax_rate( $address, 0 );
		if ( ! $rate_data ) {
			$this->clear_current_rate();
			return;
		}

		$this->current_rate_data = $rate_data;
		$this->current_rate_id   = null;

		$sales_rate = (float) $rate_data['sales_tax_rate'];

		// Determine shipping taxability.
		$shipping_pref      = $this->get_shipping_tax_preference();
		$this->tax_shipping = false;

		if ( 'yes' === $shipping_pref ) {
			$this->tax_shipping = true;
		} elseif ( 'api' === $shipping_pref ) {
			$this->tax_shipping = ! empty( $rate_data['freight_taxable'] );
		}

		// Look up a non-standard merchant override for the customer's
		// location (Reduced rate / Zero rate / custom class). When one
		// matches, it replaces the API rate on the standard tax line at
		// inject time — regardless of which tax class the cart product
		// is in.
		$this->merchant_override = $this->find_merchant_override( $address );
		if ( $this->merchant_override ) {
			ZipTax_WooCommerce::log( sprintf(
				'Merchant override row %d (%s class, %.4f%%) will replace the API rate (%.4f%%) for this lookup',
				$this->merchant_override['id'],
				$this->merchant_override['class'],
				$this->merchant_override['rate_pct'],
				$sales_rate * 100
			) );
		}

		// At 0% there's nothing to inject and nothing useful to store.
		if ( $sales_rate <= 0 ) {
			ZipTax_WooCommerce::log( sprintf(
				'Rate ready: %.4f%% (no row, ship_tax=%s)',
				$sales_rate * 100,
				$this->tax_shipping ? 'yes' : 'no'
			) );
			return;
		}

		// Refresh the plugin's Standard Rate row with this lookup's result.
		// Prefer the geocoded jurisdiction returned by the API
		// (addressDetailExtended); fall back to the customer-entered
		// address when a component is empty.
		$api_state    = $rate_data['state']    ?? '';
		$api_city     = $rate_data['city']     ?? '';
		$api_postcode = $rate_data['postcode'] ?? '';

		$this->current_rate_id = $this->get_or_create_tax_rate_id(
			$sales_rate,
			'' !== $api_state    ? $api_state    : $address['state'],
			'' !== $api_city     ? $api_city     : $address['city'],
			'' !== $api_postcode ? $api_postcode : $address['postcode'],
			$address['country'],
			$this->tax_shipping
		);

		$this->ziptax_owned_rate_ids[ $this->current_rate_id ] = true;

		ZipTax_WooCommerce::log( sprintf(
			'Rate ready: %.4f%% (ID %d, ship_tax=%s)',
			$sales_rate * 100,
			$this->current_rate_id,
			$this->tax_shipping ? 'yes' : 'no'
		) );
	}

	/**
	 * Find a non-standard merchant tax-rate row that overrides the API
	 * rate for the customer's location.
	 *
	 * Looks for any row whose tax_rate_class is non-empty (i.e. Reduced
	 * rate, Zero rate, or any custom class) and whose Country/State/
	 * Postcode/City matches the customer's address using the same
	 * matching rules WC_Tax::find_rates() applies — country and state
	 * with `''` wildcards, postcode via wc_get_wildcard_postcodes() plus
	 * numeric range rows (e.g. 90210...90220), and city via
	 * wc_tax_rate_locations.
	 *
	 * Plugin-owned rows (tax_rate_name = self::RATE_NAME) are excluded.
	 * When multiple rows match, WooCommerce's natural priority/order
	 * applies and we take the first.
	 *
	 * @param array $address Location with country/state/postcode/city.
	 * @return array|null Row data, or null when nothing matches.
	 */
	private function find_merchant_override( array $address ) {
		$country  = strtoupper( $address['country'] ?? '' );
		$state    = strtoupper( $address['state'] ?? '' );
		$city     = strtoupper( wc_clean( $address['city'] ?? '' ) );
		$postcode = strtoupper( wc_normalize_postcode( wc_clean( $address['postcode'] ?? '' ) ) );

		$valid_postcodes = function_exists( 'wc_get_wildcard_postcodes' )
			? wc_get_wildcard_postcodes( $postcode, $country )
			: array( $postcode );

		if ( empty( $valid_postcodes ) ) {
			$valid_postcodes = array( $postcode );
		}

		global $wpdb;

		$postcode_placeholders = implode( ',', array_fill( 0, count( $valid_postcodes ), '%s' ) );

		$query_args   = array( self::RATE_NAME, $country, $state, $city );
		$query_args   = array_merge( $query_args, $valid_postcodes );

		// Postcode match: exact and wildcard patterns come from
		// wc_get_wildcard_postcodes(); WooCommerce's range syntax
		// (e.g. 90210...90220) is not expanded by that helper, so rows
		// stored as ranges are matched numerically here, the same way
		// WC_Tax::find_rates() does. Ranges only apply to numeric
		// postcodes (US ZIPs) — Canadian postal codes never match one.
		$zip_match_sql = "zip_loc.location_code IS NULL OR zip_loc.location_code IN ($postcode_placeholders)";
		if ( ctype_digit( $postcode ) ) {
			$zip_match_sql .= " OR ( zip_loc.location_code LIKE '%%...%%'
			       AND CAST( SUBSTRING_INDEX( zip_loc.location_code, '...', 1 ) AS UNSIGNED ) <= %d
			       AND CAST( SUBSTRING_INDEX( zip_loc.location_code, '...', -1 ) AS UNSIGNED ) >= %d )";
			$query_args[] = $postcode;
			$query_args[] = $postcode;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT tr.tax_rate_id,
			        tr.tax_rate,
			        tr.tax_rate_name,
			        tr.tax_rate_class,
			        tr.tax_rate_shipping,
			        tr.tax_rate_compound
			 FROM {$wpdb->prefix}woocommerce_tax_rates tr
			 LEFT JOIN {$wpdb->prefix}woocommerce_tax_rate_locations city_loc
			     ON tr.tax_rate_id = city_loc.tax_rate_id AND city_loc.location_type = 'city'
			 LEFT JOIN {$wpdb->prefix}woocommerce_tax_rate_locations zip_loc
			     ON tr.tax_rate_id = zip_loc.tax_rate_id AND zip_loc.location_type = 'postcode'
			 WHERE tr.tax_rate_name != %s
			   AND tr.tax_rate_class != ''
			   AND tr.tax_rate_country IN ('', %s)
			   AND tr.tax_rate_state   IN ('', %s)
			   AND ( city_loc.location_code IS NULL OR city_loc.location_code = %s )
			   AND ( $zip_match_sql )
			 ORDER BY tr.tax_rate_priority ASC, tr.tax_rate_order ASC
			 LIMIT 1",
			$query_args
		) );

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'       => (int) $row->tax_rate_id,
			'rate_pct' => round( (float) $row->tax_rate, 4 ),
			'label'    => (string) $row->tax_rate_name,
			'shipping' => ( '1' === (string) $row->tax_rate_shipping ) ? 'yes' : 'no',
			'compound' => ( '1' === (string) $row->tax_rate_compound ) ? 'yes' : 'no',
			'class'    => (string) $row->tax_rate_class,
		);
	}

	/**
	 * Return only the rate IDs that were NOT created by this plugin.
	 *
	 * Used in the non-standard tax class branch of inject_tax_rate() to
	 * strip plugin-owned rate rows out of WooCommerce's matched rates
	 * before deciding whether the merchant has a Reduced rate / Zero
	 * rate / custom-class row matching the customer's location.
	 *
	 * @param array $matched_tax_rates Rate map keyed by tax_rate_id.
	 * @return array Subset of $matched_tax_rates that the merchant defined.
	 */
	private function filter_to_user_defined_rates( array $matched_tax_rates ) {
		if ( empty( $matched_tax_rates ) ) {
			return array();
		}

		$ids_to_check = array();
		foreach ( array_keys( $matched_tax_rates ) as $rate_id ) {
			$rate_id = (int) $rate_id;
			if ( ! isset( $this->ziptax_owned_rate_ids[ $rate_id ] ) ) {
				$ids_to_check[] = $rate_id;
			}
		}

		if ( ! empty( $ids_to_check ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $ids_to_check ), '%d' ) );
			$query_args   = $ids_to_check;
			$query_args[] = self::RATE_NAME;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ziptax_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
				 WHERE tax_rate_id IN ($placeholders)
				   AND tax_rate_name = %s",
				$query_args
			) );

			foreach ( $ziptax_ids as $id ) {
				$this->ziptax_owned_rate_ids[ (int) $id ] = true;
			}
		}

		$user_rates = array();
		foreach ( $matched_tax_rates as $rate_id => $rate ) {
			if ( ! isset( $this->ziptax_owned_rate_ids[ (int) $rate_id ] ) ) {
				$user_rates[ $rate_id ] = $rate;
			}
		}

		return $user_rates;
	}

	// ------------------------------------------------------------------
	// Hook: woocommerce_find_rates
	// ------------------------------------------------------------------

	/**
	 * Inject the ZipTax rate into WooCommerce's native tax rate lookup.
	 *
	 * Precedence applied per find_rates() call:
	 *
	 *   1. Unsupported country: merchant-defined rates only.
	 *   2. No prefetched API data and no merchant override: merchant-
	 *      defined rates only.
	 *   3. Standard class (tax_class is '' or 'standard'):
	 *        a. If a non-standard merchant row matches the customer's
	 *           location (computed in prefetch_rate via
	 *           find_merchant_override()), that row's rate replaces the
	 *           API rate on this lookup. This covers the case where the
	 *           merchant added a Reduced rate row for US/CA/92694 or a
	 *           Zero rate row for US/TX and expects it to override the
	 *           sales tax on every product, not just on products
	 *           assigned to that tax class.
	 *        b. Otherwise the API rate wins. Merchant-added Standard
	 *           rate rows are not used at runtime — the plugin's row,
	 *           upserted from the API, is the authoritative Standard
	 *           rate.
	 *   4. Non-standard class (Reduced rate, Zero rate, custom):
	 *        a. If WC_Tax::find_rates() matched a merchant row in this
	 *           class for the customer's location, return it.
	 *        b. Otherwise inject the API rate as a fallback so products
	 *           in non-standard classes are still taxed correctly when
	 *           the merchant has not configured a class-specific row.
	 *
	 * Match granularity for the location-based override and for
	 * WC_Tax::find_rates() is identical: Country (with `''` wildcard),
	 * State (with `''` wildcard), Postcode (with wc_get_wildcard_postcodes
	 * expansion + WC range support like `90210...90220`), and City
	 * (against wc_tax_rate_locations).
	 *
	 * ZipTax-managed rows persisted in wc_tax_rates (kept for order
	 * reporting) are always stripped from WooCommerce's matched rates.
	 * They only ever apply through the explicit injection below, so a
	 * stale row from a previous lookup can never tax a request the
	 * plugin did not calculate a rate for.
	 *
	 * @param array $matched_tax_rates Existing matched rates from WC_Tax.
	 * @param array $args              Location arguments (country, state, postcode, city, tax_class).
	 * @return array
	 */
	public function inject_tax_rate( $matched_tax_rates, $args ) {
		// Strip ZipTax-managed rows so they never apply on their own —
		// they only ever reach the customer through the explicit
		// injection below.
		$user_rates = $this->filter_to_user_defined_rates( $matched_tax_rates );

		// No prefetched API data and no precomputed override — nothing to
		// inject; only merchant rates may apply.
		if ( null === $this->current_rate_data && null === $this->merchant_override ) {
			return $user_rates;
		}

		$country = $args['country'] ?? '';
		if ( ! $this->is_supported_country( $country ) ) {
			return $user_rates;
		}

		$tax_class         = $args['tax_class'] ?? '';
		$is_standard_class = ( '' === $tax_class || 'standard' === $tax_class );

		// Standard class: a location-matched non-standard merchant row
		// (Reduced rate / Zero rate / custom) replaces the API rate.
		if ( $is_standard_class && $this->merchant_override ) {
			ZipTax_WooCommerce::log( sprintf(
				'Standard tax line: applying merchant override row %d (%s class) at %.4f%% for %s/%s/%s/%s',
				$this->merchant_override['id'],
				$this->merchant_override['class'],
				$this->merchant_override['rate_pct'],
				$args['country']  ?? '',
				$args['state']    ?? '',
				$args['postcode'] ?? '',
				$args['city']     ?? ''
			) );
			return array(
				$this->merchant_override['id'] => array(
					'rate'     => $this->merchant_override['rate_pct'],
					'label'    => $this->merchant_override['label'],
					'shipping' => $this->merchant_override['shipping'],
					'compound' => $this->merchant_override['compound'],
				),
			);
		}

		// Non-standard class: defer to whatever WC matched in this class.
		// If a merchant row exists for this class+location, return it.
		// Otherwise fall through to the API rate.
		if ( ! $is_standard_class ) {
			if ( ! empty( $user_rates ) ) {
				ZipTax_WooCommerce::log( sprintf(
					'Merchant %s rate matched for %s/%s/%s/%s — using rate IDs: %s',
					$tax_class,
					$args['country']  ?? '',
					$args['state']    ?? '',
					$args['postcode'] ?? '',
					$args['city']     ?? '',
					implode( ',', array_keys( $user_rates ) )
				) );
				return $user_rates;
			}
			ZipTax_WooCommerce::log( sprintf(
				'No merchant %s rate matched for %s/%s/%s/%s — falling back to API rate',
				$tax_class,
				$args['country']  ?? '',
				$args['state']    ?? '',
				$args['postcode'] ?? '',
				$args['city']     ?? ''
			) );
			// Fall through to the API rate injection below.
		}

		// API rate fallback. Requires a prefetched plugin row with a positive rate.
		if ( null === $this->current_rate_id || null === $this->current_rate_data ) {
			return $user_rates;
		}

		$sales_rate = (float) $this->current_rate_data['sales_tax_rate'];
		if ( $sales_rate <= 0 ) {
			return $user_rates;
		}

		$rate_pct = round( $sales_rate * 100, 4 );

		return array(
			$this->current_rate_id => array(
				'rate'     => $rate_pct,
				'label'    => __( 'Sales Tax', 'ziptax-sales-tax' ),
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
	 * Only adjusts items for which Zip Tax actually supplied the rate —
	 * i.e. items whose tax class is standard AND whose calculated line
	 * tax was keyed by our injected rate ID. Items routed to a
	 * merchant-defined rate (because the customer's location has a
	 * user-configured Standard Rate, or because the product is in a
	 * non-standard tax class) are left alone.
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

		// Collect TIC codes from cart items in the standard tax class only.
		$has_tic = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product || ! $product->is_taxable() ) {
				continue;
			}
			$item_tax_class = $product->get_tax_class();
			if ( '' !== $item_tax_class && 'standard' !== $item_tax_class ) {
				continue;
			}
			$tic = (int) $product->get_meta( '_ziptax_tic_code' );
			if ( $tic > 0 ) {
				$has_tic = true;
				break;
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

				// Non-standard tax classes use merchant-defined rates as-is.
				$item_tax_class = $product->get_tax_class();
				if ( '' !== $item_tax_class && 'standard' !== $item_tax_class ) {
					continue;
				}

				// Only adjust items whose tax was actually calculated against
				// our injected rate. If a merchant-defined Standard Rate won
				// for this location, $line_tax_data is keyed by that rate ID
				// and we leave the item alone.
				$line_total_data = $cart_item['line_tax_data']['total'] ?? array();
				if ( ! isset( $line_total_data[ $rate_id ] ) ) {
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
