<?php

use App\Models\Company;
use App\Services\CompanyService;
use App\Support\Cnpj;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $cnpj = '';

    public string $corporateName = '';

    public string $fantasyName = '';

    public string $registrationStatus = '';

    public string $registrationStatusCode = '';

    public string $registrationStatusDate = '';

    public string $registrationStatusReasonCode = '';

    public string $startDate = '';

    public string $shareCapital = '';

    public string $sizeCode = '';

    public string $sizeDescription = '';

    public string $legalNatureCode = '';

    public string $legalNatureDescription = '';

    public string $responsibleQualificationCode = '';

    public string $federativeEntity = '';

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
        $this->company = $company->load([
            'establishments',
        ]);

        $matrix = $company
            ->establishments
            ->firstWhere(
                'type',
                'matrix'
            )
            ?? $company
                ->establishments
                ->first();

        $this->corporateName =
            $company->corporate_name;

        $this->shareCapital =
            $company->share_capital !== null
                ? number_format(
                    (float) $company->share_capital,
                    2,
                    ',',
                    '.'
                )
                : '';

        $this->sizeCode =
            $company->size_code ?? '';

        $this->sizeDescription =
            $company->size_description ?? '';

        $this->legalNatureCode =
            $company->legal_nature_code ?? '';

        $this->legalNatureDescription =
            $company
                ->legal_nature_description
                ?? '';

        $this->responsibleQualificationCode =
            $company
                ->responsible_qualification_code
                ?? '';

        $this->federativeEntity =
            $company->federative_entity ?? '';

        if (! $matrix) {
            return;
        }

        $this->cnpj =
            Cnpj::format(
                $matrix->cnpj
            );

        $this->fantasyName =
            $matrix->fantasy_name ?? '';

        $this->registrationStatus =
            $matrix->registration_status ?? '';

        $this->registrationStatusCode =
            $matrix
                ->registration_status_code
                ?? '';

        $this->registrationStatusDate =
            $matrix
                ->registration_status_date
                ?->format('Y-m-d')
                ?? '';

        $this->registrationStatusReasonCode =
            $matrix
                ->registration_status_reason_code
                ?? '';

        $this->startDate =
            $matrix
                ->start_date
                ?->format('Y-m-d')
                ?? '';

        $this->addressType =
            $matrix->address_type ?? '';

        $this->street =
            $matrix->street ?? '';

        $this->number =
            $matrix->number ?? '';

        $this->complement =
            $matrix->complement ?? '';

        $this->neighborhood =
            $matrix->neighborhood ?? '';

        $this->zipCode =
            $matrix->zip_code ?? '';

        $this->state =
            $matrix->state ?? '';

        $this->municipalityCode =
            $matrix->municipality_code ?? '';

        $this->municipalityName =
            $matrix->municipality_name ?? '';

        $this->email =
            $matrix->email ?? '';

        $this->phone1 =
            $matrix->phone_1 ?? '';

        $this->phone2 =
            $matrix->phone_2 ?? '';

        $this->fax =
            $matrix->fax ?? '';

        $this->specialSituation =
            $matrix->special_situation ?? '';

        $this->specialSituationDate =
            $matrix
                ->special_situation_date
                ?->format('Y-m-d')
                ?? '';
    }

    public function save(
        CompanyService $service
    ) {
        $validated = $this->validate([
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

            'sizeDescription' => [
                'nullable',
                'string',
                'max:100',
            ],

            'legalNatureCode' => [
                'nullable',
                'string',
                'max:10',
            ],

            'legalNatureDescription' => [
                'nullable',
                'string',
                'max:255',
            ],

            'responsibleQualificationCode' => [
                'nullable',
                'string',
                'max:10',
            ],

            'federativeEntity' => [
                'nullable',
                'string',
                'max:255',
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

        $shareCapital =
            $this->parseMoney(
                $validated[
                    'shareCapital'
                ] ?? ''
            );

        if (
            ($validated['shareCapital'] ?? '')
                !== ''
            && $shareCapital === null
        ) {
            $this->addError(
                'shareCapital',
                'Informe um capital social válido.'
            );

            return;
        }

        $service->updateCompanyAndMatrix(
            $this->company,
            [
                'corporate_name' =>
                    $validated[
                        'corporateName'
                    ],

                'share_capital' =>
                    $shareCapital,

                'size_code' =>
                    $this->nullable(
                        $validated[
                            'sizeCode'
                        ]
                    ),

                'size_description' =>
                    $this->nullable(
                        $validated[
                            'sizeDescription'
                        ]
                    ),

                'legal_nature_code' =>
                    $this->nullable(
                        $validated[
                            'legalNatureCode'
                        ]
                    ),

                'legal_nature_description' =>
                    $this->nullable(
                        $validated[
                            'legalNatureDescription'
                        ]
                    ),

                'responsible_qualification_code' =>
                    $this->nullable(
                        $validated[
                            'responsibleQualificationCode'
                        ]
                    ),

                'federative_entity' =>
                    $this->nullable(
                        $validated[
                            'federativeEntity'
                        ]
                    ),
            ],
            [
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
            ],
        );

        session()->flash(
            'success',
            'Empresa atualizada com sucesso.'
        );

        return redirect()->route(
            'companies.show',
            $this->company
        );
    }

    private function nullable(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
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

<div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

        <div>

            <a
                href="{{ route('companies.show', $company) }}"
                wire:navigate
                class="text-sm font-medium text-blue-600 hover:text-blue-700"
            >
                ← Voltar para empresa
            </a>

            <h1 class="mt-4 text-2xl font-semibold text-zinc-900 dark:text-white">
                Editar empresa
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Atualize os dados cadastrais da empresa e da matriz.
            </p>

        </div>

    </div>

    @if ($errors->any())

        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
            Verifique os campos destacados.
        </div>

    @endif

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

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        CNPJ
                    </label>

                    <input
                        type="text"
                        value="{{ $cnpj }}"
                        disabled
                        class="w-full cursor-not-allowed rounded-lg border border-zinc-200 bg-zinc-100 px-3 py-2.5 text-sm text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950"
                    >

                    <p class="mt-1 text-xs text-zinc-500">
                        O CNPJ não pode ser alterado por esta tela.
                    </p>

                </div>

                <div>

                    <label
                        for="corporateName"
                        class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Razão social
                    </label>

                    <input
                        id="corporateName"
                        wire:model.blur="corporateName"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('corporateName')
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

        {{-- PORTE E NATUREZA --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">
                Porte e natureza jurídica
            </h2>

            <div class="mt-5 grid gap-5 md:grid-cols-2 lg:grid-cols-3">

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Capital social
                    </label>

                    <input
                        wire:model.blur="shareCapital"
                        placeholder="2.500.000,00"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                    @error('shareCapital')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código do porte
                    </label>

                    <input
                        wire:model.blur="sizeCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Descrição do porte
                    </label>

                    <input
                        wire:model.blur="sizeDescription"
                        placeholder="Ex.: Demais"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código natureza jurídica
                    </label>

                    <input
                        wire:model.blur="legalNatureCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div class="lg:col-span-2">

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Descrição natureza jurídica
                    </label>

                    <input
                        wire:model.blur="legalNatureDescription"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div>

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Qualificação responsável
                    </label>

                    <input
                        wire:model.blur="responsibleQualificationCode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

                <div class="lg:col-span-2">

                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Ente federativo responsável
                    </label>

                    <input
                        wire:model.blur="federativeEntity"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    >

                </div>

            </div>

        </section>

        {{-- SITUAÇÃO CADASTRAL --}}
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
                Endereço da matriz
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

        {{-- CONTATOS --}}
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
                    Salvar alterações
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
