<?php

use App\Models\Establishment;
use App\Services\CompanyService;
use App\Support\Cnpj;
use Livewire\Component;

new class extends Component
{
    public string $cnpj = '';

    public string $corporateName = '';

    public string $fantasyName = '';

    public string $type = 'matrix';

    public string $registrationStatus = 'ATIVA';

    public string $state = '';

    public string $municipalityName = '';

    public string $email = '';

    public string $phone1 = '';

    public string $shareCapital = '';

    public string $sizeCode = '';

    public string $legalNatureCode = '';

    public function save(CompanyService $service)
    {
        $validated = $this->validate([
            'cnpj' => [
                'required',
                'string',
                'max:30',
            ],

            'corporateName' => [
                'required',
                'string',
                'max:255',
            ],

            'fantasyName' => [
                'nullable',
                'string',
                'max:255',
            ],

            'type' => [
                'required',
                'in:matrix,branch',
            ],

            'registrationStatus' => [
                'nullable',
                'string',
                'max:100',
            ],

            'state' => [
                'nullable',
                'string',
                'size:2',
            ],

            'municipalityName' => [
                'nullable',
                'string',
                'max:150',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'phone1' => [
                'nullable',
                'string',
                'max:50',
            ],

            'shareCapital' => [
                'nullable',
                'string',
                'max:40',
            ],

            'sizeCode' => [
                'nullable',
                'string',
                'max:10',
            ],

            'legalNatureCode' => [
                'nullable',
                'string',
                'max:10',
            ],
        ]);

        $normalizedCnpj = Cnpj::normalize(
            $validated['cnpj']
        );

        if (! Cnpj::isValid($normalizedCnpj)) {
            $this->addError(
                'cnpj',
                'Informe um CNPJ válido.'
            );

            return;
        }

        if (
            Establishment::query()
                ->where('cnpj', $normalizedCnpj)
                ->exists()
        ) {
            $this->addError(
                'cnpj',
                'Este estabelecimento já está cadastrado.'
            );

            return;
        }

        $shareCapital = $this->parseMoney(
            $validated['shareCapital'] ?? ''
        );

        if (
            ($validated['shareCapital'] ?? '') !== ''
            && $shareCapital === null
        ) {
            $this->addError(
                'shareCapital',
                'Informe um capital social válido.'
            );

            return;
        }

        $service->createOrUpdateFromEstablishment(
            [
                'corporate_name' =>
                    trim($validated['corporateName']),

                'share_capital' =>
                    $shareCapital,

                'size_code' =>
                    $validated['sizeCode'] !== ''
                        ? trim($validated['sizeCode'])
                        : null,

                'legal_nature_code' =>
                    $validated['legalNatureCode'] !== ''
                        ? trim(
                            $validated['legalNatureCode']
                        )
                        : null,

                'source' =>
                    'manual',
            ],
            [
                'cnpj' =>
                    $normalizedCnpj,

                'type' =>
                    $validated['type'],

                'fantasy_name' =>
                    $validated['fantasyName'] !== ''
                        ? trim(
                            $validated['fantasyName']
                        )
                        : null,

                'registration_status' =>
                    $validated['registrationStatus']
                        !== ''
                            ? trim(
                                $validated[
                                    'registrationStatus'
                                ]
                            )
                            : null,

                'state' =>
                    $validated['state'] !== ''
                        ? mb_strtoupper(
                            trim($validated['state'])
                        )
                        : null,

                'municipality_name' =>
                    $validated['municipalityName']
                        !== ''
                            ? trim(
                                $validated[
                                    'municipalityName'
                                ]
                            )
                            : null,

                'email' =>
                    $validated['email'] !== ''
                        ? mb_strtolower(
                            trim($validated['email'])
                        )
                        : null,

                'phone_1' =>
                    $validated['phone1'] !== ''
                        ? trim($validated['phone1'])
                        : null,

                'source' =>
                    'manual',
            ],
        );

        session()->flash(
            'success',
            'Empresa cadastrada com sucesso.'
        );

        return redirect()->route(
            'companies.index'
        );
    }

    private function parseMoney(
        ?string $value
    ): ?float {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_ireplace(
            'R$',
            '',
            $value
        );

        $value = str_replace(
            ' ',
            '',
            $value
        );

        /*
         * Formato brasileiro:
         * 1.500.000,50
         */
        if (str_contains($value, ',')) {
            $value = str_replace(
                '.',
                '',
                $value
            );

            $value = str_replace(
                ',',
                '.',
                $value
            );
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number < 0) {
            return null;
        }

        return $number;
    }
};
?>

<div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                Inteligência de Leads
            </p>

            <h1 class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">
                Nova empresa
            </h1>

            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                Cadastre uma empresa manualmente para iniciar a análise comercial.
            </p>
        </div>

        <a
            href="{{ route('companies.index') }}"
            wire:navigate
            class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
        >
            Voltar
        </a>
    </div>

    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
            <p class="font-semibold">
                Verifique os campos destacados.
            </p>
        </div>
    @endif

    <form
        wire:submit="save"
        class="space-y-6"
    >
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-6">

            <div class="mb-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                    Identificação
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Dados principais do grupo empresarial e do estabelecimento.
                </p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">

                <div>
                    <label
                        for="cnpj"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        CNPJ
                    </label>

                    <input
                        id="cnpj"
                        type="text"
                        wire:model.blur="cnpj"
                        placeholder="00.000.000/0000-00"
                        autocomplete="off"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('cnpj')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="type"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Tipo do estabelecimento
                    </label>

                    <select
                        id="type"
                        wire:model="type"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                        <option value="matrix">
                            Matriz
                        </option>

                        <option value="branch">
                            Filial
                        </option>
                    </select>

                    @error('type')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label
                        for="corporateName"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Razão social
                    </label>

                    <input
                        id="corporateName"
                        type="text"
                        wire:model.blur="corporateName"
                        placeholder="Razão social completa"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('corporateName')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label
                        for="fantasyName"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Nome fantasia
                    </label>

                    <input
                        id="fantasyName"
                        type="text"
                        wire:model.blur="fantasyName"
                        placeholder="Opcional"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('fantasyName')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-6">

            <div class="mb-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                    Cadastro e porte
                </h2>
            </div>

            <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">

                <div>
                    <label
                        for="registrationStatus"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Situação cadastral
                    </label>

                    <select
                        id="registrationStatus"
                        wire:model="registrationStatus"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                        <option value="ATIVA">Ativa</option>
                        <option value="SUSPENSA">Suspensa</option>
                        <option value="INAPTA">Inapta</option>
                        <option value="BAIXADA">Baixada</option>
                        <option value="NULA">Nula</option>
                    </select>
                </div>

                <div>
                    <label
                        for="shareCapital"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Capital social
                    </label>

                    <input
                        id="shareCapital"
                        type="text"
                        wire:model.blur="shareCapital"
                        placeholder="Ex.: 2.500.000,00"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('shareCapital')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="sizeCode"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Código de porte
                    </label>

                    <input
                        id="sizeCode"
                        type="text"
                        wire:model.blur="sizeCode"
                        placeholder="Ex.: 05"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                </div>

                <div>
                    <label
                        for="legalNatureCode"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Natureza jurídica
                    </label>

                    <input
                        id="legalNatureCode"
                        type="text"
                        wire:model.blur="legalNatureCode"
                        placeholder="Código"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                </div>

            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-6">

            <div class="mb-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                    Localização e contato cadastral
                </h2>
            </div>

            <div class="grid gap-5 md:grid-cols-2">

                <div>
                    <label
                        for="state"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        UF
                    </label>

                    <input
                        id="state"
                        type="text"
                        maxlength="2"
                        wire:model.blur="state"
                        placeholder="PR"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm uppercase dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('state')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="municipalityName"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Município
                    </label>

                    <input
                        id="municipalityName"
                        type="text"
                        wire:model.blur="municipalityName"
                        placeholder="Toledo"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                </div>

                <div>
                    <label
                        for="email"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        E-mail cadastral
                    </label>

                    <input
                        id="email"
                        type="email"
                        wire:model.blur="email"
                        placeholder="contato@empresa.com.br"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('email')
                        <p class="mt-1.5 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="phone1"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Telefone cadastral
                    </label>

                    <input
                        id="phone1"
                        type="text"
                        wire:model.blur="phone1"
                        placeholder="(00) 0000-0000"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                </div>

            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

            <a
                href="{{ route('companies.index') }}"
                wire:navigate
                class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-5 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
                Cancelar
            </a>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="save">
                    Salvar empresa
                </span>

                <span wire:loading wire:target="save">
                    Salvando...
                </span>
            </button>

        </div>
    </form>

</div>
