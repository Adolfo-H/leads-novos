<?php

namespace App\Services\Campaigns;

/**
 * Descreve um código DSN (RFC 3463/3464) para o usuário.
 * Não altera o status do envio nem é usado para suprimir endereços.
 */
final class CampaignBounceReason
{
    public static function label(?string $statusCode): string
    {
        $code = trim((string) $statusCode);

        return match (true) {
            in_array($code, ['4.2.2', '5.2.2'], true) => 'Caixa de e-mail cheia',
            in_array($code, ['5.1.1', '5.1.2', '5.1.10'], true) => 'Endereço de e-mail inexistente',
            $code === '5.1.3' => 'Formato de endereço inválido',
            $code === '5.2.1' => 'Caixa postal desativada',
            $code === '5.3.4' => 'Mensagem maior que o limite permitido',
            str_starts_with($code, '5.7.') => 'Bloqueado pelo servidor destinatário',
            str_starts_with($code, '4.7.') => 'Bloqueio ou limite temporário do servidor',
            str_starts_with($code, '4.4.') => 'Problema temporário de conexão ou rota',
            str_starts_with($code, '5.4.') => 'Erro de roteamento ou domínio',
            str_starts_with($code, '5.6.') => 'Formato ou conteúdo da mensagem rejeitado',
            str_starts_with($code, '5.1.') => 'Endereço recusado pelo destinatário',
            str_starts_with($code, '4.2.') => 'Caixa postal temporariamente indisponível',
            str_starts_with($code, '5.2.') => 'Caixa postal indisponível',
            str_starts_with($code, '4.') => 'Falha temporária no recebimento',
            str_starts_with($code, '5.') => 'Mensagem devolvida pelo servidor',
            default => 'Motivo não identificado — conferir diagnóstico',
        };
    }

    public static function categoryLabel(?string $classification): string
    {
        return match ($classification) {
            'invalid' => 'Endereço inválido',
            'temporary' => 'Falha temporária',
            'permanent_other' => 'Rejeição permanente',
            default => 'Revisar',
        };
    }
}
