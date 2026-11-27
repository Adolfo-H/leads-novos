<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignContactTransfer;
use App\Services\Campaigns\CampaignImportReader;
use App\Services\Campaigns\CampaignImportReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Isolated, manager-only import/review. Every operation is local and draft-only. */
final class CampaignImportController extends Controller
{
    private const PAGE_SIZE = 25;

    private const TTL_MINUTES = 30;

    public function show(Request $request, CampaignImportReview $review): View
    {
        [$owner, $wizard, $state] = $this->context($request);
        $preview = $this->preview($owner, $wizard);
        $expected = $this->expected($state);
        $result = $preview === null ? null : $review->review($preview['table']['rows'], $preview['mapping']);
        $blocked = $result === null ? [] : $this->blocked(array_column($result['contacts'], 'email'));
        $chosen = array_fill_keys(array_values($expected), true);
        $contacts = $result['contacts'] ?? [];
        $reviewed = $preview['reviewed'] ?? [];
        $eligible = $this->availableMarks($contacts, $blocked, $chosen);
        $marks = array_intersect_key($preview['marks'] ?? array_fill_keys(array_keys($reviewed), true), $eligible);
        $ready = array_intersect_key($this->availableReviewed($contacts, $reviewed, $blocked, $chosen), $marks);
        $pageCount = max(1, (int) ceil(count($contacts) / self::PAGE_SIZE));
        $requestedPage = $request->query('pagina', 1);
        $page = is_numeric($requestedPage) ? max(1, min($pageCount, (int) $requestedPage)) : 1;

        return view('campaigns.import-v27', [
            'wizardId' => $wizard,
            'preview' => $preview,
            'result' => $result,
            'blocked' => $blocked,
            'chosen' => $chosen,
            'contacts' => array_slice($contacts, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'page' => $page,
            'pageCount' => $pageCount,
            'selectedCount' => count($expected),
            'reviewed' => $reviewed,
            'reviewedCount' => count($ready),
            'marks' => $marks,
            'markedCount' => count($marks),
            'eligibleCount' => count($eligible),
            'draftEvidence' => $preview['draft_evidence'] ?? [],
            'cap' => $this->cap(),
        ]);
    }

    public function upload(Request $request, CampaignImportReader $reader, CampaignImportReview $review): RedirectResponse
    {
        [$owner, $wizard] = $this->context($request);
        try {
            $data = $request->validate([
                'source_label' => ['required', 'string', 'min:3', 'max:240'],
                'import_file' => ['nullable', 'file', 'max:8192'],
                'paste' => ['nullable', 'string', 'max:262144'],
            ]);
            $file = $request->file('import_file');
            $paste = (string) ($data['paste'] ?? '');
            if (($file !== null && ! $file instanceof UploadedFile) || ($file !== null && trim($paste) !== '')) {
                throw ValidationException::withMessages(['import_file' => 'Escolha apenas um arquivo ou uma lista colada.']);
            }
            $table = $reader->read($file, $paste);
            $preview = [
                'token' => (string) Str::uuid(),
                'source' => trim((string) $data['source_label']),
                'file' => $file === null ? 'Lista colada' : mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                'table' => $table,
                'mapping' => $review->autoMapping($table['columns'], $table['rows']),
            ];
            $this->storePreview($owner, $wizard, $preview);
        } catch (ValidationException $exception) {
            // Never flash the raw pasted contacts or uploaded document into session input.
            return $this->back($wizard)->withErrors($exception->errors());
        }

        return $this->back($wizard)->with('import_notice', 'Leitura concluída. Nenhum contato foi aprovado ou incluído automaticamente.');
    }

    public function mapping(Request $request): RedirectResponse
    {
        [$owner, $wizard] = $this->context($request);
        $preview = $this->requirePreview($request, $owner, $wizard);
        $raw = $request->validate(['mapping' => ['required', 'array'], 'mapping.*' => ['integer']]);
        $mapping = [];
        foreach (array_keys(CampaignImportReview::FIELDS) as $field) {
            $index = (int) ($raw['mapping'][$field] ?? -1);
            if ($index < -1 || $index >= count($preview['table']['columns']) || ($field === 'email' && $index === -1)) {
                throw ValidationException::withMessages(['mapping' => 'Escolha uma coluna válida de e-mail.']);
            }
            $mapping[$field] = $index;
        }
        $used = array_values(array_filter($mapping, static fn (int $i): bool => $i >= 0));
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['mapping' => 'Não utilize a mesma coluna em dois campos.']);
        }
        $preview['mapping'] = $mapping;
        // A new mapping changes the interpretation of row indexes.
        unset($preview['reviewed'], $preview['marks'], $preview['draft_evidence']);
        $preview['token'] = (string) Str::uuid();
        $this->storePreview($owner, $wizard, $preview);

        return $this->back($wizard)->with('import_notice', 'Mapeamento atualizado. Revise os destinatários antes de adicionar.');
    }

    public function add(Request $request, CampaignImportReview $review, CampaignContactTransfer $transfer): RedirectResponse
    {
        [$owner, $wizard, $state] = $this->context($request);
        $page = 1;
        try {
            $preview = $this->requirePreview($request, $owner, $wizard);
            $mode = $request->validate([
                'action' => ['nullable', 'in:add,review_page,add_reviewed,mark_all,clear_marks,keep_marks,next_page,previous_page'],
                'page' => ['nullable', 'integer', 'min:1', 'max:200'],
            ]);
            $action = (string) ($mode['action'] ?? 'add');
            $page = (int) ($mode['page'] ?? 1);
            $result = $review->review($preview['table']['rows'], $preview['mapping']);
            $byIndex = array_column($result['contacts'], null, 'index');
            $expected = $this->expected($state);
            $seen = array_fill_keys(array_values($expected), true);
            $staged = $preview['reviewed'] ?? [];
            $blocked = $this->blocked(array_column($result['contacts'], 'email'));
            $pageRows = array_slice($result['contacts'], ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
            $pageIndexes = array_fill_keys(array_column($pageRows, 'index'), true);
            $eligible = $this->availableMarks($result['contacts'], $blocked, $seen);
            $marks = array_intersect_key($preview['marks'] ?? array_fill_keys(array_keys($staged), true), $eligible);
            $draftEvidence = $preview['draft_evidence'] ?? [];

            // Selection and authorization are deliberately separate. Navigation and
            // mark-all never approve recipients or create records in the campaign.
            if (in_array($action, ['mark_all', 'clear_marks', 'keep_marks', 'next_page', 'previous_page'], true)) {
                $pageCount = max(1, (int) ceil(count($result['contacts']) / self::PAGE_SIZE));
                if ($page > $pageCount) {
                    throw ValidationException::withMessages(['selected' => 'Página desatualizada. Atualize a prévia.']);
                }
                $data = $request->validate([
                    'selected' => ['sometimes', 'array', 'max:'.self::PAGE_SIZE],
                    'selected.*' => ['required', 'integer', 'min:0', 'distinct'],
                    'evidence' => ['sometimes', 'array', 'max:'.self::PAGE_SIZE],
                    'evidence.*' => ['nullable', 'string', 'max:500'],
                ]);
                // The client may change only visible, currently eligible rows.
                $pageMarks = [];
                foreach ($data['selected'] ?? [] as $rawIndex) {
                    $index = (int) $rawIndex;
                    if (! isset($pageIndexes[$index], $eligible[$index])) {
                        throw ValidationException::withMessages(['selected' => 'Seleção desatualizada. Atualize a prévia.']);
                    }
                    $pageMarks[$index] = true;
                }
                $marks = array_replace(array_diff_key($marks, $pageIndexes), $pageMarks);
                // Unconfirmed text stays in the private preview, never in reviewed.
                foreach ($pageIndexes as $index => $unused) {
                    if (! isset($pageMarks[$index])) {
                        continue;
                    }
                    $proof = trim((string) ($data['evidence'][$index] ?? ''));
                    if (array_key_exists($index, $data['evidence'] ?? [])) {
                        $draftEvidence[$index] = $proof;
                        if (isset($staged[$index]) && $staged[$index] !== $proof) {
                            unset($staged[$index]);
                        }
                    }
                }
                if ($action === 'mark_all') {
                    $marks = $eligible;
                } elseif ($action === 'clear_marks') {
                    $marks = [];
                }
                $preview['marks'] = $marks;
                $preview['reviewed'] = $staged;
                $preview['draft_evidence'] = array_intersect_key($draftEvidence, $eligible);
                $preview['token'] = (string) Str::uuid();
                $this->storePreview($owner, $wizard, $preview);
                $target = match ($action) {
                    'next_page' => min($pageCount, $page + 1),
                    'previous_page' => max(1, $page - 1),
                    default => $page,
                };

                return $this->back($wizard, $target)->with('import_notice', count($marks).' marcado(s) em todo o arquivo. Nenhuma autorização ou envio foi efetuado nesta ação.');
            }

            $request->validate(['permission_confirmed' => ['required', 'accepted']]);
            if ($action === 'add_reviewed') {
                // Only individual reviews stored by the authenticated server are used.
                // Client-provided bulk indexes or references cannot approve new records.
                $evidence = array_intersect_key($this->availableReviewed($result['contacts'], $staged, $blocked, $seen), $marks);
                $indexes = array_keys($evidence);
                if ($indexes === []) {
                    throw ValidationException::withMessages(['selected' => 'Nenhum contato revisado disponível para adicionar.']);
                }
            } else {
                $data = $request->validate([
                    'selected' => [$action === 'review_page' ? 'sometimes' : 'required', 'array', 'max:'.self::PAGE_SIZE],
                    'selected.*' => ['required', 'integer', 'min:0', 'distinct'],
                    'evidence' => ['sometimes', 'array', 'max:'.self::PAGE_SIZE],
                    'evidence.*' => ['nullable', 'string', 'max:500'],
                ]);
                $indexes = $data['selected'] ?? [];
                $evidence = $data['evidence'] ?? [];
                if ($action === 'add' && $indexes === []) {
                    throw ValidationException::withMessages(['selected' => 'Marque pelo menos um contato.']);
                }
            }

            $selected = [];
            foreach ($indexes as $rawIndex) {
                $index = (int) $rawIndex;
                $contact = $byIndex[$index] ?? null;
                $proof = trim((string) ($evidence[$index] ?? ''));
                if ($contact === null || isset($seen[$contact['email']]) || ($action === 'review_page' && ! isset($pageIndexes[$index]))) {
                    throw ValidationException::withMessages(['selected' => 'Seleção desatualizada ou endereço já selecionado. Atualize a prévia.']);
                }
                if (isset($blocked[$contact['email']])) {
                    throw ValidationException::withMessages(['selected' => 'Há endereços descadastrados ou bloqueados. Atualize a lista; nada foi adicionado.']);
                }
                if (mb_strlen($proof) < 8 || mb_strlen($proof) > 500) {
                    throw ValidationException::withMessages(['evidence' => 'Informe a referência individual de permissão comprovada de cada contato marcado (8–500 caracteres). A origem do arquivo, sozinha, não comprova permissão.']);
                }
                $seen[$contact['email']] = true;
                $selected[] = ['contact' => $contact, 'proof' => $proof];
            }

            if ($action === 'review_page') {
                // Save only this page; unchecked rows lose their staged review.
                $marks = array_diff_key($marks, $pageIndexes);
                foreach ($pageIndexes as $index => $unused) {
                    unset($staged[$index], $draftEvidence[$index]);
                }
                foreach ($selected as $item) {
                    $index = $item['contact']['index'];
                    $staged[$index] = $item['proof'];
                    $marks[$index] = true;
                }
                $preview['reviewed'] = $staged;
                $preview['marks'] = $marks;
                $preview['draft_evidence'] = $draftEvidence;
                $preview['token'] = (string) Str::uuid();
                $this->storePreview($owner, $wizard, $preview);

                return $this->back($wizard, $page)->with('import_notice', count($selected).' revisão(ões) guardada(s) nesta página. Nenhum contato foi adicionado ou enviado.');
            }
            if (count($expected) + count($selected) > $this->cap()) {
                throw ValidationException::withMessages(['selected' => 'A seleção ultrapassa o limite atual da campanha. Revise os contatos; nenhuma cota foi ampliada.']);
            }
            $newExpected = DB::transaction(function () use ($owner, $wizard, $preview, $selected, $expected, $transfer): array {
                $emails = array_map(static fn (array $item): string => $item['contact']['email'], $selected);
                if ($this->blocked($emails) !== []) {
                    throw ValidationException::withMessages(['selected' => 'Há endereços descadastrados ou bloqueados. Atualize a lista; nada foi adicionado.']);
                }
                foreach ($selected as $item) {
                    $contact = $item['contact'];
                    $existing = DB::table('email_campaign_import_contacts')->where('owner_id', $owner)
                        ->where('wizard_id', $wizard)->where('email', $contact['email'])->first();
                    if ($existing !== null) {
                        // Previously reviewed data are immutable; do not silently overwrite.
                        $id = (string) $existing->id;
                    } else {
                        $id = (string) Str::uuid();
                        DB::table('email_campaign_import_contacts')->insert([
                            'id' => $id, 'owner_id' => $owner, 'wizard_id' => $wizard,
                            'email' => $contact['email'], 'person_name' => $contact['name'],
                            'company_name' => $contact['company'], 'job_title' => $contact['job'],
                            'email_type' => $contact['type'], 'source_label' => $preview['source'],
                            'file_label' => $preview['file'], 'permission_reference' => Crypt::encryptString($item['proof']),
                            'reviewed_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                        ]);
                    }
                    $expected['import:'.$id] = $contact['email'];
                }
                // Same deduplication, exact-address and suppression guard as existing wizard.
                $transfer->guard(array_keys($expected), $expected);

                return $expected;
            });
            $state['expected'] = $newExpected;
            $request->session()->put('campaigns_wizard_v93_'.$wizard, $state);
        } catch (ValidationException $exception) {
            return $this->back($wizard, $page)->withErrors($exception->errors());
        }

        return $this->back($wizard, $page)->with('import_notice', count($selected).' contato(s) revisado(s) adicionado(s) à seleção. Nenhum e-mail enviado.');
    }

    public function discard(Request $request): RedirectResponse
    {
        [$owner, $wizard] = $this->context($request);
        Cache::forget($this->cacheKey($owner, $wizard));

        return $this->back($wizard)->with('import_notice', 'Prévia descartada. Os contatos já revisados na seleção permanecem.');
    }

    /** @return array{0:int,1:string,2:array<string,mixed>} */
    private function context(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
        $wizard = $request->input('wizard_id', $request->query('wizard_id'));
        abort_unless(is_string($wizard) && Str::isUuid($wizard), 422);
        $wizard = strtolower($wizard);
        $state = $request->session()->get('campaigns_wizard_v93_'.$wizard);
        abort_unless(is_array($state), 404, 'Abra uma nova campanha antes de importar.');

        /** @var array<string,mixed> $state */
        return [(int) $user->id, $wizard, $state];
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,string>
     */
    private function expected(array $state): array
    {
        $raw = $state['expected'] ?? [];
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $email) {
                if (is_string($key) && is_string($email)) {
                    $out[$key] = $email;
                }
            }
        }

        return $out;
    }

    private function cap(): int
    {
        return max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
    }

    /**
     * @param  list<string>  $emails
     * @return array<string,true>
     */
    private function blocked(array $emails): array
    {
        $result = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            foreach (EmailCampaignSuppression::query()->whereIn('normalized_email', $chunk)->pluck('normalized_email') as $address) {
                $result[(string) $address] = true;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{index:int,email:string,name:string,company:string,job:string,type:string}>  $contacts
     * @param  array<int,string>  $reviewed
     * @param  array<string,true>  $blocked
     * @param  array<string,true>  $chosen
     * @return array<int,string>
     */
    private function availableReviewed(array $contacts, array $reviewed, array $blocked, array $chosen): array
    {
        $available = [];
        foreach ($contacts as $contact) {
            $index = $contact['index'];
            if (isset($reviewed[$index]) && ! isset($blocked[$contact['email']]) && ! isset($chosen[$contact['email']])) {
                $available[$index] = $reviewed[$index];
            }
        }

        return $available;
    }

    /**
     * @param  list<array{index:int,email:string,name:string,company:string,job:string,type:string}>  $contacts
     * @param  array<string,true>  $blocked
     * @param  array<string,true>  $chosen
     * @return array<int,true>
     */
    private function availableMarks(array $contacts, array $blocked, array $chosen): array
    {
        $available = [];
        foreach ($contacts as $contact) {
            if (! isset($blocked[$contact['email']]) && ! isset($chosen[$contact['email']])) {
                $available[$contact['index']] = true;
            }
        }

        return $available;
    }

    private function cacheKey(int $owner, string $wizard): string
    {
        return 'campaign-import-v27:'.$owner.':'.$wizard;
    }

    /** @param array{token:string,source:string,file:string,table:array{columns:list<string>,rows:list<list<string>>,kind:string},mapping:array<string,int>,reviewed?:array<int,string>,marks?:array<int,true>,draft_evidence?:array<int,string>} $preview */
    private function storePreview(int $owner, string $wizard, array $preview): void
    {
        Cache::put($this->cacheKey($owner, $wizard), Crypt::encryptString(json_encode($preview, JSON_THROW_ON_ERROR)), now()->addMinutes(self::TTL_MINUTES));
    }

    /** @return array{token:string,source:string,file:string,table:array{columns:list<string>,rows:list<list<string>>,kind:string},mapping:array<string,int>,reviewed?:array<int,string>,marks?:array<int,true>,draft_evidence?:array<int,string>}|null */
    private function preview(int $owner, string $wizard): ?array
    {
        $raw = Cache::get($this->cacheKey($owner, $wizard));
        if (! is_string($raw)) {
            return null;
        }
        try {
            // Only encrypted data written by storePreview are accepted.
            /** @var array{token:string,source:string,file:string,table:array{columns:list<string>,rows:list<list<string>>,kind:string},mapping:array<string,int>,reviewed?:array<int,string>,marks?:array<int,true>,draft_evidence?:array<int,string>} $preview */
            $preview = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);

            return $preview;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{token:string,source:string,file:string,table:array{columns:list<string>,rows:list<list<string>>,kind:string},mapping:array<string,int>,reviewed?:array<int,string>,marks?:array<int,true>,draft_evidence?:array<int,string>} */
    private function requirePreview(Request $request, int $owner, string $wizard): array
    {
        $preview = $this->preview($owner, $wizard);
        $token = $request->input('preview_token');
        if ($preview === null || ! is_string($token) || ! hash_equals($preview['token'], $token)) {
            throw ValidationException::withMessages(['import_file' => 'A prévia expirou ou mudou. Reabra a importação.']);
        }

        return $preview;
    }

    private function back(string $wizard, int $page = 1): RedirectResponse
    {
        return redirect()->route('campaigns.import-v27.show', ['wizard_id' => $wizard, 'pagina' => $page]);
    }
}
