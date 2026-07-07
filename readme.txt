=== ZipTax Sales Tax ===
Contributors: ziptax
Tags: tax, sales tax, tax calculation, woocommerce tax, ziptax
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 3.3.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated, real-time sales tax calculation for WooCommerce powered by the Zip Tax API.

== Description ==

[Zip Tax](https://zip.tax) provides automated, real-time sales tax calculation for WooCommerce stores. Install the plugin, enter your API key, and tax rates are determined automatically for every order based on your customer's precise location.

Powered by the **Zip Tax API v60**, the plugin uses address-level geocoding to deliver the most accurate tax rates for every US and Canadian jurisdiction. Rates are updated in real time — no more manual tax tables.

= Features =

* **Address-level geocoding** — full street address lookup for the most accurate rates possible.
* **US and Canada** — supports all US states, counties, cities, and special taxing districts, plus Canadian GST / PST / HST by province.
* **Product taxability codes (TIC)** — assign a Taxability Information Code to individual products and variations for product-specific tax rules (e.g. clothing, food, digital goods).
* **Shipping taxability** — automatically determines whether shipping is taxable based on the destination jurisdiction, or lets you override.
* **Session caching** — minimises API calls by caching rates for the same address within a session.
* **WooCommerce HPOS compatible** — fully compatible with High-Performance Order Storage.
* **Debug logging** — optional debug logs viewable in WooCommerce > Status > Logs.

== Installation ==

1. Upload the `ziptax-sales-tax` folder to `wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu.
3. Go to **WooCommerce > Settings > Integration > Zip Tax** and enter your API key.
4. That's it — tax rates are now calculated automatically at checkout.

To obtain an API key, visit [https://zip.tax](https://zip.tax).

== Frequently Asked Questions ==

= Where do I get an API key? =

Visit [https://zip.tax](https://zip.tax) to create an account and obtain your API key.

= What API version does this plugin use? =

Version 3.0 uses the Zip Tax API v60, which provides address-level geocoded tax lookups, product taxability codes, and Canadian tax support.

= Does this plugin support Canadian taxes? =

Yes. When a customer's shipping address is in Canada, the plugin automatically requests Canadian tax rates (GST, PST, HST) from the API. Your API key must have Canadian rate access enabled.

= What are Taxability Information Codes (TIC)? =

TIC codes allow you to specify the type of product being sold so that product-specific tax rules can be applied. For example, clothing may be taxed differently than electronics in some jurisdictions. You can assign a TIC code on each product's edit screen under the General tab.

= Is this plugin compatible with WooCommerce HPOS? =

Yes. The plugin declares full compatibility with WooCommerce High-Performance Order Storage.

== Screenshots ==

== Changelog ==

= 3.3.1 =
* Fix: Stale Zip Tax rate rows persisted in `wc_tax_rates` (kept for order reporting) could be matched by WooCommerce on their own and apply an outdated rate — for example, taxing a location covered by a Zero Rate Rates entry. Zip Tax-managed rows are now always stripped from WooCommerce's matched rates and only apply when the plugin actively injects a freshly calculated rate.

= 3.3.0 =
* Fix: Merchant-configured tax rates now take precedence over Zip Tax. Standard Rates, Reduced Rate Rates, Zero Rate Rates, and any other rates configured under WooCommerce > Settings > Tax are no longer overwritten by the API rate.
* Change: The plugin no longer modifies WooCommerce tax options (Enable taxes, Calculate tax based on, Display, Rounding, etc.). Merchants fully own those settings.
* Change: A `wc_tax_rates` row for the Zip Tax rate is only created when no merchant rate covers the customer's location, instead of on every calculation.
* Change: TIC product adjustments are no longer applied to items in non-standard tax classes (Zero Rate, Reduced Rate, custom classes), or to items whose tax was calculated against a merchant-defined rate.

= 3.2.1 =
* Fix: Tax now recalculates correctly when the shipping address is edited or the "Ship to a different address" checkbox is toggled during checkout.

= 3.2.0 =
* Improvement: API lookups always use the full `address` parameter — postal code, city, and state are never sent as separate parameters, ensuring address-level geocoding is used in every case.
* Improvement: Address string is now comma-formatted (e.g. "200 Spectrum Center Drive, Irvine, CA 92618") for consistent geocoding.
* Improvement: Tax is always calculated against the shipping address. The "Calculate tax based on" setting (billing vs. shipping) has been removed.
* Improvement: Tax is only calculated once the complete shipping address is entered (street, city, state, and postcode are all required). Partial addresses no longer trigger API calls.

= 3.1.1 =
* Fix: Added missing translators comment for WordPress i18n compliance.
* Fix: Corrected PHPCS annotations for nonce verification and direct database queries.
* Fix: Reduced readme tags to comply with WordPress Plugin Directory limit.

= 3.0.1 =
* Fix: TIC-exempt products (0% rate) now correctly exempt instead of taxed at the general rate.
* Fix: WooCommerce tax reports now show accurate per-jurisdiction breakdowns.
* Fix: Cleanup queries use correct column names and support HPOS stores.
* Fix: Respect WooCommerce tax classes (Zero Rate, Reduced Rate) for non-TIC products.
* Fix: Improved exception safety and cache consistency for TIC lookups.

= 3.0.0 =
* Complete rewrite using Zip Tax API v60.
* Address-level geocoding for precise tax rates.
* US and Canada support.
* Product taxability codes (TIC) with per-product and per-variation fields.
* Shipping taxability determined by API or manual override.
* WooCommerce HPOS compatibility.
* WooCommerce logger integration (replaces custom log file).
* Session and transient caching for rate lookups.
* Requires PHP 7.4+, WordPress 6.0+, WooCommerce 8.0+.

= 2.0.0 =
* Internal development version.

= 1.0.0 (2016-03-01) =
* Initial release.

== Upgrade Notice ==

= 3.3.1 =
Bug fix: stale Zip Tax rate rows can no longer apply on their own, which could show sales tax for locations covered by merchant-configured Zero Rate Rates.

= 3.3.0 =
Merchant-configured WooCommerce tax rates (Standard, Reduced, Zero, etc.) now take precedence over Zip Tax. The plugin no longer modifies WooCommerce tax options or overwrites manually configured rate tables.

= 3.2.1 =
Bug fix: tax now recalculates correctly when the shipping address is changed during checkout.

= 3.2.0 =
Geocoding accuracy improvements: API now always uses full address lookup. Tax is calculated from the shipping address only and requires a complete address before displaying. The "Calculate tax based on" setting has been removed.

= 3.0.1 =
Bug fixes for TIC-exempt products, tax reporting accuracy, WooCommerce tax class support, and HPOS cleanup compatibility.

= 3.0.0 =
Major upgrade: now uses Zip Tax API v60 with address geocoding, Canada support, and product taxability codes. Requires PHP 7.4+, WordPress 6.0+, WooCommerce 8.0+.

== How It Works ==

The Zip Tax plugin sends your customer's full shipping address to the Zip Tax API at checkout. The API geocodes the address and returns the exact combined sales tax rate for that location, broken down by jurisdiction (state, county, city, district). The rate is applied to the cart total and displayed as a single "Sales Tax" line item.

If a product has a Taxability Information Code (TIC) assigned, the API applies product-specific tax rules for that jurisdiction — for example, reduced rates or exemptions on clothing, food, or digital goods.

For Canadian addresses, the plugin requests GST, PST, and HST rates by province.

Shipping charges are taxed according to the API's jurisdiction-level freight taxability rules, or you can override this in the plugin settings.
