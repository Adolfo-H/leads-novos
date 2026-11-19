<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Contacts\WhatsappManualPhone;
use App\Services\Contacts\WhatsappWebControlPanel;
use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Acoes explicitas do gestor via HTTP, quando Livewire estiver indisponivel. */
final class WhatsappOperationsHttpController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $mode = (string) $request->input('mode', '');
        $section = in_array($mode, ['specific', 'review:corrected', 'review:invalid'], true)
            ? '#wa16-review' : '#wa12-operation';
        if ($mode === 'specific') {
            $section = '#wa16-manual-queue';
        }
        $location = route('contacts.whatsapp', ['aba' => 'operation']).$section;

        try {
            if ($mode === 'both' || in_array($mode, ['single:1', 'single:2'], true)) {
                $fields = $request->validate([
                    'scope' => ['required', 'in:all,mobile,landline'],
                    'limit' => ['required', 'integer', 'in:3,5,10'],
                ]);
                $scope = (string) $fields['scope'];
                $limit = (int) $fields['limit'];
                if ($mode === 'both') {
                    $batches = app(WhatsappWebDailyCoordinator::class)->startBoth($actor, $limit, $scope);
                    $message = count($batches).' lote(s) solicitado(s). Confira o andamento das contas abaixo.';
                } else {
                    $session = (int) substr($mode, -1);
                    app(WhatsappWebControlPanel::class)->request($actor, $session, $limit, $scope);
                    $message = 'Lote solicitado para o WhatsApp '.$session.'. Confira o andamento abaixo.';
                }
            } elseif ($mode === 'cancel') {
                $fields = $request->validate(['batch_id' => ['required', 'integer', 'min:1']]);
                app(WhatsappWebControlPanel::class)->cancel($actor, (int) $fields['batch_id']);
                $message = 'Interrupcao solicitada. O numero em processamento pode concluir antes do encerramento.';
            } elseif ($mode === 'specific') {
                $fields = $request->validate(['numbers' => ['required', 'string', 'max:450']]);
                $result = app(WhatsappManualPhone::class)->queue($actor, (string) $fields['numbers']);
                $message = $result['queued'].' numero(s) enviado(s) para as contas conectadas; '.$result['skipped'].' ignorado(s).';
            } elseif (in_array($mode, ['review:corrected', 'review:invalid'], true)) {
                $fields = $request->validate([
                    'phone_id' => ['required', 'integer', 'min:1'],
                    'corrected_phone' => ['nullable', 'string', 'max:40'],
                    'review_note' => ['nullable', 'string', 'max:300'],
                    'review_confirm' => ['accepted'],
                    'review_search' => ['nullable', 'string', 'max:35'],
                ]);
                $id = (int) $fields['phone_id'];
                $already = DB::table('whatsapp_phone_format_reviews_v16')
                    ->where('original_phone_id', $id)->exists();
                if ($already) {
                    throw ValidationException::withMessages([
                        'wa16_format' => 'Este formato ja foi revisado. Atualize a pagina antes de continuar.',
                    ]);
                }
                $decision = $mode === 'review:corrected' ? 'corrected' : 'invalid';
                app(WhatsappManualPhone::class)->review(
                    $actor,
                    $id,
                    $decision,
                    (string) ($fields['corrected_phone'] ?? ''),
                    (string) ($fields['review_note'] ?? ''),
                );
                $message = $decision === 'corrected'
                    ? 'Correcao registrada no indice local. Registro original preservado.'
                    : 'Formato invalido registrado. Nao sera consultado automaticamente.';
            } else {
                abort(422, 'Acao de WhatsApp desconhecida.');
            }
        } catch (ValidationException $error) {
            $query = ['aba' => 'operation'];
            if (in_array($mode, ['review:corrected', 'review:invalid'], true)) {
                $id = (string) $request->input('phone_id', '');
                if (preg_match('/^[1-9][0-9]{0,10}$/D', $id) === 1) {
                    $query['revisarNumero'] = $id;
                }
            }

            return redirect()->to(route('contacts.whatsapp', $query).$section)
                ->withErrors($error->errors())->withInput();
        }

        return redirect()->to($location)->with('wa_operations_success', $message);
    }
}
