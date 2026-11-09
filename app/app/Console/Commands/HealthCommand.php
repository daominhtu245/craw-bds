<?php

namespace App\Console\Commands;

use App\Services\Ops\HealthChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:health')]
#[Description('Alert the team when a collector stops sending heartbeats or an important source goes quiet')]
class HealthCommand extends Command
{
    public function handle(HealthChecker $checker): int
    {
        $result = $checker->check();
        $this->info("alerts sent: {$result['alerts']}, recoveries sent: {$result['recoveries']}");

        return self::SUCCESS;
    }
}
