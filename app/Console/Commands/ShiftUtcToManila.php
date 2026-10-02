<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off repair for data written while config('app.timezone') was UTC.
 *
 * After the switch to Asia/Manila every stored wall-clock value is read as
 * Manila time, so anything the system stamped while running in UTC reads
 * 8 hours early. This moves those system-generated values forward by 8h:
 *
 *  - created_at/updated_at and the other system timestamps listed in
 *    TIMESTAMP_COLUMNS;
 *  - attendances.date + time_in/time_out, which were written as
 *    today()/now() in UTC. They are combined into UTC datetimes, shifted,
 *    and written back — the date rolls forward when the UTC time-in was
 *    16:00 or later.
 *
 * User-entered calendar dates (report periods, evaluation periods) and
 * durations (attendances.rendered_hours) are deliberately left alone.
 *
 * A row in `data_fixes` named MARKER is written in the same transaction as
 * the shift; while it exists the command refuses to run again, so the data
 * can never be shifted twice.
 */
class ShiftUtcToManila extends Command
{
    public const MARKER = 'shift_utc_to_manila';

    public const OFFSET_HOURS = 8;

    /**
     * System-generated timestamp columns to shift, by table.
     */
    public const TIMESTAMP_COLUMNS = [
        'roles' => ['created_at', 'updated_at'],
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
        'companies' => ['created_at', 'updated_at'],
        'company_supervisors' => ['created_at', 'updated_at'],
        'students' => ['created_at', 'updated_at'],
        'attendances' => ['created_at', 'updated_at'],
        'internship_reports' => ['reviewed_at', 'created_at', 'updated_at'],
        'notifications' => ['read_at', 'created_at', 'updated_at'],
        'evaluation_criteria' => ['created_at', 'updated_at'],
        'evaluations' => ['submitted_at', 'locked_at', 'created_at', 'updated_at'],
        'evaluation_responses' => ['created_at', 'updated_at'],
    ];

    /**
     * Shown in the output so it's explicit what is *not* touched and why.
     */
    private const LEFT_ALONE = [
        'internship_reports.period_start/period_end' => 'user-entered dates',
        'evaluations.evaluation_period_start/end' => 'user-entered dates',
        'attendances.rendered_hours' => 'duration, not a point in time',
        'sessions, cache, jobs, failed_jobs, password_reset_tokens' => 'framework tables, not SIMS data',
    ];

    private const SAMPLES_PER_TABLE = 2;

    protected $signature = 'app:shift-utc-to-manila
                            {--dry-run : Report what would change without writing anything}';

    protected $description = 'One-off: move system timestamps and attendance date/time written in UTC forward 8h to Asia/Manila';

    public function handle(): int
    {
        if (! Schema::hasTable('data_fixes')) {
            $this->error('The data_fixes table is missing — run `php artisan migrate` first.');

            return self::FAILURE;
        }

        if ($this->alreadyRan()) {
            return self::FAILURE;
        }

        if ($missing = $this->missingColumns()) {
            $this->error('Unexpected schema, refusing to run. Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');

            return $this->plan()['ok'] ? self::SUCCESS : self::FAILURE;
        }

        return DB::transaction(function () {
            // Re-check inside the transaction; the unique index on
            // data_fixes.name is the final guard against a concurrent run.
            if ($this->alreadyRan()) {
                return self::FAILURE;
            }

            $plan = $this->plan();

            if (! $plan['ok']) {
                return self::FAILURE;
            }

            $this->apply($plan['attendances']);

            DB::table('data_fixes')->insert([
                'name' => self::MARKER,
                'summary' => json_encode([
                    'offset_hours' => self::OFFSET_HOURS,
                    'timestamp_counts' => $plan['counts'],
                    'attendance_rows' => count($plan['attendances']),
                    'attendance_date_rollovers' => $plan['rollovers'],
                ]),
                'ran_at' => now(),
            ]);

            $this->info('Done. Shifted all listed columns by +'.self::OFFSET_HOURS.'h and recorded marker "'.self::MARKER.'" in data_fixes.');

            return self::SUCCESS;
        });
    }

    private function alreadyRan(): bool
    {
        $marker = DB::table('data_fixes')->where('name', self::MARKER)->first();

        if (! $marker) {
            return false;
        }

        $this->error('Refusing to run: this database was already shifted on '.$marker->ran_at
            .' (data_fixes row "'.self::MARKER.'"). Running again would shift the data twice.');

        return true;
    }

    /**
     * @return list<string>
     */
    private function missingColumns(): array
    {
        $missing = [];

        $expected = self::TIMESTAMP_COLUMNS;
        $expected['attendances'] = [...$expected['attendances'], 'date', 'time_in', 'time_out'];

        foreach ($expected as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        return $missing;
    }

    /**
     * Compute and print everything the run would change.
     *
     * @return array{ok: bool, counts: array<string, int>, attendances: list<array<string, mixed>>, rollovers: int}
     */
    private function plan(): array
    {
        $counts = $this->reportTimestamps();

        $attendances = $this->planAttendances();
        $rollovers = count(array_filter($attendances, fn ($row) => $row['new_date'] !== $row['old_date']));

        $this->reportAttendances($attendances, $rollovers);

        $this->newLine();
        $this->line('Left untouched:');
        foreach (self::LEFT_ALONE as $what => $why) {
            $this->line("  - {$what} ({$why})");
        }

        $collisions = $this->collisions($attendances);

        if ($collisions) {
            $this->newLine();
            $this->error('Shifting would break the unique(student_id, date) constraint on attendances. Nothing was written.');
            foreach ($collisions as $key => $ids) {
                [$studentId, $date] = explode('|', $key);
                $this->line("  - student {$studentId} on {$date}: attendance ids ".implode(', ', $ids));
            }
        }

        return [
            'ok' => $collisions === [],
            'counts' => $counts,
            'attendances' => $attendances,
            'rollovers' => $rollovers,
        ];
    }

    /**
     * @return array<string, int> "table.column" => non-null rows
     */
    private function reportTimestamps(): array
    {
        $counts = [];
        $rows = [];

        foreach (self::TIMESTAMP_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $count = DB::table($table)->whereNotNull($column)->count();
                $counts["{$table}.{$column}"] = $count;
                $rows[] = [$table, $column, $count];
            }
        }

        $this->info('System timestamps to shift +'.self::OFFSET_HOURS.'h:');
        $this->table(['Table', 'Column', 'Non-null rows'], $rows);

        $this->info('Samples (before -> after):');

        foreach (self::TIMESTAMP_COLUMNS as $table => $columns) {
            $select = ['id'];
            foreach ($columns as $column) {
                $select[] = $column;
                $select[] = DB::raw($this->shiftSql($column)." as `{$column}__after`");
            }

            $samples = DB::table($table)->select($select)->orderBy('id')->limit(self::SAMPLES_PER_TABLE)->get();

            foreach ($samples as $sample) {
                $parts = [];
                foreach ($columns as $column) {
                    $before = $sample->{$column};
                    $after = $sample->{"{$column}__after"};
                    $parts[] = $before === null ? "{$column}: null" : "{$column}: {$before} -> {$after}";
                }

                $this->line("  {$table}#{$sample->id}  ".implode(' | ', $parts));
            }
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planAttendances(): array
    {
        $plan = [];

        // Latest date first: when a student's day D rolls into D+1, their
        // D+1 row (if any) has already moved on, so the unique index never
        // sees a transient duplicate.
        $rows = DB::table('attendances')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get(['id', 'student_id', 'date', 'time_in', 'time_out']);

        foreach ($rows as $row) {
            $in = $row->time_in !== null ? $this->shiftWallClock($row->date, $row->time_in) : null;
            $out = $row->time_out !== null ? $this->shiftWallClock($row->date, $row->time_out) : null;

            $plan[] = [
                'id' => (int) $row->id,
                'student_id' => (int) $row->student_id,
                'old_date' => $row->date,
                'old_time_in' => $row->time_in,
                'old_time_out' => $row->time_out,
                // The record's date was today() at time-in, so the shifted
                // time-in decides the new date (time-out only if there is no
                // time-in at all).
                'new_date' => ($in ?? $out)?->toDateString() ?? $row->date,
                'new_time_in' => $in?->format('H:i:s'),
                'new_time_out' => $out?->format('H:i:s'),
                'crosses_midnight' => $in && $out && ! $in->isSameDay($out),
            ];
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $attendances
     */
    private function reportAttendances(array $attendances, int $rollovers): void
    {
        $this->newLine();
        $this->info('Attendance date/time to shift: '.count($attendances).' row(s), '.$rollovers.' rolling into the next day.');

        if ($attendances === []) {
            return;
        }

        $this->table(
            ['ID', 'Student', 'Date', 'Time in', 'Time out', 'Note'],
            array_map(fn ($row) => [
                $row['id'],
                $row['student_id'],
                $this->arrow($row['old_date'], $row['new_date']),
                $this->arrow($row['old_time_in'], $row['new_time_in']),
                $this->arrow($row['old_time_out'], $row['new_time_out']),
                $row['crosses_midnight'] ? 'time-out lands on the next Manila day' : '',
            ], $attendances),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $attendances
     * @return array<string, list<int>> "student_id|date" => attendance ids
     */
    private function collisions(array $attendances): array
    {
        $byKey = [];

        foreach ($attendances as $row) {
            $byKey[$row['student_id'].'|'.$row['new_date']][] = $row['id'];
        }

        return array_filter($byKey, fn ($ids) => count($ids) > 1);
    }

    /**
     * @param  list<array<string, mixed>>  $attendances  in planAttendances() order
     */
    private function apply(array $attendances): void
    {
        foreach (self::TIMESTAMP_COLUMNS as $table => $columns) {
            // One statement per table so every column is set explicitly.
            DB::table($table)->update(
                collect($columns)->mapWithKeys(fn ($column) => [$column => DB::raw($this->shiftSql($column))])->all()
            );
        }

        foreach ($attendances as $row) {
            DB::table('attendances')->where('id', $row['id'])->update([
                'date' => $row['new_date'],
                'time_in' => $row['new_time_in'],
                'time_out' => $row['new_time_out'],
            ]);
        }
    }

    private function shiftSql(string $column): string
    {
        return "DATE_ADD(`{$column}`, INTERVAL ".self::OFFSET_HOURS.' HOUR)';
    }

    private function shiftWallClock(string $date, string $time): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'UTC')->addHours(self::OFFSET_HOURS);
    }

    private function arrow(?string $before, ?string $after): string
    {
        if ($before === null && $after === null) {
            return '-';
        }

        return $before === $after ? (string) $before : "{$before} -> {$after}";
    }
}
