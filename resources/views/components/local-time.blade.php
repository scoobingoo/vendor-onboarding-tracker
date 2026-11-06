{{-- Rendered in UTC; public/js/app.js rewrites it in the viewer's local time. --}}
@props(['at'])
<time datetime="{{ $at->toIso8601ZuluString() }}" data-local>{{ $at->utc()->format('j M Y, H:i') }} UTC</time>