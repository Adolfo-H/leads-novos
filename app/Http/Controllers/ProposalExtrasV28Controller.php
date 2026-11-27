<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Proposals\ProposalAttachmentAcceptanceV29;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProposalExtrasV28Controller extends Controller
{
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isCommercialManager() || $user->isCommercialSeller()), 403);

        return $user;
    }

    public function modelPdf(Request $request, string $service): BinaryFileResponse
    {
        $this->user($request);
        $files = ['LICENCIAMENTO' => '10_MODELO_PROPOSTA_LICENCIAMENTO.html', 'ACOMPANHAMENTO' => '12_MODELO_PROPOSTA_ACOMPANHAMENTO.html', 'RETROATIVAS' => '14_MODELO_PROPOSTA_RETROATIVAS.html', 'DFE' => '27_MODELO_PROPOSTA_DFE.html', 'NFSE' => '28_MODELO_PROPOSTA_NFSE.html'];
        abort_unless(isset($files[$service]), 404);
        $template = DB::table('ec_proposal_templates')->where('service', $service)->where('ready_to_issue', true)->first();
        abort_unless($template !== null && $template->source_file === $files[$service], 404);
        $dir = storage_path('app/private/proposals/modelos-v28');
        $path = $dir.'/'.$service.'.pdf';
        $source = resource_path('proposals/official/'.$files[$service]);
        abort_unless(is_file($path) && is_file($dir.'/manifest.json') && is_file($source), 503, 'Modelo PDF indisponível; execute o gerador de modelos V28.');
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $entry = is_array($manifest) ? ($manifest[$service] ?? []) : [];
        abort_unless(is_array($entry) && ($entry['template'] ?? '') === $files[$service] && ($entry['source_sha256'] ?? '') === hash_file('sha256', $source) && ($entry['pdf_sha256'] ?? '') === hash_file('sha256', $path), 503, 'Modelo alterado: regenere a visualização V28.');

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="MODELO_'.$service.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    // EC_ATTACHMENT_V29: GET only reads the private report; remote scans run in the CLI.
    public function coverage(Request $request): View
    {
        abort_unless($this->user($request)->isCommercialManager(), 403);
        $report = ProposalAttachmentAcceptanceV29::report();

        return view('proposals.attachment-coverage-v29', compact('report'));
    }

    public function reconcile(Request $request): RedirectResponse
    {
        abort_unless($this->user($request)->isCommercialManager(), 403);

        return redirect()->route('proposals.crm-coverage-v28')->with('proposal_ok', 'Use a conferência V29 por anexo. A inferência por nome/serviço foi desativada.');
    }
}
