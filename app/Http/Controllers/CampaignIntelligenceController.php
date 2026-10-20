<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignHumanReplies;
use App\Services\Campaigns\CampaignIntelligenceMetrics;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignSegmentation;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignIntelligenceController extends Controller
{
    // EC_RECEITA_UNIFIED_V10: filtro Receita cadastral.

    public function index(Request $request, CampaignSegmentation $segmentation, CampaignIntelligenceMetrics $metrics): View
    {
        $this->manager();
        $filters = $this->filters($request);
        $preview = $segmentation->preview($filters, 40);
        $segments = DB::table('email_campaign_segments')->orderByDesc('id')->limit(25)->get();
        $sequences = DB::table('email_campaign_sequences')->orderByDesc('id')->limit(25)->get();
        $campaigns = EmailCampaign::query()->latest('id')->limit(80)->get(['id', 'name', 'status']);
        $replies = EmailCampaignReply::query()->with('recipient')->latest('id')->limit(20)->get();

        return view('campaigns.intelligence', [
            'filters' => $filters,
            'preview' => $preview,
            'segments' => $segments,
            'sequences' => $sequences,
            'campaigns' => $campaigns,
            'replies' => $replies,
            'metrics' => $metrics->summary(),
            'repliesEnabled' => (bool) config('campaign_mail.live_enabled') && (bool) config('campaign_mail.reply_send_enabled', false),
        ]);
    }

    public function saveSegment(Request $request): RedirectResponse
    {
        $user = $this->manager();
        $data = $request->validate([
            'segment_name' => ['required', 'string', 'min:3', 'max:150'],
            'origin' => ['nullable', 'in:all,hubspot,manual,receita'],
            'company' => ['nullable', 'string', 'max:100'],
            'job' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'exclude_customers' => ['nullable', 'boolean'],
        ]);
        $filters = [
            'origin' => (string) ($data['origin'] ?? 'all'),
            'company' => (string) ($data['company'] ?? ''),
            'job' => (string) ($data['job'] ?? ''),
            'search' => (string) ($data['search'] ?? ''),
            'exclude_customers' => (string) ($data['exclude_customers'] ?? '1'),
        ];
        DB::table('email_campaign_segments')->insert([
            'name' => trim((string) $data['segment_name']),
            'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
            'created_by' => $user->id,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return redirect()->route('campaigns.intelligence.index')->with('intelligence_success', 'Segmento salvo. Nenhum envio realizado.');
    }

    public function draft(Request $request, CampaignSegmentation $segmentation, CampaignManager $manager): RedirectResponse
    {
        $user = $this->manager();
        if (config('campaign_mail.live_enabled')) {
            throw ValidationException::withMessages(['draft' => 'Bloqueie envios comerciais antes de montar campanhas segmentadas.']);
        }
        $data = $request->validate([
            'segment_id' => ['required', 'integer', 'exists:email_campaign_segments,id'],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
            'basis_reviewed' => ['accepted'],
        ]);
        $segment = DB::table('email_campaign_segments')->where('id', $data['segment_id'])->first();
        /** @var array<string, string|bool> $filters */
        $filters = json_decode((string) $segment->filters, true, 512, JSON_THROW_ON_ERROR);
        $cap = max(1, min(500, (int) config('campaign_mail.max_recipients', 100)));
        $rows = $segmentation->preview($filters, $cap);
        if ($rows === []) {
            throw ValidationException::withMessages(['draft' => 'Nenhum contato elegível. Ajuste o segmento.']);
        }
        $keys = array_map(static fn (array $row): string => $row['source_key'], $rows);
        $manager->saveDraft($user, [
            'name' => (string) $data['name'], 'subject' => (string) $data['subject'], 'body' => (string) $data['body'],
        ], $keys);

        return redirect()->route('campaigns.index')->with('intelligence_success', 'Rascunho criado. Revise os destinatários e aprovações antes de enviar.');
    }

    public function sequence(Request $request, CampaignSequencePlanner $planner): RedirectResponse
    {
        $user = $this->manager();
        $data = $request->validate([
            'email_campaign_id' => ['required', 'integer', 'exists:email_campaigns,id'],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'delay_days' => ['required', 'integer', 'min:1', 'max:90'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
        ]);
        $source = EmailCampaign::query()->findOrFail((int) $data['email_campaign_id']);
        $planner->create($user, $source, (string) $data['name'], (int) $data['delay_days'], (string) $data['subject'], (string) $data['body']);

        return redirect()->route('campaigns.intelligence.index')->with('intelligence_success', 'Follow-up planejado. Ainda não há rascunho nem envio.');
    }

    public function prepare(Request $request, int $sequence, CampaignSequencePlanner $planner): RedirectResponse
    {
        $user = $this->manager();
        $request->validate(['confirm_draft' => ['accepted']]);
        $draftId = $planner->prepare($user, $sequence);

        return redirect()->route('campaigns.intelligence.index')->with('intelligence_success', $draftId !== null
            ? 'Rascunho de follow-up nº '.$draftId.' preparado, sem enviar mensagens.'
            : 'Nenhum destinatário elegível venceu o prazo; nada foi gerado.');
    }

    public function sendReply(Request $request, CampaignHumanReplies $service): RedirectResponse
    {
        $user = $this->manager();
        $data = $request->validate([
            'reply_id' => ['required', 'integer', 'exists:email_campaign_replies,id'],
            'body' => ['required', 'string', 'min:5', 'max:10000'],
            'confirm_recipient' => ['accepted'],
            'request_id' => ['required', 'uuid'],
        ]);
        $reply = EmailCampaignReply::query()->with('recipient')->findOrFail((int) $data['reply_id']);
        $id = $service->send($user, $reply, (string) $data['body'], (string) $data['request_id']);
        $status = (string) DB::table('email_campaign_outbound_replies')->where('id', $id)->value('status');

        return redirect()->route('campaigns.intelligence.index')->with('intelligence_success',
            $status === 'sent' ? 'SMTP aceitou a resposta; entrega não garantida.' : 'Resultado incerto: revise o servidor, não reenvie automaticamente.');
    }

    private function manager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);

        return $user;
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return [
            'origin' => in_array((string) $request->query('origin'), ['hubspot', 'manual', 'receita'], true) ? (string) $request->query('origin') : 'all',
            'company' => mb_substr((string) $request->query('company', ''), 0, 100),
            'job' => mb_substr((string) $request->query('job', ''), 0, 100),
            'search' => mb_substr((string) $request->query('search', ''), 0, 100),
            'exclude_customers' => '1',
        ];
    }
}
