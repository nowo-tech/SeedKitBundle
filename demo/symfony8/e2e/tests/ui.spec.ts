import { test, expect } from '@playwright/test';
import { login } from './helpers';

/**
 * REQ-DEMO-013 — the SeedKit 0.2 pages: Import / export list, the upload dialog (loaded when it opens), the row error
 * of a refused file (nothing written), the diff, the apply through the confirm dialog, the history; the viewer is
 * refused by the bundle's guard.
 */
function categories(slug: string, label: string): Buffer {
  return Buffer.from(`provider: catalog.categories\nversion: 1\nscope: platform\nitems:\n  - { slug: ${slug}, label: ${label} }\n`);
}

test.describe('Seed Kit UI', () => {
  test('admin uploads a file, reviews the diff and applies it', async ({ page }) => {
    const slug = `e2e-${Date.now()}`;
    await login(page, 'admin');
    await page.goto('/seeds');
    await expect(page.getByTestId('seed-kit-index')).toBeVisible();
    await expect(page.getByTestId('seed-kit-provider-catalog.products')).toBeVisible();
    await expect(page.getByTestId('seed-kit-provider-catalog.categories')).toBeVisible();
    // Actions pass UiKit kinds, never icon names: the kit draws the icon of the kind (History = view, Upload = import).
    await expect(page.getByTestId('seed-kit-history').locator('svg')).toHaveCount(1);
    await expect(page.getByTestId('seed-kit-upload-open').locator('svg')).toHaveCount(1);

    await page.getByTestId('seed-kit-upload-open').click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog.locator('input[type=file]')).toBeAttached();

    // A refused file: the row error is shown in the dialog, nothing is stored.
    await dialog.locator('input[type=file]').setInputFiles({ name: 'bad.yaml', mimeType: 'application/yaml', buffer: categories('Not A Slug', 'X') });
    await dialog.getByTestId('seed-kit-upload-submit').click();
    await expect(dialog.getByTestId('seed-kit-upload-error')).toHaveCount(1);

    await dialog.locator('input[type=file]').setInputFiles({ name: 'categories.yaml', mimeType: 'application/yaml', buffer: categories(slug, 'E2E') });
    await dialog.getByTestId('seed-kit-upload-submit').click();
    await expect(page).toHaveURL(/\/seeds\/runs\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('seed-kit-run')).toHaveAttribute('data-status', 'planned');
    await expect(page.getByTestId('seed-kit-diff-row')).toHaveCount(1);
    await expect(page.getByTestId('seed-kit-diff-row')).toContainText(slug);

    await page.getByTestId('seed-kit-apply').click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByTestId('seed-kit-run')).toHaveAttribute('data-status', 'applied');

    await page.goto('/seeds/runs');
    await expect(page.getByTestId('seed-kit-runs')).toBeVisible();
    await expect(page.getByTestId('seed-kit-run-row-status').first()).toContainText('Applied');
  });

  test('the same file again plans no change', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/seeds/upload');
    await page.locator('input[type=file]').setInputFiles({ name: 'games.yaml', mimeType: 'application/yaml', buffer: categories('games', 'Board games') });
    await page.getByRole('button', { name: 'Check the file' }).click();
    await expect(page.getByTestId('seed-kit-run-planned')).toContainText('0 to create, 0 to update, 0 to delete, 1 unchanged');
  });

  test('the viewer is refused by the guard', async ({ page }) => {
    await login(page, 'viewer');
    const response = await page.goto('/seeds');
    expect(response?.status()).toBe(403);
  });
});
