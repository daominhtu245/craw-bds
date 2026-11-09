<?php

namespace App\Filament\Concerns;

/**
 * Resources and pages only admins may open (SPEC 2: sources, keywords,
 * settings, users, raw data).
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }
}
