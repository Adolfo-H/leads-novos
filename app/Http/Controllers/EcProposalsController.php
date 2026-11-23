<?php

namespace App\Http\Controllers;

use App\Models\EcProposal;
use App\Models\EcProposalDocument;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Proposals\ProposalPricing;
use App\Services\Proposals\ProposalWorkflow;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EcProposalsController extends Controller
{
    private function workspace(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isCommercialManager() || $user->isCommercialSeller()), 403);

        return $user;
    }

    private function manager(Request $request): User
    {
        $user = $this->workspace($request);
        abort_unless($user->isCommercialManager(), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        $q = trim((string) $request->query('cliente', ''));
        $companies = HubSpotCompany::query()
            ->when($q !== '', static function ($query) use ($q): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']);
            })
            ->orderBy('name')->limit(20)->get(['id', 'name', 'hubspot_id']);
        $services = DB::table('ec_proposal_templates')->orderBy('id')->get();

        return compact('companies', 'services', 'q');
    }

    public function index(Request $request): View
    {
        $this->workspace($request);
        $query = EcProposal::query();
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        if ($search !== '') {
            $query->where(static function ($q) use ($search): void {
                $q->where('client_name', 'like', '%'.$search.'%')
                    ->orWhere('legacy_code', 'like', '%'.$search.'%')
                    ->orWhereRaw('CAST(proposal_numbers AS TEXT) LIKE ?', ['%'.$search.'%']);
            });
        }
        $status = (string) $request->query('status', 'all');
        if (in_array($status, ['draft', 'issued', 'sent', 'accepted', 'partially_accepted', 'declined', 'imported', 'generation_failed'], true)) {
            $query->where('status', $status);
        }
        $service = (string) $request->query('service', 'all');
        if (in_array($service, ProposalPricing::ALL, true)) {
            $query->whereJsonContains('service_codes', $service);
        }
        $year = (string) $request->query('year', '');
        if (preg_match('/^20\d{2}$/D', $year) === 1) {
            $query->whereYear('created_at', (int) $year);
        }
        $list = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $counts = [
            'total' => EcProposal::query()->count(),
            'draft' => EcProposal::query()->where('status', 'draft')->count(),
            'issued' => EcProposal::query()->whereIn('status', ['issued', 'sent'])->count(),
            'accepted' => EcProposal::query()->where('status', 'accepted')->count(),
        ];

        return view('proposals.index', compact('list', 'counts', 'search', 'status', 'service', 'year'));
    }

    public function create(Request $request): View
    {
        $this->workspace($request);

        return view('proposals.form', array_merge($this->formData($request), [
            'proposal' => null, 'title' => 'Nova proposta',
        ]));
    }

    public function edit(Request $request, EcProposal $proposal): View
    {
        $this->workspace($request);
        abort_unless($proposal->status === 'draft', 403);

        return view('proposals.form', array_merge($this->formData($request), [
            'proposal' => $proposal, 'title' => 'Editar proposta',
        ]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:220'],
            'client_contact' => ['nullable', 'string', 'max:160'],
            'client_email' => ['nullable', 'email', 'max:200'],
            'client_cnpj' => ['nullable', 'string', 'max:30'],
            'client_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:4096'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'hubspot_company_id' => ['nullable', 'integer', 'exists:hubspot_companies,id'],
            'hubspot_deal_id' => ['nullable', 'integer', 'exists:hubspot_deals,id'],
            'service_codes' => ['required', 'array', 'min:1', 'max:5'],
            'service_codes.*' => ['required', 'string', Rule::in(ProposalPricing::ALL)],
            'price_date' => ['required', 'date_format:Y-m-d'],
            'recurring_total' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'recurring_from' => ['nullable', 'date_format:Y-m-d'],
            'recurring_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:recurring_from'],
            'retro_total' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'retro_from' => ['nullable', 'date_format:Y-m-d'],
            'retro_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:retro_from'],
            'license_status' => ['required', Rule::in(['ATIVO', 'NAO_ATIVO', 'SERA_CONTRATADO_NESTE_PEDIDO'])],
            'retro_deadline' => ['nullable', 'string', 'max:180'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'payment_days' => ['required', 'integer', 'min:1', 'max:365'],
            'sales_responsible' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        // O vínculo com o negócio é sempre um registro real do espelho local.
        if (! empty($data['hubspot_deal_id']) && ! empty($data['hubspot_company_id'])) {
            $belongs = DB::table('hubspot_company_deal')
                ->where('hubspot_company_id', $data['hubspot_company_id'])
                ->where('hubspot_deal_id', $data['hubspot_deal_id'])->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['hubspot_deal_id' => 'Negócio não vinculado à empresa selecionada.']);
            }
        }

        return $data;
    }

    public function store(Request $request, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->workspace($request);
        $data = $this->validated($request);
        $proposal = $workflow->saveDraft($actor, $data);
        $this->uploadLogo($request, $proposal);

        return redirect()->route('proposals.show', $proposal)->with('proposal_ok', 'Rascunho salvo. Nenhuma numeração consumida nem e-mail enviado.');
    }

    public function update(Request $request, EcProposal $proposal, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->workspace($request);
        $proposal = $workflow->saveDraft($actor, $this->validated($request), $proposal);
        $this->uploadLogo($request, $proposal);

        return redirect()->route('proposals.show', $proposal)->with('proposal_ok', 'Rascunho atualizado.');
    }

    private function uploadLogo(Request $request, EcProposal $proposal): void
    {
        $file = $request->file('client_logo');
        if ($file instanceof UploadedFile) {
            $path = $file->store('proposals/logos', 'local');
            abort_unless(is_string($path), 500);
            $proposal->forceFill(['client_logo_path' => $path])->save();
        }
    }

    public function show(Request $request, EcProposal $proposal): View
    {
        $this->workspace($request);
        $proposal->load('documents', 'events');
        $company = $proposal->hubspot_company_id
            ? HubSpotCompany::query()->find($proposal->hubspot_company_id) : null;
        $deal = $proposal->hubspot_deal_id
            ? HubSpotDeal::query()->find($proposal->hubspot_deal_id) : null;
        $portal = (string) config('services.hubspot.portal_id', '');

        return view('proposals.show', compact('proposal', 'company', 'deal', 'portal'));
    }

    public function issue(Request $request, EcProposal $proposal, ProposalWorkflow $workflow): RedirectResponse
    {
        $user = $this->manager($request);
        $request->validate(['confirm_issue' => ['required', 'accepted']]);
        $workflow->issue($user, $proposal);

        return redirect()->route('proposals.show', $proposal)->with('proposal_ok', 'Documentos oficiais emitidos e armazenados em área privada.');
    }

    public function duplicate(Request $request, EcProposal $proposal, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->workspace($request);
        $attrs = $proposal->only([
            'client_name', 'client_contact', 'client_email', 'client_cnpj', 'company_id',
            'hubspot_company_id', 'hubspot_deal_id', 'recurring_total', 'retro_total',
            'license_status', 'retro_deadline', 'validity_days', 'payment_days',
            'sales_responsible', 'notes',
        ]);
        foreach (['recurring_from', 'recurring_to', 'retro_from', 'retro_to'] as $field) {
            $attrs[$field] = self::formatDate($proposal->{$field}, 'Y-m-d');
        }
        $attrs['service_codes'] = $proposal->service_codes;
        $attrs['price_date'] = now('America/Sao_Paulo')->toDateString();
        $copy = $workflow->saveDraft($actor, $attrs);
        $copy->forceFill(['client_logo_path' => $proposal->client_logo_path])->save();

        return redirect()->route('proposals.edit', $copy)->with('proposal_ok', 'Cópia criada sem número. Revise o preço atual e o cliente.');
    }

    /** Normalize Eloquent date casts for duplicates without assuming object types. */
    private static function formatDate(mixed $value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->format($format);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    public function revise(Request $request, EcProposal $proposal, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->manager($request);
        $revision = $workflow->createRevision($actor, $proposal);

        return redirect()->route('proposals.edit', $revision)
            ->with('proposal_ok', 'Rascunho de revisão criado com o mesmo número e sufixo REV. Valores anteriores preservados.');
    }

    public function changeStatus(Request $request, EcProposal $proposal, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['sent', 'accepted', 'declined'])],
            'accepted_services' => ['nullable', 'array'],
            'accepted_services.*' => ['string', Rule::in(ProposalPricing::ALL)],
            'evidence' => ['required', 'string', 'min:8', 'max:1500'],
        ]);
        if (! in_array($proposal->status, ['issued', 'sent', 'accepted', 'partially_accepted'], true)) {
            throw ValidationException::withMessages(['status' => 'Somente proposta emitida pode receber andamento comercial.']);
        }
        if (in_array($proposal->status, ['accepted', 'partially_accepted'], true) && $data['status'] !== 'accepted') {
            throw ValidationException::withMessages(['status' => 'Um aceite registrado não pode ser retirado sem auditoria e revisão formal.']);
        }
        $accepted = [];
        $newStatus = $data['status'];
        if ($newStatus === 'accepted') {
            $accepted = array_values(array_unique((array) ($data['accepted_services'] ?? [])));
            if ($accepted === [] || array_diff($accepted, (array) $proposal->service_codes) !== []) {
                throw ValidationException::withMessages(['accepted_services' => 'Indique os serviços aceitos de forma inequívoca.']);
            }
            $newStatus = count($accepted) === count((array) $proposal->service_codes)
                ? 'accepted' : 'partially_accepted';
        }
        $proposal->forceFill([
            'status' => $newStatus,
            'accepted_services' => $data['status'] === 'accepted' ? $accepted : $proposal->accepted_services,
            'accepted_at' => $data['status'] === 'accepted' ? now('UTC') : $proposal->accepted_at,
            'acceptance_evidence' => $data['status'] === 'accepted' ? $data['evidence'] : $proposal->acceptance_evidence,
            'updated_by' => $actor->id,
        ])->save();
        $workflow->event($proposal, $actor, 'status_'.$newStatus, ['evidence' => $data['evidence'], 'accepted_services' => $accepted]);

        return redirect()->route('proposals.show', $proposal)->with('proposal_ok', 'Andamento registrado; nenhuma mensagem foi enviada automaticamente.');
    }

    public function download(Request $request, EcProposal $proposal, EcProposalDocument $document): StreamedResponse
    {
        $this->workspace($request);
        abort_unless((int) $document->proposal_id === (int) $proposal->id, 404);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path, $document->original_name);
    }

    public function importPage(Request $request): View
    {
        $this->manager($request);

        return view('proposals.import');
    }

    public function import(Request $request, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'reviewed' => ['required', 'accepted'],
            'client_name' => ['required', 'string', 'max:220'],
            'legacy_code' => ['required', 'string', 'max:180'],
            'service' => ['required', Rule::in(ProposalPricing::ALL)],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'number' => ['nullable', 'integer', 'min:1000', 'max:999999999'],
            'monthly_final' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'one_time_final' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);
        $realPath = $file->getRealPath();
        abort_unless(is_string($realPath), 422);
        $sha = hash_file('sha256', $realPath);
        abort_unless(is_string($sha), 422);
        if (DB::table('ec_proposal_documents')->where('sha256', $sha)->exists()) {
            throw ValidationException::withMessages(['document' => 'Este PDF já está arquivado no histórico (mesmo hash).']);
        }
        if (DB::table('ec_proposals')->where('legacy_code', $data['legacy_code'])->exists()) {
            throw ValidationException::withMessages(['legacy_code' => 'Identificador histórico já registrado.']);
        }
        $p = DB::transaction(function () use ($data, $actor, $workflow): EcProposal {
            $numbers = isset($data['number']) ? [$data['service'] => (int) $data['number']] : null;
            if ($numbers !== null && DB::table('ec_proposal_number_ledger')->where('number', $data['number'])->exists()) {
                throw ValidationException::withMessages(['number' => 'Número de proposta já utilizado em outro registro.']);
            }
            $row = EcProposal::query()->create([
                'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
                'legacy_code' => $data['legacy_code'], 'client_name' => $data['client_name'],
                'service_codes' => [$data['service']], 'proposal_numbers' => $numbers,
                'issued_on' => $data['issued_on'] ?? null,
                'monthly_final' => $data['monthly_final'] ?? null,
                'one_time_final' => $data['one_time_final'] ?? null,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            if ($numbers !== null) {
                DB::table('ec_proposal_number_ledger')->insert([
                    'number' => $data['number'], 'proposal_id' => $row->id,
                    'service' => $data['service'], 'source' => 'imported',
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            $workflow->event($row, $actor, 'historical_import');

            return $row;
        });
        $savedPath = $file->storeAs('proposals/'.$p->public_id, 'original-historico.pdf', 'local');
        abort_unless(is_string($savedPath), 500);
        DB::table('ec_proposal_documents')->insert([
            'proposal_id' => $p->id, 'kind' => 'historical_pdf',
            'service' => $data['service'], 'storage_path' => $savedPath,
            'original_name' => $file->getClientOriginalName(),
            'sha256' => $sha, 'bytes' => (int) $file->getSize(), 'mime' => 'application/pdf',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return redirect()->route('proposals.show', $p)->with('proposal_ok', 'PDF original arquivado sem recalcular seu preço ou alterar sua numeração.');
    }

    public function pricing(Request $request): View
    {
        $this->workspace($request);
        $versions = DB::table('ec_proposal_price_versions')->orderBy('service')->orderByDesc('effective_from')->get();
        $adjustments = DB::table('ec_proposal_adjustments')->orderByDesc('effective_from')->get();
        $taxes = DB::table('ec_proposal_tax_profiles')->orderByDesc('effective_from')->get();
        $numbering = DB::table('ec_proposal_numbering')->where('id', 1)->first();

        return view('proposals.pricing', compact('versions', 'adjustments', 'taxes', 'numbering'));
    }

    public function addAdjustment(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'event_key' => ['required', 'string', 'max:100', 'unique:ec_proposal_adjustments,event_key'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'percentage' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'approve' => ['required', 'accepted'],
        ]);
        DB::table('ec_proposal_adjustments')->insert([
            'event_key' => $data['event_key'], 'effective_from' => $data['effective_from'],
            'percentage' => $data['percentage'], 'reason' => $data['reason'],
            'approved_by' => $actor->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return back()->with('proposal_ok', 'Novo reajuste aprovado e versionado; documentos emitidos não mudam.');
    }

    public function addTaxProfile(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'version' => ['required', 'string', 'max:100', 'unique:ec_proposal_tax_profiles,version'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ISS' => ['required', 'numeric', 'min:0', 'max:99'],
            'PIS' => ['required', 'numeric', 'min:0', 'max:99'],
            'COFINS' => ['required', 'numeric', 'min:0', 'max:99'],
            'IBS' => ['required', 'numeric', 'min:0', 'max:99'],
            'CBS' => ['required', 'numeric', 'min:0', 'max:99'],
            'approve' => ['required', 'accepted'],
        ]);
        $rates = array_intersect_key($data, array_flip(['ISS', 'PIS', 'COFINS', 'IBS', 'CBS']));
        if (array_sum($rates) >= 100) {
            throw ValidationException::withMessages(['ISS' => 'Soma das alíquotas deve ser inferior a 100%.']);
        }
        DB::table('ec_proposal_tax_profiles')->insert([
            'version' => $data['version'], 'effective_from' => $data['effective_from'],
            'rates' => json_encode($rates, JSON_THROW_ON_ERROR), 'approved' => true,
            'approved_by' => $actor->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return back()->with('proposal_ok', 'Perfil tributário versionado. Documentos antigos preservados.');
    }

    public function addPriceVersion(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'service' => ['required', Rule::in(ProposalPricing::OFFICIAL)],
            'version' => ['required', 'string', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'tiers' => ['required', 'array', 'min:5', 'max:6'],
            'tiers.*.fixed' => ['required', 'numeric', 'min:0', 'max:999999'],
            'tiers.*.unit' => ['required', 'numeric', 'min:0', 'max:999999'],
            'special_implementation' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'approve' => ['required', 'accepted'],
        ]);
        $previous = DB::table('ec_proposal_price_versions')
            ->where('service', $data['service'])->where('approved', true)
            ->orderByDesc('effective_from')->orderByDesc('id')->firstOrFail();
        if (DB::table('ec_proposal_price_versions')->where('service', $data['service'])->where('version', $data['version'])->exists()) {
            throw ValidationException::withMessages(['version' => 'Já existe uma versão com este nome.']);
        }
        $base = json_decode((string) $previous->configuration, true, 512, JSON_THROW_ON_ERROR);
        if (count($base['faixas']) !== count($data['tiers'])) {
            throw ValidationException::withMessages(['tiers' => 'A estrutura das faixas oficiais não pode ser alterada sem novo modelo.']);
        }
        foreach ($base['faixas'] as $i => &$tier) {
            $tier['fixed'] = (string) $data['tiers'][$i]['fixed'];
            $tier['unit'] = (string) $data['tiers'][$i]['unit'];
        }
        unset($tier);
        if ($data['service'] === 'RETROATIVAS') {
            $base['implantacao_sem_licenciamento']['valor_liquido'] = (string) ($data['special_implementation'] ?? $base['implantacao_sem_licenciamento']['valor_liquido']);
        }
        $base['versao'] = $data['version'];
        DB::table('ec_proposal_price_versions')->insert([
            'service' => $data['service'], 'version' => $data['version'],
            'effective_from' => $data['effective_from'],
            'configuration' => json_encode($base, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'approved' => true, 'approved_by' => $actor->id,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return back()->with('proposal_ok', 'Nova versão de tabela aprovada. As faixas anteriores não foram sobrescritas.');
    }

    public function templates(Request $request): View
    {
        $this->workspace($request);
        $templates = DB::table('ec_proposal_templates')->orderBy('id')->get();

        return view('proposals.templates', compact('templates'));
    }

    public function updateTemplate(Request $request): RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'service' => ['required', Rule::in(ProposalPricing::ALL)],
            'commercial_notes' => ['nullable', 'string', 'max:4000'],
            'description' => ['nullable', 'string', 'max:1200'],
        ]);
        DB::table('ec_proposal_templates')->where('service', $data['service'])
            ->update(['commercial_notes' => $data['commercial_notes'] ?? '',
                'description' => $data['description'] ?? '', 'updated_at' => now('UTC')]);

        return back()->with('proposal_ok', 'Observações internas do modelo atualizadas. HTML jurídico original preservado.');
    }

    public function reconcile(Request $request, ProposalWorkflow $workflow): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'last_number' => ['required', 'integer', 'min:999', 'max:999999999'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $workflow->reconcileNumbering($actor, (int) $data['last_number']);

        return back()->with('proposal_ok', 'Numeração global conciliada com confirmação explícita do gestor.');
    }
}
