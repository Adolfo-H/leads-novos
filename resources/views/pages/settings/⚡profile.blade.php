<?php

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configurações do perfil')] class extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        $this->name =
            Auth::user()->name;

        $this->email =
            Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user =
            Auth::user();

        $validated =
            $this->validate(
                $this->profileRules(
                    $user->id
                )
            );

        $user->fill(
            $validated
        );

        if (
            $user->isDirty(
                'email'
            )
        ) {
            $user->email_verified_at =
                null;
        }

        $user->save();

        Flux::toast(
            variant: 'success',
            text: 'Perfil atualizado com sucesso.'
        );
    }

    public function resendVerificationNotification(): void
    {
        $user =
            Auth::user();

        if (
            $user
                ->hasVerifiedEmail()
        ) {
            $this->redirectIntended(
                default: route(
                    'dashboard',
                    absolute: false
                )
            );

            return;
        }

        $user
            ->sendEmailVerificationNotification();

        Session::flash(
            'status',
            'verification-link-sent'
        );
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user()
            instanceof MustVerifyEmail
            && ! Auth::user()
                ->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user()
            instanceof MustVerifyEmail
            || (
                Auth::user()
                    instanceof MustVerifyEmail
                && Auth::user()
                    ->hasVerifiedEmail()
            );
    }
};
?>

<section class="ec-settings-screen ecui-settings">

    @include('partials.settings-heading')

    <x-pages::settings.layout
        heading="Perfil"
        subheading="Mantenha seus dados de identificação atualizados."
    >
        <div class="ecui-profile-grid">

            <section class="ecui-card">
                <header class="ecui-card-head">
                    <h3>Dados do perfil</h3>
                    <p>
                        Informe o nome e o e-mail
                        que serão utilizados no sistema.
                    </p>
                </header>

                <form
                    wire:submit="updateProfileInformation"
                    class="ecui-profile-form"
                    novalidate
                >
                    <fieldset
                        wire:loading.attr="disabled"
                        wire:target="updateProfileInformation,resendVerificationNotification"
                    >
                        <legend class="ecui-sr">
                            Dados do perfil
                        </legend>

                        <div class="ecui-field">
                            <label for="ecui-name">Nome</label>

                            <input
                                id="ecui-name"
                                type="text"
                                wire:model="name"
                                required
                                autocomplete="name"
                                aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                                @if ($errors->has('name'))
                                    aria-describedby="ecui-name-error"
                                @endif
                            >

                            @error('name')
                                <p
                                    id="ecui-name-error"
                                    class="ecui-error"
                                    role="alert"
                                >
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="ecui-field">
                            <label for="ecui-email">E-mail</label>

                            <input
                                id="ecui-email"
                                type="email"
                                wire:model="email"
                                required
                                autocomplete="email"
                                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                                @if ($errors->has('email'))
                                    aria-describedby="ecui-email-error"
                                @endif
                            >

                            @error('email')
                                <p
                                    id="ecui-email-error"
                                    class="ecui-error"
                                    role="alert"
                                >
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    </fieldset>

                    @if ($this->hasUnverifiedEmail)
                        <div class="ecui-verification" role="status">
                            <strong>E-mail ainda não verificado.</strong>

                            <button
                                type="button"
                                wire:click="resendVerificationNotification"
                                wire:loading.attr="disabled"
                            >
                                Reenviar e-mail de verificação
                            </button>

                            @if (session('status') === 'verification-link-sent')
                                <p>
                                    Um novo link de verificação foi enviado.
                                </p>
                            @endif
                        </div>
                    @endif

                    <footer class="ecui-form-footer">
                        <span class="ecui-muted">
                            Revise seus dados antes de salvar.
                        </span>

                        <button
                            type="submit"
                            class="ecui-button ecui-primary"
                            data-test="update-profile-button"
                            wire:loading.attr="disabled"
                            wire:target="updateProfileInformation,resendVerificationNotification"
                        >
                            <span
                                wire:loading.remove
                                wire:target="updateProfileInformation"
                            >
                                Salvar alterações
                            </span>

                            <span
                                wire:loading
                                wire:target="updateProfileInformation"
                            >
                                Salvando...
                            </span>
                        </button>
                    </footer>
                </form>
            </section>

            <aside
                class="ecui-card ecui-account"
                aria-label="Conta atual"
            >
                <span class="ecui-avatar" aria-hidden="true">
                    {{ auth()->user()->initials() }}
                </span>

                <strong>{{ auth()->user()->name }}</strong>
                <p>{{ auth()->user()->email }}</p>

                <span class="ecui-account-status">
                    {{
                        $this->hasUnverifiedEmail
                            ? 'Verificação pendente'
                            : 'E-mail verificado'
                    }}
                </span>

                <p class="ecui-account-note">
                    Para alterar a senha ou gerenciar a autenticação,
                    acesse Segurança.
                </p>

                <a
                    href="{{ route('security.edit') }}"
                    wire:navigate
                    class="ecui-button ecui-secondary"
                >
                    Acessar segurança →
                </a>
            </aside>

        </div>

        @if ($this->showDeleteUser)
            <div class="ecui-delete-section">
                <livewire:pages::settings.delete-user-form />
            </div>
        @endif

    </x-pages::settings.layout>

</section>
