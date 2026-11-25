<?php

namespace App\Console\Commands;

use App\Services\Campaigns\DualCampaignSenders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AuthorizeCampaignSenders extends Command
{
    protected $signature = 'campaigns:authorize-senders {--provider-reference= : Referência da autorização do provedor}';

    protected $description = 'Autoriza caixas dedicadas para novas campanhas; não inicia envios';

    public function handle(): int
    {
        if (! Schema::hasTable('email_campaign_sender_control')) {
            $this->error('Instale a migration do controle de remetentes.');

            return self::FAILURE;
        }
        $reference = trim((string) $this->option('provider-reference'));
        if ($reference === '') {
            $reference = trim((string) $this->ask('Referência da autorização do provedor (chamado ou e-mail)'));
        }
        if (! $this->confirm('Confirma que o provedor liberou o uso das duas caixas para campanhas, que não há restrição aplicável e que as cotas globais vigentes estão autorizadas?', false)) {
            $this->line('Nenhuma alteração efetuada.');

            return self::SUCCESS;
        }
        try {
            DualCampaignSenders::authorize($reference);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Caixas autorizadas para novos rascunhos aprovados. Nenhum envio iniciado.');

        return self::SUCCESS;
    }
}
