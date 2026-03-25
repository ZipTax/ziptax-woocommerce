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

The plugin sends your customer's full shipping (or billing) address to the Zip Tax API at checkout. The API geocodes the address and returns the exact combined sales tax rate for that location, broken down by jurisdiction (state, county, city, district). The rate is applied to the cart total and displayed as a single "Sales Tax" line item.

If a product has a Taxability Information Code (TIC) assigned, the API applies product-specific tax rules for that jurisdiction — for example, reduced rates or exemptions on clothing, food, or digital goods.

For Canadian addresses, the plugin requests GST, PST, and HST rates by province.

Shipping charges are taxed according to the API's jurisdiction-level freight taxability rules, or you can override this in the plugin settings.

## FAQ

### Where do I get an API key?

Visit [https://zip.tax](https://zip.tax) to create an account and obtain your API key.

### What API version does this plugin use?

Version 3.0 uses the Zip Tax API v60, which provides address-level geocoded tax lookups, product taxability codes, and Canadian tax support.

### Does this plugin support Canadian taxes?

Yes. When a customer's shipping or billing address is in Canada, the plugin automatically requests Canadian tax rates (GST, PST, HST) from the API. Your API key must have Canadian rate access enabled.

### What are Taxability Information Codes (TIC)?

TIC codes allow you to specify the type of product being sold so that product-specific tax rules can be applied. For example, clothing may be taxed differently than electronics in some jurisdictions. You can assign a TIC code on each product's edit screen under the General tab.

### Is this plugin compatible with WooCommerce HPOS?

Yes. The plugin declares full compatibility with WooCommerce High-Performance Order Storage.

## Changelog

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
