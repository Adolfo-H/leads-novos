<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public function deleteUser(
        Logout $logout
    ): void {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(
            Auth::user(),
            $logout(...)
        )->delete();

        $this->redirect(
            '/',
            navigate: true
        );
    }
};
?>


<flux:modal
    name="confirm-user-deletion"
    :show="$errors->isNotEmpty()"
    focusable
    class="max-w-lg"
>

    <form
        method="POST"
        wire:submit="deleteUser"
        class="ec-settings-delete-modal"
    >

        <div>

            <h3>
                Excluir sua conta?
            </h3>

            <p>
                Esta ação é permanente. Informe
                sua senha para confirmar a exclusão.
            </p>

        </div>


        <div class="ec-settings-field">

            <label for="delete-password">
                Senha
            </label>

            <input
                id="delete-password"
                wire:model="password"
                type="password"
                autocomplete="current-password"
            >

            @error('password')

                <span class="ec-settings-error">
                    {{ $message }}
                </span>

            @enderror

        </div>


        <div class="ec-settings-modal-actions">

            <flux:modal.close>

                <button
                    type="button"
                    class="ec-settings-secondary-button"
                >
                    Cancelar
                </button>

            </flux:modal.close>


            <button
                type="submit"
                class="ec-settings-danger-button"
                data-test="confirm-delete-user-button"
            >
                Excluir permanentemente
            </button>

        </div>

    </form>

</flux:modal>
