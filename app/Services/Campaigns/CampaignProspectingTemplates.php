<?php

namespace App\Services\Campaigns;

/**
 * Textos curtos e editáveis para prospecção.
 * Nenhum modelo inicia campanhas nem agenda envio.
 * A assinatura é acrescentada pelo HTML/texto do Mailable.
 */
final class CampaignProspectingTemplates
{
    /** @return list<string> */
    public static function keys(): array
    {
        return ['initial', 'direct', 'followup', 'meeting'];
    }

    /** @return array{0:string,1:string} */
    public static function template(string $key): array
    {
        return match ($key) {
            'direct' => [
                'Como vocês acompanham as averbações das exportações?',
                "{{saudacao}}\n\nNas exportações diretas, como a {{empresa}} acompanha as NF-e, DU-Es e averbações?\n\nSaldos pendentes, divergências e prazos podem dificultar a comprovação fiscal dessas operações.\n\nA ExportControl centraliza essas informações e ajuda a identificar pendências para acompanhamento.\n\nVocê é a pessoa responsável por esse controle ou poderia me indicar com quem conversar?",
            ],
            'followup' => [
                'Retomando o contato sobre exportações',
                "{{saudacao}}\n\nRetomo meu contato sobre o acompanhamento fiscal das exportações da {{empresa}}.\n\nFaz sentido conversarmos com a pessoa que acompanha NF-e, DU-E e averbações? Caso não seja sua área, poderia me indicar o responsável?",
            ],
            'meeting' => [
                'Uma conversa rápida sobre controle de exportações',
                "{{saudacao}}\n\nGostaria de apresentar brevemente como a ExportControl apoia o controle e a comprovação fiscal das exportações.\n\nVocê teria disponibilidade para uma conversa de 15 minutos ou poderia me indicar o responsável?",
            ],
            default => [
                'Vendas para tradings: como vocês comprovam a exportação?',
                "{{saudacao}}\n\nA {{empresa}} realiza vendas para tradings ou comerciais exportadoras com fim específico de exportação?\n\nQuando a exportação não é comprovada, essas operações podem gerar questionamentos fiscais e cobrança de ICMS e encargos.\n\nA ExportControl ajuda a acompanhar NF-e, DU-E, averbações e saldos pendentes, facilitando a identificação de situações que precisam de atenção.\n\nVocê é a pessoa responsável por esse controle ou poderia me indicar com quem conversar?",
            ],
        };
    }
}
