<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Confirmação explícita via HTTP. Preserva a trilha de auditoria existente. */
final class WhatsappVerificationController extends Controller
{
    public function store(Request $request, int $phone, WhatsappPhoneRegistry $registry): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $data = $request->validate([
            'status' => ['required', 'in:yes,no,unknown'],
            'confirmed' => ['required', 'accepted'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $status = $data['status'];

        $note = trim((string) ($data['note'] ?? ''));
        if ($status === 'no' && mb_strlen($note) < 10) {
            throw ValidationException::withMessages([
                'note' => 'Descreva a conferência manual com pelo menos 10 caracteres.',
            ]);
        }

        $registry->record($actor, $phone, $status, $note);
        $digits = (string) DB::table('whatsapp_phone_numbers')->where('id', $phone)->value('digits');

        $message = match ($status) {
            'yes' => 'Confirmação manual salva: possui WhatsApp. Contatos vinculados atualizados.',
            'no' => 'Confirmação manual salva: não possui WhatsApp. Contatos vinculados atualizados.',
            default => 'Confirmação desfeita. Número voltou a não verificado.',
        };

        return redirect()->route('contacts.whatsapp', ['busca' => $digits])
            ->with('wa_registry_success', $message);
    }
}
