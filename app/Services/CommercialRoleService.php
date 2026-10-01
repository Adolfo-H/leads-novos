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

        return DB::transaction(
            function () use (
                $actor,
                $target,
                $role,
            ): User {
                /*
                 * Actor e target são bloqueados
                 * sempre na mesma ordem.
                 *
                 * Isso evita trabalhar com um
                 * papel comercial que tenha sido
                 * alterado enquanto a operação
                 * estava sendo iniciada.
                 */
                $ids =
                    collect([
                        (int) $actor->id,
                        (int) $target->id,
                    ])
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                $lockedUsers =
                    User::query()
                        ->whereKey(
                            $ids
                        )
                        ->orderBy(
                            'id'
                        )
                        ->lockForUpdate()
                        ->get()
                        ->keyBy(
                            'id'
                        );

                /** @var User|null $lockedActor */
                $lockedActor =
                    $lockedUsers->get(
                        $actor->id
                    );

                /** @var User|null $lockedTarget */
                $lockedTarget =
                    $lockedUsers->get(
                        $target->id
                    );

                if (
                    ! $lockedActor
                    || ! $lockedTarget
                ) {
                    throw new DomainException(
                        'Usuário comercial não encontrado.'
                    );
                }

                if (
                    ! $lockedActor
                        ->isCommercialManager()
                ) {
                    throw new DomainException(
                        'Somente gestores podem alterar perfis comerciais.'
                    );
                }

                if (
                    $lockedTarget
                        ->email_verified_at
                    === null
                ) {
                    throw new DomainException(
                        'Somente usuários verificados podem receber perfil comercial.'
                    );
                }

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
                    && $lockedActor->id
                        === $lockedTarget->id
                ) {
                    throw new DomainException(
                        'Você não pode remover seu próprio perfil de gestor.'
                    );
                }

                /*
                 * Se o alvo é outro gestor,
                 * o actor bloqueado continua
                 * sendo gestor.
                 *
                 * Portanto sempre permanece
                 * pelo menos um gestor após
                 * a alteração.
                 *
                 * Não usamos COUNT(*) FOR UPDATE,
                 * combinação inválida no PostgreSQL.
                 */
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
