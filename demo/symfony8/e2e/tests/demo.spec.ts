import { test, expect } from '@playwright/test';
import { login } from './helpers';

/** REQ-DEMO-013 — the main path of the demo: providers, downloads for the admin, refusal for the viewer. */
test.describe('Seed Kit demo', () => {
  test('home lists both providers in dependency order and the console commands', async ({ page }) => {
    await page.goto('/');
    const rows = page.getByTestId('demo-providers').locator('tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('catalog.categories');
    await expect(rows.nth(1)).toContainText('catalog.products');
    await expect(rows.nth(1)).toContainText('catalog.categories'); // depends on
    await expect(page.getByTestId('demo-commands')).toContainText('bin/console seed:list');
    await expect(page.getByTestId('demo-commands')).toContainText('seed:export catalog.products --empty --actor=admin');
    await expect(page.getByTestId('demo-download-login-catalog.products')).toBeVisible();
  });

  test('downloads need a login', async ({ request }) => {
    const response = await request.get('/seed/catalog.products/empty', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toContain('/login');
  });

  test('admin downloads the empty template, the JSON Schema and the current export', async ({ page }) => {
    await login(page, 'admin');

    const empty = await page.request.get('/seed/catalog.products/empty');
    expect(empty.status()).toBe(200);
    expect(empty.headers()['content-disposition']).toContain('catalog.products.empty.seed.yaml');
    const emptyBody = await empty.text();
    expect(emptyBody).toContain('provider: catalog.products');
    expect(emptyBody).toContain('items: []');
    expect(emptyBody).toContain('sku* (string');
    expect(emptyBody).toContain('category* (string, key of catalog.categories)');

    const schema = await page.request.get('/seed/catalog.products/schema');
    expect(schema.status()).toBe(200);
    const json = JSON.parse(await schema.text());
    expect(JSON.stringify(json)).toContain('x-seed-ref');
    expect(JSON.stringify(json)).toContain('sku');

    const current = await page.request.get('/seed/catalog.products/current');
    expect(current.status()).toBe(200);
    const currentBody = await current.text();
    expect(currentBody).toContain('provider: catalog.products');
    expect(currentBody).toContain('sku: BK-0001');
    expect(currentBody).toContain('category: books');

    const jsonl = await page.request.get('/seed/catalog.categories/current?format=jsonl');
    expect(jsonl.status()).toBe(200);
    const lines = (await jsonl.text()).trim().split('\n');
    expect(JSON.parse(lines[0]).provider).toBe('catalog.categories');
    expect(lines.slice(1).map((line) => JSON.parse(line).slug)).toEqual(['books', 'games', 'stationery']);

    await page.goto('/');
    await expect(page.getByTestId('demo-download-empty-catalog.products')).toBeVisible();
    await expect(page.getByTestId('demo-runs')).toContainText('applied');
  });

  test('the guard refuses the exports to the viewer', async ({ page }) => {
    await login(page, 'viewer');
    expect((await page.request.get('/seed/catalog.products/empty')).status()).toBe(403);
    expect((await page.request.get('/seed/catalog.products/current')).status()).toBe(403);
    expect((await page.request.get('/seed/unknown.thing/empty')).status()).toBe(404);
  });

  test('a wrong password is refused with an error', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('wrong');
    await page.getByTestId('login-form').getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toContainText('incorrect');
  });
});
