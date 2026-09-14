<x-layouts::app :title="__('Dashboard')">

    @php
        $companiesTotal =
            \App\Models\Company::query()->count();

        $establishmentsTotal =
            \App\Models\Establishment::query()->count();

        $activeTotal =
            \App\Models\Establishment::query()
                ->where('registration_status', 'ATIVA')
                ->count();

        $matrixTotal =
            \App\Models\Establishment::query()
                ->where('type', 'matrix')
                ->count();

        $branchesTotal =
            \App\Models\Establishment::query()
                ->where('type', 'branch')
                ->count();

        $cnaesTotal =
            \App\Models\Cnae::query()
                ->whereHas('establishments')
                ->count();

        $emailTotal =
            \App\Models\Establishment::query()
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->count();

        $phoneTotal =
            \App\Models\Establishment::query()
                ->whereNotNull('phone_1')
                ->where('phone_1', '!=', '')
                ->count();

        $activePercent = $establishmentsTotal > 0
            ? round(
                ($activeTotal / $establishmentsTotal) * 100
            )
            : 0;

        $emailPercent = $establishmentsTotal > 0
            ? round(
                ($emailTotal / $establishmentsTotal) * 100
            )
            : 0;

        $phonePercent = $establishmentsTotal > 0
            ? round(
                ($phoneTotal / $establishmentsTotal) * 100
            )
            : 0;
    @endphp

    <div class="ec-page-shell">

        {{-- CABEÇALHO --}}
        <div class="ec-page-header">

            <div>

                <div class="ec-page-kicker">
                    Inteligência Comercial
                </div>

                <h1 class="ec-page-title mt-1">
                    Dashboard
                </h1>

                <p class="ec-page-description">
                    Visão geral da base utilizada pelo Prospector.
                </p>

            </div>

            <div class="ec-dashboard-status">

                <span class="ec-dashboard-status-dot"></span>

                Sistema operacional

            </div>

        </div>


        {{-- CARDS --}}
        <div class="ec-dashboard-cards">

            <div class="ec-dashboard-card">

                <div class="ec-dashboard-card-top">

                    <span>
                        Empresas
                    </span>

                    <div class="ec-dashboard-card-icon">
                        ◇
                    </div>

                </div>

                <strong>
                    {{ number_format($companiesTotal, 0, ',', '.') }}
                </strong>

                <small>
                    Grupos empresariais cadastrados
                </small>

            </div>


            <div class="ec-dashboard-card">

                <div class="ec-dashboard-card-top">

                    <span>
                        Estabelecimentos
                    </span>

                    <div class="ec-dashboard-card-icon">
                        ⌂
                    </div>

                </div>

                <strong>
                    {{ number_format($establishmentsTotal, 0, ',', '.') }}
                </strong>

                <small>
                    Matriz e filiais
                </small>

            </div>


            <div class="ec-dashboard-card">

                <div class="ec-dashboard-card-top">

                    <span>
                        Estabelecimentos ativos
                    </span>

                    <div class="ec-dashboard-card-icon">
                        ✓
                    </div>

                </div>

                <strong>
                    {{ number_format($activeTotal, 0, ',', '.') }}
                </strong>

                <small>
                    {{ $activePercent }}% da base
                </small>

            </div>


            <div class="ec-dashboard-card ec-dashboard-card-accent">

                <div class="ec-dashboard-card-top">

                    <span>
                        CNAEs mapeados
                    </span>

                    <div class="ec-dashboard-card-icon">
                        #
                    </div>

                </div>

                <strong>
                    {{ number_format($cnaesTotal, 0, ',', '.') }}
                </strong>

                <small>
                    Atividades vinculadas à base
                </small>

            </div>

        </div>


        {{-- PAINÉIS --}}
        <div class="ec-dashboard-grid">

            {{-- QUALIDADE DA BASE --}}
            <section class="ec-dashboard-panel">

                <div class="ec-dashboard-panel-header">

                    <div>

                        <h2>
                            Qualidade da base
                        </h2>

                        <p>
                            Cobertura atual dos dados empresariais.
                        </p>

                    </div>

                    <span>
                        Atual
                    </span>

                </div>


                <div class="ec-dashboard-progress-list">

                    <div>

                        <div class="ec-dashboard-progress-head">

                            <span>
                                Situação ativa
                            </span>

                            <strong>
                                {{ $activePercent }}%
                            </strong>

                        </div>

                        <div class="ec-dashboard-progress">

                            <span
                                style="width: {{ $activePercent }}%"
                            ></span>

                        </div>

                    </div>


                    <div>

                        <div class="ec-dashboard-progress-head">

                            <span>
                                E-mail cadastral
                            </span>

                            <strong>
                                {{ $emailPercent }}%
                            </strong>

                        </div>

                        <div class="ec-dashboard-progress">

                            <span
                                style="width: {{ $emailPercent }}%"
                            ></span>

                        </div>

                    </div>


                    <div>

                        <div class="ec-dashboard-progress-head">

                            <span>
                                Telefone cadastral
                            </span>

                            <strong>
                                {{ $phonePercent }}%
                            </strong>

                        </div>

                        <div class="ec-dashboard-progress">

                            <span
                                style="width: {{ $phonePercent }}%"
                            ></span>

                        </div>

                    </div>

                </div>

            </section>


            {{-- COMPOSIÇÃO --}}
            <section class="ec-dashboard-panel">

                <div class="ec-dashboard-panel-header">

                    <div>

                        <h2>
                            Estrutura empresarial
                        </h2>

                        <p>
                            Composição dos estabelecimentos.
                        </p>

                    </div>

                </div>


                <div class="ec-dashboard-structure">

                    <div>

                        <span class="ec-dashboard-structure-number">
                            {{ $matrixTotal }}
                        </span>

                        <span class="ec-dashboard-structure-label">
                            Matrizes
                        </span>

                    </div>


                    <div class="ec-dashboard-structure-divider"></div>


                    <div>

                        <span class="ec-dashboard-structure-number">
                            {{ $branchesTotal }}
                        </span>

                        <span class="ec-dashboard-structure-label">
                            Filiais
                        </span>

                    </div>

                </div>

            </section>

        </div>


        {{-- FLUXO FUTURO --}}
        <section class="ec-dashboard-panel">

            <div class="ec-dashboard-panel-header">

                <div>

                    <h2>
                        Motor de prospecção
                    </h2>

                    <p>
                        Descoberta e qualificação automática de novas oportunidades comerciais.
                    </p>

                </div>

                <a
                    href="{{ route('prospecting.index') }}"
                    wire:navigate
                    class="
                        inline-flex items-center gap-2
                        rounded-lg border
                        border-cyan-300/20
                        bg-cyan-300/5
                        px-3 py-1.5
                        text-xs font-semibold
                        text-cyan-300
                        transition
                        hover:bg-cyan-300/10
                    "
                >
                    Abrir motor
                    <span>→</span>
                </a>

            </div>


            <div class="ec-pipeline">

                <div class="ec-pipeline-step ec-pipeline-step-ready">

                    <span>
                        01
                    </span>

                    <strong>
                        Descoberta
                    </strong>

                    <small>
                        Receita Federal e filtros ICP
                    </small>

                </div>

                <div class="ec-pipeline-arrow">
                    →
                </div>

                <div class="ec-pipeline-step">

                    <span>
                        02
                    </span>

                    <strong>
                        ICP
                    </strong>

                    <small>
                        Perfil e aderência comercial
                    </small>

                </div>

                <div class="ec-pipeline-arrow">
                    →
                </div>

                <div class="ec-pipeline-step">

                    <span>
                        03
                    </span>

                    <strong>
                        CRM
                    </strong>

                    <small>
                        HubSpot e histórico comercial
                    </small>

                </div>

                <div class="ec-pipeline-arrow">
                    →
                </div>

                <div class="ec-pipeline-step">

                    <span>
                        04
                    </span>

                    <strong>
                        Exportação
                    </strong>

                    <small>
                        Evidências públicas de exportação
                    </small>

                </div>

                <div class="ec-pipeline-arrow">
                    →
                </div>

                <div class="ec-pipeline-step">

                    <span>
                        05
                    </span>

                    <strong>
                        Score
                    </strong>

                    <small>
                        Priorização e fila de Leads
                    </small>

                </div>

            </div>

        </section>

    </div>

</x-layouts::app>
