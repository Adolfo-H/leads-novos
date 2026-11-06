@props([
    'sidebar' => false,
])

<a
    {{ $attributes->merge([
        'class' => 'ec-brand-link',
    ]) }}
>
    <span class="ec-brand-image-wrap">
        <img
            src="{{ asset('images/brand/pngexportcontrol.png') }}"
            alt="ExportControl"
            class="ec-brand-image"
        >
    </span>

    <span class="ec-brand-text">
        <span class="ec-brand-name">
            EXPORTCONTROL
        </span>

        <span class="ec-brand-product">
            Prospector
        </span>
    </span>
</a>
