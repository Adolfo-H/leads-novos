<?php

namespace App\Jobs;

use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Um site por job para manter a resposta Laravel rápida. */
final class ResearchWhatsappPublicCompanySite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 40;

    public int $tries = 1;

    public function __construct(public int $runId, public string $domain) {}

    public function handle(WhatsappPublicSiteResearch $research): void
    {
        $research->researchDomain($this->runId, $this->domain);
    }
}
