{{-- CONFIGURACOES_FIGMA_V63 — preserva Fortify, Livewire, passkeys e modais originais. --}}
<section class="ec-cfg-v63 ec-cfg-security" data-ec-settings-figma="U02-V63">
    <header class="ec-cfg-hero">
        <span class="ec-cfg-kicker">CONTA</span>
        <h1>Segurança da conta.</h1>
        <p>Gerencie sua senha e as formas de proteger o acesso.</p>
        <div class="ec-cfg-hero-actions">
            <button type="submit" form="ec-cfg-password-form" class="ec-cfg-btn ec-cfg-btn-primary"
                    wire:loading.attr="disabled" wire:target="updatePassword"
                    data-test="update-password-button">Atualizar senha</button>
            @if($canManageTwoFactor)
                <a href="#ec-cfg-2fa" class="ec-cfg-btn ec-cfg-btn-outline">
                    {{ $twoFactorEnabled ? 'Ver segundo fator' : 'Ativar segundo fator' }}
                </a>
            @endif
        </div>
    </header>

    @include('pages.settings.partials.tabs-v63')

    <section class="ec-cfg-card" aria-labelledby="ec-cfg-password-title">
        <h2 id="ec-cfg-password-title" class="ec-cfg-card-heading">Alterar senha</h2>
        <form id="ec-cfg-password-form" wire:submit="updatePassword" autocomplete="off">
            <fieldset class="ec-cfg-fields ec-cfg-password-fields" wire:loading.attr="disabled" wire:target="updatePassword">
                <legend class="ec-cfg-sr">Alterar a senha da conta</legend>
                <div class="ec-cfg-field">
                    <label for="ec-cfg-current-password">Senha atual</label>
                    <input id="ec-cfg-current-password" type="password" wire:model="current_password"
                           required autocomplete="current-password">
                    @error('current_password') <p class="ec-cfg-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="ec-cfg-field">
                    <label for="ec-cfg-new-password">Nova senha</label>
                    <input id="ec-cfg-new-password" type="password" wire:model="password" required autocomplete="new-password">
                    <small>Use uma senha que atenda às regras da conta.</small>
                    @error('password') <p class="ec-cfg-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="ec-cfg-field">
                    <label for="ec-cfg-confirm-password">Confirmar nova senha</label>
                    <input id="ec-cfg-confirm-password" type="password" wire:model="password_confirmation"
                           required autocomplete="new-password">
                    <small>Repita a nova senha.</small>
                    @error('password_confirmation') <p class="ec-cfg-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </fieldset>
            <div class="ec-cfg-mobile-save"><button type="submit" class="ec-cfg-btn ec-cfg-btn-primary" wire:loading.attr="disabled"
                  wire:target="updatePassword">Atualizar senha</button></div>
        </form>
    </section>

    @if ($canManageTwoFactor)
        <div id="ec-cfg-2fa" class="ec-cfg-section-title">Autenticação em duas etapas</div>
        <div class="ec-cfg-two-col">
            <section class="ec-cfg-card ec-cfg-security-mini" aria-label="Segundo fator">
                <span class="ec-cfg-pill {{ $twoFactorEnabled ? '' : 'is-muted' }}">
                    {{ $twoFactorEnabled ? 'Ativado' : 'Desativado' }}
                </span>
                <h2 class="ec-cfg-card-heading">
                    {{ $twoFactorEnabled ? 'Segurança extra confirmada' : 'Adicione uma segunda confirmação' }}
                </h2>
                <p>{{ $twoFactorEnabled
                    ? 'Seu próximo login poderá solicitar um código do autenticador.'
                    : 'Use um aplicativo autenticador para proteger seu próximo login.' }}</p>
                @if ($twoFactorEnabled)
                    <button type="button" class="ec-cfg-link ec-cfg-link-danger" wire:click="disable"
                            wire:loading.attr="disabled" wire:target="disable">Desativar segundo fator →</button>
                @else
                    <flux:modal.trigger name="two-factor-setup-modal">
                        <button type="button" class="ec-cfg-link"
                                wire:click="$dispatch('start-two-factor-setup')">Ativar segundo fator →</button>
                    </flux:modal.trigger>
                    <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                @endif
            </section>
            <section class="ec-cfg-card ec-cfg-security-mini" aria-label="Códigos de recuperação">
                <span class="ec-cfg-pill">Acesso protegido</span>
                <h2 class="ec-cfg-card-heading">Códigos de recuperação</h2>
                <p>Ficam disponíveis após concluir a configuração do segundo fator.</p>
                @if($twoFactorEnabled)
                    <div class="ec-cfg-recovery-live" wire:cloak>
                        <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                    </div>
                @endif
            </section>
        </div>
    @else
        <div class="ec-cfg-card ec-cfg-security-mini">
            <p>A autenticação em duas etapas não está habilitada na configuração atual do servidor.</p>
        </div>
    @endif

    @if($canManagePasskeys)
        <div class="ec-cfg-section-title">Chaves de acesso</div>
        <section class="ec-cfg-card ec-cfg-passkeys-card" aria-labelledby="ec-cfg-passkeys-title">
            <h2 id="ec-cfg-passkeys-title" class="ec-cfg-card-heading">Chaves de acesso</h2>
            <div class="ec-cfg-table-scroller">
                <table class="ec-cfg-passkeys-table">
                    <thead><tr><th scope="col">Nome</th><th scope="col">Incluída em</th><th scope="col">Último uso</th><th scope="col">Ação</th></tr></thead>
                    <tbody>
                        @forelse($passkeys as $passkey)
                            <tr wire:key="ec-cfg-passkey-{{ $passkey['id'] }}">
                                <td>{{ $passkey['name'] }}</td>
                                <td>{{ $passkey['created_at_diff'] }}</td>
                                <td>{{ $passkey['last_used_at_diff'] ?? 'Ainda não utilizada' }}</td>
                                <td><button type="button" class="ec-cfg-link ec-cfg-link-danger"
                                            wire:click="confirmDelete({{ (int) $passkey['id'] }})">Remover</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ec-cfg-empty">Nenhuma chave cadastrada nesta conta.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="ec-cfg-small-helper">Você pode cadastrar mais de uma chave, conforme o suporte do navegador.</p>
        </section>
        <div class="ec-cfg-section-title">Gerenciar chaves</div>
        <div class="ec-cfg-two-col">
            <section class="ec-cfg-card ec-cfg-security-mini">
                <span class="ec-cfg-pill">WebAuthn</span>
                <h2 class="ec-cfg-card-heading">Cadastrar chave de acesso</h2>
                <p>Use a autenticação oferecida pelo seu dispositivo.</p>
                {{-- Componente oficial do starter kit, preservado. --}}
                <div class="ec-cfg-passkey-registration"><x-passkey-registration /></div>
            </section>
            <section class="ec-cfg-card ec-cfg-security-mini">
                <h2 class="ec-cfg-card-heading">Remover uma chave</h2>
                <p>Revise a chave antes de confirmar a remoção. A lista acima mostra apenas as chaves reais da conta.</p>
                <a href="#ec-cfg-passkeys-title" class="ec-cfg-link">Ver chaves cadastradas →</a>
            </section>
        </div>
        <flux:modal name="delete-passkey-modal" class="max-w-md md:min-w-md"
                    @close="closeDeleteModal" wire:model="showDeleteModal">
            <div class="ec-cfg-confirm-modal">
                <h3>Remover chave de acesso?</h3>
                <p>A chave “{{ $deletingPasskeyName }}” deixará de funcionar nesta conta.</p>
                <div class="ec-cfg-modal-buttons">
                    <button type="button" class="ec-cfg-btn ec-cfg-btn-outline" wire:click="closeDeleteModal">Cancelar</button>
                    <button type="button" class="ec-cfg-btn ec-cfg-btn-danger" wire:click="deletePasskey">Remover chave</button>
                </div>
            </div>
        </flux:modal>
    @else
        <div class="ec-cfg-card ec-cfg-security-mini"><p>O recurso de autenticação por chave está indisponível neste ambiente.</p></div>
    @endif
    <footer class="ec-cfg-foot">ExportControl Prospector · Configurações da conta</footer>
</section>
