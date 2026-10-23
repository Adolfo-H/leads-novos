<x-layouts::auth :title="'Solicitar acesso'">
    <div class="ec24-head"><span class="ec24-overline">CONTROLE DE ACESSO</span><h2>Cadastro por convite</h2><p>Por segurança, novas contas só são criadas após aprovação do responsável.</p></div>
    <a class="ec24-primary" href="{{ route('access.request.create') }}">Solicitar acesso <span>→</span></a>
    <div class="ec24-divider"><span>ou</span></div>
    <a class="ec24-link-centered" href="{{ route('login') }}">Já tenho conta · Entrar</a>
</x-layouts::auth>
