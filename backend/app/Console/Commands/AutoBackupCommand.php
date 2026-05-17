<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ServerBackup;
use App\Services\BackupExporter;
use Illuminate\Console\Command;

class AutoBackupCommand extends Command
{
    protected $signature   = 'backup:auto';
    protected $description = 'Create automatic server backups for projects based on their interval setting';

    public function handle(BackupExporter $exporter): void
    {
        $now = now();

        Project::where('auto_backup_interval', '!=', 'never')->get()
            ->each(function (Project $project) use ($now, $exporter) {
                $due = match ($project->auto_backup_interval) {
                    'daily'   => ! $project->last_auto_backup_at || $project->last_auto_backup_at->diffInHours($now) >= 24,
                    'weekly'  => ! $project->last_auto_backup_at || $project->last_auto_backup_at->diffInDays($now) >= 7,
                    'monthly' => ! $project->last_auto_backup_at || $project->last_auto_backup_at->diffInDays($now) >= 30,
                    default   => false,
                };

                if (! $due) return;

                ServerBackup::create([
                    'project_id'  => $project->id,
                    'entity_type' => 'project',
                    'entity_id'   => $project->id,
                    'entity_name' => $project->name,
                    'created_by'  => $project->user_id,
                    'data'        => json_encode([
                        'version'     => '1.0',
                        'exported_at' => $now->toISOString(),
                        'auto'        => true,
                        'project'     => $exporter->exportProject($project),
                    ]),
                ]);

                $project->update(['last_auto_backup_at' => $now]);

                $this->line("  Backed up: {$project->name}");
            });

        $this->info('Auto backup complete.');
    }
}
