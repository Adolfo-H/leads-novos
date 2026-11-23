<?php

namespace App\Services\Campaigns;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single source for displayed reply/notice totals and a chronological feed.
 * Human replies and useful notices remain different records and statuses.
 * Never modifies mail, recipients, the CRM or follow-up settings.
 */
final class CampaignResponseFeed
{
    /** @return array{total:int,human:int,useful:int} */
    public function globalTotals(): array
    {
        $human = DB::table('email_campaign_replies')->count();
        $useful = $this->noticeTableExists() ? $this->usefulNotices()->count() : 0;

        return $this->result((int) $human, (int) $useful);
    }

    /** @return array{total:int,human:int,useful:int} */
    public function campaignTotals(int $campaignId): array
    {
        $results = $this->totalsForCampaigns([$campaignId]);

        return $results[$campaignId] ?? $this->result(0, 0);
    }

    /**
     * Groups counters by campaign ID, in two bounded SQL queries (no per-row N+1).
     *
     * @param  list<int>  $campaignIds
     * @return array<int, array{total:int,human:int,useful:int}>
     */
    public function totalsForCampaigns(array $campaignIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (int $id): int => $id, $campaignIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        // Build the two count maps independently, then assemble every row with
        // the COMPLETE and stable total/human/useful shape. Incremental writes
        // like $totals[$id]['human'] cause PHPStan to infer optional keys.
        /** @var array<int, int> $humanByCampaign */
        $humanByCampaign = [];
        $humanCounts = DB::table('email_campaign_replies')
            ->whereIn('email_campaign_id', $ids)
            ->select('email_campaign_id')->selectRaw('COUNT(*) AS total')
            ->groupBy('email_campaign_id')->get();
        foreach ($humanCounts as $row) {
            $humanByCampaign[(int) $row->email_campaign_id] = (int) $row->total;
        }

        /** @var array<int, int> $usefulByCampaign */
        $usefulByCampaign = [];
        if ($this->noticeTableExists()) {
            $usefulCounts = $this->usefulNotices()
                ->whereIn('email_campaign_id', $ids)
                ->select('email_campaign_id')->selectRaw('COUNT(*) AS total')
                ->groupBy('email_campaign_id')->get();
            foreach ($usefulCounts as $row) {
                $usefulByCampaign[(int) $row->email_campaign_id] = (int) $row->total;
            }
        }

        /** @var array<int, array{total:int,human:int,useful:int}> $totals */
        $totals = [];
        foreach ($ids as $id) {
            $totals[$id] = $this->result(
                $humanByCampaign[$id] ?? 0,
                $usefulByCampaign[$id] ?? 0,
            );
        }

        return $totals;
    }

    /**
     * Paginated, sorted feed. This is a UNION ALL, not two separately sorted
     * lists: each page contains the true newest messages across both types.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(?int $campaignId, string $search = '', int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $page = max(1, min($page, 100000));
        $perPage = max(1, min($perPage, 50));
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100));

        $human = DB::table('email_campaign_replies')
            ->selectRaw("'human' AS entry_kind, id AS row_id, email_campaign_id, from_email, subject, received_at, NULL AS notice_type, NULL AS link_method, NULL AS requires_review")
            ->when($campaignId !== null, static fn (Builder $q) => $q->where('email_campaign_id', $campaignId))
            ->when($term !== '', static function (Builder $q) use ($term): void {
                $q->where(static function (Builder $where) use ($term): void {
                    $where->where('from_email', 'like', '%'.$term.'%')
                        ->orWhere('subject', 'like', '%'.$term.'%');
                });
            });

        if ($this->noticeTableExists()) {
            $notices = $this->usefulNotices()
                ->selectRaw("'notice' AS entry_kind, id AS row_id, email_campaign_id, from_email, subject, received_at, notice_type, link_method, requires_review")
                ->when($campaignId !== null, static fn (Builder $q) => $q->where('email_campaign_id', $campaignId))
                ->when($term !== '', static function (Builder $q) use ($term): void {
                    $q->where(static function (Builder $where) use ($term): void {
                        $where->where('from_email', 'like', '%'.$term.'%')
                            ->orWhere('subject', 'like', '%'.$term.'%');
                    });
                });
            $human->unionAll($notices);
        }

        return DB::query()->fromSub($human, 'campaign_responses')
            ->orderByDesc('received_at')
            ->orderByDesc('row_id')
            ->orderBy('entry_kind')
            ->paginate($perPage, ['*'], 'paginaRespostas', $page);
    }

    /**
     * Exclude the same IMAP message if it somehow exists in both sources.
     * V2.2's filter already removes promoted notices; this also protects
     * legacy duplicate rows without mutating them.
     */
    private function usefulNotices(): Builder
    {
        return CampaignInboundNoticeFilter::query()
            ->whereNotExists(static function (Builder $q): void {
                $q->selectRaw('1')
                    ->from('email_campaign_replies as confirmed_reply')
                    ->whereColumn('confirmed_reply.mailbox', 'email_campaign_inbound_notices.mailbox')
                    ->whereColumn('confirmed_reply.uid_validity', 'email_campaign_inbound_notices.uid_validity')
                    ->whereColumn('confirmed_reply.imap_uid', 'email_campaign_inbound_notices.imap_uid');
            });
    }

    private function noticeTableExists(): bool
    {
        return Schema::hasTable('email_campaign_inbound_notices');
    }

    /** @return array{total:int,human:int,useful:int} */
    private function result(int $human, int $useful): array
    {
        return ['total' => $human + $useful, 'human' => $human, 'useful' => $useful];
    }
}
