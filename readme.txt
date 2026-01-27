=== HgE: Shipping Zones for FAN Courier Romania ===
Contributors: hge321
Donate link: https://www.linkedin.com/in/hurubarugeorgesemanuel/
Tags: shipping zones, romania, fan courier, woocommerce, awb
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standard FAN Courier integration for WooCommerce with automatic AWB generation, PDF labels, real-time tracking, and dynamic shipping rates.

== Description ==

**HgE: Shipping Zones for FAN Courier Romania** is a professional WordPress plugin that provides seamless integration between WooCommerce and FAN Courier shipping services in Romania. Generate AWB labels automatically, calculate shipping costs in real-time, and track packages directly from your WordPress admin.

= Key Features =

* **Automatic AWB Generation** - Create shipping labels with one click or automatically when orders reach "Processing" status
* **Real-time Shipping Rates** - Calculate accurate shipping costs based on destination, weight, and package dimensions
* **PDF Label Download** - Download A4 format shipping labels ready for printing
* **Package Tracking** - Automatic status synchronization with FAN Courier tracking system
* **Bulk Operations** - Generate AWBs for multiple orders at once
* **Cash on Delivery (COD)** - Full support for COD payments with bank account integration
* **Flexible Pricing** - Choose between dynamic API pricing or fixed rates for different regions
* **WooCommerce Shipping Zones** - Complete integration with WooCommerce shipping zones system
* **Order Management** - Manage AWBs directly from order edit page with action buttons
* **Health Check Dashboard** - Diagnostic tools to monitor API connectivity and system health
* **HPOS Compatible** - Full support for WooCommerce High-Performance Order Storage (HPOS)
* **Action Scheduler** - Asynchronous task processing for better performance on high-traffic sites
* **Detailed Logging** - Debug mode with comprehensive logs for troubleshooting

= Shipping Methods Included =

* **FAN Courier Standard** - Classic home/office delivery service

= Perfect For =

* Online stores shipping within Romania
* E-commerce businesses using COD payment method
* WooCommerce shops requiring automatic AWB generation
* Businesses needing real-time shipping cost calculation
* Multi-vendor marketplaces using FAN Courier services

= Technical Features =

* REST API integration with FAN Courier eCommerce platform
* JWT authentication with automatic token refresh
* Rate limiting and retry logic for API stability
* Idempotency keys to prevent duplicate AWB generation
* Transient-based locking mechanism for concurrent operations
* Complete AJAX support for seamless admin experience
* WordPress coding standards compliant
* Security hardened with nonce verification and capability checks

= Requirements =

* WordPress 5.0 or higher
* WooCommerce 3.0 or higher
* PHP 8.1 or higher
* MySQL 5.6 or higher
* Active FAN Courier account with API credentials
* SSL certificate recommended for secure API communication

= Language Support =

* Romanian (primary)
* English (interface elements)

= Developer Friendly =

* Clean, well-documented code
* WordPress hooks and filters
* Action Scheduler integration
* Extensible architecture
* PSR-compliant coding style

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Search for "FAN Courier Shipping Zones for Romania"
4. Click **Install Now** and then **Activate**
5. Go to **WooCommerce > Settings > Fan Courier** to configure

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to WordPress admin panel
3. Navigate to **Plugins > Add New > Upload Plugin**
4. Choose the downloaded ZIP file and click **Install Now**
5. Click **Activate Plugin**
6. Go to **WooCommerce > Settings > Fan Courier** to configure

= Configuration Steps =

1. **Get API Credentials**
   * Log in to FAN Courier selfAWB platform
   * Obtain your Username, Password, and Client ID

2. **Configure Plugin Settings**
   * Navigate to **WooCommerce > Settings > Fan Courier**
   * Enter your FAN Courier credentials (Username, Password, Client ID)
   * Configure sender information (auto-populated from WooCommerce settings)
   * Set COD options if using Cash on Delivery
   * Choose between dynamic or fixed shipping rates

3. **Add Shipping Method to Zones**
   * Go to **WooCommerce > Settings > Shipping > Shipping Zones**
   * Select your shipping zone or create a new one
   * Click **Add shipping method**
   * Select **Fan Courier Standard** from the dropdown
   * Configure method settings (title, pricing, free shipping threshold)

4. **Test Configuration**
   * Navigate to **WooCommerce > Fan Courier Healthcheck**
   * Click **Ping API** to test connectivity
   * Click **Test API Credentials** to verify authentication
   * Review environment information and settings

5. **Start Processing Orders**
   * Process orders normally through WooCommerce
   * Generate AWBs from order edit page or automatically
   * Download PDF labels and track shipments

== Frequently Asked Questions ==

= Do I need a FAN Courier account? =

Yes, you need an active FAN Courier Romania business account with API access credentials. Contact FAN Courier Romania to set up your account.

= What are the API credentials I need? =

You need three credentials:
* **Username** - Your selfAWB platform username
* **Password** - Your selfAWB platform password
* **Client ID** - Your FAN Courier client identification code

= Can I generate AWBs automatically? =

Yes. Only in the Pro version of this plugin you can choose multiple Order Statuses in order to generate AWBs automatically

= Does the plugin support Cash on Delivery (COD)? =

Yes, the plugin fully supports COD with options to:
* Include/exclude shipping costs in COD amount
* Specify bank account IBAN for COD collection. The FAN Courier API doesn't allow us to pass the account IBAN for COD collection. You need to ask FAN Courier to set this at account level if it is different from the account in your contract
* Choose document type (Invoice or Receipt)
* Configure COD payment at destination

= How are shipping costs calculated? =

You can choose between:
* **Dynamic Pricing** - Real-time calculation via the FAN Courier API based on destination, weight, and the dimensions you set on the product itself. Without weight and dimensions, no delivery option will be available for your clients in the Cart
* **Fixed Pricing** - Set your own rates for Bucharest/Ilfov and rest of country

= Can I offer free shipping? =

Yes. In **WooCommerce > Settings > Shipping**, in the Shipping Methods, you have a new method available: Fan Courier – Standard, where you can configure a minimum order value for free shipping.

= What happens if AWB generation fails? =

The plugin includes:
* Automatic retry mechanism with exponential backoff
* Detailed error logging for troubleshooting
* Manual retry option from order page
* Idempotency protection to prevent duplicates

= Can I delete an AWB after creation? =

No, you can’t delete an AWB from the order page. You can delete it from the SelfAWB platform on the date it was created. After you delete it, the old AWB is removed from the order page and you can generate a new one.

= Is the plugin compatible with HPOS? =

Yes, the plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

= Does it work on multisite? =

The plugin is designed for single-site installations. Multisite compatibility has not been tested.

= Where can I see debug logs? =

Enable "Debug log" in plugin settings, then view logs at:
**WooCommerce > Status > Logs** and select source `woo-fancourier`

= Can I use this with other shipping plugins? =

Yes, FAN Courier Shipping Zones for Romania works alongside other WooCommerce shipping plugins.

= What if I have multiple sender locations? =

Currently, the plugin supports a single sender location configured in settings. Future versions may support multiple locations.

= Is there support for locker delivery? =

Currently, only Standard home/office delivery is supported. All other FAN Courier services such as Red Code, White Products, Locker, and Collect Point delivery will be available in the Pro version of this plugin.

== Screenshots ==

1. Plugin settings page with API configuration and sender information
2. Shipping method configuration in WooCommerce shipping zones
3. Order edit page with AWB generation buttons and tracking info
4. Health Check dashboard with diagnostic tools and system information
5. Bulk AWB generation from orders list
6. AWB history and tracking status in order details

== Changelog ==
= 1.0.6 - 2026-01-27 =
* Added: Formal HPOS (High-Performance Order Storage) compatibility declaration
* Fixed: AWB history not saving correctly when HPOS is enabled
* Fixed: Order meta data caching issues with HPOS
* Improved: `log_awb_action()` now clears order cache before reading to ensure fresh data
* Improved: All AWB read operations use helper functions for legacy meta key compatibility
* Compatibility: Tested with WooCommerce 9.7 HPOS mode

= 1.0.5 - 2025-12-07 =
* Enhanced: Logger class now supports all PSR-3 log levels (emergency, alert, critical, error, warning, notice, info, debug)
* Added: New logging methods - `warning()`, `critical()`, `alert()`, `emergency()`, `notice()`, `debug()`, `info()`
* Improved: Centralized sensitive data sanitization in Logger (passwords, API keys, tokens, secrets)
* Improved: `warning()` and `error()` levels are always logged regardless of debug setting
* Code Quality: Better code organization with private helper methods in Logger class
* Documentation: Added comprehensive PHPDoc comments to Logger class

= 1.0.4 - 2025-12-07 =
* Code Quality: Standardized all meta keys to use `_hgezlpfcr_` prefix
* Backward Compatibility: Added fallback reading for legacy `_fc_` meta keys
* Added: Helper methods `get_awb_number()`, `get_awb_status()`, `get_awb_history()`, `get_awb_date()` with dual-key support
* Added: Transient constants for AWB locks and cache with proper prefixing
* Improved: Healthcheck cleans both new and legacy lock transients
* Security: All new orders use standardized prefixed keys

= 1.0.3 - 2024-10-22 =
* More detailed logging for debugging through comprehensive error handling
* Full synchronization between Standard and PRO plugin
* Romanian translation

= 1.0.2 - 2024-10-18 =
* Security: Fixed XSS vulnerability in cookie handling
* Security: Improved SQL query sanitization in lock cleanup
* Security: Enhanced data validation for all user inputs
* Removed: FANBox locker delivery functionality (Standard delivery only)
* Fixed: Debug mode forced to disabled in production
* Improved: Better error handling for API timeouts
* Improved: Enhanced logging with sensitive data masking
* Updated: WordPress 6.7 compatibility tested
* Updated: WooCommerce 9.7 compatibility tested

= 1.0.1 - 2024-09-15 =
* Added: Automatic AWB generation on order status change
* Added: Action Scheduler integration for async operations
* Added: Health Check dashboard with diagnostic tools
* Added: AWB deletion verification with FAN Courier API
* Improved: Retry logic with exponential backoff
* Improved: Better error messages and logging
* Fixed: Timezone issues with AWB generation date
* Fixed: Meta data persistence in HPOS mode

= 1.0.0 - 2024-08-01 =
* Initial release
* FAN Courier Standard shipping method
* Automatic AWB generation
* PDF label download
* Real-time tracking synchronization
* Dynamic and fixed shipping rates
* COD support with bank account integration
* WooCommerce Shipping Zones integration
* AJAX-powered admin interface
* Comprehensive logging system

== Upgrade Notice ==

= 1.0.6 =
Critical HPOS compatibility fix. If you're using WooCommerce with HPOS enabled, this update ensures AWB history saves correctly. Required for HgE PRO plugin v2.0.8+.

= 1.0.5 =
Logger enhancement with PSR-3 compatible log levels. Enables better debugging and monitoring. Fully backward compatible.

= 1.0.4 =
Code quality update with standardized meta key prefixes. Backward compatible - existing orders with legacy `_fc_` meta keys will continue to work.

= 1.0.2 =
Important security update. Please update immediately. This version fixes XSS and SQL injection vulnerabilities. FANBox functionality has been removed - plugin now supports Standard delivery only.

= 1.0.1 =
Recommended update with improved AWB management, automatic generation, and diagnostic tools.

= 1.0.0 =
First stable release.

== Additional Information ==

= Credits =

* Developed by Hurubaru George Emanuel
* FAN Courier API integration
* Built for the Romanian e-commerce community

= Support =

For support requests, please use the WordPress.org support forum for this plugin.

= Privacy Policy =

This plugin:
* Sends order data (customer name, address, phone, order value) to FAN Courier API for AWB generation
* Stores API credentials securely in WordPress database
* Does not collect or transmit data to third parties except FAN Courier
* Logs IP addresses for security purposes (can be disabled)
* Does not use cookies on the frontend

= External Services =

This plugin relies on external FAN Courier API services to provide shipping functionality. Data is transmitted to these third-party services as described below:

**FAN Courier eCommerce API** (`https://ecommerce.fancourier.ro/`)

Used for:
* Authentication and authorization token generation
* Real-time shipping rate calculation based on destination and package details
* Service availability checking for specific locations

Data sent:
* Authentication: `/authShop` - Client credentials (username, password, client ID), website domain
* Rate calculation: `/get-tariff` - Destination address, package weight, dimensions, declared value, website domain
* Service availability: `/check-service` - Destination locality, website domain

When data is sent:
* During plugin configuration (credential verification)
* When customers view cart/checkout pages (shipping rate calculation)
* When checking service availability for customer addresses

**FAN Courier REST API** (`https://api.fancourier.ro/`)

Used for:
* JWT authentication for API access
* AWB (shipping label) generation and management
* PDF label generation for printing
* Real-time package tracking and status updates
* AWB reports and history

Data sent:
* Authentication: `/login` - Client credentials (username, password)
* AWB generation: `/intern-awb` - Sender details (name, address, contact), recipient details (name, address, phone), package details (weight, dimensions, contents), payment information (COD amount, bank account), delivery instructions
* Label download: `/awb/label` - AWB number, client ID
* Tracking: `/reports/awb/tracking` - AWB number, client ID
* AWB reports: `/reports/awb` - Client ID, date range for reports

When data is sent:
* During plugin configuration (credential verification)
* When store admin generates AWB labels (manual or automatic)
* When downloading PDF labels for shipping
* During automated tracking synchronization (scheduled background task)
* When viewing AWB history and reports

**Legal Information**

By using this plugin, you acknowledge that customer and order data will be transmitted to FAN Courier's servers for shipping purposes. You are responsible for ensuring compliance with applicable data protection regulations (GDPR, etc.) and obtaining necessary customer consent.

* FAN Courier General Terms and Conditions: https://www.fancourier.ro/conditii-generale-privind-furnizarea-serviciilor-postale/
* FAN Courier Privacy Policy: https://www.fancourier.ro/politica-de-confidentialitate/
* FAN Courier Personal Data Processing Policy: https://www.fancourier.ro/politica-de-prelucrare-a-datelor-cu-caracter-personal/

**Data Security**

* All API communications use HTTPS encryption
* API credentials are stored securely in WordPress database
* No customer data is stored on third-party servers beyond what's necessary for shipping operations
* You can enable debug logging to monitor all API communications (disable in production for security)

= Roadmap =

Planned features for future releases:
* Multiple sender locations
* Return AWB generation
* Pickup scheduling
* Express delivery options
* Bulk tracking updates
* Custom email notifications with tracking links
* Integration with popular page builders

= Contributing =

This plugin is open source. Contributions are welcome via GitHub.

== License ==

This plugin is licensed under GPLv2 or later.

Copyright (C) 2024-2026 Hurubaru George Emanuel

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
