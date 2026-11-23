@props(['title' => 'Propostas Comerciais', 'subtitle' => 'Crie, organize e acompanhe propostas da ExportControl.'])
<x-layouts::app :title="$title ?? 'Propostas Comerciais'">
    @include('proposals.style')
    <div class="ecp" data-proposals-exportcontrol="v1">
        <div class="head">
            <div><span class="eyebrow">RELACIONAMENTO / PROPOSTAS COMERCIAIS</span><h1>{{ $title ?? 'Propostas Comerciais' }}</h1><p>{{ $subtitle ?? 'Crie, organize e acompanhe propostas da ExportControl.' }}</p></div>
            <div class="actions"><a class="btn primary" href="{{ route('proposals.create') }}">+ Nova proposta</a></div>
        </div>
        <nav class="tabs" aria-label="Seções de propostas">
            <a href="{{ route('proposals.index') }}" class="{{ request()->routeIs('proposals.index', 'proposals.show') ? 'active' : '' }}">Todas as propostas</a>
            <a href="{{ route('proposals.create') }}" class="{{ request()->routeIs('proposals.create', 'proposals.edit') ? 'active' : '' }}">Criar / editar</a>
            <a href="{{ route('proposals.templates') }}" class="{{ request()->routeIs('proposals.templates') ? 'active' : '' }}">Modelos</a>
            <a href="{{ route('proposals.pricing') }}" class="{{ request()->routeIs('proposals.pricing') ? 'active' : '' }}">Preços e reajustes</a>
            <a href="{{ route('proposals.import.page') }}" class="{{ request()->routeIs('proposals.import.*') ? 'active' : '' }}">Importar histórico</a>
        </nav>
        @if (session('proposal_ok')) <div class="notice success" role="status">{{ session('proposal_ok') }}</div> @endif
        @if ($errors->any())<div class="notice error" role="alert"><strong>Confira as informações:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </div>
</x-layouts::app>
