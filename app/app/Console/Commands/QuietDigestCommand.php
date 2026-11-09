<?php

namespace App\Console\Commands;

use App\Services\Notify\QuietDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:quiet-digest')]
#[Description('Send one summary of leads held back during quiet hours (no-op while quiet)')]
class QuietDigestCommand extends Command
{
    public function handle(QuietDigest $digest): int
    {
        $count = $digest->sendIfDue();
        $this->info("leads in digest: {$count}");

        return self::SUCCESS;
    }
}
