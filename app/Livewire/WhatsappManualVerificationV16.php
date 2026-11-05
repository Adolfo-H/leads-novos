<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Contacts\WhatsappManualPhoneV16;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Ações sob demanda, sem iniciar envios nem agendar verificações. */
class WhatsappManualVerificationV16 extends Component
{
    public string $numbers = '';

    public string $reviewSearch = '';

    public int $reviewPhoneId = 0;

    public string $correctedPhone = '';

    public string $reviewNote = '';

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isCommercialManager(), 403);
    }

    public function requestSpecific(): void
    {
        $outcome = app(WhatsappManualPhoneV16::class)->queue(auth()->user(), $this->numbers);
        $this->numbers = '';
        session()->flash('wa16_ok', $outcome['queued'].' número(s) selecionado(s) enviado(s) para a fila das contas conectadas.');
    }

    public function chooseReview(int $id): void
    {
        $candidate = DB::table('whatsapp_phone_numbers')->where('id', $id)->where('kind', 'review')->first();
        abort_unless($candidate !== null, 404);
        $this->reviewPhoneId = $id;
        $this->correctedPhone = '';
        $this->reviewNote = '';
    }

    public function saveReview(string $decision): void
    {
        app(WhatsappManualPhoneV16::class)->review(auth()->user(), $this->reviewPhoneId,
            $decision, $this->correctedPhone, $this->reviewNote);
        $this->reviewPhoneId = 0;
        $this->correctedPhone = '';
        $this->reviewNote = '';
        session()->flash('wa16_ok', $decision === 'corrected'
            ? 'Correção registrada. O original não foi alterado; o número corrigido já está disponível no índice.'
            : 'Formato inválido registrado; não será consultado automaticamente.');
    }

    public function render(): View
    {
        $search = (string) preg_replace('/\D+/', '', $this->reviewSearch);
        $query = DB::table('whatsapp_phone_numbers as n')
            ->leftJoin('whatsapp_phone_format_reviews_v16 as r', 'r.original_phone_id', '=', 'n.id')
            ->where('n.kind', 'review')
            ->whereNull('r.id');
        if (strlen($search) >= 3) {
            $query->where('n.digits', 'like', '%'.$search.'%');
        }
        $items = $query->orderBy('n.id')->limit(20)->get(['n.id', 'n.digits', 'n.contacts_count']);

        return view('livewire.whatsapp-manual-verification-v16', ['reviews' => $items]);
    }
}
