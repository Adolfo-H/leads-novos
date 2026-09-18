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


<section class="ec-settings-screen">

    @include('partials.settings-heading')


    <x-pages::settings.layout
        heading="Perfil"
        subheading="Atualize seu nome e endereço de e-mail."
    >

        <div class="ec-settings-card">

            <div class="ec-settings-card-title">

                <div class="ec-settings-card-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <circle
                            cx="12"
                            cy="8"
                            r="3"
                        />

                        <path
                            d="
                                M5 21
                                c0-4.5
                                2.8-7
                                7-7
                                s7 2.5
                                7 7
                            "
                        />
                    </svg>

                </div>

                <div>

                    <h3>
                        Dados do perfil
                    </h3>

                    <p>
                        Informações utilizadas
                        para identificação no sistema.
                    </p>

                </div>

            </div>


            <form
                wire:submit="updateProfileInformation"
                class="ec-settings-form"
            >

                <div class="ec-settings-field">

                    <label for="settings-name">
                        Nome
                    </label>

                    <input
                        id="settings-name"
                        type="text"
                        wire:model="name"
                        required
                        autofocus
                        autocomplete="name"
                    >

                    @error('name')

                        <span class="ec-settings-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                <div class="ec-settings-field">

                    <label for="settings-email">
                        E-mail
                    </label>

                    <input
                        id="settings-email"
                        type="email"
                        wire:model="email"
                        required
                        autocomplete="email"
                    >

                    @error('email')

                        <span class="ec-settings-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                @if ($this->hasUnverifiedEmail)

                    <div class="ec-settings-warning">

                        <strong>
                            E-mail ainda não verificado.
                        </strong>

                        <button
                            type="button"
                            wire:click.prevent="
                                resendVerificationNotification
                            "
                        >
                            Reenviar e-mail de verificação
                        </button>

                        @if (
                            session('status')
                            === 'verification-link-sent'
                        )

                            <span>
                                Um novo link de verificação
                                foi enviado.
                            </span>

                        @endif

                    </div>

                @endif


                <div class="ec-settings-actions">

                    <button
                        type="submit"
                        class="ec-settings-primary-button"
                        data-test="update-profile-button"
                    >
                        Salvar alterações
                    </button>

                </div>

            </form>

        </div>


        @if ($this->showDeleteUser)

            <livewire:pages::settings.delete-user-form />

        @endif

    </x-pages::settings.layout>

</section>
