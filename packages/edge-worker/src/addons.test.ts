import { describe, expect, it } from 'vitest';
import { applyHtmlAddons, injectSnippets, injectTags, injectTurnstileWidget } from './addons';

describe('edge addons html helpers', () => {
  it('injects turnstile into forms', () => {
    const html = '<html><body><form action="/contact"></form></body></html>';
    const out = injectTurnstileWidget(html, 'site-key-1');
    expect(out).toContain('cf-turnstile');
    expect(out).toContain('site-key-1');
    expect(out).toContain('challenges.cloudflare.com/turnstile');
  });

  it('injects head snippets', () => {
    const html = '<html><head><title>x</title></head><body></body></html>';
    const out = injectSnippets(html, '/', {
      enabled: true,
      items: [{ name: 'meta', phase: 'head', path: '/*', html: '<meta name="x" content="1">' }],
    });
    expect(out).toContain('<meta name="x" content="1"></head>');
  });

  it('injects https tag scripts', () => {
    const html = '<html><head></head><body></body></html>';
    const out = injectTags(html, {
      enabled: true,
      tools: [{ name: 'a', src: 'https://example.com/a.js', async: true }],
    });
    expect(out).toContain('src="https://example.com/a.js"');
  });

  it('applyHtmlAddons combines tags and snippets', () => {
    const html = '<html><head></head><body><form></form></body></html>';
    const out = applyHtmlAddons(html, '/', {
      snippets: { enabled: true, items: [{ name: 'n', phase: 'head', path: '/*', html: '<!--s-->' }] },
      tags: { enabled: true, tools: [{ name: 't', src: 'https://cdn.example/t.js' }] },
      turnstile: { enabled: true, site_key: 'sk', secret_key: 'sec', mode: 'forms' },
      forms: { enabled: true, endpoints: [] },
    });
    expect(out).toContain('<!--s-->');
    expect(out).toContain('cdn.example/t.js');
    expect(out).toContain('cf-turnstile');
  });
});
