<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CommercialRoleService
{
    public function changeRole(
        User $actor,
        User $target,
        string $role,
    ): User {
        if (
            ! $actor
                ->isCommercialManager()
        ) {
            throw new DomainException(
                'Somente gestores podem alterar perfis comerciais.'
            );
        }

        if (
            ! in_array(
                $role,
                [
                    User::ROLE_MANAGER,
                    User::ROLE_SELLER,
                ],
                true
            )
        ) {
            throw new DomainException(
                'Perfil comercial inválido.'
            );
        }

        if (
            $target->email_verified_at
            === null
        ) {
            throw new DomainException(
                'Somente usuários verificados podem receber perfil comercial.'
            );
        }

        return DB::transaction(
            function () use (
                $actor,
                $target,
                $role,
            ): User {
                $lockedTarget =
                    User::query()
                        ->whereKey(
                            $target->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $lockedTarget
                        ->commercial_role
                    === $role
                ) {
                    return $lockedTarget;
                }

                if (
                    $lockedTarget
                        ->isCommercialManager()
                    && $role
                        === User::ROLE_SELLER
                ) {
                    /*
                     * Evita que o gestor logado
                     * remova o próprio acesso
                     * no meio da sessão.
                     */
                    if (
                        $actor->id
                        === $lockedTarget->id
                    ) {
                        throw new DomainException(
                            'Você não pode remover seu próprio perfil de gestor.'
                        );
                    }

                    $managerCount =
                        User::query()
                            ->where(
                                'commercial_role',
                                User::ROLE_MANAGER
                            )
                            ->lockForUpdate()
                            ->count();

                    if ($managerCount <= 1) {
                        throw new DomainException(
                            'O sistema precisa manter pelo menos um gestor.'
                        );
                    }
                }

                $lockedTarget
                    ->forceFill([
                        'commercial_role' => $role,
                    ])
                    ->save();

                return $lockedTarget
                    ->refresh();
            }
        );
    }
}
