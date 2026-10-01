import { defineConfig } from '@playwright/test';

// Deterministic CI suite (not MCP): two WordPress sites from compose.yaml,
// one full migration flow per run, single worker, generous timeouts for
// shared-CI container startup.
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 240_000,
    expect: { timeout: 180_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.SOURCE_URL || 'http://localhost:8081',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
