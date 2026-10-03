import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

test('Withdrawing Playwright harness is executable', () => {
  expect(typeof test).toBe('function');
  expect(typeof expect).toBe('function');

  execFileSync('php', ['tool/behavioral-coverage.php'], { cwd: process.cwd(), stdio: 'pipe' });
});
