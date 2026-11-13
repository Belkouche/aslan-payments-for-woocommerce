import { test, expect } from '@playwright/test';

/**
 * End-to-end smoke test against the Aslan staging API.
 *
 * Verifies the chain from WooCommerce checkout → Gateway::process_payment →
 * AslanHttpClient::createCheckoutSession → redirect to hosted Aslan checkout.
 * We stop at the hosted checkout URL — driving card entry on pay.aslanpay.ma
 * is out of scope for this smoke (tests the plugin, not the Aslan paywall).
 */
test('woocommerce checkout redirects to Aslan hosted checkout', async ({ page, baseURL }) => {
  test.setTimeout(90_000);

  page.on('console', (m) => console.log(`[browser:${m.type()}] ${m.text()}`));
  page.on('pageerror', (e) => console.log(`[pageerror] ${e.message}`));
  page.on('response', async (r) => {
    if (r.url().includes('wc-ajax=checkout') || r.url().includes('/wp-json/aslan')) {
      let body = '';
      try { body = await r.text(); } catch {}
      console.log(`[xhr ${r.status()}] ${r.url()} body=${body.slice(0, 500)}`);
    }
  });

  // 1. Add the E2E product to cart via the add-to-cart URL (bypasses theme-specific
  //    "Add to cart" button selectors).
  await page.goto('/?add-to-cart=11');
  await expect(page).toHaveURL(/add-to-cart=11|cart/);

  // 2. Checkout.
  await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
  // Wait for the checkout form to be ready; WC emits many background XHRs
  // so we can't rely on networkidle.
  await page.locator('#billing_first_name').waitFor({ state: 'visible', timeout: 20_000 });

  // Fill required billing. WC may rename fields based on theme; use billing_* ids.
  await page.fill('#billing_first_name', 'Aya');
  await page.fill('#billing_last_name', 'Alaoui');
  await page.fill('#billing_email', 'aya+e2e@example.ma');
  await page.fill('#billing_phone', '0600000000');
  await page.fill('#billing_address_1', '123 Boulevard Zerktouni');
  await page.fill('#billing_city', 'Casablanca');
  await page.fill('#billing_postcode', '20000');
  // billing_state is a Select2 widget — set via jQuery + trigger Select2's own
  // change event so the hidden form field gets written and WC accepts the submit.
  await page.evaluate(() => {
    // @ts-expect-error jQuery lives on window at runtime
    window.jQuery('#billing_state').val('macas').trigger('change');
  });
  // Give WC's form-sync a tick to react.
  await page.waitForTimeout(500);

  // 3. Aslan is the only available gateway → WC auto-selects it, no radio to tick.
  //    (Dump the current snapshot for debugging.)
  const stateVal = await page.locator('#billing_state').inputValue().catch(() => '<missing>');
  console.log(`billing_state value = ${stateVal}`);

  // 4. Place order. WC posts via AJAX. Capture the body eagerly (before the
  //    browser's own JS consumes it and navigates), so we assert on the server's
  //    actual response and don't race the follow-up navigation.
  let checkoutBody: { result?: string; redirect?: string; messages?: string } | null = null;
  page.on('response', async (r) => {
    if (r.url().includes('wc-ajax=checkout') && r.status() === 200 && !checkoutBody) {
      try {
        checkoutBody = await r.json();
      } catch {
        // response buffer already freed by the browser's own reader; fall back to
        // asserting on the final URL instead.
      }
    }
  });

  await page.locator('#place_order').click();
  // Wait until the browser leaves our site — Aslan's hosted checkout then
  // typically trampolines on to the PSP (NAPS pre-prod), so we just need
  // "no longer on the WordPress host".
  const myHost = new URL(baseURL!).host;
  await page.waitForFunction(
    (h) => new URL(window.location.href).host !== h,
    myHost,
    { timeout: 45_000 }
  );

  const finalUrl = page.url();
  console.log(`url after place_order: ${finalUrl}`);
  if (checkoutBody) {
    console.log('checkout response:', JSON.stringify(checkoutBody));
    expect(
      checkoutBody.result,
      `checkout failed: ${checkoutBody.messages ?? JSON.stringify(checkoutBody)}`
    ).toBe('success');
    expect(checkoutBody.redirect, 'no redirect URL in response').toMatch(/aslanpay\.ma/);
  }
  // Landed on the Aslan hosted checkout page. Aslan's staging currently
  // serves it from staging-pay.tkpay.ma; prod serves from pay.aslanpay.ma.
  // The checkout page may itself trampoline to the PSP (naps.ma) during the
  // card step — all three are "the plugin's job is done".
  expect(finalUrl).toMatch(/aslanpay\.ma|tkpay\.ma|naps\.ma/);
  expect(new URL(finalUrl).host).not.toBe(myHost);
});
