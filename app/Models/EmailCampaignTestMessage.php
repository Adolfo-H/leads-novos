<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teste de envio rastreado: separado de destinatários e respostas comerciais.
 */
class EmailCampaignTestMessage extends Model
{
    protected $fillable = [
        'email_campaign_id', 'test_to', 'message_id', 'status',
        'attempted_at', 'sent_at', 'replied_at', 'reply_subject',
        'reply_uid_validity', 'reply_imap_uid', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }
}
