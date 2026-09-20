<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        Notification::factory()->count(3)->create(['user_id' => $otherUser->id]);

        $this->actingAs($user)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 2)
            );
    }

    public function test_a_user_can_mark_their_own_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->patch("/notifications/{$notification->id}/read");

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($user)
            ->patch("/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_affects_the_authenticated_users_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        $othersNotification = Notification::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($user)->patch('/notifications/read-all');

        $this->assertSame(
            0,
            Notification::where('user_id', $user->id)->whereNull('read_at')->count(),
        );
        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_guests_cannot_access_notifications(): void
    {
        $this->get('/notifications')->assertRedirect(route('login', absolute: false));
    }

    public function test_notifications_with_identical_timestamps_still_return_newest_first(): void
    {
        $user = User::factory()->create();
        $tiedTimestamp = now();

        $first = Notification::factory()->create([
            'user_id' => $user->id,
            'title' => 'First',
            'created_at' => $tiedTimestamp,
        ]);
        $second = Notification::factory()->create([
            'user_id' => $user->id,
            'title' => 'Second',
            'created_at' => $tiedTimestamp,
        ]);
        $third = Notification::factory()->create([
            'user_id' => $user->id,
            'title' => 'Third',
            'created_at' => $tiedTimestamp,
        ]);

        $ids = $user->notifications()->pluck('id')->all();

        $this->assertSame([$third->id, $second->id, $first->id], $ids);

        $this->actingAs($user)
            ->get('/notifications')
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.id', $third->id)
                ->where('notifications.data.1.id', $second->id)
                ->where('notifications.data.2.id', $first->id)
            );
    }
}
