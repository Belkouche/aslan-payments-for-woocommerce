import { test, expect } from '@playwright/test';

test('customer pays with Aslan test card and lands on completed order', async ({ page }) => {
  await page.goto('/shop/');
  await page.click('text=Add to cart');
  await page.goto('/checkout/');
  await page.fill('#billing_first_name', 'Aya');
  await page.fill('#billing_last_name', 'Alaoui');
  await page.fill('#billing_email', 'aya@example.ma');
  await page.fill('#billing_phone', '+212600000000');
  await page.check('input[value="aslan"]');
  await page.click('#place_order');

  // We should be on pay.aslan.ma (test env) — pay with 4242 4242 4242 4242
  await expect(page).toHaveURL(/pay\.aslan\.ma/);
  await page.fill('input[name="cardNumber"]', '4242424242424242');
  await page.fill('input[name="expiry"]', '12/28');
  await page.fill('input[name="cvc"]', '123');
  await page.click('button:has-text("Pay")');

  await page.waitForURL(/order-received/);
  await expect(page.locator('body')).toContainText(/completed|confirming/i);
});
