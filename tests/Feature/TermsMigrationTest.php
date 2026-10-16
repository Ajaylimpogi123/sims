<?php

namespace Tests\Feature;

use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration 23 (terms): re-run on existing students, it creates the
 * current term and backfills every student into it.
 *
 * DDL commits implicitly, so this test runs outside RefreshDatabase's
 * transaction and cleans up its own rows; the schema is left migrated.
 */
class TermsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * No wrapping transaction (see the class comment).
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_migration_backfills_existing_students_into_a_new_current_term(): void
    {
        $userIds = [];

        try {
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('terms'));

            foreach (['TMT-0001', 'TMT-0002'] as $number) {
                $userId = DB::table('users')->insertGetId([
                    'name' => $number,
                    'email' => strtolower($number).'@example.com',
                    'password' => 'x',
                    'role_id' => null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $userIds[] = $userId;

                DB::table('students')->insert([
                    'user_id' => $userId,
                    'student_number' => $number,
                    'course' => 'BSIT',
                    'section' => 'A',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Artisan::call('migrate', ['--force' => true]);
            Term::flushCurrent();

            $term = Term::query()->where('is_current', true)->sole();

            $this->assertSame(
                [$term->id, $term->id],
                DB::table('students')->whereIn('user_id', $userIds)->orderBy('id')->pluck('term_id')->map(fn ($id) => (int) $id)->all(),
            );
            $this->assertSame(0, DB::table('students')->whereNull('term_id')->count());
        } finally {
            if (! DB::getSchemaBuilder()->hasTable('terms')) {
                Artisan::call('migrate', ['--force' => true]);
            }

            DB::table('students')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            Term::flushCurrent();
        }
    }
}
