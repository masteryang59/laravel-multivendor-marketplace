@props(['label', 'value', 'hint' => null])
<div {{ $attributes->class('card p-5') }}>
    <p class="text-sm font-medium text-stone-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tracking-tight text-stone-900 tabular-nums">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>
    @endif
</div>
