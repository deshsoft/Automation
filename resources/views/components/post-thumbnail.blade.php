@props(['post', 'size' => 'size-12'])
<div class="{{ $size }} flex shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-slate-400">
    @if ($post->isPhoto() && $post->hasMedia())
        <img src="{{ $post->mediaUrl() }}" alt="" class="size-full object-cover" loading="lazy">
    @elseif ($post->isVideo())
        <x-icon name="video" class="size-5" />
    @elseif ($post->option('link'))
        <x-icon name="link" class="size-5" />
    @else
        <x-icon name="text" class="size-5" />
    @endif
</div>
