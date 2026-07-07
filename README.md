# Zip Tax - Sales Tax for WooCommerce

Automated, real-time sales tax calculation for WooCommerce powered by the [Zip Tax](https://zip.tax) API v60.

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 8.0+ |
| PHP | 7.4+ |

## Description

Install the plugin, enter your API key, and tax rates are determined automatically for every order based on your customer's precise location. The plugin uses address-level geocoding to deliver the most accurate tax rates for every US and Canadian jurisdiction. Rates are updated in real time — no more manual tax tables.

## Features

- **Address-level geocoding** — full street address lookup for the most accurate rates possible.
- **US and Canada** — supports all US states, counties, cities, and special taxing districts, plus Canadian GST / PST / HST by province.
- **Product taxability codes (TIC)** — assign a Taxability Information Code to individual products and variations for product-specific tax rules (e.g. clothing, food, digital goods).
- **Shipping taxability** — automatically determines whether shipping is taxable based on the destination jurisdiction, or lets you override.
- **Session caching** — minimises API calls by caching rates for the same address within a session.
- **WooCommerce HPOS compatible** — fully compatible with High-Performance Order Storage.
- **Debug logging** — optional debug logs viewable in WooCommerce > Status > Logs.

## Installation

1. Upload the `ziptax-sales-tax` folder to `wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu.
3. Go to **WooCommerce > Settings > Integration > Zip Tax** and enter your API key.
4. That's it — tax rates are now calculated automatically at checkout.

To obtain an API key, visit [https://zip.tax](https://zip.tax).

## How It Works

The plugin sends your customer's full shipping address to the Zip Tax API at checkout. The API geocodes the address and returns the exact combined sales tax rate for that location, broken down by jurisdiction (state, county, city, district). The rate is applied to the cart total and displayed as a single "Sales Tax" line item.

If a product has a Taxability Information Code (TIC) assigned, the API applies product-specific tax rules for that jurisdiction — for example, reduced rates or exemptions on clothing, food, or digital goods.

For Canadian addresses, the plugin requests GST, PST, and HST rates by province.

Shipping charges are taxed according to the API's jurisdiction-level freight taxability rules, or you can override this in the plugin settings.

## FAQ

### Where do I get an API key?

Visit [https://zip.tax](https://zip.tax) to create an account and obtain your API key.

### What API version does this plugin use?

Version 3.0 uses the Zip Tax API v60, which provides address-level geocoded tax lookups, product taxability codes, and Canadian tax support.

### Does this plugin support Canadian taxes?

Yes. When a customer's shipping address is in Canada, the plugin automatically requests Canadian tax rates (GST, PST, HST) from the API. Your API key must have Canadian rate access enabled.

### What are Taxability Information Codes (TIC)?

TIC codes allow you to specify the type of product being sold so that product-specific tax rules can be applied. For example, clothing may be taxed differently than electronics in some jurisdictions. You can assign a TIC code on each product's edit screen under the General tab.

### Is this plugin compatible with WooCommerce HPOS?

Yes. The plugin declares full compatibility with WooCommerce High-Performance Order Storage.

## Changelog

### 3.3.12

- Fix: Merchant override rows stored with WooCommerce's postcode range syntax (e.g. `90210...90220`) now match the customer's location. Previously only exact and wildcard postcode entries matched during the override lookup.
- Fix: The customer-facing "Sales Tax" label rewrite now applies only to the merchant row used as the current request's location-based override. Products genuinely assigned to a Reduced rate / Zero rate / custom tax class keep the merchant's configured label (e.g. "GST").

### 3.3.11

- Maintenance release. Version bump only; no functional changes since 3.3.10.

### 3.3.10

- Change: The customer-facing tax label now reads "Sales Tax" for both the plugin's API rate and any merchant Reduced rate / Zero rate / custom-class row applied as a location-based override. Admin screens (Tax settings, tax reports, order edit) keep the merchant's chosen rate name so the configuration UI is unchanged.

### 3.3.9

- Fix: Reduced rate and Zero rate rows now correctly override the API rate on the standard tax line when their Country/State/Postcode/City matches the customer's address — even when the cart product is in the Standard tax class. Previously these merchant rows only applied to products explicitly assigned to their class, because `WC_Tax::find_rates(tax_class='standard')` does not return rows whose `tax_rate_class` is non-empty.
- Improvement: Plugin now performs a dedicated lookup for non-standard merchant rows during `prefetch_rate()` and stores the match for the request. Match granularity is identical to `WC_Tax::find_rates()`: country and state with `''` wildcards, postcode via `wc_get_wildcard_postcodes()` (so stored entries like `9021*`, `902**`, ranges like `90210...90220` all work), and city against `wc_tax_rate_locations`.

### 3.3.8

- Confirmed: Reduced rate and Zero rate rows match the customer's location at the same four levels Standard rates use — Country, State, Postcode (with WooCommerce's full wildcard and range support, e.g. `9021*`, `902**`, `90210...90220`), and City. Matching is delegated to `WC_Tax::find_rates()` so coverage is identical across every tax class.
- Improvement: Debug log now records, for every non-standard-class lookup, whether a merchant rate matched and at which Country/State/Postcode/City — visible under **WooCommerce → Status → Logs** when **Debug logging** is enabled in the Zip Tax integration settings.

### 3.3.7

- Change: Tax-rate precedence is now per-class instead of a broad backoff:
  - **Standard rates**: the API rate always wins. The plugin upserts the API value into a Standard rates row keyed by Country + State + Postcode + City; merchant-added Standard rate rows are not used at runtime.
  - **Reduced rate rates**: when a merchant Reduced rate row matches the customer's Country/State/Postcode/City, that row's rate is used. When no Reduced rate row matches, the API rate is used as the fallback.
  - **Zero rate rates / custom classes**: same pattern as Reduced rate — merchant row when matched, API rate when not.
- Fix: Reduced rate rates now consistently override the API rate the same way Zero rate rates do.

### 3.3.6

- Fix: Sales Tax line is restored on cart and checkout. The 3.3.5 change that invalidated WooCommerce's "taxes" cache on every rate-row update broke rate-id continuity inside `calculate_totals()` and caused the tax line to disappear. Reverted that change; the freshness it was protecting against is already handled because `inject_tax_rate()` overrides the rate value with the latest API result regardless of WC's cached rate value.

### 3.3.5

- Fix: Cart and checkout pages built with the WooCommerce Cart and Checkout blocks now also trigger a forced tax recalculation on page load, not just pages set as the WC cart/checkout page in the WooCommerce page settings.
- Fix: Forced recalculation is also hooked on `template_redirect` (in addition to `wp`), so it runs reliably under themes whose template loading skips one or the other.
- Fix: WooCommerce's `taxes` cache group is now invalidated whenever the plugin's existing rate row is updated with a new API value — previously it was only invalidated on row insert, so changes to an existing row's rate were not picked up by `WC_Tax::find_rates()` until the cache expired.

### 3.3.4

- Change: The cart and checkout tax line for plugin-managed rates now reads "Sales Tax" instead of the internal "ZipTax Sales Tax" marker. The DB row name is unchanged so the precedence logic that distinguishes plugin rows from merchant rows still works.

### 3.3.3

- Fix: Sales tax is now recalculated on every cart and checkout page load, not just when the cart contents change. WooCommerce was serving session-cached totals on plain page revisits without firing the tax pipeline, which left the tax line stale.
- Fix: Sales tax is recalculated immediately after the customer saves a new shipping or billing address from the My Account > Addresses form.
- Confirmed: Reduced rate rates configured under **WooCommerce > Settings > Tax > Reduced rate rates** take precedence over the Zip Tax rate for matching locations, matching the existing behavior for Zero rate rates and Standard rates.

### 3.3.2

- Change: The plugin's Standard Rate row is now refreshed on every API lookup so its stored rate stays in sync with the latest API result. The row is still ignored at runtime whenever the merchant has configured their own rate for the location, so this never overrides merchant settings — it just keeps the fallback row current.
- Fix: Zero rate rates (and Reduced rate / custom-class rates) now correctly suppress the Zip Tax rate for any location they cover. Any merchant-configured rate row that matches the customer's country/state/postcode/city causes Zip Tax to back off, regardless of the cart product's tax class.
- Fix: Plugin-managed rate rows are now unique by Country + State + Postcode + City. Previously two concurrent carts in different ZIPs of the same city could overwrite each other's rate row.
- Improvement: Postcode is now stored in `wc_tax_rate_locations` alongside city for plugin-created rate rows.

### 3.3.1

- Fix: Stale Zip Tax rate rows persisted in `wc_tax_rates` (kept for order reporting) could be matched by WooCommerce on their own and apply an outdated rate — for example, taxing a location covered by a Zero Rate Rates entry. Zip Tax-managed rows are now always stripped from WooCommerce's matched rates and only apply when the plugin actively injects a freshly calculated rate.
- Improvement: API lookups now request `addressDetailExtended`, so the geocoded city, county, state, and postal code returned by the API are used to key rate rows to the exact jurisdiction matched. Previously the extended address block was never returned, and rate rows could be created without state or city location data, causing them to match unrelated locations.

### 3.3.0

- Fix: Merchant-configured tax rates now take precedence over Zip Tax. Standard Rates, Reduced Rate Rates, Zero Rate Rates, and any other rates configured under **WooCommerce > Settings > Tax** are no longer overwritten by the API rate.
- Change: The plugin no longer modifies WooCommerce tax options (Enable taxes, Calculate tax based on, Display, Rounding, etc.). Merchants fully own those settings.
- Change: A `wc_tax_rates` row for the Zip Tax rate is only created when no merchant rate covers the customer's location, instead of on every calculation.
- Change: TIC product adjustments are no longer applied to items in non-standard tax classes (Zero Rate, Reduced Rate, custom classes), or to items whose tax was calculated against a merchant-defined rate.

### 3.2.1

- Fix: Tax now recalculates correctly when the shipping address is edited or the "Ship to a different address" checkbox is toggled during checkout.

### 3.2.0

- Improvement: API lookups always use the full `address` parameter — postal code, city, and state are never sent as separate parameters, ensuring address-level geocoding is used in every case.
- Improvement: Address string is now comma-formatted (`200 Spectrum Center Drive, Irvine, CA 92618`) for consistent geocoding.
- Improvement: Tax is always calculated against the shipping address. The "Calculate tax based on" setting (billing vs. shipping) has been removed.
- Improvement: Tax is only calculated once the complete shipping address is entered (street, city, state, and postcode are all required). Partial addresses no longer trigger API calls.

### 3.1.1

- Fix: Added missing translators comment for WordPress i18n compliance.
- Fix: Corrected PHPCS annotations for nonce verification and direct database queries.
- Fix: Reduced readme tags to comply with WordPress Plugin Directory limit.

### 3.0.1

- Fix: TIC-exempt products (0% rate) now correctly exempt instead of taxed at the general rate.
- Fix: WooCommerce tax reports now show accurate per-jurisdiction breakdowns.
- Fix: Cleanup queries use correct column names and support HPOS stores.
- Fix: Respect WooCommerce tax classes (Zero Rate, Reduced Rate) for non-TIC products.
- Fix: Improved exception safety and cache consistency for TIC lookups.

### 3.0.0

- Complete rewrite using Zip Tax API v60.
- Address-level geocoding for precise tax rates.
- US and Canada support.
- Product taxability codes (TIC) with per-product and per-variation fields.
- Shipping taxability determined by API or manual override.
- WooCommerce HPOS compatibility.
- WooCommerce logger integration (replaces custom log file).
- Session and transient caching for rate lookups.
- Requires PHP 7.4+, WordPress 6.0+, WooCommerce 8.0+.

### 2.0.0

- Internal development version.

### 1.0.0 (2016-03-01)

- Initial release.

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
