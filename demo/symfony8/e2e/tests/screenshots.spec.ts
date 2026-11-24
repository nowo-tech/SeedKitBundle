import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Seed Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('overview — providers, console commands and the last runs', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/');
    await expect(page.getByTestId('demo-providers')).toContainText('catalog.products');
    await expect(page.getByTestId('demo-runs')).toContainText('applied');
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'overview.png'), fullPage: true });
  });
});
