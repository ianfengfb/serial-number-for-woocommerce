# Serial Number for WooCommerce

A commercial WooCommerce extension for assigning and managing serial numbers
on products/orders. Distributed as a free (Lite) tier plus a paid Pro tier.

## Free/Pro architecture rules

- **Single codebase, gated by `Licensing::is_pro_active()`.** Every class that
  implements a Pro-only feature must check this before doing anything, and
  must live under the `SerialNumberForWooCommerce\Pro\` namespace
  (`includes/Pro/`). Free-tier code lives directly under
  `SerialNumberForWooCommerce\` (`includes/`).
- **Never build a Pro feature unlocked-by-default in the shipped code.**
  Gate it from the moment it's created — don't build features free first and
  retrofit gating later.
- **Local dev unlock:** define `SNW_DEV_UNLOCK_ALL` as `true` in `wp-config.php`
  to make `Licensing::is_pro_active()` return `true` everywhere, so every Pro
  feature can be exercised during development without a real license. This
  constant must never ship enabled and is not read from anywhere but
  `Licensing`.
- **Release packaging (when we get there):** the free/Lite zip is built by
  excluding `includes/Pro/` from the package; the Pro zip includes everything
  plus a real license-activation flow (licensing SDK/service TBD — e.g.
  Freemius or EDD Software Licensing). `Licensing::is_pro_active()` is the
  only integration point that will need to change to talk to a real license
  check — nothing else in the codebase should.

## Structure

```
serial-number-for-woocommerce.php   Plugin bootstrap: header, constants, WC dependency check, HPOS compat
composer.json                       PSR-4 autoload (SerialNumberForWooCommerce\ -> includes/)
includes/
  Plugin.php                        Singleton; wires up free + Pro features on init()
  Licensing.php                     is_pro_active() gate (see above)
  Install.php                       register_activation_hook target; creates/upgrades DB tables via dbDelta
  Admin/Menu.php                    Free tier: WooCommerce > Serial Numbers admin page (list <-> add-new routing)
  Admin/SerialNumbers/
    Repository.php                  $wpdb CRUD/search against the snw_serial_numbers table
    ListTable.php                   WP_List_Table: search + paginated list of serial numbers
    FormController.php              Add New form render + validation + save
    Ajax.php                        wp_ajax_snw_search_products / snw_search_orders (selectWoo AJAX search)
  Pro/                              Reserved for Pro-only classes (empty for now)
assets/js/admin.js                  Enqueued only on the Serial Numbers screen; inits select2 AJAX search
assets/vendor/select2/              Vendored select2 (JS+CSS) — bundled rather than relying on WooCommerce's
                                     own select2/selectWoo asset handles, which aren't guaranteed to be
                                     registered/enqueued on a third-party admin page across WC versions
```

## Data model

- `{$wpdb->prefix}snw_serial_numbers` (created in `Install::activate()`): `id`,
  `serial_number` (unique), `status`, `product_id` (nullable), `order_id`
  (nullable), `created_at`, `expires_at` (nullable). Product/order columns
  store IDs only — always resolve via `wc_get_product()` / `wc_get_order()`
  rather than joining WC's tables directly, so this keeps working under HPOS.
- Status values live in `FormController::STATUSES` (currently `active`,
  `inactive`, `expired`, `revoked`) — the single place to add/rename statuses.

Namespaces map 1:1 to folders (PSR-4), files are named after the class they
contain (e.g. `Admin\Menu` -> `includes/Admin/Menu.php`) — no legacy
`class-*.php` prefixing.

## Local testing

This repo is meant to be used directly as the plugin folder, e.g. symlinked
or cloned into `wp-content/plugins/serial-number-for-woocommerce/` on a Local
WordPress site.

Before activating the plugin:

1. Run `composer install` in the plugin directory (needed for autoloading —
   the plugin shows an admin notice and refuses to load if `vendor/` is
   missing).
2. Make sure WooCommerce is installed and active (the plugin no-ops with an
   admin notice otherwise).

## Conventions

- PHP 7.4+ syntax, typed properties/returns where practical.
- Prefix for global constants/hooks/text domain: `snw` / `SNW_`.
- Text domain: `serial-number-for-woocommerce`.
- No build step for PHP; a `vendor/` Composer autoloader is the only
  generated artifact required to run the plugin.
