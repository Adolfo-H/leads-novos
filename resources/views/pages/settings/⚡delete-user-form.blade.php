<?php

use Livewire\Component;

new class extends Component {};
?>


<section class="ec-settings-danger-card">

    <div>

        <h3>
            Excluir conta
        </h3>

        <p>
            Exclui permanentemente sua conta
            e os recursos vinculados a ela.
        </p>

    </div>


    <flux:modal.trigger
        name="confirm-user-deletion"
    >

        <button
            type="button"
            class="ec-settings-danger-button"
            data-test="delete-user-button"
        >
            Excluir conta
        </button>

    </flux:modal.trigger>


    <livewire:pages::settings.delete-user-modal />

</section>
