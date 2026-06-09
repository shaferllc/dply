import { describe, expect, it } from 'vitest';
import { injectRumScript, shouldInjectRum, VITALS_BEACON_PATH } from './rum';

describe('shouldInjectRum', () => {
  it('injects for index.html when ingest is enabled', () => {
    expect(shouldInjectRum('index.html', true)).toBe(true);
    expect(shouldInjectRum('about.html', true)).toBe(true);
  });

  it('skips non-html paths and when ingest is disabled', () => {
    expect(shouldInjectRum('assets/app.js', true)).toBe(false);
    expect(shouldInjectRum('index.html', false)).toBe(false);
  });
});

describe('injectRumScript', () => {
  it('inserts script before closing body tag', () => {
    const html = '<html><body><p>Hi</p></body></html>';
    const result = injectRumScript(html);

    expect(result).toContain('</body>');
    expect(result).toContain(VITALS_BEACON_PATH);
    expect(result.indexOf('<script>')).toBeLessThan(result.indexOf('</body>'));
  });
});
