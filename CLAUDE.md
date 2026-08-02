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
- **Visible-but-disabled Pro teasers render from Free code, not Pro code.**
  Pro controls stay visible but greyed out with a "PRO" badge when
  unlicensed, rather than being hidden — it upsells in context instead of
  hiding that the feature exists. That teaser markup has to be written in
  the Free-tier file, because it must render even in the free zip where
  `includes/Pro/` doesn't exist — a Pro class can't be instantiated to draw
  its own teaser. `Admin\Products\ProductTab` is the example for inline
  controls: it renders the "Manage product stock with Serial Number" and
  "custom auto-generation rule" checkboxes' teasers (unlicensed) or hands off
  entirely to `Pro\StockSync\StockSync` / `Pro\CustomRules\CustomRules` for
  the real save/sync logic (licensed). `Admin\Menu` does the same for a
  whole page: the "Bulk Generate" button always shows, but unlicensed it
  links to a static teaser notice instead of instantiating
  `Pro\BulkGenerate\Controller` at all. Either way, the actual Pro
  *behavior* stays gated and out of Free code; only inert preview markup
  lives there.

## Structure

```
serial-number-for-woocommerce.php   Plugin bootstrap: header, constants, WC dependency check, HPOS compat
composer.json                       PSR-4 autoload (SerialNumberForWooCommerce\ -> includes/)
includes/
  Plugin.php                        Singleton; wires up free + Pro features on init()
  Licensing.php                     is_pro_active() gate (see above)
  Install.php                       register_activation_hook target; creates/upgrades DB tables via dbDelta
  Admin/Menu.php                    Free tier: WooCommerce > Serial Numbers admin page (list/add/edit/delete/
                                     bulk-generate routing; list view has a by-product / no-product filter)
  Admin/Settings.php                Free tier: WooCommerce > Settings > Serial Numbers tab (default status, auto-gen rules)
  Admin/Products/ProductTab.php     Free tier: "Serial Number" tab, unlabeled Free area plus a "Pro Features"
                                     area (see Free/Pro architecture rules above for why the Pro controls'
                                     disabled/teaser markup lives here rather than under Pro/)
  Admin/SerialNumbers/
    Repository.php                  $wpdb CRUD/search (with optional product/no-product filters) against
                                     the snw_serial_numbers table
    ListTable.php                   WP_List_Table: search + paginated list, hover row actions to Edit/Delete,
                                     checkbox column + a "Delete" bulk action
    FormController.php              Add New / Edit form render + validation + save
    Status.php                      Serial number lifecycle statuses: values, labels, configured default
    Generator.php                   Builds a random serial from the configured (or per-call override) rules
    Ajax.php                        wp_ajax_snw_search_products / snw_search_orders / snw_generate_serial / snw_import_serials
  Orders/
    Assigner.php                    Free tier: assigns serials to order line items when an order is placed
    ItemDisplay.php                 Free tier: shows an item's assigned serials on the admin order edit screen
  Pro/
    StockSync/StockSync.php         Pro: mirrors a product's Available pool count onto WC stock
    CustomRules/CustomRules.php     Pro: a product's own auto-generation rule, overriding the global one
    CustomRules/Ajax.php            Pro: wp_ajax_snw_bulk_generate_for_product (product tab's own bulk-generate)
    BulkGenerate/Controller.php     Pro: multi-row (prefix/suffix/product/amount) bulk serial generation page
    Export/Exporter.php             Pro: streams the (optionally filtered) list as a CSV via admin_post
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
  - `deleted` — soft-deleted via the list table's single-row Delete action or
    its "Delete" bulk action. A normal selectable status like any other (so
    it's editable back to something else too), kept rather than hard-deleted
    for audit/recoverability. Needs no special exclusion anywhere:
    Available-counting queries (`count_available()`, `claim_available()`)
    already filter for `status = 'available'` specifically, so a deleted row
    falls out of them the same way an Unavailable one already does. The bulk
    action's key is `bulk_delete`, not `delete`, specifically so its
    `$_REQUEST['action']` value can't collide with the single-row Delete
    link's own `action=delete&id=`.

  Always store/compare the lowercase keys (`Status::AVAILABLE` etc.); labels
  from `Status::all()` / `Status::label()` are translated display strings only.
  `Status::configured_default()` resolves the `snw_default_status` option for
  new serials, so no caller should read that option directly.
  `Install::LEGACY_STATUS_MAP` maps the pre-rename values (`active`,
  `inactive`, `revoked`) and is applied to existing rows and to the saved
  default on activation.

- Per-product opt-in is the `_snw_enabled` post meta (`yes`/`no`) on the parent
  product — `ProductTab::META_KEY`. `_snw_manage_stock`
  (`ProductTab::MANAGE_STOCK_META_KEY`) and `_snw_custom_rule_enabled`
  (`ProductTab::CUSTOM_RULE_ENABLED_META_KEY`) are further dependent
  per-product meta, both Pro-gated — `ProductTab::save()` never persists
  either as `yes` without a license — see Stock sync and Custom generation
  rules below. The meta-key constants live on the Free `ProductTab` class
  (not the Pro classes that act on them) precisely so Free code can render
  their teaser markup and gate their persistence without referencing Pro.
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

## Stock sync (Pro)

`Pro\StockSync\StockSync::sync( $product_id )` recomputes and writes a
product's WooCommerce stock (`_manage_stock`, `_stock`, `_stock_status`) from
`Repository::count_available()` — the product's Available, unexpired serial
count. It no-ops unless licensed and *both* `_snw_enabled` and
`_snw_manage_stock` are `yes`, so every Free-tier call site wraps it in its
own `Licensing::is_pro_active()` check before calling (required regardless,
since the class doesn't exist at all in the free zip): `ProductTab::save()`,
`FormController::save()` (syncing the old product too if a serial's product
changed), and `Assigner::assign_for_order()` (once per product that had a
successful pool claim — `generate_assigned()`'s fallback path never touches
the pool, so it never needs a sync). `Pro\BulkGenerate\Controller` calls it
unguarded since that whole class only ever runs when licensed already. Any
future code path that changes which serials are Available for a product must
call `StockSync::sync()` too, gated the same way.

`StockSync::sync()` always wins over a manual edit — it unconditionally
overwrites `_stock` on every call, with no check for whether a human just set
it. `ProductTab`'s inline script reflects that in the UI: while both
checkboxes are on (and licensed), WooCommerce's native stock quantity field
(`#_stock`, on the Inventory tab) is disabled with a short note explaining
why, toggled live as either checkbox changes.

## Custom generation rules (Pro)

`Generator::generate( array $overrides = array() )` takes any of `prefix`,
`suffix`, `length`, `charset`; each falls back independently to its global
`snw_auto_*` option when left unset or empty, so a caller only needs to pass
the fields it wants to override.

`Pro\CustomRules\CustomRules::resolve_overrides( $product_id, $extra_overrides = [] )`
is the single place that decides which rule applies to a product: if that
product has `_snw_custom_rule_enabled` = `yes` (and is licensed), its own
`_snw_custom_prefix` / `_snw_custom_suffix` / `_snw_custom_length` /
`_snw_custom_charset` meta seed the overrides array; `$extra_overrides` is
then layered on top for anything the caller explicitly supplies, so it wins
over the product's stored rule for that one call. Both bulk-generate paths
go through this: `Pro\CustomRules\Ajax` (the product tab's own "Bulk
generate for this product" button, no extra overrides — just the product's
rule or global) and `Pro\BulkGenerate\Controller` (each row's own
prefix/suffix are passed as `$extra_overrides`, so an explicit row value
still wins over that row's product's custom rule). Rule field values persist
even while `_snw_custom_rule_enabled` is off, so re-checking it later
doesn't lose what was typed — `is_enabled_for_product()` alone gates whether
they take effect, same pattern as `StockSync`.

The product tab's own "Bulk generate for this product" button always reads
the product's *saved* meta at generate time, not whatever's currently typed
into the rule fields — the tooltip says as much, so save the product first
if the rule was just changed.

## CSV export (Pro)

`Pro\Export\Exporter` hooks `admin_post_snw_export_serials` — a raw
file-download response, not a normal admin page, so it can't go through the
usual `?page=serial-number-for-woocommerce&action=` routing in `Admin\Menu`.
The "Export CSV" button links straight to `admin-post.php` with the list's
*current* search term and product filter carried over as query args, so the
export always matches what's on screen. `Repository::search_all()` shares
its WHERE-building (`build_where()`) with the paginated `search()` used by
the list table, so the two filtering behaviors can't drift apart.

Export columns are `serial_number, status, product_id, product_sku,
product_name, order_id, created_at, expires_at` — raw values (status key,
not its translated label; ISO datetime, not the list table's localized
display format) so the file stays useful as an input to a future CSV
import, not just as a human-readable report. `product_sku`/`product_name`
are resolved at export time purely for readability; `product_id` is the
authoritative column.

Unlike Bulk Generate's teaser page, an unlicensed "Export CSV" renders as a
plain `<span>`, not a link — there's no HTML admin page on the other end of
`admin_post_snw_export_serials` to redirect to when it isn't hooked (the
whole point of admin-post is to skip page rendering), so a dead link would
be a worse experience than a non-clickable control.

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
