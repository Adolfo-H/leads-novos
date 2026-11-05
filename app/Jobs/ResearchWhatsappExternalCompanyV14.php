<?php

namespace App\Jobs;

use App\Services\Contacts\WhatsappExternalCompanyDiscoveryV14;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ResearchWhatsappExternalCompanyV14 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(WhatsappExternalCompanyDiscoveryV14 $discovery): void
    {
        $discovery->execute($this->runId);
    }
}
