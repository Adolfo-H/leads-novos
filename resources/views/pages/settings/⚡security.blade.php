<?php

use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component
{
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>


<section class="ec-settings-screen">

    @include('partials.settings-heading')


    <x-pages::settings.layout
        heading="Segurança"
        subheading="Proteja sua conta e gerencie suas credenciais de acesso."
    >

        {{-- ALTERAR SENHA --}}
        <div class="ec-settings-card">

            <div class="ec-settings-card-title">

                <div class="ec-settings-card-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <rect
                            x="5"
                            y="10"
                            width="14"
                            height="11"
                            rx="2"
                        />

                        <path
                            d="
                                M8 10
                                V7
                                a4 4 0 0 1
                                8 0
                                v3
                            "
                        />
                    </svg>

                </div>

                <div>

                    <h3>
                        Alterar senha
                    </h3>

                    <p>
                        Use uma senha forte e exclusiva
                        para proteger sua conta.
                    </p>

                </div>

            </div>


            <form
                method="POST"
                wire:submit="updatePassword"
                class="ec-settings-form"
            >

                <div class="ec-settings-field">

                    <label for="current-password">
                        Senha atual
                    </label>

                    <input
                        id="current-password"
                        wire:model="current_password"
                        type="password"
                        required
                        autocomplete="current-password"
                    >

                    @error('current_password')

                        <span class="ec-settings-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                <div class="ec-settings-form-grid">

                    <div class="ec-settings-field">

                        <label for="new-password">
                            Nova senha
                        </label>

                        <input
                            id="new-password"
                            wire:model="password"
                            type="password"
                            required
                            autocomplete="new-password"
                        >

                        @error('password')

                            <span class="ec-settings-error">
                                {{ $message }}
                            </span>

                        @enderror

                    </div>


                    <div class="ec-settings-field">

                        <label for="password-confirmation">
                            Confirmar nova senha
                        </label>

                        <input
                            id="password-confirmation"
                            wire:model="password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                        >

                    </div>

                </div>


                <div class="ec-settings-actions">

                    <button
                        type="submit"
                        class="ec-settings-primary-button"
                        data-test="update-password-button"
                    >
                        Atualizar senha
                    </button>

                </div>

            </form>

        </div>


        {{-- 2FA --}}
        @if ($canManageTwoFactor)

            <section class="ec-settings-card">

                <div class="ec-settings-card-title">

                    <div class="ec-settings-card-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <path
                                d="
                                    M12 3
                                    19 6
                                    v5
                                    c0 5
                                    -3 8
                                    -7 10
                                    -4-2
                                    -7-5
                                    -7-10
                                    V6
                                    Z
                                "
                            />

                            <path
                                d="m9 12 2 2 4-5"
                            />
                        </svg>

                    </div>

                    <div>

                        <h3>
                            Autenticação em dois fatores
                        </h3>

                        <p>
                            Adicione uma segunda camada
                            de proteção ao seu acesso.
                        </p>

                    </div>

                </div>


                <div
                    class="ec-settings-security-content"
                    wire:cloak
                >

                    @if ($twoFactorEnabled)

                        <div class="ec-settings-security-status is-enabled">

                            <span></span>

                            Autenticação em dois fatores ativada

                        </div>


                        <p>
                            Durante o login será solicitado
                            um código gerado pelo aplicativo
                            autenticador configurado.
                        </p>


                        <button
                            type="button"
                            wire:click="disable"
                            class="ec-settings-danger-outline"
                        >
                            Desativar 2FA
                        </button>


                        <div class="ec-settings-recovery-codes">

                            <livewire:pages::settings.two-factor.recovery-codes
                                :$requiresConfirmation
                            />

                        </div>

                    @else

                        <div class="ec-settings-security-status">

                            <span></span>

                            Autenticação em dois fatores desativada

                        </div>


                        <p>
                            Ao ativar, será solicitado um código
                            adicional durante o login.
                        </p>


                        <flux:modal.trigger
                            name="two-factor-setup-modal"
                        >

                            <button
                                type="button"
                                wire:click="
                                    $dispatch(
                                        'start-two-factor-setup'
                                    )
                                "
                                class="ec-settings-primary-button"
                            >
                                Ativar 2FA
                            </button>

                        </flux:modal.trigger>


                        <livewire:pages::settings.two-factor-setup-modal
                            :requires-confirmation="$requiresConfirmation"
                        />

                    @endif

                </div>

            </section>

        @endif


        {{-- PASSKEYS --}}
        @if ($canManagePasskeys)

            <section class="ec-settings-card">

                <div class="ec-settings-card-title">

                    <div class="ec-settings-card-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >
                            <circle
                                cx="8"
                                cy="15"
                                r="4"
                            />

                            <path
                                d="m11 12 8-8"
                            />

                            <path
                                d="m15 8 2 2"
                            />

                            <path
                                d="m17 6 2 2"
                            />
                        </svg>

                    </div>

                    <div>

                        <h3>
                            Chaves de acesso
                        </h3>

                        <p>
                            Gerencie suas chaves para
                            acesso sem senha.
                        </p>

                    </div>

                </div>


                <div
                    class="ec-settings-passkeys"
                    wire:cloak
                >

                    @forelse (
                        $passkeys
                        as $passkey
                    )

                        <div class="ec-settings-passkey-row">

                            <div>

                                <strong>
                                    {{ $passkey['name'] }}
                                </strong>

                                <span>
                                    Adicionada
                                    {{ $passkey['created_at_diff'] }}

                                    @if (
                                        $passkey[
                                            'last_used_at_diff'
                                        ]
                                    )

                                        · Último uso
                                        {{
                                            $passkey[
                                                'last_used_at_diff'
                                            ]
                                        }}

                                    @endif
                                </span>

                            </div>


                            <button
                                type="button"
                                wire:click="
                                    confirmDelete(
                                        {{ $passkey['id'] }}
                                    )
                                "
                                class="ec-settings-passkey-delete"
                            >
                                Remover
                            </button>

                        </div>

                    @empty

                        <div class="ec-settings-passkey-empty">

                            <strong>
                                Nenhuma chave de acesso cadastrada
                            </strong>

                            <span>
                                Você pode adicionar uma chave
                                de acesso opcional abaixo.
                            </span>

                        </div>

                    @endforelse


                    <div class="ec-settings-passkey-register">
                        <x-passkey-registration />
                    </div>

                </div>

            </section>

        @endif

    </x-pages::settings.layout>


    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >

        <div class="ec-settings-delete-modal">

            <div>

                <h3>
                    Remover chave de acesso?
                </h3>

                <p>
                    A chave "{{ $deletingPasskeyName }}"
                    não poderá mais ser usada para entrar.
                </p>

            </div>


            <div class="ec-settings-modal-actions">

                <button
                    type="button"
                    wire:click="closeDeleteModal"
                    class="ec-settings-secondary-button"
                >
                    Cancelar
                </button>

                <button
                    type="button"
                    wire:click="deletePasskey"
                    class="ec-settings-danger-button"
                >
                    Remover chave
                </button>

            </div>

        </div>

    </flux:modal>

</section>
