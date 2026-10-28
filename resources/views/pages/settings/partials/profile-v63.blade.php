{{-- CONFIGURACOES_FIGMA_V63 — view completa; preserva métodos do componente Volt original. --}}
<section class="ec-cfg-v63 ec-cfg-profile" data-ec-settings-figma="U01-V63">
    <header class="ec-cfg-hero">
        <span class="ec-cfg-kicker">CONTA</span>
        <h1>Seu perfil.</h1>
        <p>Mantenha seus dados de identificação atualizados.</p>
        <div class="ec-cfg-hero-actions">
            <button type="submit" form="ec-cfg-profile-form" class="ec-cfg-btn ec-cfg-btn-primary"
                    wire:loading.attr="disabled" wire:target="updateProfileInformation"
                    data-test="update-profile-button">
                <span wire:loading.remove wire:target="updateProfileInformation">Salvar alterações</span>
                <span wire:loading wire:target="updateProfileInformation">Salvando...</span>
            </button>
            <a href="{{ route('security.edit') }}" wire:navigate class="ec-cfg-btn ec-cfg-btn-outline">Segurança da conta</a>
        </div>
    </header>

    @include('pages.settings.partials.tabs-v63')

    <div class="ec-cfg-section-title">Minha conta</div>
    <section class="ec-cfg-card ec-cfg-account-overview" aria-label="Conta atual">
        <span class="ec-cfg-pill {{ $this->hasUnverifiedEmail ? 'is-warning' : '' }}">
            {{ $this->hasUnverifiedEmail ? 'Verificação pendente' : 'E-mail verificado' }}
        </span>
        <strong>{{ auth()->user()->name }}</strong>
        <span class="ec-cfg-subtle">{{ auth()->user()->email }}</span>
    </section>

    <section class="ec-cfg-card" aria-labelledby="ec-cfg-profile-personal-title">
        <h2 id="ec-cfg-profile-personal-title" class="ec-cfg-card-heading">Informações pessoais</h2>
        <form id="ec-cfg-profile-form" wire:submit="updateProfileInformation" novalidate>
            <fieldset class="ec-cfg-fields" wire:loading.attr="disabled" wire:target="updateProfileInformation,resendVerificationNotification">
                <legend class="ec-cfg-sr">Dados pessoais</legend>
                <div class="ec-cfg-field">
                    <label for="ec-cfg-profile-name">Nome</label>
                    <input id="ec-cfg-profile-name" type="text" wire:model="name" autocomplete="name"
                           required maxlength="255" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                    <small>Como você aparece na equipe.</small>
                    @error('name') <p class="ec-cfg-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="ec-cfg-field">
                    <label for="ec-cfg-profile-email">E-mail</label>
                    <input id="ec-cfg-profile-email" type="email" wire:model="email" autocomplete="email"
                           required maxlength="255" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                    <small>Uma alteração pode exigir nova verificação.</small>
                    @error('email') <p class="ec-cfg-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </fieldset>
            <div class="ec-cfg-mobile-save">
                <button type="submit" class="ec-cfg-btn ec-cfg-btn-primary" wire:loading.attr="disabled"
                        wire:target="updateProfileInformation">Salvar alterações</button>
            </div>
        </form>
    </section>

    <div class="ec-cfg-note {{ $this->hasUnverifiedEmail ? 'is-warning' : 'is-success' }}" role="status">
        <span class="ec-cfg-note-icon" aria-hidden="true">{{ $this->hasUnverifiedEmail ? '!' : '✓' }}</span>
        <div>
            <strong>Verificação de e-mail</strong>
            @if ($this->hasUnverifiedEmail)
                <p>Seu e-mail ainda precisa de verificação para concluir a proteção da conta.</p>
                <button type="button" class="ec-cfg-link" wire:click="resendVerificationNotification"
                        wire:loading.attr="disabled" wire:target="resendVerificationNotification">
                    Reenviar link de verificação →
                </button>
                @if (session('status') === 'verification-link-sent')
                    <p>Um novo link de verificação foi enviado.</p>
                @endif
            @else
                <p>Seu endereço está verificado. Se a verificação estiver pendente, você poderá reenviar o link por aqui.</p>
            @endif
        </div>
    </div>

    @if ($this->showDeleteUser)
        <div class="ec-cfg-section-title">Gerenciar conta</div>
        <section class="ec-cfg-card ec-cfg-delete-card" aria-label="Gerenciar conta">
            <span class="ec-cfg-pill">Ação permanente</span>
            <h2 class="ec-cfg-card-heading">Excluir minha conta</h2>
            <p>A exclusão permanente exige sua senha e está disponível somente quando permitida.</p>
            {{-- Reutiliza o modal real do Fortify/Livewire; não implementa exclusão paralela. --}}
            <div class="ec-cfg-delete-actual"><livewire:pages::settings.delete-user-form /></div>
        </section>
    @endif

    <footer class="ec-cfg-foot">ExportControl Prospector · Configurações da conta</footer>
</section>
