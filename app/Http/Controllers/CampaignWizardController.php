<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContactTransfer;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignPacing;
use App\Services\Campaigns\CampaignProspectingTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fluxo de criação HTTP seguro: não depende de Alpine/Livewire para filtros,
 * seleção, avanço, retorno, cancelamento ou gravação de RASCUNHO.
 * Nenhuma ação deste controlador inicia envio de e-mail.
 */
final class CampaignWizardController extends Controller
{
    private const SESSION = 'campaigns_wizard_v93';

    /** @var list<string> */
    private const ORIGINS = ['all', 'hubspot', 'manual', 'receita'];

    /** @var list<string> */
    private const MODELS = ['initial', 'direct', 'followup', 'meeting'];

    public function index(Request $request, CampaignAudience $audience, CampaignContactTransfer $transfer): View|RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        if (! $request->query('wizard_id')) {
            return redirect()->route('campaigns.wizard-v93', array_merge(
                $request->query(),
                ['wizard_id' => $wizardId],
            ));
        }
        $state = $this->state($request, $wizardId);

        if ($request->query('reiniciar') === '1') {
            $state = [];
        }

        // V94: editar um rascunho existente no MESMO assistente HTTP.
        // Não carrega nem altera campanhas já iniciadas/concluídas.
        $editId = $request->query('editar');
        if (is_string($editId) && preg_match('/^[1-9][0-9]{0,11}$/D', $editId) === 1) {
            $draft = EmailCampaign::query()->whereKey((int) $editId)
                ->where('status', 'draft')->firstOrFail();
            $expectedForEdit = [];
            foreach ($draft->recipients()->whereNotIn('status', ['canceled', 'suppressed'])->get() as $recipient) {
                $sourceKey = (string) $recipient->source_key;
                $email = CampaignAudience::normalizedEmail((string) $recipient->normalized_email);
                if ($sourceKey !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $expectedForEdit[$sourceKey] = $email;
                }
            }
            $transfer->guard(array_keys($expectedForEdit), $expectedForEdit);
            $state = [
                'editing_campaign_id' => (int) $draft->getKey(),
                'expected' => $expectedForEdit,
                'model' => 'initial',
                'name' => (string) $draft->name,
                'subject' => (string) $draft->subject,
                'body' => (string) $draft->body,
                'hours' => '8',
            ];
        }

        // Compatível com o POST de Contatos da V92: reaproveita APENAS
        // os e-mails efetivamente marcados, sem buscar novos destinatários.
        $pending = $request->session()->pull($this->transferSessionKey($wizardId), []);
        if (is_array($pending) && $pending !== []) {
            $transfer->guard(array_keys($pending), $pending);
            $state = ['expected' => $pending, 'model' => 'initial'];
        }

        $model = $request->query('modelo');
        if ($state === []) {
            $model = is_string($model) && in_array($model, self::MODELS, true) ? $model : 'initial';
            [$subject, $body] = $this->template($model);
            $state = [
                'expected' => [],
                'model' => $model,
                'name' => '',
                'subject' => $subject,
                'body' => $body,
                'hours' => '8',
            ];
        }
        $request->session()->put($this->sessionKey($wizardId), $state);

        $step = $request->query('passo', 'contatos');
        $step = is_string($step) && in_array($step, ['contatos', 'mensagem', 'ritmo', 'revisao'], true) ? $step : 'contatos';
        if ($step !== 'contatos' && $this->expected($state) === []) {
            return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId])->withErrors([
                'recipients' => 'Selecione ao menos um endereço antes de continuar.',
            ]);
        }

        $origin = $request->query('origem', 'all');
        $origin = is_string($origin) && in_array($origin, self::ORIGINS, true) ? $origin : 'all';
        $search = $request->query('busca', '');
        $search = is_string($search) ? mb_substr(trim($search), 0, 120) : '';
        $page = $request->query('pagina', 1);
        $page = is_numeric($page) ? max(1, min(10000, (int) $page)) : 1;
        $people = $step === 'contatos' ? $audience->paginate($search, $origin, $page) : null;
        $expected = $this->expected($state);
        $suppressed = $people !== null
            ? EmailCampaignSuppression::query()->whereIn('normalized_email', array_map(
                static fn (object $row): string => CampaignAudience::normalizedEmail((string) $row->email),
                $people->items(),
            ))->pluck('normalized_email')->all()
            : [];

        return view('campaigns.wizard', [
            'state' => $state,
            'wizardId' => $wizardId,
            'step' => $step,
            'origin' => $origin,
            'search' => $search,
            'people' => $people,
            'expected' => $expected,
            'suppressed' => $suppressed,
            'cap' => $this->cap(),
        ]);
    }

    public function selection(Request $request, CampaignContactTransfer $transfer): RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        $data = $request->validate([
            'wizard_id' => ['nullable', 'uuid'],
            'keys' => ['sometimes', 'array', 'max:1000'],
            'keys.*' => ['string', 'max:160'],
            'visible' => ['sometimes', 'array', 'max:100'],
            'visible.*' => ['string', 'max:160'],
            'expected' => ['sometimes', 'array', 'max:100'],
            'expected.*' => ['string', 'max:255'],
            'action' => ['sometimes', 'in:add,continue'],
            'next_origin' => ['nullable', 'in:all,hubspot,manual,receita'],
            'next_search' => ['nullable', 'string', 'max:120'],
            'next_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'origem' => ['nullable', 'in:all,hubspot,manual,receita'],
            'busca' => ['nullable', 'string', 'max:120'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $rawKeys = $data['keys'] ?? [];
        $rawVisible = $data['visible'] ?? [];
        $newKeys = is_array($rawKeys) ? array_values(array_unique($rawKeys)) : [];
        $visible = is_array($rawVisible) ? array_values(array_unique($rawVisible)) : [];
        foreach ($newKeys as $key) {
            if (! in_array($key, $visible, true)) {
                throw ValidationException::withMessages(['recipients' => 'Seleção fora da página atual. Atualize e tente novamente.']);
            }
        }

        $rawExpected = $data['expected'] ?? [];
        $new = $newKeys !== [] ? $transfer->capture($newKeys, is_array($rawExpected) ? $rawExpected : []) : [];
        $state = $this->state($request, $wizardId);
        $expected = $this->expected($state);
        foreach ($visible as $key) {
            unset($expected[$key]);
        }
        $expected = array_replace($expected, $new);
        if (count($expected) > $this->cap()) {
            throw ValidationException::withMessages(['recipients' => 'O total excede o limite permitido por campanha.']);
        }
        // Protege inclusive os contatos selecionados em páginas anteriores.
        $transfer->guard(array_keys($expected), $expected);
        $state['expected'] = $expected;
        $request->session()->put($this->sessionKey($wizardId), $state);

        if (($data['action'] ?? 'add') === 'continue') {
            if ($expected === []) {
                throw ValidationException::withMessages(['recipients' => 'Marque ao menos um contato com e-mail válido.']);
            }

            return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']);
        }

        return redirect()->route('campaigns.wizard-v93', [
            'wizard_id' => $wizardId,
            'origem' => $data['next_origin'] ?? $data['origem'] ?? 'all',
            'busca' => $data['next_search'] ?? $data['busca'] ?? '',
            'pagina' => isset($data['next_origin']) || (isset($data['next_search']) && $data['next_search'] !== ($data['busca'] ?? ''))
                ? 1 : ($data['next_page'] ?? $data['pagina'] ?? 1),
        ])->with('campaigns_v93_notice', 'Seleção sincronizada. Nenhum e-mail foi enviado.');
    }

    public function addFiltered(Request $request, CampaignAudience $audience, CampaignContactTransfer $transfer): RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        $data = $request->validate([
            'wizard_id' => ['nullable', 'uuid'],
            'origem' => ['nullable', 'in:all,hubspot,manual,receita'],
            'busca' => ['nullable', 'string', 'max:120'],
        ]);
        $origin = $data['origem'] ?? 'all';
        $search = $data['busca'] ?? '';
        try {
            $keys = $audience->allMatchingKeys($search, $origin, $this->cap());
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['recipients' => $exception->getMessage()]);
        }
        $rows = $audience->resolve($keys);
        $state = $this->state($request, $wizardId);
        $expected = $this->expected($state);
        foreach ($rows as $row) {
            if (! $row['suppressed']) {
                $expected[$row['source_key']] = $row['normalized_email'];
            }
        }
        if (count($expected) > $this->cap()) {
            throw ValidationException::withMessages(['recipients' => 'A seleção excede o limite desta campanha. Refine a pesquisa.']);
        }
        $transfer->guard(array_keys($expected), $expected);
        $state['expected'] = $expected;
        $request->session()->put($this->sessionKey($wizardId), $state);

        return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'origem' => $origin, 'busca' => $search])
            ->with('campaigns_v93_notice', 'Contatos encontrados adicionados à seleção.');
    }

    public function remove(Request $request): RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        $data = $request->validate([
            'wizard_id' => ['nullable', 'uuid'],
            'key' => ['required', 'string', 'max:160'],
            'origem' => ['nullable', 'in:all,hubspot,manual,receita'],
            'busca' => ['nullable', 'string', 'max:120'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $state = $this->state($request, $wizardId);
        $expected = $this->expected($state);
        unset($expected[$data['key']]);
        $state['expected'] = $expected;
        $request->session()->put($this->sessionKey($wizardId), $state);

        return redirect()->route('campaigns.wizard-v93', [
            'wizard_id' => $wizardId,
            'origem' => $data['origem'] ?? 'all',
            'busca' => $data['busca'] ?? '',
            'pagina' => $data['pagina'] ?? 1,
        ]);
    }

    /** A troca de modelo substitui somente assunto/corpo deste assistente. Sem SMTP. */
    public function chooseTemplate(Request $request): RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        $data = $request->validate([
            'wizard_id' => ['required', 'uuid'],
            'model' => ['required', 'in:initial,direct,followup,meeting'],
        ]);
        $state = $this->state($request, $wizardId);
        if ($state === []) {
            return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId]);
        }
        [$subject, $body] = CampaignProspectingTemplates::template($data['model']);
        $state['model'] = $data['model'];
        $state['subject'] = $subject;
        $state['body'] = $body;
        $request->session()->put($this->sessionKey($wizardId), $state);

        return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']);
    }

    public function message(Request $request): RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        $data = $request->validate([
            'wizard_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
        ]);
        $state = $this->state($request, $wizardId);
        if ($this->expected($state) === []) {
            return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId])->withErrors(['recipients' => 'Nenhum contato selecionado.']);
        }
        $request->session()->put($this->sessionKey($wizardId), array_merge($state, $data));

        return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'ritmo']);
    }

    public function pace(Request $request): RedirectResponse
    {
        $this->manager($request);
        $wizardId = $this->wizardId($request);
        $data = $request->validate([
            'wizard_id' => ['nullable', 'uuid'],
            'hours' => ['required', 'integer', 'in:1,2,4,8,12,24'],
        ]);
        $state = $this->state($request, $wizardId);
        if ($this->expected($state) === [] || mb_strlen(trim((string) ($state['body'] ?? ''))) < 10) {
            return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId])->withErrors(['campaign' => 'Conclua contatos e mensagem primeiro.']);
        }
        $state['hours'] = (string) $data['hours'];
        $request->session()->put($this->sessionKey($wizardId), $state);

        return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'revisao']);
    }

    public function save(Request $request, CampaignContactTransfer $transfer, CampaignManager $manager, CampaignPacing $pacing): RedirectResponse
    {
        $actor = $this->manager($request);
        $wizardId = $this->wizardId($request);
        $request->validate(['wizard_id' => ['nullable', 'uuid']]);
        $state = $this->state($request, $wizardId);
        $expected = $this->expected($state);
        if ($expected === [] || count($expected) > $this->cap()) {
            throw ValidationException::withMessages(['recipients' => 'Seleção vazia ou acima do limite. Nenhuma campanha foi criada.']);
        }
        $request->merge([
            'name' => $state['name'] ?? null,
            'subject' => $state['subject'] ?? null,
            'body' => $state['body'] ?? null,
        ]);
        $fields = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
        ]);
        $keys = array_keys($expected);
        $transfer->guard($keys, $expected);
        // Apenas rascunho: SMTP e filas jamais são acionados por este POST.
        $editingId = $state['editing_campaign_id'] ?? null;
        $editing = is_int($editingId) && $editingId > 0
            ? EmailCampaign::query()->whereKey($editingId)->where('status', 'draft')->firstOrFail()
            : null;
        // V94: atualiza o mesmo rascunho, sem duplicar destinatários/campanhas.
        $campaign = $manager->saveDraft($actor, $fields, $keys, $editing);
        $pacing->save($campaign, (int) ($state['hours'] ?? 8));
        $request->session()->forget($this->sessionKey($wizardId));
        $request->session()->forget($this->transferSessionKey($wizardId));

        return redirect()->route('campaigns.index', ['campanha' => $campaign->getKey()])
            ->with('campaign_success', 'Rascunho salvo com os destinatários conferidos. Nenhum e-mail enviado.');
    }

    private function manager(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        return $actor;
    }

    private function cap(): int
    {
        return max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
    }

    private function wizardId(Request $request): string
    {
        $id = $request->input('wizard_id', $request->query('wizard_id'));
        if ($id === null || $id === '') {
            return (string) Str::uuid();
        }
        if (! is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw ValidationException::withMessages(['wizard_id' => 'Identificador do assistente inválido. Abra uma nova campanha.']);
        }

        return strtolower($id);
    }

    private function sessionKey(string $wizardId): string
    {
        return self::SESSION.'_'.$wizardId;
    }

    private function transferSessionKey(string $wizardId): string
    {
        return 'campaign_transfer_pending_v92_'.$wizardId;
    }

    /** @return array<string, mixed> */
    private function state(Request $request, string $wizardId): array
    {
        $raw = $request->session()->get($this->sessionKey($wizardId), []);
        if (! is_array($raw)) {
            return [];
        }
        $state = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    private function expected(array $state): array
    {
        $stored = $state['expected'] ?? [];
        if (! is_array($stored)) {
            return [];
        }
        $expected = [];
        foreach ($stored as $key => $email) {
            if (is_string($key) && is_string($email)) {
                $expected[$key] = $email;
            }
        }

        return $expected;
    }

    /** @return array{0: string, 1: string} */
    private function template(string $key): array
    {
        return CampaignProspectingTemplates::template($key);
    }
}
