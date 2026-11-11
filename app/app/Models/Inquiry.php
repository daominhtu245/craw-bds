<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request a customer sent through the public site or Telegram Mini App.
 * name / contact are encrypted at rest and erased after the retention period.
 */
class Inquiry extends Model
{
    public const CHANNELS = ['phone', 'zalo', 'whatsapp', 'telegram', 'email'];

    public const SOURCES = ['website', 'telegram_miniapp'];

    protected $fillable = [
        'lead_id', 'listing_id', 'source', 'locale', 'name', 'contact_channel', 'contact', 'arrival_date',
        'duration_months', 'budget_usd', 'message', 'ref', 'ip_hash', 'consent_at', 'contact_purged_at',
    ];

    protected $hidden = ['name', 'contact', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'contact' => 'encrypted',
            'arrival_date' => 'date',
            'consent_at' => 'datetime',
            'contact_purged_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
