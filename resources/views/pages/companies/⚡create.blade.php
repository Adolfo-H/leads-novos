<?php

use App\Models\Company;
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

    public function save(
        CompanyService $service
    ) {
        $validated =
            $this->validate(
                [
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
                ],
                [
                    'cnpj.required' => 'Informe o CNPJ.',

                    'cnpj.max' => 'O CNPJ informado é muito longo.',

                    'corporateName.required' => 'Informe a razão social.',

                    'corporateName.max' => 'A razão social deve ter no máximo 255 caracteres.',

                    'fantasyName.max' => 'O nome fantasia deve ter no máximo 255 caracteres.',

                    'type.required' => 'Selecione o tipo do estabelecimento.',

                    'type.in' => 'O tipo do estabelecimento é inválido.',

                    'state.size' => 'A UF deve possuir exatamente 2 caracteres.',

                    'municipalityName.max' => 'O município deve ter no máximo 150 caracteres.',

                    'email.email' => 'Informe um endereço de e-mail válido.',

                    'email.max' => 'O e-mail deve ter no máximo 255 caracteres.',

                    'phone1.max' => 'O telefone deve ter no máximo 50 caracteres.',

                    'shareCapital.max' => 'O capital social informado é muito longo.',

                    'sizeCode.max' => 'O código de porte deve ter no máximo 10 caracteres.',

                    'legalNatureCode.max' => 'O código de natureza jurídica deve ter no máximo 10 caracteres.',
                ]
            );

        $normalizedCnpj =
            Cnpj::normalize(
                $validated['cnpj']
            );

        if (
            ! Cnpj::isValid(
                $normalizedCnpj
            )
        ) {
            $this->addError(
                'cnpj',
                'Informe um CNPJ válido.'
            );

            return;
        }

        if (
            Establishment::query()
                ->where(
                    'cnpj',
                    $normalizedCnpj
                )
                ->exists()
        ) {
            $this->addError(
                'cnpj',
                'Este estabelecimento já está cadastrado.'
            );

            return;
        }

        /*
         * A tela Nova empresa cria um novo grupo.
         *
         * Se a raiz já existe, o usuário precisa
         * utilizar o fluxo específico de filial.
         *
         * Isso impede uma filial manual de
         * sobrescrever os dados do grupo.
         */
        $existingCompany =
            Company::query()
                ->where(
                    'cnpj_root',
                    Cnpj::root(
                        $normalizedCnpj
                    )
                )
                ->first();

        if ($existingCompany) {
            $this->addError(
                'cnpj',
                'Esta raiz de CNPJ já pertence a uma empresa cadastrada. Abra o dossiê da empresa e use a opção de adicionar filial.'
            );

            return;
        }

        $shareCapital =
            $this->parseMoney(
                $validated[
                    'shareCapital'
                ]
                ?? ''
            );

        if (
            (
                $validated[
                    'shareCapital'
                ]
                ?? ''
            ) !== ''
            && $shareCapital === null
        ) {
            $this->addError(
                'shareCapital',
                'Informe um capital social válido.'
            );

            return;
        }

        $service
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => trim(
                        $validated[
                            'corporateName'
                        ]
                    ),

                    'share_capital' => $shareCapital,

                    'size_code' => $validated[
                            'sizeCode'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'sizeCode'
                                ]
                            )
                            : null,

                    'legal_nature_code' => $validated[
                            'legalNatureCode'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'legalNatureCode'
                                ]
                            )
                            : null,

                    'source' => 'manual',
                ],
                [
                    'cnpj' => $normalizedCnpj,

                    'type' => $validated[
                            'type'
                        ],

                    'fantasy_name' => $validated[
                            'fantasyName'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'fantasyName'
                                ]
                            )
                            : null,

                    'registration_status' => $validated[
                            'registrationStatus'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'registrationStatus'
                                ]
                            )
                            : null,

                    'state' => $validated[
                            'state'
                        ] !== ''
                            ? mb_strtoupper(
                                trim(
                                    $validated[
                                        'state'
                                    ]
                                )
                            )
                            : null,

                    'municipality_name' => $validated[
                            'municipalityName'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'municipalityName'
                                ]
                            )
                            : null,

                    'email' => $validated[
                            'email'
                        ] !== ''
                            ? mb_strtolower(
                                trim(
                                    $validated[
                                        'email'
                                    ]
                                )
                            )
                            : null,

                    'phone_1' => $validated[
                            'phone1'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'phone1'
                                ]
                            )
                            : null,

                    'source' => 'manual',
                ],
            );

        session()->flash(
            'success',
            'Empresa cadastrada com sucesso.'
        );

        return redirect()
            ->route(
                'companies.index'
            );
    }

    private function parseMoney(
        ?string $value
    ): ?float {
        if ($value === null) {
            return null;
        }

        $value =
            trim(
                $value
            );

        if ($value === '') {
            return null;
        }

        $value =
            str_ireplace(
                'R$',
                '',
                $value
            );

        $value =
            str_replace(
                ' ',
                '',
                $value
            );

        /*
         * Formato brasileiro:
         *
         * 1.500.000,50
         */
        if (
            str_contains(
                $value,
                ','
            )
        ) {
            $value =
                str_replace(
                    '.',
                    '',
                    $value
                );

            $value =
                str_replace(
                    ',',
                    '.',
                    $value
                );
        }

        if (
            ! is_numeric(
                $value
            )
        ) {
            return null;
        }

        $number =
            (float) $value;

        if ($number < 0) {
            return null;
        }

        return $number;
    }
};
?>

{{-- EMPRESAS_FIGMA_V64_E02: apresentação do cadastro. O método save e as validações continuam no componente original. --}}
<div class="ecnc-page ec-e02-page" data-ec-companies-figma="E02-V64">
    <header class="ecnc-header ec-e02-header">
        <span class="ecnc-breadcrumb">EMPRESAS</span>
        <h1>Nova empresa</h1>
        <p>Cadastre a empresa e seu estabelecimento de referência.</p>
        <div class="ec-e02-actions">
            <a class="ecnc-button ecnc-secondary" href="{{ route('companies.index') }}" wire:navigate>Cancelar</a>
            <button class="ecnc-button ecnc-primary" type="submit" form="ec-e02-form" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Salvar empresa</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </button>
        </div>
    </header>

    @if ($errors->any())
        <div class="ecnc-alert" role="alert" tabindex="-1">
            <strong>Revise o cadastro antes de salvar.</strong>
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <form id="ec-e02-form" class="ecnc-form" wire:submit="save" novalidate>
        <div class="ecnc-layout">
            <fieldset class="ecnc-main" wire:loading.attr="disabled" wire:target="save">
                <legend class="ecnc-sr">Dados da nova empresa</legend>
                <section class="ecnc-card" aria-labelledby="ec-e02-ident">
                    <h2 id="ec-e02-ident">Identificação</h2>
                    <div class="ecnc-grid">
                        <div class="ecnc-field">
                            <label for="ecnc-cnpj">CNPJ <span class="ecnc-required">*</span></label>
                            <input id="ecnc-cnpj" type="text" wire:model="cnpj" maxlength="30" required placeholder="00.000.000/0000-00" aria-invalid="{{ $errors->has('cnpj') ? 'true' : 'false' }}">
                            @error('cnpj') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-type">Tipo do estabelecimento <span class="ecnc-required">*</span></label>
                            <select id="ecnc-type" wire:model="type" required><option value="matrix">Matriz</option><option value="branch">Filial</option></select>
                            @error('type') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-corporateName">Razão social <span class="ecnc-required">*</span></label>
                            <input id="ecnc-corporateName" wire:model="corporateName" maxlength="255" required placeholder="Razão social completa">
                            @error('corporateName') <span class="ecnc-error">{{ $message }}</span> @enderror
                            <small>Máximo de 255 caracteres.</small>
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-fantasyName">Nome fantasia</label>
                            <input id="ecnc-fantasyName" wire:model="fantasyName" maxlength="255" placeholder="Nome utilizado comercialmente">
                            @error('fantasyName') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </section>

                <section class="ecnc-card" aria-labelledby="ec-e02-location">
                    <h2 id="ec-e02-location">Cadastro e localização</h2>
                    <div class="ecnc-grid">
                        <div class="ecnc-field">
                            <label for="ecnc-registrationStatus">Situação cadastral</label>
                            <select id="ecnc-registrationStatus" wire:model="registrationStatus">
                                <option value="ATIVA">Ativa</option><option value="SUSPENSA">Suspensa</option>
                                <option value="INAPTA">Inapta</option><option value="BAIXADA">Baixada</option>
                                <option value="NULA">Nula</option><option value="">Não informada</option>
                            </select>
                            @error('registrationStatus') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-shareCapital">Capital social</label>
                            <input id="ecnc-shareCapital" wire:model="shareCapital" maxlength="40" placeholder="R$ 2.500.000,00">
                            @error('shareCapital') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-sizeCode">Código de porte</label>
                            <input id="ecnc-sizeCode" wire:model="sizeCode" maxlength="10" placeholder="05">
                            @error('sizeCode') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-legalNatureCode">Código de natureza jurídica</label>
                            <input id="ecnc-legalNatureCode" wire:model="legalNatureCode" maxlength="10" placeholder="2062">
                            @error('legalNatureCode') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-state">UF</label>
                            <input id="ecnc-state" wire:model="state" maxlength="2" placeholder="SP">
                            @error('state') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-municipalityName">Município</label>
                            <input id="ecnc-municipalityName" wire:model="municipalityName" maxlength="150" placeholder="Município da empresa">
                            @error('municipalityName') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </section>

                <section class="ecnc-card" aria-labelledby="ec-e02-channels">
                    <h2 id="ec-e02-channels">Canais empresariais</h2>
                    <div class="ecnc-grid">
                        <div class="ecnc-field">
                            <label for="ecnc-email">E-mail cadastral</label>
                            <input id="ecnc-email" type="email" wire:model="email" maxlength="255" placeholder="contato@empresa.com.br">
                            @error('email') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ecnc-field">
                            <label for="ecnc-phone1">Telefone cadastral</label>
                            <input id="ecnc-phone1" type="tel" wire:model="phone1" maxlength="50" placeholder="(00) 00000-0000">
                            @error('phone1') <span class="ecnc-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </section>
            </fieldset>

            <aside class="ecnc-aside" aria-label="Prévia do cadastro">
                <h2>Prévia do cadastro</h2>
                <section class="ecnc-summary">
                    <span class="ecnc-tag">Cadastro manual</span>
                    <h3 x-text="$wire.corporateName || 'Nova empresa'">{{ $corporateName ?: 'Nova empresa' }}</h3>
                    <p x-text="$wire.cnpj || 'CNPJ não informado'">{{ $cnpj ?: 'CNPJ não informado' }}</p>
                    <p><span x-text="$wire.type === 'matrix' ? 'Matriz' : 'Filial'">{{ $type === 'matrix' ? 'Matriz' : 'Filial' }}</span>
                       · <span x-text="$wire.registrationStatus || 'Não informada'">{{ $registrationStatus ?: 'Não informada' }}</span></p>
                    <p x-text="[$wire.municipalityName, $wire.state].filter(Boolean).join(' / ') || 'Localização não informada'">{{ trim($municipalityName.' / '.$state, ' /') ?: 'Localização não informada' }}</p>
                </section>
                <section class="ecnc-note">
                    <h3>Canais da empresa</h3>
                    <p>E-mail e telefone cadastrais permanecem canais empresariais. Eles não criam uma pessoa de contato.</p>
                </section>
            </aside>
        </div>
    </form>
    <footer class="ec-e02-footer">ExportControl Prospector · Cadastro e vínculos empresariais</footer>
</div>
