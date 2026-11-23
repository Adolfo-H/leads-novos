<?php

namespace App\Services\Contacts;

use Illuminate\Database\Query\Builder;

/**
 * Agrupa SOMENTE canais institucionais repetidos dentro da MESMA raiz fiscal.
 *
 * - Mesmo e-mail institucional E ambos os telefones iguais: um canal.
 * - Sem e-mail: exige os dois telefones iguais apos limpar pontuacao.
 * - Mesmo e-mail com telefones diferentes continua como canais distintos.
 * - Nunca cruza raizes de CNPJ, pessoas HubSpot ou contatos manuais.
 * - Pesquisas por numero/CNPJ mantem cada filial individual acessivel.
 *
 * Nenhuma escrita em fonte, CRM ou cadastro; apenas uma condicao na listagem.
 */
final class ContactChannelConsolidation
{
    public static function receita(string $search = ''): Builder
    {
        $query = ReceitaContactChannels::query();
        $searchedDigits = (string) preg_replace('/\D+/', '', $search);

        // Busca por telefone/CNPJ: nao ocultar a filial que possui a informacao.
        if (strlen($searchedDigits) >= 7) {
            return $query;
        }

        $email = "LOWER(TRIM(COALESCE(e.email, '')))";
        $otherEmail = "LOWER(TRIM(COALESCE(sibling.email, '')))";
        $phone1 = self::digitsSql('e.phone_1');
        $phone2 = self::digitsSql('e.phone_2');
        $otherPhone1 = self::digitsSql('sibling.phone_1');
        $otherPhone2 = self::digitsSql('sibling.phone_2');

        // So consolidar assinatura integral: email e os DOIS telefones.
        // Compartilhar apenas a caixa postal nao prova um canal identico.
        $sameChannel = "($email = $otherEmail AND $phone1 = $otherPhone1 AND $phone2 = $otherPhone2)";

        return $query->whereNotExists(static function (Builder $q) use ($sameChannel): void {
            $q->selectRaw('1')
                ->from('establishments as sibling')
                ->whereColumn('sibling.company_id', 'e.company_id')
                ->whereColumn('sibling.id', '<', 'e.id')
                ->whereRaw($sameChannel);
        });
    }

    /**
     * Remove caracteres de apresentacao, compativel com SQLite/PG.
     * Fragmentos sao formados apenas com identificadores constantes deste metodo.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function digitsSql(string $column): string
    {
        $value = "COALESCE($column, '')";
        foreach ([' ', '(', ')', '-', '+', '.', '/'] as $char) {
            $value = "REPLACE($value, '$char', '')";
        }

        return $value;
    }
}
