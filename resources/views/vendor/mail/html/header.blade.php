@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="https://laravel.com/img/notification-logo-v2.1.png" class="logo" alt="Laravel Logo">
@else
{{-- Text wordmark with a gold accent dot. Deliberately text (not an image) so it
     renders reliably across email clients and in local mail catchers, where an
     absolute asset URL (app.url) isn't reachable. --}}
<span class="wordmark">{{ trim($slot) }}</span><span class="wordmark-dot">.</span>
@endif
</a>
</td>
</tr>
