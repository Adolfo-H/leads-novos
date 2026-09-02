<?php

use App\Models\Company;
use App\Services\EstablishmentService;
use App\Support\Cnpj;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $cnpj = '';

    public string $fantasyName = '';

    public string $registrationStatus = 'ATIVA';

    public string $registrationStatusCode = '';

    public string $registrationStatusDate = '';

    public string $registrationStatusReasonCode = '';

    public string $startDate = '';

    public string $addressType = '';

    public string $street = '';

    public string $number = '';

    public string $complement = '';

    public string $neighborhood = '';

    public string $zipCode = '';

    public string $state = '';

    public string $municipalityCode = '';

    public string $municipalityName = '';

    public string $email = '';

    public string $phone1 = '';

    public string $phone2 = '';

    public string $fax = '';

    public string $specialSituation = '';

    public string $specialSituationDate = '';

    public function mount(
        Company $company
    ): void {
        $this->company = $company;
    }

    public function save(
        EstablishmentService $service
    ) {
        $validated = $this->validate([
            'cnpj' => [
                'required',
                'string',
                'max:30',
            ],

            'fantasyName' => [
                'nullable',
                'string',
                'max:255',
            ],

            'registrationStatus' => [
                'nullable',
                'string',
                'max:100',
            ],

            'registrationStatusCode' => [
                'nullable',
                'string',
                'max:10',
            ],

            'registrationStatusDate' => [
                'nullable',
                'date',
            ],

            'registrationStatusReasonCode' => [
                'nullable',
                'string',
                'max:10',
            ],

            'startDate' => [
                'nullable',
                'date',
            ],

            'addressType' => [
                'nullable',
                'string',
                'max:50',
            ],

            'street' => [
                'nullable',
                'string',
                'max:255',
            ],

            'number' => [
                'nullable',
                'string',
                'max:50',
            ],

            'complement' => [
                'nullable',
                'string',
                'max:255',
            ],

            'neighborhood' => [
                'nullable',
                'string',
                'max:150',
            ],

            'zipCode' => [
                'nullable',
                'string',
                'max:20',
            ],

            'state' => [
                'nullable',
                'string',
                'size:2',
            ],

            'municipalityCode' => [
                'nullable',
                'string',
                'max:20',
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

            'phone2' => [
                'nullable',
                'string',
                'max:50',
            ],

            'fax' => [
                'nullable',
                'string',
                'max:50',
            ],

            'specialSituation' => [
                'nullable',
                'string',
                'max:255',
            ],

            'specialSituationDate' => [
                'nullable',
                'date',
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

        try {
            $service->createBranch(
                $this->company,
                [
                    'cnpj' =>
                        $normalizedCnpj,

                    'fantasy_name' =>
                        $this->nullable(
                            $validated[
                                'fantasyName'
                            ]
                        ),

                    'registration_status' =>
                        $this->nullable(
                            $validated[
                                'registrationStatus'
                            ]
                        ),

                    'registration_status_code' =>
                        $this->nullable(
                            $validated[
                                'registrationStatusCode'
                            ]
                        ),

                    'registration_status_date' =>
                        $this->nullable(
                            $validated[
                                'registrationStatusDate'
                            ]
                        ),

                    'registration_status_reason_code' =>
                        $this->nullable(
                            $validated[
                                'registrationStatusReasonCode'
                            ]
                        ),

                    'start_date' =>
                        $this->nullable(
                            $validated[
                                'startDate'
                            ]
                        ),

                    'address_type' =>
                        $this->nullable(
                            $validated[
                                'addressType'
                            ]
                        ),

                    'street' =>
                        $this->nullable(
                            $validated[
                                'street'
                            ]
                        ),

                    'number' =>
                        $this->nullable(
                            $validated[
                                'number'
                            ]
                        ),

                    'complement' =>
                        $this->nullable(
                            $validated[
                                'complement'
                            ]
                        ),

                    'neighborhood' =>
                        $this->nullable(
                            $validated[
                                'neighborhood'
                            ]
                        ),

                    'zip_code' =>
                        $this->nullable(
                            $validated[
                                'zipCode'
                            ]
                        ),

                    'state' =>
                        $this->nullable(
                            $validated[
                                'state'
                            ]
                        ),

                    'municipality_code' =>
                        $this->nullable(
                            $validated[
                                'municipalityCode'
                            ]
                        ),

                    'municipality_name' =>
                        $this->nullable(
                            $validated[
                                'municipalityName'
                            ]
                        ),

                    'email' =>
                        $this->nullable(
                            $validated[
                                'email'
                            ]
                        ),

                    'phone_1' =>
                        $this->nullable(
                            $validated[
                                'phone1'
                            ]
                        ),

                    'phone_2' =>
                        $this->nullable(
                            $validated[
                                'phone2'
                            ]
                        ),

                    'fax' =>
                        $this->nullable(
                            $validated[
                                'fax'
                            ]
                        ),

                    'special_situation' =>
                        $this->nullable(
                            $validated[
                                'specialSituation'
                            ]
                        ),

                    'special_situation_date' =>
                        $this->nullable(
                            $validated[
                                'specialSituationDate'
                            ]
                        ),
                ]
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError(
                'cnpj',
                $exception->getMessage()
            );

            return;
        }

        session()->flash(
            'success',
            'Filial cadastrada com sucesso.'
        );

        return redirect()->route(
            'companies.show',
            $this->company
        );
    }

    private function nullable(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value === ''
            ? null
            : $value;
    }
};
?>

<div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    <div>

        <a
            href="{{ route('companies.show', $company) }}"
            wire:navigate
            class="text-sm font-medium text-blue-600 hover:text-blue-700"
        >
            ← Voltar para empresa
        </a>

        <h1 class="mt-4 text-2xl font-semibold text-zinc-900 dark:text-white">
            Adicionar filial
        </h1>

        <p class="mt-2 text-sm text-zinc-500">
            Cadastre outro estabelecimento pertencente à mesma empresa.
        </p>

    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">

        <p class="text-sm font-medium text-blue-900 dark:text-blue-200">
            {{ $company->corporate_name }}
        </p>

        <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
            Raiz do CNPJ:
            <strong>{{ $company->cnpj_root }}</strong>
        </p>

        <p class="mt-2 text-xs text-blue-600 dark:text-blue-400">
            A nova filial precisa possuir a mesma raiz de CNPJ.
        </p>

    </div>

    <form
        wire:submit="save"
        class="space-y-6"
    >

        {{-- IDENTIFICAÇÃO --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Identificação
            </h2>

            <div class="mt-5 grid gap-5 md:grid-cols-2">

                <div>

                    <label
                        for="cnpj"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        CNPJ da filial
                    </label>

                    <input
                        id="cnpj"
                        wire:model.blur="cnpj"
                        placeholder="00.000.000/0002-00"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('cnpj')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Nome fantasia
                    </label>

                    <input
                        wire:model.blur="fantasyName"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Data de abertura
                    </label>

                    <input
                        type="date"
                        wire:model.blur="startDate"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

            </div>

        </section>

        {{-- SITUAÇÃO --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Situação cadastral
            </h2>

            <div class="mt-5 grid gap-5 md:grid-cols-2 lg:grid-cols-4">

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Situação
                    </label>

                    <select
                        wire:model="registrationStatus"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >
                        <option value="">
                            Não informado
                        </option>

                        <option value="ATIVA">
                            Ativa
                        </option>

                        <option value="SUSPENSA">
                            Suspensa
                        </option>

                        <option value="INAPTA">
                            Inapta
                        </option>

                        <option value="BAIXADA">
                            Baixada
                        </option>

                        <option value="NULA">
                            Nula
                        </option>
                    </select>

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código
                    </label>

                    <input
                        wire:model.blur="registrationStatusCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Data da situação
                    </label>

                    <input
                        type="date"
                        wire:model.blur="registrationStatusDate"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Motivo
                    </label>

                    <input
                        wire:model.blur="registrationStatusReasonCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

            </div>

        </section>

        {{-- ENDEREÇO --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Endereço
            </h2>

            <div class="mt-5 grid gap-5 md:grid-cols-2 lg:grid-cols-4">

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Tipo
                    </label>

                    <input
                        wire:model.blur="addressType"
                        placeholder="Rua, Avenida..."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div class="lg:col-span-2">

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Logradouro
                    </label>

                    <input
                        wire:model.blur="street"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Número
                    </label>

                    <input
                        wire:model.blur="number"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div class="md:col-span-2">

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Complemento
                    </label>

                    <input
                        wire:model.blur="complement"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Bairro
                    </label>

                    <input
                        wire:model.blur="neighborhood"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        CEP
                    </label>

                    <input
                        wire:model.blur="zipCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        UF
                    </label>

                    <input
                        maxlength="2"
                        wire:model.blur="state"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm uppercase dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('state')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código município
                    </label>

                    <input
                        wire:model.blur="municipalityCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div class="md:col-span-2">

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Município
                    </label>

                    <input
                        wire:model.blur="municipalityName"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

            </div>

        </section>

        {{-- CONTATO --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Contato cadastral
            </h2>

            <div class="mt-5 grid gap-5 md:grid-cols-2 lg:grid-cols-4">

                <div class="md:col-span-2">

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        E-mail
                    </label>

                    <input
                        type="email"
                        wire:model.blur="email"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('email')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Telefone 1
                    </label>

                    <input
                        wire:model.blur="phone1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Telefone 2
                    </label>

                    <input
                        wire:model.blur="phone2"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Fax
                    </label>

                    <input
                        wire:model.blur="fax"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

            </div>

        </section>

        {{-- SITUAÇÃO ESPECIAL --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Situação especial
            </h2>

            <div class="mt-5 grid gap-5 md:grid-cols-2">

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Situação especial
                    </label>

                    <input
                        wire:model.blur="specialSituation"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Data
                    </label>

                    <input
                        type="date"
                        wire:model.blur="specialSituationDate"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

            </div>

        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

            <a
                href="{{ route('companies.show', $company) }}"
                wire:navigate
                class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-5 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200"
            >
                Cancelar
            </a>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
            >

                <span
                    wire:loading.remove
                    wire:target="save"
                >
                    Cadastrar filial
                </span>

                <span
                    wire:loading
                    wire:target="save"
                >
                    Salvando...
                </span>

            </button>

        </div>

    </form>

</div>
