<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Authors whose posts are dropped by the prefilter (step 4). Only the hashed
 * author_key is stored; the dashboard shows their latest post for context.
 */
class KnownAgent extends Model
{
    protected $fillable = ['author_key', 'reason', 'created_by'];

    public function rawItems(): HasMany
    {
        return $this->hasMany(RawItem::class, 'author_key', 'author_key');
    }

    public function latestItem(): ?RawItem
    {
        return $this->rawItems()->with('source')->latest('posted_at')->first();
    }

    /** Display name from the most recent post that has one. */
    public function handle(): ?string
    {
        return $this->rawItems()->whereNotNull('author_handle')->latest('posted_at')->value('author_handle');
    }

    /** "Hệ thống" for automatic entries, otherwise the user's name. */
    public function creatorName(): string
    {
        if ($this->created_by === 'system') {
            return 'Hệ thống (tự động)';
        }

        return ctype_digit((string) $this->created_by)
            ? (User::query()->find((int) $this->created_by)?->name ?? 'Người dùng #'.$this->created_by)
            : (string) $this->created_by;
    }
}
