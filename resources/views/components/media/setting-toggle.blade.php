@props([
    'name',
    'label',
    'hint' => null,
    'checked' => false,
])

{{--
    One preference, as a row.

    The hidden input before the checkbox is what makes "off" reach the server:
    an unticked checkbox sends nothing at all, so without it a toggle could be
    turned on and never turned back off.
--}}
<label class="settings-row">
    <span class="settings-row__text">
        <span class="settings-row__label">{{ $label }}</span>
        @if ($hint)
            <span class="settings-row__hint">{{ $hint }}</span>
        @endif
    </span>

    <input type="hidden" name="{{ $name }}" value="0">
    <input type="checkbox"
           name="{{ $name }}"
           value="1"
           @checked($checked)
           {{ $attributes->merge(['class' => 'settings-switch']) }}>
</label>
