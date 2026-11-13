# Aslan Payments for WooCommerce — plugin design

- **Date:** 2026-11-13
- **Author:** Bilal EL KOUCHE (brainstormed with Claude)
- **Status:** Draft — awaiting user review
- **Repo:** `aslan-payments-for-woocommerce` (this repo — to be pushed as `Belkouche/aslan-payments-for-woocommerce`)
- **Consumes:** Aslan Payment API (`aslan-payment-api` in the Aslan monorepo), via the official PHP SDK `aslan/payment-php`
- **License:** GPL-v2-or-later (required by wordpress.org)

---

## 1. One-sentence scope

A single WordPress plugin, `aslan-payments-for-woocommerce`, that adds Aslan as a WooCommerce payment gateway plus an `[aslan_pay]` shortcode and matching Gutenberg block, auto-configures its webhook from the merchant's API keys on save, and ships with one hard rule: card data never touches WordPress.

## 2. Scope

### In v1

- WooCommerce payment gateway. Payment methods are whatever Aslan's hosted page offers at session time (card, mobile wallet, cash agents). The merchant does not pick a subset in the plugin — Aslan's dashboard already controls that
- Cash-agent payments are treated as asynchronous: on the `payment.pending_cash` webhook, the Woo order moves to a new status `aslan-awaiting-cash` (registered by the plugin, labelled "Awaiting cash payment"); on the later `payment.succeeded` webhook, it moves to `completed` through the normal path
- Pay / refund / order-status sync through the API
- `[aslan_pay amount="…" description="…" success_url="…"]` shortcode and a matching Gutenberg block (same server-side render callback) for standalone one-off payments
- Two-field admin settings page (test secret key + live secret key), explicit mode toggle, zero other required fields
- Automatic webhook registration from the saved key — no copy-paste of URLs, no copy-paste of signing secrets
- Test-mode banner on the WooCommerce checkout, above the payment methods, when the gateway is in test mode (config Q4 = A)
- Hosted checkout via redirect (config Q5 = A); card data never touches WordPress
- i18n ready: English + French strings in the bundle; Arabic (`ar_MA`) added post-v1

### Explicitly not in v1

Declined even if asked, to keep the first release shippable and safe:

- Subscriptions (WooCommerce Subscriptions integration)
- Saved payment methods / tokenization
- Apple Pay / Google Pay surfacing in the admin UI
- Multi-currency (MAD only)
- Payment links feature (separate from the shortcode)
- Embedded card fields (Stripe-Elements-style)
- Modal/iframe checkout
- Deep-feature Gutenberg block (price picker, product picker, donation preset tiers)

### Compatibility targets

| Dependency | Minimum | Enforced where |
|---|---|---|
| WordPress | 6.4 | `plugin header` + activation check |
| WooCommerce | 8.0 | activation check, admin notice if missing |
| PHP | 8.2.35 / 8.3.36 / 8.4.27 / ≥ 8.5.12 | `composer.json` (matches PHP SDK — patched versions only, see SDK README for php2shell CVE) |
| WooCommerce HPOS (`custom_order_tables`) | declared compatible | `FeaturesUtil::declare_compatibility()` in bootstrap |

HPOS compatibility is baseline, not a feature: WooCommerce 9.0+ ships HPOS as default and non-compatible plugins show a red "incompatible" badge in the plugin list. Writing the plugin against the HPOS order API from the start costs nothing and makes the plugin work on every modern install without the merchant toggling a compatibility flag.

## 3. Architecture

Everything lives in one PHP plugin directory: `aslan-payments-for-woocommerce/`. The code has three internal layers so each file does one job and can be read on its own.

### Layer 1 — HTTP client (`src/Client/`)

A thin wrapper around the official `aslan/payment-php` SDK.

- One class, `AslanHttpClient`
- Picks the right secret key for the current mode (test vs live)
- Translates SDK exceptions to the plugin's own `AslanWooException`
- Retries connection-level failures twice (300ms, 900ms backoff). Does NOT retry 4xx or 5xx response bodies — those are the caller's decision
- Zero WordPress-specific calls — unit-testable with PHPUnit alone

### Layer 2 — Services (`src/Service/`)

WordPress-aware business logic. Three services:

- `CheckoutService` — turns a `WC_Order` into a session-create payload, enforces whole-MAD, attaches order id to metadata, remembers the returned `cs_` id on the order, builds the redirect URL
- `WebhookService` — verifies the HMAC-SHA256 signature on `X-Webhook-Signature`, rejects timestamps older than 5 minutes, routes by event type, mutates the `WC_Order` via HPOS API (paid / failed / refunded), idempotent against the Aslan event id
- `KeyLifecycleService` — on settings save: pings `/v1/account` with the pasted key to validate it, then registers (or re-registers) the webhook endpoint via `POST /v1/webhooks/config` pointing at `/wp-json/aslan/v1/webhook`, stores the returned signing secret in `wp_options`. The merchant copies nothing

### Layer 3 — WordPress integrations (`src/WooCommerce/`, `src/Shortcode/`, `src/Admin/`)

Glue only. `WC_Payment_Gateway` subclass, shortcode registration, Gutenberg block render callback, settings page fields. These files are thin — they call into Layer 2 and return. Keeps WP hook soup in one place so the business logic stays portable (and testable).

### Request entry points — exactly three

1. `woocommerce_payment_gateways` filter → gateway appears in the Woo checkout
2. `WC_Gateway_Aslan::process_payment( $order_id )` → called when the customer clicks "Place order"
3. `POST /wp-json/aslan/v1/webhook` (unauthenticated — signature-verified at the handler) → Aslan posts here when a payment settles

### What we do NOT add

- No custom tables. All plugin state lives on `WC_Order` meta (via HPOS API) and in `wp_options`
- No WP-Cron jobs. Webhook retries are Aslan's responsibility
- No admin AJAX endpoints beyond those strictly required by the shortcode
- No background queue (BullMQ etc.). WP hosts can't rely on persistent workers

## 4. Data flow

### Happy path — WooCommerce order

```
Customer                WordPress                    Aslan API              Aslan hosted page
   │                        │                            │                         │
   │  "Place order"         │                            │                         │
   ├──────────────────────▶ │                            │                         │
   │                        │  process_payment($order)   │                         │
   │                        │  → CheckoutService         │                         │
   │                        │   .createSessionForOrder() │                         │
   │                        ├──────────────────────────▶ │                         │
   │                        │   POST /v1/checkout/       │                         │
   │                        │        sessions            │                         │
   │                        │   Idempotency-Key:         │                         │
   │                        │   order_<id>_<attempt>     │                         │
   │                        │ ◀─────────────────────────┤                         │
   │                        │   { id: "cs_…", url, }     │                         │
   │                        │   save cs_id on order meta │                         │
   │                        │   set status =             │                         │
   │                        │     pending-payment        │                         │
   │ 302 redirect            │                            │                         │
   │ ◀──────────────────────┤                            │                         │
   │                                                     │                         │
   ├─────────────────────────────────────────────────────┼───────────────────────▶ │
   │                                                     │                         │
   │   pays or cancels                                   │                         │
   │ ◀──────────────────────────────────────────────────────────────────────────── │
   │ returned to success_url / cancel_url                │                         │
   │                        │                            │                         │
   │                        │ ◀──────────────────────────┤  POST /wp-json/aslan/   │
   │                        │   verify HMAC signature    │       v1/webhook        │
   │                        │   WebhookService           │                         │
   │                        │   .handle(event):          │                         │
   │                        │   - payment.succeeded      │                         │
   │                        │     → $order->                                       │
   │                        │       payment_complete()                             │
   │                        │   - payment.failed                                   │
   │                        │     → $order->                                       │
   │                        │       update_status('failed')                        │
   │                        │   - refund.succeeded                                 │
   │                        │     → wc_create_refund()                             │
   │                        │ ───────────────────────────▶ 200 OK                  │
```

### Property: webhook is the only source of truth

The order moves to `paid` / `failed` / `refunded` **only via the webhook**, never via the success-URL redirect. The redirect is a UX hint ("your order is being confirmed"), not a trust boundary. A customer who closes the browser never sees the thank-you page but their order still updates when the webhook arrives. Matches Stripe / Mollie / Adyen.

The webhook and the customer redirect are racy — either can land first. Both paths must tolerate the other arriving at any later moment:
- If the customer lands on the thank-you page before the webhook: the page checks `WC_Order` status; if still `pending`, it polls the order (through a Woo endpoint, not Aslan) for up to 10s with a "confirming your payment" spinner, then falls back to "we'll email you a confirmation"
- If the webhook lands before the customer redirects back: the order is already `completed` when they hit the thank-you page; no spinner, straight to the success state

### Idempotency — two layers

1. **Outbound** — every `POST /checkout/sessions` carries `Idempotency-Key: order_<orderId>_<attemptNumber>` so a page refresh doesn't create multiple sessions
2. **Inbound** — webhook handler records `aslan_event_<evt_id>` as a 7-day transient. Duplicate deliveries are a no-op after the first

### Failure modes (see also §7 Error handling)

- API 5xx / timeout during session create → gateway returns `['result' => 'failure']`, order stays `pending`
- Webhook signature invalid → 401, no mutation
- Webhook for unknown order → 200 OK, log entry, no mutation
- Refund webhook arrives before we recorded the refund-initiated attempt (refund fired from the Aslan dashboard) → we still create the matching `WC_Order_Refund` so Woo stays in sync

### Standalone shortcode flow

Same code path, lighter weight:

1. Button rendered by `[aslan_pay amount=… description=…]` or the matching block
2. Click → AJAX POST to `/wp-json/aslan/v1/shortcode/pay` with a WP nonce
3. Server creates a lightweight `aslan_payment` custom post type (`{ amount, description, status: 'pending', success_url, cancel_url, reference }`)
4. Server creates an Aslan session with `metadata: { aslan_payment_id: <cpt id> }`
5. Returns the session URL as JSON → browser redirects
6. Webhook arrives → handler finds the CPT via metadata, flips status

## 5. Admin UI

Three surfaces, all native WooCommerce / WordPress chrome.

### 5.1 Settings page

- Lives at **WooCommerce → Settings → Payments → Aslan**
- Two input fields:
  - **Test secret key** (placeholder `sk_test_…`)
  - **Live secret key** (placeholder `sk_live_…`, masked with a Reveal link once saved)
- One mode toggle: **Test ⇄ Live**. Mode is a plugin setting, not derived from the key — a merchant can configure both keys and flip between them at will
- Two checkout-display fields:
  - **Title shown to customers** (e.g., "Pay by card, wallet, or cash agent")
  - **Show test-mode banner on checkout** (toggle — banner only ever appears when mode = Test)
- **No field for the webhook URL. No field for the webhook signing secret.** The plugin computes the URL from `rest_url( 'aslan/v1/webhook' )` and handles the secret automatically
- **Override for non-standard hosting** (reverse proxy, subdir install, staging host): a hidden-by-default **Advanced** disclosure exposes a `Webhook URL override` field. Empty by default. If filled, that URL is what we register with Aslan — useful for a dev setup behind a tunnel, or a WP install served from a path the public URL doesn't match. Saved overrides also go through the key-lifecycle flow on save

### 5.2 Setup status card (appears after save on the same page)

Lists exactly what the plugin did when the keys were saved:

- ✓ Test key — verified, resolved merchant id shown
- ✓ Live key — verified, resolved merchant id shown
- ✓ Webhook endpoint registered — URL shown
- ✓ Webhook signing secret stored — "(never shown)"
- ✓ Last test ping — timestamp + latency

Below the status list, a **Troubleshooting** row with three buttons:

- **Re-send test webhook** — asks Aslan to deliver a `webhook.test` event to our endpoint
- **Re-register webhook** — re-runs the key-lifecycle flow (idempotent)
- **Download diagnostic log** — the capped `wp_options` log (last 100 events)

### 5.3 Order screen (WooCommerce native)

The native Woo order screen is extended with one **Payment** block and one **Actions** row:

**Payment block** — 5 rows:
- Method (Aslan — card ending in XXXX)
- Amount
- Aslan session id (clickable, opens Aslan dashboard in new tab)
- Payment id (clickable)
- Captured timestamp

**Actions row** — 2 buttons:
- **Refund via Aslan** — opens Woo's native refund modal; on submit, calls Aslan refund API, listens for the `refund.succeeded` webhook, updates the order
- **View in Aslan dashboard ↗**

**Order notes** — every state transition is recorded as a WooCommerce order note so support can reconstruct what happened.

## 6. Standalone shortcode

Signature:

```
[aslan_pay amount="500" description="Donation" success_url="/thank-you"]
```

### Attributes

| Attribute | Required | Default | Notes |
|---|---|---|---|
| `amount` | yes | — | Integer centimes. Must be a multiple of 100 (§10). Matches Aslan API format |
| `description` | yes | — | Shown on the hosted page as "Paying: {description}" |
| `success_url` | no | `home_url('/aslan/thank-you')` | Where Aslan sends the customer after a successful pay |
| `cancel_url` | no | `wp_get_referer()` | Where Aslan sends the customer on cancel |
| `button_text` | no | "Pay {amount} MAD" | Button label |
| `reference` | no | — | Free-form merchant reference stored on the CPT and passed as metadata to Aslan |

### Gutenberg block

Same attributes, same PHP `render_callback`. Users with the block editor get a sidebar form; users writing shortcodes directly get the same output. One code path.

### What the shortcode is NOT

Not a cart, not a product gallery, not a subscription signup, not a donation plugin with recurring giving. Anyone who needs those uses WooCommerce + the gateway. The shortcode is exactly one button for one amount — the common 80% case (a Pay button on an invoice page, a Donate button in a sidebar, a deposit button on a booking page).

## 7. Error handling

Four classes of failure, four responses. Nothing else gets caught.

### Class A — Transient Aslan API error during `process_payment`

- Scope: 5xx response, timeout, DNS failure, connection reset
- Retry policy: `AslanHttpClient` retries connection failures (not 5xx response bodies) with 2 extra attempts, 300ms and 900ms backoff
- If still failing: `process_payment()` returns `['result' => 'failure']` and a customer-visible notice: *"Payment service is temporarily unavailable. Please try again in a moment."* (translatable string)
- Order stays `pending`. Merchant can retry or switch to another gateway. No partial state
- Aslan request id (if any) is logged to order notes for support

### Class B — Validation / 4xx from Aslan

- Scope: bad amount, inactive merchant, mode mismatch, missing provider account
- No retry. These are our bugs or config bugs
- Customer sees a generic failure message; details stay admin-side
- An admin notice is queued and shown on the next admin page view: *"Aslan rejected a payment attempt — order #1024. Reason: {code}. Check your plugin settings."*
- If the error code is `invalid_mode` (test key against live endpoint or vice versa), the notice links straight to Settings with pre-focused instructions: *"Your current mode and API key do not match."*

### Class C — Invalid webhook signature

- Return `401 Unauthorized`, no order mutation
- Log the raw event id + source IP for 24h (transient), never log the body
- No alerting on repeated bad signatures. Aslan rate-limits from their side. An alert here would just paint a target: ping to annoy the merchant

### Class D — Webhook for an unknown resource

- Scope: deleted order, stale replay, race during customer delete
- Return `200 OK` with no body. Idempotent — Aslan won't retry on 2xx
- One entry added to the circular diagnostic log (`wp_options`, capped at 100 rows): `event <id> for <resource> — not found, skipped`
- Visible under *Troubleshooting → Download diagnostic log*

### Invariant across all four classes

**We never call `$order->update_status('failed')` without a webhook telling us to.** An order whose session was created but whose webhook never arrived stays `pending` forever — that is correct. "We don't know" is a real status; quietly flipping it to failed would corrupt the merchant's accounting.

### Explicitly out of scope

- WordPress-side fatals (DB down, PHP OOM). WP already shows the WSOD; recovery isn't ours
- Theme-induced CSS breakage on the Woo checkout. Theme author's problem
- Customers hitting Back mid-redirect. The webhook settles it eventually

## 8. Testing

Three layers, each answering a different question.

### Unit — `tests/Unit/` — PHPUnit 11

Targets Layer 1 and most of Layer 2 (the WordPress-free code).

- `AslanHttpClient`: payload shape, key selection, exception mapping, connection-retry behavior
- `CheckoutService::createSessionForOrder`: mock `WC_Order` → correct API payload, whole-MAD enforcement, order-id metadata, idempotency key format
- `WebhookService::handle`: signature verification (reject bad HMAC, reject timestamps > 5min), event routing, idempotency against event id
- `KeyLifecycleService`: validation ping shape, webhook registration shape, secret storage

Fast (< 2s), no WP bootstrap, mock the SDK with `prophesize`.

### Integration — `tests/Integration/` — WP-PHPUnit + WooCommerce test harness

Targets Layer 3 (the WordPress integrations).

- Gateway appears in `WC()->payment_gateways()->payment_gateways` only when configured
- `process_payment()` on a real `WC_Order` produces the correct status transitions
- Webhook route (`/wp-json/aslan/v1/webhook`) accepts a signed payload and transitions the order to `completed`
- Refund from the Woo order screen posts the correct API call and listens for the webhook
- HPOS compatibility — the whole suite runs twice, once with `custom_order_tables` enabled, once disabled

Uses a mocked `AslanHttpClient` — no real network calls.

### End-to-end — `tests/E2E/` — Playwright + Docker Compose

- Spin up WP + Woo + plugin + MySQL in Docker
- Seed a product, place an order as a customer, redirect to Aslan test checkout, pay with `4242 4242 4242 4242`, assert order status `completed`
- Repeat for the fail card, the 3DS card, and the cash-pending card
- Shortcode flow: visit a page with `[aslan_pay amount="50000"]`, click, pay, assert CPT status `succeeded`
- Fire a refund from the order screen, assert `refunded` status after the webhook

Runs on CI against Aslan's test environment. ~3 minutes per run.

### What we do NOT test

- Real payment network declines — Aslan's test keys simulate these; we trust the simulation
- Live-mode flows in CI — live keys don't belong in CI secrets
- Every WordPress theme — we test with `storefront` and `twentytwentyfive`

### Quality gates in CI

| Gate | Required? | Notes |
|---|---|---|
| PHPUnit (unit + integration) | yes | — |
| Playwright E2E | yes on PR to `main` | skippable on hotfix branches |
| `phpcs --standard=WordPress` | yes | — |
| PHPStan level 8 | yes | — |
| No-network check | yes | `grep -r "curl_exec\|file_get_contents\(.*http" src/ | grep -v "src/Client/"` must be empty (card-data provenance invariant) |

## 9. Distribution

### Where

- **wordpress.org Plugin Directory — primary channel from day one.** Free, searchable from inside any WP admin, auto-updates built in. Expect a ~2-week review on first submission
- **docs.aslanpay.ma mirror.** Same ZIP, same version, for merchants who read the docs first
- **Composer** — not in v1. Can be added later for Bedrock / Trellis users

### Release model

- **Repo:** `Belkouche/aslan-payments-for-woocommerce` (new, public, GPL-v2+ to match wordpress.org requirements)
- **Versioning:** SemVer. `0.x` while the shortcode and order-UI patterns are still shifting; `1.0.0` after one merchant is live for 30 days
- **CI/CD:** push a git tag → GitHub Actions builds the ZIP (strips `.git`, `tests/`, dev dependencies) → posts to wordpress.org SVN → attaches ZIP to GitHub release
- **Auto-updates:** through wordpress.org's built-in update channel. Zero extra work on our side
- **Deprecation policy:** support N and N-1. Older versions still work but get "please update" admin notices after a security release

### Support surface

- `AGENTS.md` + `llms.txt` at the repo root (same pattern as the PHP SDK)
- `readme.txt` in wordpress.org's required format (short description, FAQ, changelog, screenshots)
- One support channel documented: an email alias routing to Aslan's existing support team. No new Zendesk, no new Slack
- No new community forum in v1. The wordpress.org support forum is free; we monitor it

### Localization

- English and French bundled at v1
- Arabic (`ar_MA`) after v1 based on demand — strings are already `__()` wrapped
- `.pot` file regenerated on every release via `wp i18n make-pot`

## 10. Amount handling — whole MAD only

### Why

Memory `naps-whole-mad-only`: NAPS declines any computed online charge that is not a multiple of 100 centimes (whole MAD). Since NAPS is one of the two PSPs the gateway routes to, any plugin that lets a merchant request `12345` (= 123.45 MAD) will break randomly depending on routing — passing in test through Chari, failing the first live charge through NAPS.

### Where enforced

| Entry point | Enforcement |
|---|---|
| WooCommerce `process_payment()` | Before creating the session, assert `$order->get_total()` has no fractional MAD. On fail, return `['result' => 'failure']` with a customer-visible notice: *"Aslan accepts whole MAD amounts only. Please remove decimals from product prices or taxes."* The order stays `pending` |
| Shortcode / Gutenberg block | `amount` attribute must be a multiple of 100 (centimes). On fail: editor shows an error for logged-in admins; frontend refuses to render the button |
| Plugin activation | One-time scan of WooCommerce products; if any has a non-whole MAD price, a sticky admin notice lists the SKUs. Not a block — a heads-up |
| Settings → Troubleshooting | "Scan prices" button re-runs the activation scan on demand |

### What we do NOT do

**We never auto-round.** Silently charging 124.00 MAD when the invoice says 123.45 MAD is worse than refusing to charge. The merchant decides: edit the product price, adjust the tax rule, waive the odd centime.

## 11. Open questions

None blocking v1. Items parked for follow-up planning, not spec:

- Should the plugin surface Aslan's hosted-page branding controls (theme color, logo) from the settings page, or stay as "configure in Aslan dashboard"? Lean: Aslan dashboard
- Do we want a "resend payment link" button on the order screen for phone-ordered merchants? In v2 if asked for
- Arabic string set — timing TBD based on wordpress.org install signal

## 12. Decision log

| Q | Choice | Reason |
|---|---|---|
| Scope | WooCommerce gateway + lightweight shortcode (option C) | Same code base, serves both WooCommerce stores and sites without Woo |
| v1 feature set | Minimal (option A) — pay, refund, mode switch, shortcode | Every v1 feature must be supported forever; keep attack surface small |
| Setup UX | Two key fields visible at once, explicit mode toggle (option B) | Matches Stripe / Mollie after years of iteration; merchant configures both environments in one sitting |
| Test-mode visibility | Soft banner above payment methods on checkout only (option A) | Visible enough that a customer can't miss it; scoped tightly enough that soft-launching to friends still works |
| Checkout flow | Redirect to pay.aslan.ma (option A) | The only mode the API supports today; simplest; most robust across themes; path to adding B/C later is clean |
| Amount handling | Reject fractional MAD, never auto-round | NAPS constraint; silent rounding corrupts accounting |

## 13. Next step

Invoke `writing-plans` to turn this spec into an implementation plan. The plan will break the build into chunks (plugin skeleton + HTTP client + gateway + webhook + admin UI + shortcode + tests + release pipeline) and name verification gates for each.
