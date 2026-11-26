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

  for (const scheme of ['light', 'dark'] as const) {
    test(`Import / export pages (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await login(page, 'admin');
      const shot = async (name: string): Promise<void> => {
        await waitForWebProfiler(page);
        await page.screenshot({ path: resolve(outDir, `${name}-${scheme}.png`), fullPage: true });
      };

      await page.goto('/seeds');
      await expect(page.getByTestId('seed-kit-index')).toBeVisible();
      await shot('import-export');

      await page.getByTestId('seed-kit-upload-open').click();
      const dialog = page.locator('dialog[open]');
      await expect(dialog.locator('input[type=file]')).toBeAttached();
      await shot('upload-dialog');

      await dialog.locator('input[type=file]').setInputFiles({
        name: 'catalog.yaml',
        mimeType: 'application/yaml',
        buffer: Buffer.from('provider: catalog.categories\nversion: 1\nscope: platform\nitems:\n  - { slug: books, label: Books and e-books, description: Printed and digital books }\n  - { slug: puzzles, label: Puzzles }\n  - { slug: Bad Slug, label: X }\n'),
      });
      await dialog.getByTestId('seed-kit-upload-submit').click();
      await expect(dialog.getByTestId('seed-kit-upload-error')).toHaveCount(1);
      await shot('upload-errors');

      await dialog.locator('input[type=file]').setInputFiles({
        name: 'catalog.yaml',
        mimeType: 'application/yaml',
        buffer: Buffer.from(`provider: catalog.categories\nversion: 1\nscope: platform\nitems:\n  - { slug: books, label: Books and e-books, description: Printed and digital books }\n  - { slug: shot-${scheme}-${Date.now()}, label: Puzzles }\n`),
      });
      await dialog.getByTestId('seed-kit-upload-submit').click();
      await expect(page.getByTestId('seed-kit-run')).toHaveAttribute('data-status', 'planned');
      await shot('run-diff');

      await page.getByTestId('seed-kit-apply').click();
      await expect(page.locator('dialog[open]')).toBeVisible();
      await shot('run-apply-confirm');
      await page.locator('dialog[open]').getByRole('button', { name: 'Apply' }).click();
      await expect(page.getByTestId('seed-kit-run')).toHaveAttribute('data-status', 'applied');
      await shot('run-applied');

      await page.goto('/seeds/runs');
      await expect(page.getByTestId('seed-kit-runs')).toBeVisible();
      await shot('runs');

      // Put the demo data back for the next capture.
      await page.goto('/seeds/upload');
      await shot('upload-page');
      await page.locator('input[type=file]').setInputFiles({
        name: 'catalog.yaml',
        mimeType: 'application/yaml',
        buffer: Buffer.from('provider: catalog.categories\nversion: 1\nscope: platform\nitems:\n  - { slug: books, label: Books, description: Printed and digital books }\n'),
      });
      await page.getByRole('button', { name: 'Check the file' }).click();
      await page.getByTestId('seed-kit-apply').click();
      await page.locator('dialog[open]').getByRole('button', { name: 'Apply' }).click();
      await expect(page.getByTestId('seed-kit-run')).toHaveAttribute('data-status', 'applied');
    });
  }
});
