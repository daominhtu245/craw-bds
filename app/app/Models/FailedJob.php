<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of Laravel's failed_jobs table, for the admin page
 * (local setups without Horizon).
 */
class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /** Short class name of the job, e.g. "NotifyLeadJob". */
    public function jobName(): string
    {
        $name = (string) (json_decode((string) $this->payload, true)['displayName'] ?? 'unknown');

        return class_basename($name);
    }

    /** First line of the exception, without the stack trace. */
    public function errorSummary(): string
    {
        return mb_strimwidth(strtok((string) $this->exception, "\n") ?: '', 0, 300, '…');
    }
}
