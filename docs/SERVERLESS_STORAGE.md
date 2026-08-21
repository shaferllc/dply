---
title: "Function storage & uploads"
slug: serverless-storage
category: "Services"
order: 30
description: "Each function gets its own object-storage bucket wired into its environment, and browser uploads go straight to it with a signed URL instead of through the function."
group: serverless
---

# Function storage & uploads

Every dply-managed function gets **its own bucket**. dply creates it on the
first deploy, mints a key granted to that bucket alone, and puts the connection
details in the function's environment — so there is nothing to configure and no
credential to copy.

## The disk

The bucket is wired up as a Laravel disk named `uploads`:

```php
Storage::disk('uploads')->put('reports/june.pdf', $contents);
$url = Storage::disk('uploads')->temporaryUrl('reports/june.pdf', now()->addMinutes(5));
```

The variables arrive in the environment as `AWS_UPLOADS_BUCKET`,
`AWS_UPLOADS_ACCESS_KEY_ID`, `AWS_UPLOADS_SECRET_ACCESS_KEY`,
`AWS_UPLOADS_DEFAULT_REGION` and `AWS_UPLOADS_ENDPOINT`, and Laravel's
`config/filesystems.php` picks them up if you add the matching disk:

```php
'uploads' => [
    'driver' => 's3',
    'key' => env('AWS_UPLOADS_ACCESS_KEY_ID'),
    'secret' => env('AWS_UPLOADS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_UPLOADS_DEFAULT_REGION'),
    'bucket' => env('AWS_UPLOADS_BUCKET'),
    'endpoint' => env('AWS_UPLOADS_ENDPOINT'),
    'use_path_style_endpoint' => false,
    'throw' => false,
],
```

It is **not** your default disk. `FILESYSTEM_DISK` stays whatever you set, so
dply never silently repoints storage your app was already using.

The key reaches this bucket and no other. It cannot see another function's
files, and it cannot see the bucket dply publishes your front-end build to.

## Uploads do not go through the function

A function has a hard cap on request size, and a big upload will hit it long
before your validation runs. The fix is the same one every serverless platform
uses: the browser uploads **straight to the bucket**, and your app only ever
signs the request.

**1. An endpoint that signs an upload** — authorize it exactly like any other
action; this is the moment you decide who may upload and what they may write:

```php
Route::post('/uploads/sign', function (Request $request) {
    $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'type' => ['required', 'string', 'max:100'],
    ]);

    Gate::authorize('upload-files');

    $key = 'tmp/'.$request->user()->id.'/'.Str::uuid().'-'.$request->string('name');

    return [
        'key' => $key,
        'url' => Storage::disk('uploads')->temporaryUploadUrl(
            $key, now()->addMinutes(5), ['ContentType' => $request->string('type')],
        ),
    ];
})->middleware('auth');
```

**2. The browser PUTs the file at the signed URL:**

```js
const { url, key } = await (await fetch('/uploads/sign', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify({ name: file.name, type: file.type }),
})).json()

await fetch(url.url ?? url, { method: 'PUT', body: file, headers: { 'Content-Type': file.type } })
// `key` is what you post back to your app once the upload finishes.
```

**3. Your app claims the file** — move it out of `tmp/` once you have accepted
it:

```php
Storage::disk('uploads')->move($request->string('key'), 'invoices/'.$invoice->id.'.pdf');
```

The bucket already allows cross-origin `PUT`, so no CORS setup is needed.

## The `tmp/` prefix expires

Anything left under `tmp/` is deleted after **24 hours**. That is deliberate: an
upload the browser finished but your app never claimed — the user closed the
tab, the request failed — would otherwise sit in the bucket forever, on your
storage bill. Move files out of `tmp/` as soon as you accept them, and use it
for nothing else. Abandoned multipart uploads are cleared on the same schedule.

## What it costs

Storage in your function's bucket is **measured and shown** on the function's
Overview, next to the front-end asset figures. It is not billed today. Your
published front-end build — a different bucket, delivered by dply's CDN — is the
one with an included allowance and metered overage.

## Deleting a function

Deleting a function deletes its bucket **and everything in it**, and revokes the
key. Copy anything you want to keep out first — there is no undo, and dply keeps
no copy.

## Related sections

- **Front-end assets** — the published build, its allowance, and custom asset
  domains.
- **Environment** — where these variables appear, alongside your own.
