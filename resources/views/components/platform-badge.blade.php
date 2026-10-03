@props(['platform'])
@php($styles = [
    'facebook' => ['FB', 'bg-blue-600'],
    'instagram' => ['IG', 'bg-pink-600'],
    'youtube' => ['YT', 'bg-red-600'],
    'tiktok' => ['TT', 'bg-slate-900'],
])
@php([$short, $color] = $styles[$platform instanceof \App\Enums\Platform ? $platform->value : $platform] ?? ['?', 'bg-slate-400'])
<span {{ $attributes->merge(['class' => "inline-flex size-6 shrink-0 items-center justify-center rounded-md text-[10px] font-bold text-white $color"]) }}>{{ $short }}</span>
