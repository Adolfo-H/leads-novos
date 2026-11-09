<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignProspectingAudienceV80;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Nova aba: somente recomendações e rascunhos; jamais inicia despacho. */
final class CampaignProspectingControllerV80 extends Controller
{
    public function index(Request $request, CampaignProspectingAudienceV80 $audience): View
    {
        $actor = $this->manager();
        $filters = CampaignProspectingAudienceV80::filters($request->query());
        $page = max(1, min(10000, (int) $request->query('page', 1)));
        // Evita varrer a base inteira da Receita ao simplesmente abrir a aba.
        $hasAnalysis = $request->query('analyze') === '1';
        $people = $hasAnalysis
            ? $audience->paginate($filters, $page, (int) $actor->id)
            : new LengthAwarePaginator([], 0, 50, 1);
        $cap = $this->cap();
        $counts = DB::table('email_campaign_recipients')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'sent') AS sent")
            ->selectRaw("COUNT(*) FILTER (WHERE status IN ('reserved','sending','uncertain')) AS in_progress")
            ->first();

        return view('campaigns.prospecting-v80', [
            'filters' => $filters,
            'hasAnalysis' => $hasAnalysis,
            'people' => $people,
            'cap' => $cap,
            'previousSent' => (int) ($counts->sent ?? 0),
            'inProgress' => (int) ($counts->in_progress ?? 0),
            'excludedCount' => DB::table('email_campaign_prospecting_exclusions_v83')
                ->where('user_id', $actor->id)->count(),
            'excludedRecent' => $hasAnalysis
                ? DB::table('email_campaign_prospecting_exclusions_v83')
                    ->where('user_id', $actor->id)->orderByDesc('id')->limit(30)->get()
                : collect(),
            'smtpPerDay' => max(1, (int) config('campaign_mail.smtp_approved_per_day', 500)),
        ]);
    }

    public function draft(Request $request, CampaignProspectingAudienceV80 $audience, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
            'basis_reviewed' => ['accepted'],
            'origin' => ['nullable', 'in:all,hubspot,manual,receita'],
            'days' => ['nullable', 'integer', 'in:30,60,90,120,180,365'],
            'mode' => ['nullable', 'in:all,new,dormant'],
            'company' => ['nullable', 'string', 'max:100'],
            'job' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $filters = CampaignProspectingAudienceV80::filters($data);
        $cap = $this->cap();
        $candidates = $audience->query($filters, (int) $user->id)->limit($cap)->get();
        // A seleção é inteiramente automática: sem Livewire arrays de 20 mil IDs.
        $keys = [];
        foreach ($candidates as $candidate) {
            if (filter_var((string) $candidate->normalized_email, FILTER_VALIDATE_EMAIL)) {
                $keys[] = (string) $candidate->source_key;
            }
        }
        if ($keys === []) {
            throw ValidationException::withMessages([
                'recipients' => 'Não há e-mails válidos elegíveis para esses filtros.',
            ]);
        }

        $campaign = $manager->saveDraft($user, [
            'name' => (string) $data['name'],
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
        ], $keys);

        return redirect()->route('campaigns.index')->with(
            'intelligence_success',
            'Rascunho #'.$campaign->id.' criado com seleção inteligente. Nenhum e-mail foi enviado; revise o público antes da aprovação.'
        );
    }

    public function export(Request $request, CampaignProspectingAudienceV80 $audience): StreamedResponse
    {
        $actor = $this->manager();
        $filters = CampaignProspectingAudienceV80::filters($request->query());
        $query = $audience->query($filters, (int) $actor->id)->limit(25000);

        return response()->streamDownload(static function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['E-mail', 'Contato', 'Empresa', 'Cargo', 'Origem', 'Última atividade direta', 'Chave de origem'], ';', '"', '');
            foreach ($query->cursor() as $row) {
                // Protege a exportação contra fórmulas executáveis no Excel.
                $safe = static function (mixed $value): string {
                    $value = (string) $value;

                    return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
                };
                fputcsv($handle, [
                    $safe($row->normalized_email),
                    $safe($row->contact_name),
                    $safe($row->company_name),
                    $safe($row->job_title),
                    $safe($row->origin),
                    $safe($row->last_contact_at),
                    $safe($row->source_key),
                ], ';', '"', '');
            }
            fclose($handle);
        }, 'prospeccao-elegiveis-'.now('UTC')->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // EC_PROSPECTING_EXCLUDE_V83: excluir somente sugestões do gestor, nunca descadastrar.
    public function exclude(Request $request, CampaignProspectingAudienceV80 $audience): RedirectResponse
    {
        $actor = $this->manager();
        $data = $this->exclusionInput($request);
        $email = mb_strtolower(trim((string) $data['email']));
        $filters = CampaignProspectingAudienceV80::filters($data);

        if (! $audience->query($filters, (int) $actor->id)
            ->where('eligible.normalized_email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'E-mail não está elegível nesta análise. Atualize os filtros antes de excluir.',
            ]);
        }

        DB::table('email_campaign_prospecting_exclusions_v83')->insertOrIgnore([
            'user_id' => $actor->id,
            'normalized_email' => $email,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $this->backToAnalysis($filters, $data)
            ->with('prospecting_v83_notice', 'E-mail removido das suas sugestões de prospecção. Nenhum descadastro global foi criado.');
    }

    public function restore(Request $request): RedirectResponse
    {
        $actor = $this->manager();
        $data = $this->exclusionInput($request);
        $filters = CampaignProspectingAudienceV80::filters($data);
        $email = mb_strtolower(trim((string) $data['email']));
        DB::table('email_campaign_prospecting_exclusions_v83')
            ->where('user_id', $actor->id)->where('normalized_email', $email)->delete();

        return $this->backToAnalysis($filters, $data)
            ->with('prospecting_v83_notice', 'E-mail liberado novamente para a análise de prospecção.');
    }

    /** @return array<string, mixed> */
    private function exclusionInput(Request $request): array
    {
        return $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'origin' => ['nullable', 'in:all,hubspot,manual,receita'],
            'days' => ['nullable', 'integer', 'in:30,60,90,120,180,365'],
            'mode' => ['nullable', 'in:all,new,dormant'],
            'company' => ['nullable', 'string', 'max:100'],
            'job' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
    }

    /** @param array<string, mixed> $filters
     * @param  array<string, mixed>  $input
     */
    private function backToAnalysis(array $filters, array $input): RedirectResponse
    {
        return redirect()->route('campaigns.prospecting.index', [
            ...$filters,
            'analyze' => '1',
            'page' => (int) ($input['page'] ?? 1),
        ]);
    }

    private function cap(): int
    {
        // Preserva o teto do fluxo atual e do resolver de campanhas.
        return max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
    }

    private function manager(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        return $actor;
    }
}
