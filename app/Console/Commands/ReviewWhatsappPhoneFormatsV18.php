<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappFormatAutoCorrectionV18;
use Illuminate\Console\Command;

/** Nenhuma API, WhatsApp ou dado fiscal é alterado por este comando. */
final class ReviewWhatsappPhoneFormatsV18 extends Command
{
    protected $signature = 'contacts:wa-format:preview
        {--apply : Registrar conversões candidatas para o índice local}
        {--confirm= : Exige CORRIGIR_FORMATOS para aplicar}
        {--max-links=5 : Limite de origens associadas ao mesmo número antigo, de 1 a 100}
        {--merge-existing-unknown : Permite conciliar com número já indexado SOMENTE se não houver consultas ou confirmações}';

    protected $description = 'Prévia e aplicação auditável de telefones móveis antigos com DDD, sem confirmar a existência do número.';

    public function handle(WhatsappFormatAutoCorrectionV18 $service): int
    {
        $apply = (bool) $this->option('apply');
        $maxLinks = filter_var($this->option('max-links'), FILTER_VALIDATE_INT);
        if ($maxLinks === false || $maxLinks < 1 || $maxLinks > 100) {
            $this->error('Informe --max-links entre 1 e 100.');

            return self::FAILURE;
        }
        if ($apply && $this->option('confirm') !== 'CORRIGIR_FORMATOS') {
            $this->error('Primeiro faça a prévia; para aplicar use --apply --confirm=CORRIGIR_FORMATOS.');

            return self::FAILURE;
        }

        $mergeExistingUnknown = (bool) $this->option('merge-existing-unknown');
        $summary = $service->execute($apply, $maxLinks, $mergeExistingUnknown);
        $this->line('Modo: '.($mergeExistingUnknown ? 'conciliar destinos existentes não verificados' : 'normalização conservadora V18'));
        $this->line('Índice em revisão examinado: '.$summary['reviewed']);
        $this->line('Possíveis móveis antigos com DDD: '.$summary['candidates']);
        $this->line('Elegíveis à transformação local: '.$summary['eligible']);
        $this->line('Compartilhados acima do limite: '.$summary['shared']);
        $this->line('Conflitos/consultas prévias: '.$summary['conflict']);
        $this->line('Já registrados: '.$summary['already']);
        foreach ($summary['examples'] as $example) {
            $this->line('Exemplo (mascarado): '.$example);
        }
        if ($apply) {
            $this->info('Conversões registradas no índice local: '.$summary['applied']);
            $this->warn('Execute contacts:sync-whatsapp-phones --apply para refletir os vínculos no índice.');
        } else {
            $this->info('SOMENTE PRÉVIA. Nenhuma alteração realizada.');
        }
        $this->warn('O nono dígito é uma hipótese de atualização, não confirmação de titularidade/WhatsApp.');
        $this->warn('Números sem DDD, concatenados, com conflito ou muitos vínculos continuam para revisão humana.');
        if ($mergeExistingUnknown) {
            $this->warn('Conciliar formatos NÃO prova que os números pertencem ao mesmo titular; as fontes originais ficam preservadas.');
        }

        return self::SUCCESS;
    }
}
