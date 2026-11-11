<?php

namespace App\Models;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'raw_item_id', 'intent', 'stage', 'language', 'summary_vi', 'arrival_date', 'duration_months',
        'property_type', 'bedrooms_min', 'areas', 'budget_min_usd', 'budget_max_usd', 'budget_raw',
        'household', 'requirements', 'confidence', 'score', 'status', 'assigned_to', 'notified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'intent' => LeadIntent::class,
            'stage' => LeadStage::class,
            'property_type' => PropertyType::class,
            'status' => LeadStatus::class,
            'arrival_date' => 'date',
            'areas' => 'array',
            'requirements' => 'array',
            'confidence' => 'decimal:2',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function rawItem(): BelongsTo
    {
        return $this->belongsTo(RawItem::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /** Requests the customer sent through the public site / Mini App (App #2). */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class)->latest('id');
    }

    public function replyDrafts(): HasMany
    {
        return $this->hasMany(ReplyDraft::class);
    }

    public function matchedListings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'lead_listing_matches')
            ->withPivot('match_score')
            ->withTimestamps()
            ->orderByPivot('match_score', 'desc');
    }
}
