@props(['label', 'value', 'icon', 'hint' => null, 'tone' => 'indigo'])
@php($tones = [
    'indigo' => 'bg-indigo-50 text-indigo-600',
    'green' => 'bg-green-50 text-green-700',
    'red' => 'bg-red-50 text-red-600',
    'amber' => 'bg-amber-50 text-amber-700',
    'slate' => 'bg-slate-100 text-slate-600',
])
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 tabular-nums sm:text-3xl">{{ $value }}</div>
        </div>
        <div class="hidden rounded-lg p-2 sm:block {{ $tones[$tone] }}">
            <x-icon :name="$icon" class="size-5" />
        </div>
    </div>
    @if ($hint)
        <div class="mt-3 text-xs text-slate-500">{{ $hint }}</div>
    @endif
</div>
