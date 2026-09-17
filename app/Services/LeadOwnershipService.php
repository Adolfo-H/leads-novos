<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLeadWorkState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LeadOwnershipService
{
    public function __construct(
        private readonly LeadActivityService $activities,
    ) {}

    public function assign(
        Company $company,
        ?User $owner,
    ): CompanyLeadWorkState {
        return DB::transaction(
            function () use (
                $company,
                $owner,
            ): CompanyLeadWorkState {
                $state =
                    CompanyLeadWorkState::query()
                        ->where(
                            'company_id',
                            $company->id
                        )
                        ->lockForUpdate()
                        ->first();

                if ($state === null) {
                    $state =
                        CompanyLeadWorkState::query()
                            ->create([
                                'company_id' => $company->id,

                                'status' => 'new',
                            ]);
                }

                $previousOwnerId =
                    is_numeric(
                        $state->assigned_user_id
                    )
                        ? (int) $state
                            ->assigned_user_id
                        : null;

                $nextOwnerId =
                    $owner?->id;

                /*
                 * Se já estiver com o mesmo
                 * responsável, não cria ruído
                 * no histórico.
                 */
                if (
                    $previousOwnerId
                    === $nextOwnerId
                ) {
                    return $state->load(
                        'assignedUser'
                    );
                }

                $previousOwner =
                    $previousOwnerId !== null
                        ? User::query()
                            ->find(
                                $previousOwnerId
                            )
                        : null;

                $state->forceFill([
                    'assigned_user_id' => $nextOwnerId,
                ])->save();

                $this->activities
                    ->ownerChanged(
                        companyId: $company->id,

                        fromOwner: $previousOwner,

                        toOwner: $owner,
                    );

                return $state
                    ->refresh()
                    ->load(
                        'assignedUser'
                    );
            }
        );
    }
}
