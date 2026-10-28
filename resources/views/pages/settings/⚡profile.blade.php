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
@include('pages.settings.partials.profile-v63')
