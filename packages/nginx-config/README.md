# dply/nginx-config

Parse, build and **diff** NGINX configuration as a structured directive tree.

This is a modernized, trimmed fork of [`nelexa/crossplane`](https://github.com/Ne-Lexa/php-crossplane)
(itself a PHP port of nginx's own [`crossplane`](https://github.com/nginxinc/crossplane)).
We took it in-house because the upstream is unmaintained, emits PHP 8.4/8.5
deprecations, and pulled in a `symfony/console` CLI we never use.

What changed from upstream:

- Namespace `Nelexa\NginxParser\*` → `Dply\NginxConfig\*`.
- Dropped the `Console/` CLI and `bin/`, removing the `symfony/console` and
  `symfony/polyfill-php73`/`php80` dependencies (those polyfills are native on
  our PHP ≥ 8.3 baseline).
- Fixed implicitly-nullable parameter deprecations.
- Added [`ConfigDiff`](src/ConfigDiff.php), a small facade for the one thing dply
  actually needs: comparing two configs to find what an overwrite would destroy.

## Read-back before overwrite

```php
use Dply\NginxConfig\ConfigDiff;

// What does the live on-box vhost contain that our freshly generated one does not?
$lost = ConfigDiff::lostOnOverwrite($currentOnBox, $incomingGenerated);

if ($lost !== []) {
    // e.g. ['server > add_header X-Frame-Options SAMEORIGIN', 'server > location /legacy ...']
    // Someone hand-edited the vhost; overwriting would drop these directives.
}
```

`ConfigDiff::signatures($config)` flattens a config into normalized, block-prefixed
directive signatures; `ConfigDiff::parse($config)` returns the raw crossplane
payload (`status` / `errors` / `config`).

## Caveat

The lexer tolerates some malformed input (an unclosed `{` is implicitly closed at
EOF), so this library is **not** a syntax validator. `nginx -t` on the server
remains the authority on whether a config is loadable. This library is for
*structural comparison*, not certification.

## License

Apache-2.0 (inherited from upstream). See `LICENSE` and `NOTICE`.
