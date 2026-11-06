<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappFullFormatSweepV21;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Console\Command;

/** Varredura total dos formatos ativos sob revisão no índice local. */
final class ReviewAllWhatsappFormatsV21 extends Command
{
    protected $signature = 'contacts:wa-format:full-sweep
        {--apply : Registrar conversões e sincronizar os vínculos locais}
        {--confirm= : Informe VARREDURA_FORMATOS_V21 para aplicar}
        {--include-verified-destinations : Inclui destinos já confirmados; os vínculos herdarão o status de WhatsApp do destino}';

    protected $description = 'Revisa todos os telefones de formato inconsistente, incluindo DDD+8 e prefixos 0/55, sem alterar fontes externas.';

    public function handle(WhatsappFullFormatSweepV21 $service): int
    {
        $apply = (bool) $this->option('apply');
        $verified = (bool) $this->option('include-verified-destinations');
        if ($apply && $this->option('confirm') !== 'VARREDURA_FORMATOS_V21') {
            $this->error('Execute primeiro a prévia. Para aplicar, confirme com --confirm=VARREDURA_FORMATOS_V21.');

            return self::FAILURE;
        }
        if ($verified) {
            $this->warn('ATENÇÃO: o destino já verificado mantém seu status. Ao conciliar, os novos vínculos herdarão essa informação no diretório.');
            $this->warn('Isso NÃO comprova que o contato original seja o titular do número atualizado.');
        }
        $s = $service->execute($apply, $verified);
        $this->line('===== VARREDURA TOTAL DE TELEFONES V21 =====');
        $this->line('Total em Revisar formato examinado: '.$s['reviewed']);
        $this->line('Já decididos manualmente: '.$s['already_reviewed']);
        $this->line('Não candidatos a celular antigo: '.$s['non_candidates']);
        $this->line('  Sem DDD (8/9 dígitos): '.$s['no_ddd']);
        $this->line('  DDD/prefixo inválido ou não móvel: '.$s['invalid_ddd_or_prefix']);
        $this->line('  Muito longos/concatenados: '.$s['long_or_combined']);
        $this->line('  Outros formatos: '.$s['other']);
        $this->line('Celulares antigos DDD+8 identificados: '.$s['candidates']);
        $this->line('Já registrados em correções anteriores: '.$s['already']);
        $this->line('Elegíveis nesta rodada: '.$s['eligible']);
        $this->line('  Reutilizando destino desconhecido: '.$s['eligible_existing_unknown']);
        $this->line('  Reutilizando destino já verificado: '.$s['eligible_existing_verified']);
        $this->line('Bloqueados por histórico/estado do original: '.$s['blocked_original']);
        $this->line('Bloqueados por destino existente: '.$s['blocked_destination']);
        $this->line('Bloqueados por histórico de destino unknown: '.$s['blocked_history']);
        $this->line('Bloqueados por chave inconsistente: '.$s['blocked_key']);
        foreach ($s['examples'] as $example) {
            $this->line('Amostra mascarada: '.$example);
        }
        if (! $apply) {
            $this->info('SOMENTE PRÉVIA. Nenhum registro foi alterado.');

            return self::SUCCESS;
        }
        $this->info('Correções registradas: '.$s['applied']);
        if ($s['applied'] > 0) {
            $this->line('Sincronizando o índice para reconstruir todos os vínculos...');
            // Uma falha neste passo não apaga overrides; o comando de sync pode ser reexecutado.
            $sync = app(WhatsappPhoneRegistry::class)->sync();
            $this->info('Índice: '.$sync['numbers'].' únicos, '.$sync['references'].' vínculos, '.$sync['review'].' ainda em revisão.');
        } else {
            $this->line('Sem novas correções; não houve sincronização desnecessária.');
        }
        $this->warn('Os dados originais de HubSpot/Receita foram preservados. A formatação não prova titularidade nem WhatsApp ativo.');

        return self::SUCCESS;
    }
}
