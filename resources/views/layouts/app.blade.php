<x-layouts::app.sidebar :title="$title ?? null">

    <flux:main class="ec-main">

        {{ $slot }}

    </flux:main>

</x-layouts::app.sidebar>
