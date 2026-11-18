<?php

namespace App\Services\Campaigns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lista comercial de avisos úteis. Registros técnicos permanecem no banco
 * para auditoria, mas nunca são apresentados como respostas de clientes.
 * O filtro SQL funciona com SQLite (testes) e PostgreSQL (operação).
 */
final class CampaignInboundNoticeFilter
{
    /** @var list<string> */
    private const NOISE_SUBJECTS = [
        'delivery', 'undeliver', 'mail delivery', 'mailbox full', 'mailbox is full',
        'quota exceeded', 'quota ex', 'recipient address rejected', 'invalid recipient',
        'address not found', 'returned mail', 'return to sender', 'failure notice',
        'delivery status', 'delivery failed', 'delivery delayed', 'not delivered',
        'message rejected', 'message blocked', 'blocked message', 'blacklist',
        'spam', 'quarantine', 'policy violation', 'rejected', 'mail delivery subsystem',
        'mail system error', 'server error', 'host not found', 'postmaster',
        'bounce', 'undelivered', 'devolu', 'entreg', 'caixa cheia', 'caixa lotada',
        'limite de armazenamento', 'cota excedida', 'destinatario invalido',
        'endereco invalido', 'nao recebido', 'mensagem recusada', 'bloqueio por spam',
        'bloquead', 'rejeitad', '5.1.1', '5.7.1', '4.2.2', '5.2.2',
    ];

    /** @var list<string> */
    private const NOISE_SENDERS = [
        'mailer-daemon', 'postmaster@', 'mail-delivery', 'maildelivery',
        'bounce@', 'bounces@', 'delivery-status@', 'noreply-dsn@',
    ];

    /** @var list<string> */
    private const USEFUL_AUTO_SUBJECTS = [
        'mensagem autom', 'resposta autom', 'automatic reply', 'automatic response',
        'recebemos seu', 'recebemos sua', 'recebimento confirmado',
        'agradecemos seu contato', 'agradece pelo seu contato',
        'agradecemos sua mensagem', 'solicitacao recebida', 'solicitacao registrada',
        'protocolo de atendimento', 'ticket aberto', 'ticket criado',
        'sua mensagem foi recebida', 'your message has been received',
        'thank you for contacting', 'auto response', 'autoreply',
    ];

    /**
     * A consulta filtra os 203+ registros já armazenados sem excluir dados.
     * A validação também ocorre na ingestão de novos cabeçalhos.
     */
    public static function query(): Builder
    {
        return DB::table('email_campaign_inbound_notices')
            ->where(static function (Builder $q): void {
                $q->whereIn('notice_type', [
                    'absence', 'address_change', 'reply_needs_review', 'auto_reply',
                ])->orWhere(static function (Builder $routing): void {
                    $routing->where('notice_type', 'routing')
                        ->where(static function (Builder $terms): void {
                            foreach (['redirecion', 'encaminh', 'forwarding', 'redirect'] as $needle) {
                                $terms->orWhereRaw("LOWER(COALESCE(subject, '')) LIKE ?", ['%'.$needle.'%']);
                            }
                        });
                })->orWhere(static function (Builder $automatic): void {
                    $automatic->where('notice_type', 'automatic')
                        ->where(static function (Builder $terms): void {
                            foreach (self::USEFUL_AUTO_SUBJECTS as $needle) {
                                $terms->orWhereRaw("LOWER(COALESCE(subject, '')) LIKE ?", ['%'.$needle.'%']);
                            }
                        });
                });
            })
            ->where(static function (Builder $q): void {
                foreach (self::NOISE_SUBJECTS as $needle) {
                    $q->whereRaw("LOWER(COALESCE(subject, '')) NOT LIKE ?", ['%'.$needle.'%']);
                }
                foreach (self::NOISE_SENDERS as $needle) {
                    $q->whereRaw("LOWER(COALESCE(from_email, '')) NOT LIKE ?", ['%'.$needle.'%']);
                }
            });
    }

    /**
     * Somente sinais tecnicos inequívocos. Nao confundir auto-generated
     * com DSN: alguns avisos institucionais tambem usam esse cabecalho.
     *
     * @param  array<string, mixed>  $message
     */
    public static function isTechnical(array $message): bool
    {
        $subject = mb_strtolower(trim((string) ($message['subject'] ?? '')));
        $from = mb_strtolower(trim((string) ($message['from_email'] ?? '')));

        return self::containsAny($subject, self::NOISE_SUBJECTS)
            || self::containsAny($from, self::NOISE_SENDERS)
            || is_array($message['bounce'] ?? null);
    }

    /** @param array<string, mixed> $message */
    public static function shouldIgnore(array $message): bool
    {
        $subject = mb_strtolower(trim((string) ($message['subject'] ?? '')));
        $from = mb_strtolower(trim((string) ($message['from_email'] ?? '')));
        if (self::containsAny($subject, self::NOISE_SUBJECTS)
            || self::containsAny($from, self::NOISE_SENDERS)) {
            return true;
        }

        // Auto-generated geralmente inclui status tecnico. Exigimos pista
        // comercial positiva em vez de transformar qualquer auto-generated em retorno.
        $auto = mb_strtolower(trim((string) ($message['auto_submitted'] ?? '')));

        return $auto !== '' && $auto !== 'no' && $auto !== 'auto-replied'
            && ! self::hasUsefulSubject($subject);
    }

    public static function hasUsefulSubject(string $subject): bool
    {
        $subject = mb_strtolower(trim($subject));

        return self::containsAny($subject, self::USEFUL_AUTO_SUBJECTS)
            || preg_match('/f[eé]rias|out.of.office|aus[eê]ncia|ausente|vacation|fora do escrit[oó]rio/iu', $subject) === 1
            || preg_match('/e-?mail (?:novo|alterado)|novo (?:endere[cç]o|e-?mail)|mudan[cç]a (?:de|do) (?:e-?mail|endere[cç]o)|endere[cç]o (?:alterado|novo)|altera[cç][aã]o (?:de|do) (?:e-?mail|endere[cç]o)/iu', $subject) === 1
            || self::containsAny($subject, ['redirecion', 'encaminh', 'forwarding', 'redirect']);
    }

    /** @param list<string> $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
