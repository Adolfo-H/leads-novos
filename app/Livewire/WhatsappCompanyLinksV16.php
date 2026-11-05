<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Contacts\WhatsappCompanyChannelLinkV16;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class WhatsappCompanyLinksV16 extends Component
{
    public string $numberSearch = '';

    public int $selectedChannel = 0;

    public string $companySearch = '';

    public string $selectedCompany = '';

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isCommercialManager(), 403);
    }

    public function chooseChannel(int $id): void
    {
        abort_unless(DB::table('whatsapp_public_company_channels')->where('id', $id)->exists(), 404);
        $this->selectedChannel = $id;
        $this->selectedCompany = '';
    }

    public function chooseCompany(string $key): void
    {
        abort_unless(preg_match('/^(local|hubspot):\d+$/D', $key) === 1, 422);
        $this->selectedCompany = $key;
    }

    public function saveLink(): void
    {
        app(WhatsappCompanyChannelLinkV16::class)->assign(auth()->user(), $this->selectedChannel, $this->selectedCompany);
        $this->selectedChannel = 0;
        $this->selectedCompany = '';
        $this->companySearch = '';
        $this->numberSearch = '';
        session()->flash('wa16_linked', 'Canal associado à empresa escolhida. O número permanece registrado com a fonte original.');
    }

    public function render(): View
    {
        $digits = preg_replace('/\D/', '', $this->numberSearch) ?: '';
        $channels = DB::table('whatsapp_public_company_channels as c')
            ->join('hubspot_companies as h', 'h.id', '=', 'c.hubspot_company_id')
            ->leftJoin('whatsapp_company_channel_links_v16 as link', 'link.channel_id', '=', 'c.id')
            ->whereNull('link.id')
            ->when(trim($this->numberSearch) !== '', function ($query) use ($digits): void {
                if (strlen($digits) >= 5) {
                    $query->where('c.e164', 'like', '%'.$digits.'%');
                } else {
                    $term = '%'.mb_strtolower(trim($this->numberSearch)).'%';
                    $query->whereRaw("LOWER(COALESCE(h.name, '')) LIKE ?", [$term]);
                }
            })
            ->orderByDesc('c.id')->limit(80)
            ->get(['c.id', 'c.e164', 'h.name as company_name', 'h.domain'])
            ->unique(fn (object $row): string => $row->e164.'|'.$row->domain)
            ->take(20)->values();
        $matches = collect();
        $term = mb_strtolower(trim($this->companySearch));
        if (mb_strlen($term) >= 2 && $this->selectedChannel > 0) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $cnpjDigits = (string) preg_replace('/\D+/', '', $term);
            $local = DB::table('companies')->where(static function ($query) use ($like, $cnpjDigits): void {
                $query->whereRaw("LOWER(corporate_name) LIKE ? ESCAPE '!'", [$like]);
                if (strlen($cnpjDigits) >= 4) {
                    $query->orWhere('cnpj_root', 'like', '%'.substr($cnpjDigits, 0, 8).'%');
                }
            })->orderBy('corporate_name')->limit(12)->get(['id', 'corporate_name as name'])
                ->map(fn ($item): array => ['key' => 'local:'.$item->id, 'name' => $item->name, 'source' => 'Cadastro fiscal']);
            $hubspot = DB::table('hubspot_companies')->whereRaw("LOWER(COALESCE(name,'')) LIKE ? ESCAPE '!'", [$like])
                ->orderBy('name')->limit(12)->get(['id', 'name'])
                ->map(fn ($item): array => ['key' => 'hubspot:'.$item->id, 'name' => $item->name, 'source' => 'HubSpot']);
            $matches = $local->concat($hubspot)->take(15)->values();
        }

        return view('livewire.whatsapp-company-links-v16', compact('channels', 'matches'));
    }
}
