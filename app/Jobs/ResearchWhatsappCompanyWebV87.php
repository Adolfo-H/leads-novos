<?php

namespace App\Jobs;

use App\Services\Contacts\WhatsappCompanyWebResearchV87;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Pesquisa pública assíncrona por UMA empresa selecionada; não consulta contas WhatsApp. */
final class ResearchWhatsappCompanyWebV87 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(WhatsappCompanyWebResearchV87 $service): void
    {
        $service->execute($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(WhatsappCompanyWebResearchV87::class)->markFailed($this->runId);
    }
}
