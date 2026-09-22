<?php

namespace App\Services;

use App\Models\CompanyCrmCheck;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class LeadOperationalClassificationService
{
    /**
     * Situação comercial:
     *
     * new
     * known
     * client
     * reprospecting
     */
    public function commercialStatus(
        ?CompanyCrmCheck $crm
    ): string {
        if (
            $crm === null
            || $crm->status === 'not_found'
        ) {
            return 'new';
        }

        if ($crm->status === 'client') {
            return 'client';
        }

        /*
         * Se existe tarefa aberta, ainda existe
         * acompanhamento comercial ativo.
         */
        if (
            $this->openTasks(
                $crm
            ) !== []
        ) {
            return 'known';
        }

        $lastActivityAt =
            $this->lastCommercialActivityAt(
                $crm
            );

        if (
            $lastActivityAt !== null
            && $lastActivityAt->lessThan(
                now()->toImmutable()
                    ->subDays(
                        $this->reprospectingDays()
                    )
            )
        ) {
            return 'reprospecting';
        }

        return 'known';
    }

    /**
     * Acompanhamento operacional:
     *
     * waiting
     * new
     * contacting
     * future
     * reprospecting
     */
    public function workStatus(
        ?CompanyCrmCheck $crm,
        mixed $openTasks = null,
    ): string {
        $tasks =
            $this->normalizeTasks(
                $openTasks
                    ?? $this->openTasks(
                        $crm
                    )
            );

        /*
         * Tarefa pendente sempre prevalece.
         */
        if ($tasks !== []) {
            return 'waiting';
        }

        if ($crm === null) {
            return 'new';
        }

        $contactedCount =
            max(
                0,
                (int) (
                    $crm->contacted_count
                    ?? 0
                )
            );

        $lastContactedAt =
            $this->parseDate(
                $crm->getAttribute(
                    'last_contacted_at'
                )
            );

        /*
         * Nunca houve chamada/contato.
         */
        if (
            $contactedCount === 0
            && $lastContactedAt === null
        ) {
            return 'new';
        }

        /*
         * Sabemos que houve contato,
         * mas o HubSpot não trouxe uma data.
         *
         * Não tratamos como "Novo".
         */
        if ($lastContactedAt === null) {
            return 'contacting';
        }

        $now =
            now()->toImmutable();

        /*
         * Mais de 90 dias sem contato.
         */
        if (
            $lastContactedAt->lessThan(
                $now->subDays(
                    $this->reprospectingDays()
                )
            )
        ) {
            return 'reprospecting';
        }

        /*
         * Mais de 30 dias.
         */
        if (
            $lastContactedAt->lessThan(
                $now->subDays(
                    $this->contactingDays()
                )
            )
        ) {
            return 'future';
        }

        /*
         * Contato nos últimos 30 dias.
         */
        return 'contacting';
    }

    public function lastCommercialActivityAt(
        ?CompanyCrmCheck $crm
    ): ?CarbonImmutable {
        if ($crm === null) {
            return null;
        }

        $latest =
            $this->parseDate(
                $crm->getAttribute(
                    'last_contacted_at'
                )
            );

        $rawMetadata =
            $crm->getAttribute(
                'metadata'
            );

        $metadata =
            is_array(
                $rawMetadata
            )
                ? $rawMetadata
                : [];

        /*
         * Atividade registrada nos registros
         * Company do HubSpot.
         */
        $records =
            data_get(
                $metadata,
                'hubspot_company_records',
                []
            );

        if (is_array($records)) {
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $candidate =
                    $this->parseDate(
                        $record[
                            'last_activity_at'
                        ]
                        ?? null
                    );

                $latest =
                    $this->latest(
                        $latest,
                        $candidate
                    );
            }
        }

        /*
         * Fechamento de negócio também conta
         * como atividade comercial.
         */
        $deals =
            data_get(
                $metadata,
                'deals',
                []
            );

        if (is_array($deals)) {
            foreach ($deals as $deal) {
                if (! is_array($deal)) {
                    continue;
                }

                $candidate =
                    $this->parseDate(
                        $deal[
                            'closed_at'
                        ]
                        ?? null
                    );

                $latest =
                    $this->latest(
                        $latest,
                        $candidate
                    );
            }
        }

        return $latest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openTasks(
        ?CompanyCrmCheck $crm
    ): array {
        if ($crm === null) {
            return [];
        }

        return $this->normalizeTasks(
            data_get(
                $crm->metadata ?? [],
                'open_tasks',
                []
            )
        );
    }

    private function contactingDays(): int
    {
        return max(
            1,
            (int) config(
                'prospector.crm.contacting_after_days',
                30
            )
        );
    }

    private function reprospectingDays(): int
    {
        return max(
            1,
            (int) config(
                'prospector.crm.reprospecting_after_days',
                90
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeTasks(
        mixed $tasks
    ): array {
        if (! is_array($tasks)) {
            return [];
        }

        $result = [];

        foreach ($tasks as $task) {
            if (is_array($task)) {
                $result[] =
                    $task;
            }
        }

        return $result;
    }

    private function latest(
        ?CarbonImmutable $current,
        ?CarbonImmutable $candidate,
    ): ?CarbonImmutable {
        if ($candidate === null) {
            return $current;
        }

        if (
            $current === null
            || $candidate->greaterThan(
                $current
            )
        ) {
            return $candidate;
        }

        return $current;
    }

    private function parseDate(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value instanceof DateTimeInterface
        ) {
            return CarbonImmutable::instance(
                $value
            );
        }

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            );
        } catch (Throwable) {
            return null;
        }
    }
}
