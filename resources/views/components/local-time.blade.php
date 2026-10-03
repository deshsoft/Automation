@props(['time'])
@if ($time)
    {{ $time->timezone(config('app.display_timezone'))->format('d M Y, h:i A') }}
@else
    —
@endif
