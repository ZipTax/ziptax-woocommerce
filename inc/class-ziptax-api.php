<?php
/**
 * ZipTax API v60 client.
 *
 * Handles all communication with the Zip Tax API including
 * address-based geocoded lookups, postal-code fallbacks,
 * US and Canada support, and TIC product taxability codes.
 *
 * @package ZipTax_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class ZipTax_API {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key The Zip Tax API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Look up tax rates by full address (geocoded).
	 *
	 * Builds an address string from components and sends to v60.
	 * Falls back to postal code lookup if address is insufficient.
	 *
	 * @param array $address {
	 *     Address components.
	 *
	 *     @type string $address_1   Street address line 1.
	 *     @type string $address_2   Street address line 2.
	 *     @type string $city        City name.
	 *     @type string $state       State or province code.
	 *     @type string $postcode    Postal / ZIP code.
	 *     @type string $country     Two-letter country code (US or CA).
	 * }
	 * @param int    $taxability_code Optional TIC code for product-specific rates.
	 * @return array|WP_Error Parsed API response or error.
	 */
	public function lookup_by_address( array $address, $taxability_code = 0 ) {
		$parts = array_filter( array(
			$address['address_1'] ?? '',
			$address['address_2'] ?? '',
			$address['city']      ?? '',
			$address['state']     ?? '',
			$address['postcode']  ?? '',
		) );

		$address_string = implode( ' ', $parts );

		// If we don't have at least a city or postal code, bail.
		if ( empty( $address['city'] ) && empty( $address['postcode'] ) ) {
			return new WP_Error( 'ziptax_incomplete_address', __( 'Address is incomplete for tax lookup.', 'ziptax-woocommerce' ) );
		}

		$params = array(
			'key'    => $this->api_key,
			'format' => 'json',
		);

		// Use full address string if we have a street address for best accuracy.
		if ( ! empty( $address['address_1'] ) ) {
			$params['address'] = $address_string;
		} else {
			// Fall back to individual components.
			if ( ! empty( $address['postcode'] ) ) {
				$params['postalcode'] = $address['postcode'];
			}
			if ( ! empty( $address['city'] ) ) {
				$params['city'] = $address['city'];
			}
			if ( ! empty( $address['state'] ) ) {
				$params['state'] = $address['state'];
			}
		}

		// Country support.
		$country = strtoupper( $address['country'] ?? 'US' );
		if ( 'CA' === $country ) {
			$params['countryCode'] = 'CAN';
		}
		// USA is the default, no need to send explicitly.

		// Product taxability code.
		if ( $taxability_code > 0 ) {
			$params['taxabilityCode'] = (int) $taxability_code;
		}

		return $this->request( $params );
	}

	/**
	 * Simple postal code lookup (no geocoding required).
	 *
	 * @param string $postcode    ZIP / postal code.
	 * @param string $country     Two-letter country code (US or CA).
	 * @param int    $taxability_code Optional TIC code.
	 * @return array|WP_Error Parsed API response or error.
	 */
	public function lookup_by_postcode( $postcode, $country = 'US', $taxability_code = 0 ) {
		$params = array(
			'key'        => $this->api_key,
			'postalcode' => $postcode,
			'format'     => 'json',
		);

		if ( 'CA' === strtoupper( $country ) ) {
			$params['countryCode'] = 'CAN';
		}

		if ( $taxability_code > 0 ) {
			$params['taxabilityCode'] = (int) $taxability_code;
		}

		return $this->request( $params );
	}

	/**
	 * Execute the HTTP request to the Zip Tax API.
	 *
	 * @param array $params Query parameters.
	 * @return array|WP_Error Parsed response array or WP_Error on failure.
	 */
	private function request( array $params ) {
		$url = add_query_arg( $params, ZIPTAX_API_BASE );

		$user_agent = sprintf(
			'ZipTaxWooCommerce/%s WordPress/%s WooCommerce/%s',
			ZIPTAX_VERSION,
			get_bloginfo( 'version' ),
			defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown'
		);

		ZipTax_WooCommerce::log( 'API request: ' . preg_replace( '/key=[^&]+/', 'key=***', $url ) );

		$response = wp_remote_get( $url, array(
			'timeout'    => 15,
			'user-agent' => $user_agent,
			'headers'    => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			ZipTax_WooCommerce::log( 'API HTTP error: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			ZipTax_WooCommerce::log( 'API response parse error: ' . json_last_error_msg(), 'error' );
			return new WP_Error( 'ziptax_json_error', __( 'Invalid response from Zip Tax API.', 'ziptax-woocommerce' ) );
		}

		// Check for API-level errors.
		// v60 has two response shapes:
		//   - Address/geo lookups:    metadata.response.code (100 = success)
		//   - Postal-code-only:       rCode at top level (100 = success)
		$api_code    = null;
		$api_message = 'Unknown API error';

		if ( isset( $data['metadata']['response']['code'] ) ) {
			$api_code    = (int) $data['metadata']['response']['code'];
			$api_message = $data['metadata']['response']['message'] ?? $api_message;
		} elseif ( isset( $data['rCode'] ) ) {
			$api_code    = (int) $data['rCode'];
			$api_message = $data['rMessage'] ?? $api_message;
		}

		if ( null !== $api_code && 100 !== $api_code ) {
			ZipTax_WooCommerce::log( sprintf( 'API error %d: %s (HTTP %d)', $api_code, $api_message, $http_code ), 'error' );
			return new WP_Error(
				'ziptax_api_error',
				$api_message,
				array( 'code' => $api_code, 'http_status' => $http_code )
			);
		}

		// Also treat non-2xx HTTP status as an error even if no recognizable code.
		if ( $http_code >= 400 ) {
			ZipTax_WooCommerce::log( sprintf( 'API HTTP error %d', $http_code ), 'error' );
			return new WP_Error(
				'ziptax_http_error',
				sprintf( __( 'Zip Tax API returned HTTP %d.', 'ziptax-woocommerce' ), $http_code ),
				array( 'http_status' => $http_code )
			);
		}

		ZipTax_WooCommerce::log( 'API success, HTTP ' . $http_code );

		return $this->normalize_response( $data );
	}

	/**
	 * Normalize the v60 response into a simple structure for the tax handler.
	 *
	 * @param array $data Raw decoded JSON from v60.
	 * @return array {
	 *     @type float  $sales_tax_rate   Combined sales tax rate (decimal).
	 *     @type float  $use_tax_rate     Combined use tax rate (decimal).
	 *     @type bool   $freight_taxable  Whether shipping/freight is taxable.
	 *     @type bool   $service_taxable  Whether services are taxable.
	 *     @type string $sourcing         "D" for destination, "O" for origin.
	 *     @type string $city             City from address detail.
	 *     @type string $state            State from address detail.
	 *     @type string $postcode         Postal code from address detail.
	 *     @type string $country          Country code.
	 *     @type array  $base_rates       Individual jurisdiction rate components.
	 *     @type array  $product_detail   Product-specific TIC detail (if requested).
	 *     @type array  $raw              Full raw API response.
	 * }
	 */
	private function normalize_response( array $data ) {
		$result = array(
			'sales_tax_rate'  => 0.0,
			'use_tax_rate'    => 0.0,
			'freight_taxable' => false,
			'service_taxable' => false,
			'sourcing'        => 'D',
			'city'            => '',
			'state'           => '',
			'postcode'        => '',
			'country'         => 'US',
			'base_rates'      => array(),
			'product_detail'  => array(),
			'raw'             => $data,
		);

		// v60 response: check if this is a v50-style multi-result (postalcode-only)
		// or a standard v60 response.
		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			// Postalcode-only lookup returns v50-style results array.
			return $this->normalize_v50_results( $data, $result );
		}

		// Standard v60 hierarchical response.

		// Tax summaries — extract the sales and use tax combined rates.
		if ( isset( $data['taxSummaries'] ) && is_array( $data['taxSummaries'] ) ) {
			foreach ( $data['taxSummaries'] as $summary ) {
				$type = $summary['taxType'] ?? '';
				$rate = (float) ( $summary['rate'] ?? 0.0 );

				if ( 'SALES_TAX' === $type ) {
					$result['sales_tax_rate'] = $rate;
				} elseif ( 'USE_TAX' === $type ) {
					$result['use_tax_rate'] = $rate;
				}
			}
		}

		// Base rates.
		if ( isset( $data['baseRates'] ) && is_array( $data['baseRates'] ) ) {
			$result['base_rates'] = $data['baseRates'];
		}

		// Shipping / freight taxability.
		if ( isset( $data['shipping']['taxable'] ) ) {
			$result['freight_taxable'] = ( 'Y' === $data['shipping']['taxable'] );
		}

		// Service taxability.
		if ( isset( $data['service']['taxable'] ) ) {
			$result['service_taxable'] = ( 'Y' === $data['service']['taxable'] );
		}

		// Sourcing rules.
		if ( isset( $data['sourcingRules']['value'] ) ) {
			$result['sourcing'] = $data['sourcingRules']['value'];
		}

		// Address detail.
		if ( isset( $data['addressDetail'] ) ) {
			$ad = $data['addressDetail'];
			$result['city']     = $ad['address']['city']      ?? '';
			$result['state']    = $ad['address']['stateCode'] ?? '';
			$result['postcode'] = $ad['address']['postalCode'] ?? '';

			$country_code = $ad['address']['countryCode'] ?? 'USA';
			$result['country'] = ( 'CAN' === $country_code ) ? 'CA' : 'US';

			// Fallback: if nested address block is missing, use top-level fields.
			if ( empty( $result['city'] ) && ! empty( $ad['normalizedAddress'] ) ) {
				$result['normalized_address'] = $ad['normalizedAddress'];
			}
		}

		// Product detail (TIC).
		if ( isset( $data['productDetail'] ) ) {
			$result['product_detail'] = $data['productDetail'];

			// When a TIC code is present, the taxSummaries already contains
			// the correct combined rate accounting for product-specific rules.
			// We store it as product_tax_rate so the tax handler can use it.
			if ( $result['sales_tax_rate'] > 0.0 ) {
				$result['product_tax_rate'] = $result['sales_tax_rate'];
			}
		}

		return $result;
	}

	/**
	 * Normalize v50-style multi-result response (postalcode-only lookups).
	 *
	 * When only a postal code is sent, v60 may return multiple results
	 * in a v50-compatible format. We pick the first result.
	 *
	 * @param array $data   Raw API response.
	 * @param array $result Base result template.
	 * @return array Normalized result.
	 */
	private function normalize_v50_results( array $data, array $result ) {
		$results = $data['results'] ?? array();

		if ( empty( $results ) ) {
			return $result;
		}

		// Use the first result as the default.
		$item = $results[0];

		$result['sales_tax_rate']  = (float) ( $item['taxSales'] ?? 0.0 );
		$result['use_tax_rate']    = (float) ( $item['taxUse'] ?? 0.0 );
		$result['freight_taxable'] = ( 'Y' === ( $item['txbFreight'] ?? 'N' ) );
		$result['service_taxable'] = ( 'Y' === ( $item['txbService'] ?? 'N' ) );
		$result['city']            = $item['geoCity'] ?? '';
		$result['state']           = $item['geoState'] ?? '';
		$result['postcode']        = $item['geoPostalCode'] ?? '';
		$result['country']         = $item['geoCountry'] ?? 'US';

		return $result;
	}
}
