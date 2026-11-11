<?php

namespace App\Console\Commands;

use App\Services\Privacy\DataRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:retention')]
#[Description('Delete or anonymise old data according to SPEC 15 (runs daily)')]
class RetentionCommand extends Command
{
    public function handle(DataRetention $retention): int
    {
        $result = $retention->run();

        $this->table(['raw_items deleted', 'leads deleted', 'won anonymised', 'inquiry contacts erased'], [[
            $result['raw_items'], $result['leads'], $result['anonymised'], $result['inquiry_contacts'],
        ]]);

        return self::SUCCESS;
    }
}
