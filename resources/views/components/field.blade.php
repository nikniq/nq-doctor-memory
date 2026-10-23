@props(['name', 'label', 'type' => 'text', 'value' => null])

<div class="field">
    <label for="{{ $name }}" class="field__label">{{ $label }}</label>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->merge(['class' => 'field__input']) }}
    >
    @error($name)
        <p id="{{ $name }}-error" class="field__error">{{ $message }}</p>
    @enderror
</div>
