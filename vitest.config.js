import { defineConfig } from 'vitest/config';

// Unit tests for the game's pure logic. Run with `npm run test:js`.
// The browser playthrough (`npm run test:browser`) covers the wiring.
export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.js'],
    },
});
