import '@testing-library/jest-dom/vitest'

/**
 * Recharts' ResponsiveContainer measures its parent with a ResizeObserver, which
 * jsdom does not implement. Real browsers have it; the stub keeps chart-bearing
 * pages mountable in tests.
 */
class ResizeObserverStub {
  observe(): void {}

  unobserve(): void {}

  disconnect(): void {}
}

if (!('ResizeObserver' in globalThis)) {
  Object.defineProperty(globalThis, 'ResizeObserver', {
    writable: true,
    configurable: true,
    value: ResizeObserverStub,
  })
}
