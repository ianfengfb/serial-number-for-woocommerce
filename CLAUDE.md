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
  Install.php                       register_activation_hook target; creates/upgrades DB tables via dbDelta.
                                     Also register_deactivation_hook target: clears Warranty's cron events
  Admin/Menu.php                    Free tier: WooCommerce > Serial Numbers admin page (list/add/edit/delete/
                                     bulk-generate/import routing; list view has a by-product / no-product filter)
  Admin/Settings.php                Free tier: WooCommerce > Settings > Serial Numbers tab (default status, auto-gen
                                     rules, customer-visibility toggles for emails/account order view, Warranty
                                     (Pro) activation-trigger settings)
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
    Assigner.php                    Free tier: assigns serials to order line items when an order is placed;
                                     also holds add_manual_serial(), the create-or-attach-or-reject logic
                                     behind the order edit screen's manual "Add Serial Number" control, and
                                     serial_rows()/serial_numbers(), the read-only lookups both display classes below share
    ItemDisplay.php                 Free tier: shows an item's assigned serials plus the "Add Serial Number"
                                     control on the admin order edit screen
    CustomerItemDisplay.php         Free tier: shows an item's assigned serials to the customer — order
                                     emails (HTML + plain text), the thank-you page, and My Account order view;
                                     labeled "License Key(s)" instead of "Serial Number(s)" when the item's
                                     product has License (Pro) enabled
    Ajax.php                        wp_ajax_snw_add_order_item_serial backing the admin control; enqueues
                                     assets/js/order-item-serials.js only on the order edit screen (HPOS-
                                     or CPT-storage-aware via wc_get_page_screen_id())
  Pro/
    StockSync/StockSync.php         Pro: mirrors a product's Available pool count onto WC stock
    CustomRules/CustomRules.php     Pro: a product's own auto-generation rule, overriding the global one
    CustomRules/Ajax.php            Pro: wp_ajax_snw_bulk_generate_for_product (product tab's own bulk-generate)
    BulkGenerate/Controller.php     Pro: multi-row (prefix/suffix/product/amount) bulk serial generation page
    Export/Exporter.php             Pro: streams the (optionally filtered) list as a CSV via admin_post
    Import/Controller.php           Pro: CSV import page — upload+parse, transient-backed preview, commit
    Import/RowParser.php            Pro: pure per-row parsing/validation shared by preview and commit
    Warranty/Warranty.php           Pro: per-product warranty config (opt-in, length/period) + activate_serial()
    Warranty/ActivationTrigger.php  Pro: starts warranty on an order's eligible items when it's marked Completed
                                     (immediately, or after a configured delay)
    Warranty/ExpiryChecker.php      Pro: daily cron flipping Activated serials past their expires_at to Expired
    Warranty/Extension.php          Pro: lets a customer pay to extend a product's warranty when they buy it —
                                     an option on the product page, snapshotted onto the order line item
    Warranty/Emails/
      AbstractWarrantyEmail.php     Pro: shared WC_Email plumbing for the two warranty notification emails
      WarrantyActivatedEmail.php    Pro: customer email sent on the snw_warranty_activated action
      WarrantyExpiredEmail.php      Pro: customer email sent on the generic snw_serial_expired action
                                     (filtered by is_relevant() to only warranty-enabled products)
    LicenseKey/LicenseKey.php       Pro: per-product license config (opt-in, length/period incl. lifetime,
                                     per-product activation trigger) + activate_serial()
    LicenseKey/ActivationTrigger.php Pro: activates a product's license serials per its own activation
                                     trigger (immediate, or on order Completed) — a per-product choice,
                                     unlike Warranty's store-wide setting; also fires the order-level
                                     license delivery notification regardless of each item's own trigger
    LicenseKey/CustomerActivation.php Pro: renders the "Activate" button on the customer's My Account
                                     order view for a 'manual'-trigger product's still-unactivated keys
    LicenseKey/Ajax.php             Pro: wp_ajax_snw_activate_license backing that button; enqueues
                                     assets/pro/js/license-activation.js only on the view-order endpoint
    LicenseKey/RestApi.php          Pro: registers POST /wp-json/snw/v1/license/activate for a seller's
                                     own external system to activate an 'api'-trigger product's license
                                     key; also backs the API key's admin-post "Regenerate" link
    LicenseKey/Emails/
      AbstractLicenseEmail.php     Pro: shared WC_Email plumbing for the two per-serial license emails —
                                     intentionally a separate copy of Warranty's AbstractWarrantyEmail
                                     rather than a shared base, so the two features can diverge freely
      LicenseActivatedEmail.php    Pro: customer email sent on the snw_license_activated action
      LicenseExpiredEmail.php      Pro: customer email sent on the generic snw_serial_expired action
                                     (filtered by is_relevant() to only license-enabled products)
      LicenseDeliveryEmail.php     Pro: order-level customer email (not per-serial) sent on
                                     snw_license_delivered — the key(s) plus each product's own
                                     License instructions, delivered regardless of activation trigger
      LicenseActivatedAdminEmail.php Pro: admin notice sent on the same snw_license_activated action,
                                     independently toggleable from the customer-facing one
templates/emails/                   Warranty and License notification email templates (HTML +
                                     templates/emails/plain/),
                                     theme-overridable the same way WooCommerce's own email templates are
assets/js/admin.js                  Enqueued only on the Serial Numbers screen; inits select2 AJAX search,
                                     exposes window.snwInitSearchSelects for Pro views to reuse
assets/vendor/select2/              Vendored select2 (JS+CSS) — bundled rather than relying on WooCommerce's
                                     own select2/selectWoo asset handles, which aren't guaranteed to be
                                     registered/enqueued on a third-party admin page across WC versions
assets/pro/js/bulk-generate.js       Pro: repeatable-row add/remove + select2 init for Bulk Generate
assets/pro/js/license-activation.js  Pro: AJAX handler for the customer's manual "Activate" button
```

## Data model

- `{$wpdb->prefix}snw_serial_numbers` (created in `Install::activate()`): `id`,
  `serial_number` (unique), `status`, `product_id` (nullable), `order_id`
  (nullable), `created_at`, `expires_at` (nullable), `activated_at`
  (nullable — Pro Warranty only; when a serial's warranty started, set by
  `Repository::activate()`). Product/order columns store IDs only — always
  resolve via `wc_get_product()` / `wc_get_order()` rather than joining WC's
  tables directly, so this keeps working under HPOS.
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
  (`ProductTab::MANAGE_STOCK_META_KEY`), `_snw_custom_rule_enabled`
  (`ProductTab::CUSTOM_RULE_ENABLED_META_KEY`), `_snw_warranty_enabled`
  (`ProductTab::WARRANTY_ENABLED_META_KEY`), and `_snw_license_enabled`
  (`ProductTab::LICENSE_ENABLED_META_KEY`) are further dependent per-product
  meta, all Pro-gated — `ProductTab::save()` never persists any of them as
  `yes` without a license — see Stock sync, Custom generation rules,
  Warranty, and License Key below. `_snw_warranty_extension_enabled` and its
  length/period/price siblings follow the exact same shape, one level
  further down (dependent on warranty itself being enabled); License's own
  `_snw_license_length`/`_snw_license_period`/`_snw_license_activation_trigger`/
  `_snw_license_instructions` are independent siblings of `_snw_license_enabled`
  rather than nested under a second checkbox, since they're always relevant
  once licensing is on (no further opt-in beneath them the way warranty's
  extension has).
  The meta-key constants live on the Free `ProductTab` class (not the Pro
  classes that act on them) precisely so Free code can render their teaser
  markup and gate their persistence without referencing Pro.
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

`sync()` returns the new count (or `null` if it no-opped) rather than
`void`, specifically so the two AJAX handlers that trigger it from the
product tab — `Admin\SerialNumbers\Ajax::import_serials()` ("Add to Pool")
and `Pro\CustomRules\Ajax::bulk_generate_for_product()` ("Bulk generate
this amount of serial numbers") — can hand it back to the browser as
`stock_quantity` in their JSON response. `ProductTab`'s inline script
(`snwApplyStockQuantity()`) then writes it straight into the disabled
`#_stock` field, so the admin sees the real number immediately instead of a
stale one that only catches up after saving or refreshing — `.val()` on a
disabled field works fine since `disabled` only blocks user input and form
submission, not script changes.

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

The product tab's own "Bulk generate this amount of serial numbers" button always reads the
product's *saved* meta at generate time, not whatever's currently typed
into the rule fields — the tooltip says as much, so save the product first
if the rule was just changed.

## Warranty (Pro)

`Pro\Warranty\Warranty` is a starting point, not a finished feature: an
"Enable warranty for this product" checkbox on the Serial Number tab's Pro
Features area (last item in that area, right after "Bulk generate this
amount of serial numbers"), gated and persisted the same way as
`MANAGE_STOCK_META_KEY` and `CUSTOM_RULE_ENABLED_META_KEY` (`ProductTab::save()`
never persists it as `yes` without a license). `Warranty::is_enabled_for_product()`
mirrors `StockSync`'s and `CustomRules`' own version of that method, so
later warranty features (whatever effect they have on individual serial
numbers) have a single existing place to gate from, rather than reinventing
the check.

Checking it reveals a "Warranty length" row (`#snw-warranty-fields`, toggled
the same way `#snw-custom-rule-fields` is) — a number input
(`WARRANTY_LENGTH_META_KEY`, default `1`) plus a Month(s)/Year(s) select
(`WARRANTY_PERIOD_META_KEY`, default `year`). Both persist whenever
licensed regardless of whether the checkbox is ticked, same "don't lose
what was typed" treatment as the custom-rule fields.
`Warranty::duration_for_product( $product_id )` returns them as
`['length' => int, 'period' => 'month'|'year']`; callers must still check
`is_enabled_for_product()` themselves first, same pattern as `CustomRules`.

### Activation and expiry (Pro)

No new table needed for this: it reuses `snw_serial_numbers.status`
(`Activated`/`Expired` already existed in the enum, just unused until now)
and `.expires_at`, plus one new column, `activated_at` (nullable — when a
serial's warranty started; needed because "days after completed" and any
future manual-activation flow can't be reconstructed from `expires_at`
alone). Adding it is a schema change, so it needs the usual reactivate step.

`Warranty::activate_serial( $serial_id )` is the single place that starts a
serial's warranty: computes `expires_at` from `duration_for_product()`
relative to now, then calls `Repository::activate()` (sets `Status::ACTIVATED`,
`activated_at = now()`, and the computed `expires_at` in one write — a
generic primitive, reusable by whichever trigger calls it). It's idempotent
— a serial with `activated_at` already set is left untouched — so re-firing
the same trigger twice (e.g. a status hook that runs again) never resets the
clock. It does *not* check `is_enabled_for_product()` itself: by the time
something calls it, that decision belongs to the caller.

`Pro\Warranty\ActivationTrigger` is that caller for the two automatic modes,
controlled by the "Activation trigger" setting in WooCommerce > Settings >
Serial Numbers (`snw_warranty_activation_trigger`, Pro area, disabled
inputs when unlicensed like every other Pro settings control). Its sibling
"Grace period (days)" field only means anything in the days-after-completed
mode, so `Settings::print_activation_trigger_script()` (an inline
`admin_footer` script scoped to this settings tab, targeting fields by ID
rather than assuming WooCommerce's row markup) hides that field and zeroes
it whenever the trigger isn't set to that mode. On
`woocommerce_order_status_completed`, it walks the order's items, skips any
whose product isn't `Warranty::is_enabled_for_product()`, and for each of
the item's serial IDs (`Assigner::serial_ids()`) either activates
immediately or schedules a one-off `wp_schedule_single_event()`
(`snw_activate_warranty_serial`, delay from `snw_warranty_activation_days`)
per serial. A third mode — customer-initiated manual activation — is
planned but not built yet; there is deliberately no UI option for it until
the actual customer-facing activation flow exists, so the settings screen
never offers a choice that silently does nothing.

`Pro\Warranty\ExpiryChecker` is a daily WP-Cron sweep
(`snw_check_warranty_expirations`) that flips any serial with
`status = Activated AND expires_at <= now()` to `Expired` via
`Repository::expire()` (status only — leaves `activated_at`/`expires_at` as
the historical record). It self-schedules from its own constructor rather
than on plugin activation: the class doesn't exist in the free zip, so
Free-tier code can't reference it to schedule it, and licensing can change
at runtime (`SNW_DEV_UNLOCK_ALL`) independent of when the plugin was last
activated — `Plugin::init()` only ever instantiates it when licensed, and
`wp_next_scheduled()` makes re-checking cheap on every such request.
`ActivationTrigger`'s delayed-activation hook has the same "can't be
scheduled from Free code" shape, just via `wp_schedule_single_event()`
instead of a recurring one.

This cron is shared infrastructure, not warranty-exclusive: the underlying
query (`Repository::find_activated_past_expiry()`) has no notion of
warranty vs. license, so it fires one generic `snw_serial_expired` action
per expired row rather than a warranty-specific one. `WarrantyExpiredEmail`
listens on that generic event but overrides `AbstractWarrantyEmail`'s
`is_relevant()` hook (default `true`) to check
`Warranty::is_enabled_for_product()` first, so it doesn't fire for an
expired serial that's actually License-governed. `WarrantyActivatedEmail`
needs no such check — `snw_warranty_activated` is only ever fired by
`Warranty::activate_serial()` itself, so it's inherently warranty-only.

Both cron hook names are duplicated as literal strings in
`Install::WARRANTY_CRON_HOOKS`, used by the new `Install::deactivate()`
(registered via `register_deactivation_hook` in the bootstrap file) to
`wp_clear_scheduled_hook()` them on deactivation. This has to be a literal
string rather than `ActivationTrigger::DELAYED_ACTIVATION_HOOK` /
`ExpiryChecker::CRON_HOOK` for the same reason as everywhere else a Free
class can't reference a Pro one: the constant reference would autoload a
class that doesn't exist in the free zip. Keep the three in sync by hand if
a hook name ever changes.

### Warranty extension (Pro)

Deliberately *not* a separate purchasable product linked to the main one —
that shape has real edge cases (wrong extension linked, mismatched
quantities between the two products, etc.). Instead `Pro\Warranty\Extension`
adds a plain checkbox on the product page itself ("Add N extra warranty
(+price)"), so there's only ever one product/one line item to reason about:

- **Product page** (`woocommerce_before_add_to_cart_button`): the checkbox
  only renders when `Extension::is_enabled_for_product()` passes (licensed,
  warranty enabled, and the product's own `_snw_warranty_extension_enabled`
  is `yes`).
- **Add to cart** (`woocommerce_add_cart_item_data`): if checked, the
  product's *current* extension length/period is captured into the cart
  item's data under `Extension::CART_ITEM_KEY`. WooCommerce's own cart-key
  hashing already keeps an extension-checked add separate from a plain one
  for the same product, so no manual cart-item-key trick is needed.
  `woocommerce_before_calculate_totals` adds the product's extension price
  to that cart item's price; `woocommerce_get_item_data` shows "Warranty
  extension: N Months/Years" in the cart/checkout line.
- **Checkout** (`woocommerce_checkout_create_order_line_item`): the chosen
  duration is snapshotted onto the order item as `Extension::ITEM_META_KEY`
  — a deliberate copy, not a live reference to the product's settings, so
  changing or removing the extension option on the product later never
  changes what an already-placed order paid for.
- **Activation**: `Warranty::activate_serial()` resolves the order item a
  serial belongs to via `Assigner::find_item_for_serial()` (the reverse of
  `serial_ids()`) and, if that item's `Extension::duration_for_order_item()`
  returns a duration, adds it to the base warranty length before computing
  `expires_at` — both durations are converted to months and summed
  (`duration_in_months()`) rather than combined as a length/period pair, so
  a "1 year" base plus a "6 Month" extension doesn't need any unit-mixing
  logic beyond addition.

One purchase decision applies to the *entire* line item — if a customer
buys 3 of a product with the extension checked, all 3 of that item's
serials get the extended warranty; there's no per-unit choice.

### Activation and expiry emails (Pro)

`Pro\Warranty\Emails\WarrantyActivatedEmail` and `WarrantyExpiredEmail`
(sharing their trigger/render plumbing via `AbstractWarrantyEmail`) are
registered through WooCommerce's own `woocommerce_email_classes` filter —
deliberately, rather than building a bespoke settings UI for them: this
gets each email listed on WooCommerce > Settings > Emails with WC's native
enable/disable toggle, subject/heading fields, and a theme-overridable
template (`templates/emails/warranty-activated.php` /
`warranty-expired.php`, plus `templates/emails/plain/` for the plain-text
versions) for free, matching how every other WooCommerce email already
works for the store owner. The "Warranty (Pro)" settings section links
there so it's discoverable from the Serial Number-specific settings too.

Both are triggered by a plain action carrying just the serial ID —
`snw_warranty_activated` (fired from `Warranty::activate_serial()`, right
after `Repository::activate()`) and the generic `snw_serial_expired` (fired
from `ExpiryChecker::check()`, right after `Repository::expire()` —
`WarrantyExpiredEmail`'s `is_relevant()` override is what keeps it
warranty-only despite the generic hook). Each email's `trigger()`
re-resolves the serial and its order fresh from that ID (rather than the
action passing the order/customer directly), so the same lookup logic is
exercised identically whether the plugin is in the middle of an active
request or a WP-Cron sweep. No recipient (order missing, or no billing
email) simply skips sending — same "fail quiet, not loud" posture as
everywhere else in the plugin that resolves an order.

## License Key (Pro)

`Pro\LicenseKey\LicenseKey` treats a product's serial numbers as license
keys — its own per-product opt-in (`_snw_license_enabled`), independent of
Warranty; a product can have either, both, or neither switched on. It
deliberately reuses Warranty's underlying mechanics rather than
duplicating them: `Status::ACTIVATED`/`EXPIRED`, `activated_at`/
`expires_at`, `Repository::activate()`/`expire()`, and the shared expiry
cron (see above) all work identically for a license as for a warranty — a
serial's row has no idea which feature is driving it.

Two differences from Warranty's settings shape:

- **Lifetime licenses**: `LICENSE_PERIOD_META_KEY` accepts `'lifetime'` as
  well as `'month'`/`'year'`. `LicenseKey::activate_serial()` leaves
  `expires_at` as `null` for a lifetime license — `Repository::activate()`
  takes a nullable `$expires_at` for exactly this (widened from a plain
  `string` when this feature was added; existing warranty callers are
  unaffected since they always pass a value). A lifetime license's
  `expires_at IS NULL` means it can never match
  `find_activated_past_expiry()`'s `expires_at <= now()` check, so it's
  correctly never swept by the cron — no special-casing needed there.
- **Per-product activation trigger**: `LICENSE_ACTIVATION_TRIGGER_META_KEY`
  (`'immediate'`, `'on_completed'`, `'manual'`, or `'api'`) is set
  per-product on the Serial Number tab, not as a single store-wide setting
  like Warranty's — licensed products can have very different real-world
  activation needs (instant digital delivery vs. needing a completed/paid
  order first vs. the customer redeeming it themselves vs. a seller's own
  external system deciding when). Warranty's own still-pending manual mode
  remains unbuilt — neither of License's customer- or system-driven flows
  below are shared with it, since Warranty has no "redeem this key" moment
  for anything to trigger from.

`Pro\LicenseKey\ActivationTrigger` hooks both the checkout-processed events
(classic + blocks/Store API) and `woocommerce_order_status_completed`, at
priority 20 — explicitly later than `Assigner`'s own checkout hooks (which
run at the default priority 10), so `Assigner::serial_ids()` already has
this order's serials by the time it reads them, regardless of the two
classes' instantiation order in `Plugin::init()`. For each hook firing, it
walks the order's items, skips any whose product isn't
`LicenseKey::is_enabled_for_product()` or whose own activation-trigger
setting doesn't match that hook's mode, and activates the rest via
`LicenseKey::activate_serial()`. A `'manual'`-trigger product's serials
never match either mode here, so they're correctly left untouched by both
hooks — only the customer-facing flow below ever activates them.

### Customer self-activation (Pro)

For a `'manual'`-trigger product, `Pro\LicenseKey\CustomerActivation`
renders an "Activate {key}" button next to each of the item's still-
unactivated keys (`empty( $serial->activated_at )`) on the customer's My
Account order view specifically — gated by `is_wc_endpoint_url(
'view-order' )`, not the thank-you page or emails, since those aren't
"my account, logged in" contexts. It hooks the same
`woocommerce_order_item_meta_end` hook as `CustomerItemDisplay` but at
priority 20 so its buttons render below that class's read-only key list
rather than interleaved with it. No separate permission check is needed to
render the button: WooCommerce's own `myaccount/view-order.php` template
already gates the whole page behind `current_user_can( 'view_order', ... )`
before this hook can fire.

The button posts to `wp_ajax_snw_activate_license` (`Pro\LicenseKey\Ajax`,
enqueuing `assets/pro/js/license-activation.js` only on the same
`view-order` endpoint) with the order ID and serial ID. The handler re-does
the permission check independently (`current_user_can( 'view_order',
$order_id )`) since an AJAX request can't rely on the page load's own
gate, confirms the serial actually belongs to one of that order's items via
`Assigner::find_item_for_serial()` (never trusting the posted order/serial
pairing on its own), re-checks the product is still license-enabled and
still set to `'manual'`, and rejects an already-activated key before
calling `LicenseKey::activate_serial()`. On success the page reloads, same
"server-rendered, don't patch the DOM" pattern as the admin order screen's
manual "Add Serial Number" control.

This needs no new "notify the seller" plumbing: `LicenseKey::activate_serial()`
fires the same `snw_license_activated` action regardless of what triggered
it, which `LicenseActivatedEmail` and `LicenseActivatedAdminEmail` (see
below) are already listening on — a manual customer activation notifies the
seller exactly the same way an automatic one does.

One known scope limit: a guest checkout (no account) has no My Account
order view to click this button from, so `'manual'` only works for
logged-in customers. There is no workaround planned — a seller who needs
guest-checkout customers to self-activate should use `'immediate'` or
`'on_completed'` instead.

### External / API activation (Pro)

For an `'api'`-trigger product, activation is left entirely to the
seller's own external system (their license server, a fulfillment tool,
whatever they run outside WordPress) — nothing in this plugin activates
that product's serials automatically or from a customer-facing control.
`Pro\LicenseKey\RestApi` registers `POST /wp-json/snw/v1/license/activate`
for that system to call, identifying the license by `serial_number` in the
request body (a string, not the internal row ID, since an external caller
has no reason to know it) rather than the numeric ID every other internal
caller uses.

Auth is a single store-wide shared secret —
`LicenseKey::get_or_create_api_key()` — sent as the `X-SNW-Api-Key`
header, deliberately not WooCommerce's own REST API consumer key/secret
system: that would need the seller to create a WC API key with the right
permissions just for this one endpoint, whereas a single plugin-specific
secret is a simpler "copy one value into your system" integration. The
key is generated on first use (`get_or_create_api_key()`, called from both
the REST permission check and the Settings field so neither ever sees an
empty value) and persisted as the `snw_license_api_key` option — never
read or displayed directly by anything else. It's shown read-only on the
License Key (Pro) settings section (`Settings::license_api_key_description()`)
alongside a "Regenerate" link straight to `RestApi::regenerate_api_key()`
via `admin_post_snw_regenerate_license_api_key` — a plain nonce'd link,
not AJAX, same pattern as Export CSV's own admin-post link. Regenerating
invalidates the old key immediately; there's no grace period or dual-key
overlap, since rotating on demand is the whole point of exposing the
control at all.

The endpoint's `activate()` callback re-validates everything server-side
before calling `LicenseKey::activate_serial()` — matching serial exists
(404), its product is actually license-enabled (400), that product's
trigger is actually `'api'` (409, since a key valid for the store doesn't
imply this particular serial is meant to be activated this way), and it
isn't already activated (409) — returning a `WP_Error` with the
appropriate HTTP status for each rather than a bare boolean, since an
external caller needs a real status code to branch on. Success responses
echo back `serial_number` for the caller to confirm against. No new
"notify the seller" plumbing here either, same reasoning as manual
customer activation: `activate_serial()` fires the same
`snw_license_activated` action regardless of caller, so
`LicenseActivatedEmail`/`LicenseActivatedAdminEmail` fire identically.

### License emails (Pro)

Four emails, all registered through `woocommerce_email_classes` like
Warranty's:

- **`LicenseDeliveryEmail`** (`snw_license_delivered`) — the one exception
  to "per-serial": it's order-level, since one order can contain several
  licensed products and the customer should get everything in a single
  email. Fired from `ActivationTrigger`'s own checkout-processed handler
  (`maybe_notify_delivery()`) the moment the order is placed, regardless of
  each item's own activation trigger — delivery (handing over the key) and
  activation (starting the validity countdown) are separate concerns, so a
  "customer activates manually" or "on Completed" product still delivers
  its key right away. Its template loops over every license-enabled item
  (`LicenseDeliveryEmail::collect_licenses()`), pairing each product's keys
  with that product's own `LicenseKey::instructions_for_product()` — the
  seller's per-product activation steps/download links/support contact,
  entered as a plain textarea on the Serial Number tab (`#snw-license-fields`,
  rendered via `woocommerce_wp_textarea_input()` so it gets a `name`
  attribute for free — the earlier warranty length/period fields didn't,
  since they were hand-rolled `<input>`/`<select>` markup, and silently
  never saved until that was fixed).
- **`LicenseActivatedEmail`** (`snw_license_activated`) / **`LicenseExpiredEmail`**
  (generic `snw_serial_expired`, filtered by `is_relevant()`) — per-serial,
  mirroring `WarrantyActivatedEmail`/`WarrantyExpiredEmail` exactly in shape
  via their own `AbstractLicenseEmail`. Deliberately *not* sharing
  Warranty's `AbstractWarrantyEmail`: the two are expected to diverge as
  License grows its own activation paths (manual, external/webhook) that
  Warranty will never need, so coupling the namespaces together now would
  just make that divergence harder later.
- **`LicenseActivatedAdminEmail`** (also `snw_license_activated`,
  `customer_email = false`) — the "notify the seller" half of activation,
  useful once activation can happen outside a normal checkout (a manual or
  future externally-triggered activation the seller wants visibility into).
  A separate `WC_Email` registration from `LicenseActivatedEmail` so the
  admin notice is independently toggleable from the customer-facing one,
  even though both listen to the same action.

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

## CSV import (Pro)

`Pro\Import\Controller` is a normal admin page reached via `?action=import`
(unlike Export, so its unlicensed teaser is a clickable link, same pattern
as Bulk Generate). It's a two-step upload -> preview -> commit flow, never a
straight upload -> insert, because nothing may be written to the database
until a human has reviewed a preview and confirmed it:

1. **Upload** (`handle_upload()`, triggered by `$_POST['snw_import_upload']`):
   validates the uploaded file has a header row matching
   `Import\RowParser::EXPECTED_HEADERS` exactly (`serial_number, status,
   product_sku, product_id, expires_at`) — no other header layout is
   accepted — reads up to `Controller::MAX_ROWS` (1000) data rows via
   `fgetcsv()`, and hands them to `RowParser::parse_rows()`. The parsed
   result (per-row status/product/expiry resolution plus any errors/
   warnings) is stored in a transient keyed by a one-time token
   (`wp_generate_password()`) for `Controller::TRANSIENT_TTL` (15 minutes),
   then the request redirects to `?action=import&token=...` (Post-Redirect-
   Get, so reloading the preview never re-parses the file).
2. **Preview** (`render_preview()`): reads the transient by token and renders
   one row per parsed line with a Result column — a row with any error shows
   ✕ and will be skipped; a row with only warnings still imports (⚠) but
   flags what changed; a clean row shows ✓. A missing/expired transient (TTL
   elapsed, or a stale/reused link) shows an expired notice and falls back to
   the upload form instead of erroring.
3. **Commit** (`handle_commit()`, triggered by `$_POST['snw_import_commit']`
   posting the token back): loads the same transient, skips any row with
   errors *or* that now fails a fresh `Repository::exists()` re-check (state
   may have changed since the preview — e.g. another import or manual add
   used that serial number in the meantime), inserts the rest via
   `Repository::insert()`, syncs stock once per distinct touched product
   (gated the same as everywhere else), then deletes the transient so the
   token can't be replayed, and redirects to the list with an `imported`
   notice (imported + skipped counts).

`RowParser` holds the parsing/validation rules alone (no file or DB-write
handling), so preview and commit run identically:

- **Product** (`resolve_product()`): `product_id` is checked first and, when
  given, is an override; `product_sku` is otherwise the primary lookup via
  `wc_get_product_id_by_sku()`. Both blank means no product for that row. A
  given-but-unresolvable ID or SKU is an *error* (row skipped), not silently
  treated as no product — that would drop the intended assignment without
  telling anyone.
- **Status** (`resolve_status()`): matched against `Status::all()`
  case-insensitively; blank uses `Status::configured_default()`; an
  unrecognized value also falls back to the configured default, but only as
  a *warning* — the row is still imported.
- **Expiry** (`resolve_expiry()`): must be `dd/mm/yyyy`, validated with
  `checkdate()`. Blank means no expiry (no warning). An unparseable or
  calendar-invalid value is treated as no expiry too, flagged as a warning
  rather than an error, since the row is still importable.
- **Duplicates**: within the same file, the second and later occurrence of a
  serial number (case-insensitive) is an error; so is one that already
  exists in the table. Both are caught at preview time; the commit step's
  `Repository::exists()` re-check only catches what changed *after* the
  preview was generated.
- **Order ID is never accepted** — there is no `order_id` column in
  `EXPECTED_HEADERS` at all; every imported row is inserted with
  `order_id => 0`, matching how every other bulk-creation path (bulk-add
  textarea, Bulk Generate) works.

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
item's assigned serials (resolved from `Assigner::serial_numbers()`, shared
with `CustomerItemDisplay`) on the admin order edit screen, and — unless the
item already holds one serial per ordered unit — an "Add Serial Number"
input + button underneath (a manual override tool, not a top-up, for orders
whose item was never auto-assigned in the first place, e.g. the product
wasn't `_snw_enabled` yet at checkout). It compares against
`$item->get_quantity()` rather than checking "any serials at all", so a
partially-topped-up item still shows the control for its shortfall, and a
fully-covered one hides it since there's nothing left to add.

That control posts to `wp_ajax_snw_add_order_item_serial` (`Orders\Ajax`,
enqueuing `assets/js/order-item-serials.js` only on the order edit screen —
resolved via `wc_get_page_screen_id( 'shop-order' )` so it works whether HPOS
or legacy CPT order storage is active), which loads the item scoped to the
posted order (`$order->get_item( $item_id, false )`, so an item ID from a
different order is rejected rather than loaded anyway) and hands off to
`Assigner::add_manual_serial( $item, $serial_number )` — the single place
that decides what a typed-in serial number means:

- **Unknown to the table**: created fresh, `Status::ASSIGNED`, tied to this
  item's product and order — same end state a normal checkout assignment
  would leave it in.
- **Known, not yet tied to any order, and its stored `product_id` matches
  this item's product**: updated to `Status::ASSIGNED` and this order —
  existing status is overwritten regardless of what it was, same as editing
  any other field on the Add/Edit form.
- **Known but already tied to an order, or tied to a *different* product**:
  rejected with a message explaining which, and left untouched — never
  silently reassigned or detached from its actual owner.

Either way, the serial's row ID is appended to the item's `Assigner::ITEM_META_KEY`
meta (idempotently — skipped if already present) and `StockSync::sync()` runs
if licensed, matching every other write path that can change a product's
Available count. On success the page simply reloads rather than patching the
DOM, since the read-only serial list above is rendered server-side.

`Orders\CustomerItemDisplay` shows the same read-only list
(`Assigner::serial_rows()`, shared with `ItemDisplay`'s `serial_numbers()`
rather than re-querying) to the customer, but on a different hook: order emails, the
thank-you page, and the My Account order view all render line items through
`woocommerce_order_item_meta_end` (with a `$plain_text` arg for plain-text
emails), whereas the admin order edit screen uses
`woocommerce_after_order_itemmeta` — hence the separate class rather than
extending `ItemDisplay` onto both hooks.

Each of those two customer-facing contexts has its own on/off setting in
WooCommerce > Settings > Serial Numbers — `snw_show_serials_in_emails` and
`snw_show_serials_in_account`, both defaulting to `yes` — for stores that
would rather manage serial numbers internally than show them to the
customer. Telling the two contexts apart is the tricky part: emails and the
order-details page both call the item hook with `$plain_text` false, so
`$plain_text` alone can't distinguish an HTML email from the account page.
`CustomerItemDisplay` instead brackets itself with WooCommerce's own
`woocommerce_email_order_details` / `woocommerce_email_after_order_table`
hooks, which fire once each around an email's entire items table — setting
an `$in_email` flag true for the former and false for the latter — so
`render()` knows which setting applies to the item hook firing in between.
That same flag also decides whether each serial's expiry date is shown
alongside it (`Assigner::serial_rows()` returns the full row, not just the
`serial_number` string, precisely so this and any other display can reach
`expires_at`) — only the order-details page shows it; emails stay
serial-number-only.

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
