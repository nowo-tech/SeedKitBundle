import { expect, type Page } from '@playwright/test';

/** Hide nothing: wait until the Symfony Web Debug Toolbar has finished loading (dev chrome; optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        return !bar || bar.offsetParent === null || !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
}

/** Signs in through the FormKit login form (both demo users have the password "demo"). */
export async function login(page: Page, user: 'admin' | 'viewer'): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password').fill('demo');
  await page.getByTestId('login-form').getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-providers')).toBeVisible();
}
