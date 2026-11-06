<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Contacts\WhatsappCompanyChannelLinkV16;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class WhatsappCompanyLinksV16 extends Component
{
    public string $numberSearch = '';

    /** @var list<int> */
    public array $selectedChannels = [];

    // Compatibilidade com componentes antigos que usam chooseChannel().
    public int $selectedChannel = 0;

    public string $companySearch = '';

    public string $selectedCompany = '';

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isCommercialManager(), 403);
    }

    public function toggleChannel(int $id): void
    {
        $this->resetErrorBag('wa16_link');

        if (in_array($id, $this->selectedChannels, true)) {
            $this->selectedChannels = array_values(array_diff($this->selectedChannels, [$id]));
            if ($this->selectedChannels === []) {
                $this->selectedCompany = '';
            }

            return;
        }

        $channel = DB::table('whatsapp_public_company_channels as c')
            ->leftJoin('whatsapp_company_channel_links_v16 as link', 'link.channel_id', '=', 'c.id')
            ->where('c.id', $id)->whereNull('link.id')
            ->first(['c.hubspot_company_id']);
        abort_unless($channel !== null, 404);

        if (count($this->selectedChannels) >= 50) {
            $this->addError('wa16_link', 'Selecione no máximo 50 números por vínculo.');

            return;
        }

        if ($this->selectedChannels !== []) {
            $sourceIds = DB::table('whatsapp_public_company_channels')
                ->whereIn('id', $this->selectedChannels)
                ->distinct()->pluck('hubspot_company_id');
            if ($sourceIds->count() !== 1 || (int) $sourceIds->first() !== (int) $channel->hubspot_company_id) {
                $this->addError('wa16_link', 'Selecione números da mesma empresa de origem. Confirme este grupo antes de escolher outra empresa.');

                return;
            }
        }

        $this->selectedChannels[] = $id;
        $this->selectedChannel = $id;
    }

    public function chooseChannel(int $id): void
    {
        $this->toggleChannel($id);
    }

    public function selectGroup(int $sourceCompanyId): void
    {
        $this->resetErrorBag('wa16_link');
        // Somente telefones presentes no filtro atual podem ser selecionados em lote.
        $ids = $this->channelRows()->where('hubspot_company_id', $sourceCompanyId)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return;
        }

        $this->selectedChannels = array_values($ids);
        $this->selectedCompany = '';
        $this->selectedChannel = $ids[0];
    }

    public function clearSelection(): void
    {
        $this->selectedChannels = [];
        $this->selectedChannel = 0;
        $this->selectedCompany = '';
        $this->companySearch = '';
        $this->resetErrorBag('wa16_link');
    }

    public function chooseCompany(string $key): void
    {
        abort_unless($this->selectedChannels !== [] && preg_match('/^(local|hubspot):\d+$/D', $key) === 1, 422);
        $this->selectedCompany = $key;
    }

    public function saveLinks(): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $amount = app(WhatsappCompanyChannelLinkV16::class)
            ->assignMany($actor, $this->selectedChannels, $this->selectedCompany);
        $this->clearSelection();
        $this->numberSearch = '';
        session()->flash('wa16_linked', $amount.' número(s) vinculado(s) à empresa escolhida. Fontes preservadas; nenhuma pessoa criada.');
    }

    public function saveLink(): void
    {
        $this->saveLinks();
    }

    /** @return Collection<int,\stdClass> */
    private function channelRows(): Collection
    {
        $digits = (string) preg_replace('/\D/', '', $this->numberSearch);
        $query = DB::table('whatsapp_public_company_channels as c')
            ->join('hubspot_companies as h', 'h.id', '=', 'c.hubspot_company_id')
            ->join('whatsapp_public_site_evidences as ev', 'ev.id', '=', 'c.evidence_id')
            ->leftJoin('whatsapp_company_channel_links_v16 as link', 'link.channel_id', '=', 'c.id')
            ->whereNull('link.id');

        if (trim($this->numberSearch) !== '') {
            if (strlen($digits) >= 5) {
                $query->where('c.e164', 'like', '%'.$digits.'%');
            } else {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($this->numberSearch))).'%';
                $query->where(static function ($q) use ($term): void {
                    $q->whereRaw("LOWER(COALESCE(h.name, '')) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(COALESCE(h.domain, '')) LIKE ? ESCAPE '!'", [$term]);
                });
            }
        }

        return $query->orderByDesc('c.id')->limit(120)
            ->get(['c.id', 'c.hubspot_company_id', 'c.e164', 'h.name as company_name', 'h.domain', 'ev.source_url'])
            ->unique(static fn (object $row): string => $row->hubspot_company_id.'|'.$row->e164)
            ->take(30)->values();
    }

    public function render(): View
    {
        $channels = $this->channelRows();
        $groups = $channels->groupBy('hubspot_company_id');
        $selectedCount = count($this->selectedChannels);
        $matches = collect();
        $term = mb_strtolower(trim($this->companySearch));

        if (mb_strlen($term) >= 2 && $selectedCount > 0) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $cnpjDigits = (string) preg_replace('/\D+/', '', $term);
            $local = DB::table('companies')->where(static function ($query) use ($like, $cnpjDigits): void {
                $query->whereRaw("LOWER(corporate_name) LIKE ? ESCAPE '!'", [$like]);
                if (strlen($cnpjDigits) >= 4) {
                    $query->orWhere('cnpj_root', 'like', '%'.substr($cnpjDigits, 0, 8).'%');
                }
            })->orderBy('corporate_name')->limit(12)->get(['id', 'corporate_name as name'])
                ->map(static fn (object $item): array => [
                    'key' => 'local:'.$item->id, 'name' => $item->name, 'source' => 'Cadastro fiscal',
                ]);
            $hubspot = DB::table('hubspot_companies')->whereRaw("LOWER(COALESCE(name,'')) LIKE ? ESCAPE '!'", [$like])
                ->orderBy('name')->limit(12)->get(['id', 'name'])
                ->map(static fn (object $item): array => [
                    'key' => 'hubspot:'.$item->id, 'name' => $item->name, 'source' => 'HubSpot',
                ]);
            $matches = $local->concat($hubspot)->take(15)->values();
        }

        return view('livewire.whatsapp-company-links-v16', compact('channels', 'groups', 'selectedCount', 'matches'));
    }
}
