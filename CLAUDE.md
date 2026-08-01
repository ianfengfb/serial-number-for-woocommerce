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
  excluding `includes/Pro/` and `assets/pro/` from the package; the Pro zip
  includes everything plus a real license-activation flow (licensing
  SDK/service TBD — e.g. Freemius or EDD Software Licensing).
  `Licensing::is_pro_active()` is the only integration point that will need
  to change to talk to a real license check — nothing else in the codebase
  should. Pro-only front-end assets live under `assets/pro/` (mirroring
  `includes/Pro/`) precisely so they can be excluded the same way.
- **A Free class may reference a Pro class, always behind the gate.** e.g.
  `Admin\Menu` imports `Pro\BulkGenerate\Controller` and only instantiates it
  after `Licensing::is_pro_active()` passes. This is safe even in the free
  zip (where `includes/Pro/` doesn't exist) because a PHP `use` import alone
  never triggers autoloading — only actually instantiating the class does,
  and that code path is unreachable without a license. The reverse direction
  (Pro code calling Free classes, e.g. `Repository`, `Generator`) is always
  fine and expected.

## Structure

```
serial-number-for-woocommerce.php   Plugin bootstrap: header, constants, WC dependency check, HPOS compat
composer.json                       PSR-4 autoload (SerialNumberForWooCommerce\ -> includes/)
includes/
  Plugin.php                        Singleton; wires up free + Pro features on init()
  Licensing.php                     is_pro_active() gate (see above)
  Install.php                       register_activation_hook target; creates/upgrades DB tables via dbDelta
  Admin/Menu.php                    Free tier: WooCommerce > Serial Numbers admin page (list/add/edit/bulk-generate routing)
  Admin/Settings.php                Free tier: WooCommerce > Settings > Serial Numbers tab (default status, auto-gen rules)
  Admin/Products/ProductTab.php     Free tier: "Serial Number" tab on the product edit screen (enable + manage-stock checkboxes)
  Admin/SerialNumbers/
    Repository.php                  $wpdb CRUD/search against the snw_serial_numbers table
    ListTable.php                   WP_List_Table: search + paginated list, hover row action to Edit
    FormController.php              Add New / Edit form render + validation + save
    Status.php                      Serial number lifecycle statuses: values, labels, configured default
    Generator.php                   Builds a random serial from the configured (or per-call override) rules
    Ajax.php                        wp_ajax_snw_search_products / snw_search_orders / snw_generate_serial / snw_import_serials
  Orders/
    Assigner.php                    Free tier: assigns serials to order line items when an order is placed
    ItemDisplay.php                 Free tier: shows an item's assigned serials on the admin order edit screen
  Products/
    StockSync.php                   Free tier: mirrors a product's Available pool count onto WC stock
  Pro/
    BulkGenerate/Controller.php     Pro: multi-row (prefix/suffix/product/amount) bulk serial generation page
assets/js/admin.js                  Enqueued only on the Serial Numbers screen; inits select2 AJAX search,
                                     exposes window.snwInitSearchSelects for Pro views to reuse
assets/vendor/select2/              Vendored select2 (JS+CSS) — bundled rather than relying on WooCommerce's
                                     own select2/selectWoo asset handles, which aren't guaranteed to be
                                     registered/enqueued on a third-party admin page across WC versions
assets/pro/js/bulk-generate.js       Pro: repeatable-row add/remove + select2 init for Bulk Generate
```

## Data model

- `{$wpdb->prefix}snw_serial_numbers` (created in `Install::activate()`): `id`,
  `serial_number` (unique), `status`, `product_id` (nullable), `order_id`
  (nullable), `created_at`, `expires_at` (nullable). Product/order columns
  store IDs only — always resolve via `wc_get_product()` / `wc_get_order()`
  rather than joining WC's tables directly, so this keeps working under HPOS.
- Status values live in `SerialNumbers\Status` — the single place to
  add/rename/reorder them. The lifecycle is:
  - `available` — in the pool, not tied to an order; the only status
    auto-assignment picks from.
  - `assigned` — attached to an order/customer, not used yet.
  - `activated` — redeemed/registered by the customer, in use.
  - `expired` — past `expires_at`, no longer valid.
  - `unavailable` — deliberately withheld (revoked/refunded/faulty/reserved);
    never handed out but kept on record.

  Always store/compare the lowercase keys (`Status::AVAILABLE` etc.); labels
  from `Status::all()` / `Status::label()` are translated display strings only.
  `Status::configured_default()` resolves the `snw_default_status` option for
  new serials, so no caller should read that option directly.
  `Install::LEGACY_STATUS_MAP` maps the pre-rename values (`active`,
  `inactive`, `revoked`) and is applied to existing rows and to the saved
  default on activation.

- Per-product opt-in is the `_snw_enabled` post meta (`yes`/`no`) on the parent
  product — `ProductTab::META_KEY`. `_snw_manage_stock`
  (`ProductTab::MANAGE_STOCK_META_KEY`) is a second, dependent per-product
  meta — see Stock sync below.
- Serials handed to an order live on the line item, not the order: the
  `_snw_serial_ids` order-item meta (`Assigner::ITEM_META_KEY`) holds an array
  of `snw_serial_numbers.id` values. It is what makes assignment idempotent, so
  read it via `Assigner::serial_ids()` before assigning anything new.
- `Repository::import_for_product()` is the single place that turns a block of
  pasted text into rows tied to a product — one per non-empty line, status
  `Status::configured_default()`, duplicates (existing or repeated in the same
  paste) skipped rather than erroring. The Serial Number tab's bulk-add
  textarea uses it twice: immediately via the `snw_import_serials` AJAX
  action ("Add to Pool"), and again as a save-time fallback in
  `ProductTab::save()` for whatever's still in the field — safe to double up
  on the same input since duplicates are always skipped.

## Stock sync

`Products\StockSync::sync( $product_id )` recomputes and writes a product's
WooCommerce stock (`_manage_stock`, `_stock`, `_stock_status`) from
`Repository::count_available()` — the product's Available, unexpired serial
count. It no-ops unless *both* `_snw_enabled` and `_snw_manage_stock` are
`yes`, so it's always safe to call after anything that might change a
product's pool: `ProductTab::save()`, `FormController::save()` (syncing the
old product too if a serial's product changed), `Pro\BulkGenerate\Controller`
(once per row), and `Assigner::assign_for_order()` (once per product that had
a successful pool claim — `generate_assigned()`'s fallback path never touches
the pool, so it never needs a sync). Any future code path that changes which
serials are Available for a product must call `StockSync::sync()` too.

`StockSync::sync()` always wins over a manual edit — it unconditionally
overwrites `_stock` on every call, with no check for whether a human just set
it. `ProductTab`'s inline script reflects that in the UI: while both
checkboxes are on, WooCommerce's native stock quantity field (`#_stock`, on
the Inventory tab) is disabled with a short note explaining why, toggled
live as either checkbox changes.

## Order assignment

`Orders\Assigner` runs on `woocommerce_checkout_order_processed` (classic) and
`woocommerce_store_api_checkout_order_processed` /
`woocommerce_blocks_checkout_order_processed` (blocks). For each line item whose
parent product has `_snw_enabled`, it tops the item up to **one serial per
ordered unit**: `Repository::claim_available()` takes Available, unexpired,
order-less serials from that product's pool first, and any shortfall is
generated from the global rules via `Generator::generate()`. Both paths leave
the serial `Status::ASSIGNED` with `order_id` set.

Pool claims are a compare-and-swap `UPDATE ... WHERE id = %d AND status =
'available'` so concurrent checkouts can't be handed the same serial — keep any
future claiming code on that pattern rather than a plain SELECT-then-UPDATE.
`Assigner::assign_for_order()` is public and static precisely so later features
(manual re-assign, status-transition triggers) can reuse it; it only ever
assigns the difference between an item's quantity and the serials it holds.

`Orders\ItemDisplay` hooks `woocommerce_after_order_itemmeta` to show each
item's assigned serials (resolved from `Assigner::serial_ids()` via
`Repository::find()`) on the admin order edit screen — read-only, no new
data, so it's the one place both HPOS and legacy order storage need no
special handling.

Namespaces map 1:1 to folders (PSR-4), files are named after the class they
contain (e.g. `Admin\Menu` -> `includes/Admin/Menu.php`) — no legacy
`class-*.php` prefixing.

## Branching strategy

- **`master`** — stable, released code only. A merge into `master` is always
  treated as a version bump: update the `Version:` header in
  `serial-number-for-woocommerce.php` and the `SNW_VERSION` constant together
  as part of that merge.
- **`dev`** — integration branch. Completed feature/fix branches land here
  first and get tested together before `dev` is considered releasable.
- **Feature/fix branches** — created off `dev` for each major feature or bug
  fix, merged back into `dev` when finished. Branch off the latest `dev`, not
  `master`.
- **Docs-only and minor changes** (typo fixes, small tweaks, this file) can be
  committed straight to `dev` — no separate branch needed.

Flow: `feature-or-fix branch -> dev` (as each one finishes) `-> master`
(once `dev` has accumulated one or more finished items and tested clean).

## Local testing

This repo is meant to be used directly as the plugin folder, e.g. symlinked
or cloned into `wp-content/plugins/serial-number-for-woocommerce/` on a Local
WordPress site.

`vendor/` (the Composer autoloader) is committed to the repo, so a fresh
clone/pull works as-is — no `composer install` step needed on any host,
including git-deploy setups with no build step (e.g. Cloudways). Run
`composer install` yourself only after changing `composer.json`, then commit
the regenerated `vendor/`. We have no third-party Composer dependencies yet
(`vendor/` is just Composer's own autoloader scaffolding, ~84KB) — if that
changes, revisit whether `vendor/` should still be committed vs. generated by
a release-build script instead.

Before activating the plugin, make sure WooCommerce is installed and active
(the plugin no-ops with an admin notice otherwise).

## Conventions

- PHP 7.4+ syntax, typed properties/returns where practical.
- Prefix for global constants/hooks/text domain: `snw` / `SNW_`.
- Text domain: `serial-number-for-woocommerce`.
- No build step for PHP; `vendor/` is committed (see Local testing) so the
  repo runs as-is on any host.
