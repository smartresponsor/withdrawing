import { expect, test } from '@playwright/test';

test('Withdrawing Playwright harness is executable', () => {
  expect(typeof test).toBe('function');
  expect(typeof expect).toBe('function');
});
