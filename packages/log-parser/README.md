# dply/log-parser

Tolerant, dependency-free parsers that turn raw log text into structured records.

Built first-party to replace two unmaintained packages we'd otherwise depend on
(`roma-glushko/monolog-parser` and the abandoned `tm/error-log-parser`), with a
return shape that matches dply's own `NginxAccessLogParser` (`['parsed' => bool, ...]`)
and proper multi-line handling for stack traces.

## Laravel / Monolog

```php
use Dply\LogParser\LaravelLogParser;

$records = (new LaravelLogParser())->parse($laravelLogText);
// [
//   [
//     'parsed'   => true,
//     'datetime' => DateTimeImmutable,
//     'channel'  => 'production',
//     'level'    => 'ERROR',
//     'message'  => 'Undefined variable $x',
//     'context'  => ['exception' => '...'],   // peeled + decoded when present
//     'extra'    => [],
//     'trace'    => ['#0 /app/...', '#1 ...'], // continuation lines grouped here
//     'raw'      => '...',
//   ],
// ]
```

Trailing `context`/`extra` JSON is only peeled off the message when it decodes
cleanly, so a message that merely ends in `}` is never misread as context.

## NGINX / Apache error logs

```php
use Dply\LogParser\WebserverErrorLogParser;

$entries = (new WebserverErrorLogParser())->parse($nginxErrorLogText);
// nginx entry:
//   ['parsed'=>true,'type'=>'nginx','datetime'=>..., 'level'=>'error','pid'=>1234,
//    'message'=>'FastCGI sent in stderr: "PHP message: ..."',
//    'client'=>'1.2.3.4','request'=>'GET /x HTTP/1.1','host'=>'example.com', ...]
```

The format (nginx vs apache) is auto-detected per line.

## License

MIT.
