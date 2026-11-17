<?php

namespace App\Console\Commands;

use App\Services\PermissionSynchronizer;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'centra:permissions-sync {--dry-run : Report differences without writing} {--apply : Create only missing canonical records and baseline grants}';
    protected $description = 'Safely reconcile CENTRA canonical permissions without revoking assignments.';

    public function handle(PermissionSynchronizer $synchronizer): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Use either --dry-run or --apply, not both.');
            return self::INVALID;
        }
        if (! $this->option('dry-run') && ! $this->option('apply')) {
            $this->error('Specify --dry-run or --apply. No changes were made.');
            return self::INVALID;
        }

        $differences = $this->option('apply') ? $synchronizer->apply() : $synchronizer->differences();
        foreach ($differences as $group => $values) {
            $this->line($group.': '.json_encode($values, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
