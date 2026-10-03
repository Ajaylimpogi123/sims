<?php

namespace App\Console\Commands;

use App\Models\InternshipReport;
use App\Services\InternshipReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off repair: before attachments moved to the private disk, report
 * attachments were stored on the `public` disk, where anyone can fetch them
 * at /storage/report-attachments/... without logging in (bypassing
 * InternshipReportPolicy), and the authorized download routes can't find
 * them.
 *
 * Files that a report still references are moved to the private disk at the
 * same path (so attachment_path stays valid). Files no report references are
 * only listed, unless --delete-orphans is given. Safe to run repeatedly.
 */
class SecureReportAttachments extends Command
{
    protected $signature = 'reports:secure-attachments
        {--dry-run : Only show what would be done}
        {--delete-orphans : Also delete public files that no report references}';

    protected $description = 'Move internship report attachments left on the public disk to the private disk';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(InternshipReportService::ATTACHMENT_DISK);
        $dryRun = (bool) $this->option('dry-run');

        $files = $public->files(InternshipReportService::ATTACHMENT_DIRECTORY);

        if ($files === []) {
            $this->info('No report attachments on the public disk.');

            return self::SUCCESS;
        }

        $referenced = InternshipReport::query()
            ->whereIn('attachment_path', $files)
            ->pluck('attachment_path')
            ->flip();

        $moved = $orphans = $conflicts = 0;

        foreach ($files as $path) {
            if (! isset($referenced[$path])) {
                $orphans++;
                $action = $this->option('delete-orphans') ? 'delete (no report references it)' : 'left in place (no report references it; use --delete-orphans)';
                $this->line("{$path}: {$action}");

                if ($this->option('delete-orphans') && ! $dryRun) {
                    $public->delete($path);
                }

                continue;
            }

            if ($private->exists($path) && $private->get($path) !== $public->get($path)) {
                $conflicts++;
                $this->warn("{$path}: a different file already exists on the private disk; skipped");

                continue;
            }

            $moved++;
            $this->line("{$path}: move to the private disk");

            if ($dryRun) {
                continue;
            }

            if (! $private->exists($path)) {
                $private->writeStream($path, $public->readStream($path));
            }

            if ($private->exists($path) && $private->size($path) === $public->size($path)) {
                $public->delete($path);
            } else {
                $this->error("{$path}: copy could not be verified; the public file was kept");

                return self::FAILURE;
            }
        }

        $prefix = $dryRun ? '[dry run] ' : '';
        $this->info("{$prefix}Moved: {$moved}. Unreferenced: {$orphans}".($this->option('delete-orphans') ? ' (deleted)' : ' (kept)').". Conflicts: {$conflicts}.");

        return $conflicts === 0 ? self::SUCCESS : self::FAILURE;
    }
}
