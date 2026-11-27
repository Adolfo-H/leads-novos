<?php

namespace App\Services\Proposals;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ProposalAttachmentAcceptanceV29
{
    public const EVENT = 'crm_attachment_acceptance_v29';

    public const STAGES = ['contractsent' => 'Aceite da proposta', '14249606' => 'Contrato assinado', '14249607' => 'Início do teste', 'closedwon' => 'Negócio fechado'];

    public static function verified(int $id): bool
    {
        return DB::table('ec_proposal_events')->where('proposal_id', $id)->where('event', self::EVENT)->exists();
    }

    /** @return array<string, mixed>|null */
    public static function evidence(int $id): ?array
    {
        $value = DB::table('ec_proposal_events')->where('proposal_id', $id)->where('event', self::EVENT)->latest('id')->value('metadata');
        $data = is_string($value) ? json_decode($value, true) : null;

        return is_array($data) ? $data : null;
    }

    /** @return array<string, mixed>|null */
    public static function report(): ?array
    {
        $disk = Storage::disk('local');
        $path = 'proposals/attachment-audit-v29/latest.json';
        if (! $disk->exists($path)) {
            return null;
        }
        $value = json_decode((string) $disk->get($path), true);

        return is_array($value) && ($value['version'] ?? null) === 29 ? $value : null;
    }

    /** @return array{documents:list<array<string,mixed>>,invalid:list<array<string,mixed>>} */
    public function inventory(): array
    {
        $documents = [];
        $invalid = [];
        $rows = DB::table('ec_proposal_documents as d')->join('ec_proposals as p', 'p.id', '=', 'd.proposal_id')
            ->whereIn('p.status', ['imported', 'issued', 'sent', 'accepted', 'partially_accepted'])
            ->whereIn('d.kind', ['historical_pdf', 'historical_original', 'proposal_pdf', 'consolidated_pdf'])
            ->orderBy('d.id')->get(['d.*', 'p.origin', 'p.status']);
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! $this->originalValid((string) $row->storage_path, (string) $row->sha256)) {
                $invalid[] = ['document_id' => $id, 'proposal_id' => (int) $row->proposal_id, 'reason' => 'Arquivo local ausente ou SHA-256 divergente'];

                continue;
            }
            $documents[] = ['document_id' => $id, 'proposal_id' => (int) $row->proposal_id, 'sha256' => (string) $row->sha256, 'kind' => (string) $row->kind, 'service' => $row->service, 'original_name' => (string) $row->original_name];
        }

        return compact('documents', 'invalid');
    }

    private function originalValid(string $path, string $sha): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
            return false;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return false;
        }
        $file = $disk->path($path);
        $root = realpath($disk->path(''));
        $resolved = realpath($file);

        return $root !== false && $resolved !== false && str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) && is_file($resolved) && hash_file('sha256', $resolved) === $sha;
    }

    /**
     * The report is produced locally by the authenticated read-only scanner, never by a browser request.
     * No status=accepted, accepted_at, financial fields or CRM associations are overwritten.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public function process(array $report, bool $apply): array
    {
        if (($report['version'] ?? null) !== 29 || ! is_array($report['matches'] ?? null) || ! is_array($report['deals'] ?? null)) {
            throw new RuntimeException('Relatório V29 inválido.');
        }
        $eligible = [];
        foreach ($report['deals'] as $deal) {
            if (is_array($deal) && isset(self::STAGES[(string) ($deal['stage'] ?? '')])) {
                $eligible[(string) $deal['id']] = (string) $deal['stage'];
            }
        }
        $groups = [];
        foreach ($report['matches'] as $m) {
            if (! is_array($m) || ! isset($m['proposal_id'], $m['document_id'], $m['deal_id'], $m['stage'], $m['sha256'], $m['file_id'], $m['activity_type'], $m['activity_id'])) {
                throw new RuntimeException('Evidência incompleta.');
            }
            if (($eligible[(string) $m['deal_id']] ?? null) !== $m['stage'] || preg_match('/^[a-f0-9]{64}$/D', (string) $m['sha256']) !== 1 || ! in_array($m['activity_type'], ['notes', 'emails', 'calls', 'meetings', 'tasks'], true)) {
                throw new RuntimeException('Etapa, hash ou atividade inválida.');
            }
            $groups[(int) $m['proposal_id']][] = $m;
        }

        return DB::transaction(function () use ($groups, $report, $apply): array {
            $confirmed = [];
            $pending = [];
            $new = 0;
            $covered = [];
            foreach ($groups as $id => $matches) {
                $pQuery = DB::table('ec_proposals')->where('id', $id);
                $p = ($apply ? $pQuery->lockForUpdate() : $pQuery)->first();
                if ($p === null || ! in_array($p->status, ['imported', 'issued', 'sent', 'accepted', 'partially_accepted'], true)) {
                    $pending[] = ['proposal_id' => $id, 'reason' => 'Proposta não emitida, removida ou recusada'];

                    continue;
                }
                $docsQuery = DB::table('ec_proposal_documents')->where('proposal_id', $id)->whereIn('kind', ['historical_pdf', 'historical_original', 'proposal_pdf', 'consolidated_pdf']);
                $docs = ($apply ? $docsQuery->lockForUpdate() : $docsQuery)->get()->keyBy('id');
                $verified = [];
                $matchedServices = [];
                $matchedOriginals = [];
                $consolidated = false;
                foreach ($matches as $m) {
                    $doc = $docs->get((int) $m['document_id']);
                    if ($doc === null || $doc->sha256 !== $m['sha256'] || ! $this->originalValid((string) $doc->storage_path, (string) $doc->sha256)) {
                        throw new RuntimeException('Documento local mudou durante a conferência: '.$id);
                    }
                    // Preserve only the facts needed to identify the attachment, not signed URLs or tokens.
                    $verified[$doc->id.':'.$m['deal_id'].':'.$m['file_id']] = ['document_id' => (int) $doc->id, 'sha256' => (string) $doc->sha256, 'service' => $doc->service, 'kind' => (string) $doc->kind, 'original_name' => (string) $doc->original_name, 'deal_id' => (string) $m['deal_id'], 'deal_name' => (string) ($m['deal_name'] ?? ''), 'stage' => (string) $m['stage'], 'file_id' => (string) $m['file_id'], 'file_name' => (string) ($m['file_name'] ?? ''), 'activity_type' => (string) $m['activity_type'], 'activity_id' => (string) $m['activity_id']];
                    if (in_array($doc->kind, ['historical_pdf', 'historical_original'], true)) {
                        $matchedOriginals[(string) $doc->sha256] = true;
                    } elseif ($doc->kind === 'proposal_pdf' && is_string($doc->service)) {
                        $matchedServices[$doc->service] = true;
                    } elseif ($doc->kind === 'consolidated_pdf') {
                        $consolidated = true;
                    }
                }
                $full = false;
                if ($p->origin === 'imported') {
                    $originals = $docs->filter(static fn ($d): bool => in_array($d->kind, ['historical_pdf', 'historical_original'], true))->pluck('sha256')->unique()->all();
                    $full = $originals !== [] && array_diff($originals, array_keys($matchedOriginals)) === [];
                } else {
                    $codes = json_decode((string) $p->service_codes, true);
                    $full = $consolidated || (is_array($codes) && $codes !== [] && array_diff($codes, array_keys($matchedServices)) === []);
                }
                if (! $full) {
                    $pending[] = ['proposal_id' => $id, 'reason' => 'Correspondência parcial; não confirma todos os documentos/serviços', 'attachments' => array_values($verified)];

                    continue;
                }
                $previous = self::verified($id);
                $record = ['source' => 'hubspot_attachment_sha256', 'checked_at' => (string) ($report['checked_at'] ?? ''), 'attachments' => array_values($verified)];
                $confirmed[] = ['proposal_id' => $id, 'client_name' => (string) $p->client_name, 'already_verified' => $previous, 'attachments' => array_values($verified)];
                foreach ($verified as $v) {
                    $covered[$v['deal_id']] = true;
                }
                if (! $previous) {
                    $new++;
                }
                if (! $apply || $previous) {
                    continue;
                }
                // This event is independent of the formal workflow; the existing historical flags are retained.
                DB::table('ec_proposal_events')->insert(['proposal_id' => $id, 'event' => self::EVENT, 'metadata' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'actor_id' => null, 'created_at' => now('UTC')]);
                if ($p->origin === 'imported') {
                    $meta = DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->lockForUpdate()->first();
                    if ($meta !== null) {
                        $e = json_decode((string) $meta->evidence, true);
                        if (is_array($e)) {
                            $e['crm_attachment_v29'] = $record;
                            DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->update(['commercial_status' => 'accepted_crm', 'evidence' => json_encode($e, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
                        }
                    }
                }
            }
            $prior = DB::table('ec_proposal_history_metadata as h')->join('ec_proposals as p', 'p.id', '=', 'h.proposal_id')->where('h.commercial_status', 'accepted_crm')->where('p.status', '<>', 'accepted')->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('ec_proposal_events as e')->whereColumn('e.proposal_id', 'h.proposal_id')->where('e.event', self::EVENT);
            })->get(['h.proposal_id', 'h.title', 'h.crm_match'])->all();

            return ['mode' => $apply ? 'apply' : 'preview', 'new' => $new, 'confirmed' => $confirmed, 'pending' => $pending, 'covered_deal_ids' => array_map('strval', array_keys($covered)), 'prior_unverified' => $prior];
        }, 3);
    }
}
