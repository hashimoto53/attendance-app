<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceRecordApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_data_and_meta(): void
    {
        $user = User::factory()->create(['name' => 'API太郎']);
        $this->attendance($user, '2026-05-01');

        $response = $this->getJson('/api/v1/attendance-records?user_id='.$user->id.'&month=2026-05');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user_name', 'API太郎')
            ->assertJsonPath('data.0.date', '2026-05-01')
            ->assertJsonPath('data.0.total_time', '08:00')
            ->assertJsonPath('data.0.total_break_time', '01:00');
        $this->assertArrayHasKey('last_page', $response->json('meta'));
    }

    public function test_show_includes_user_breaks_and_applications(): void
    {
        $user = User::factory()->create(['name' => '詳細API']);
        $attendance = $this->attendance($user, '2026-05-02');
        $attendance->applications()->create([
            'user_id' => $user->id,
            'date' => '2026-05-02',
            'clock_in' => '10:00:00',
            'clock_out' => '19:00:00',
            'remarks' => '申請あり',
            'status' => 0,
        ]);

        $this->getJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertOk()
            ->assertJsonPath('data.user.name', '詳細API')
            ->assertJsonPath('data.breaks.0.break_in', '12:00:00')
            ->assertJsonPath('data.breaks.0.break_out', '13:00:00')
            ->assertJsonPath('data.applications.0.comment', '申請あり');
    }

    public function test_missing_record_returns_the_not_found_json(): void
    {
        $this->getJson('/api/v1/attendance-records/99999')
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);
    }

    public function test_store_creates_an_attendance_and_rejects_invalid_input(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/attendance-records', [
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => 'API登録',
        ])->assertCreated()
            ->assertJsonPath('data.date', '2026-06-01')
            ->assertJsonPath('data.comment', 'API登録');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'remarks' => 'API登録',
        ]);

        $this->postJson('/api/v1/attendance-records', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', '勤怠日は必須です。')
            ->assertJsonPath('errors.clock_in.0', '出勤時刻は必須です。');
    }

    public function test_owner_can_update_and_delete_but_others_cannot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attendance = $this->attendance($owner, '2026-06-02');

        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/attendance-records/'.$attendance->id, [
            'comment' => '更新済み',
        ])->assertOk()->assertJsonPath('data.comment', '更新済み');

        $this->putJson('/api/v1/attendance-records/99999', ['comment' => 'なし'])
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);

        Sanctum::actingAs($other);
        $this->putJson('/api/v1/attendance-records/'.$attendance->id, ['comment' => '他人'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'この操作を実行する権限がありません。']);
        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertForbidden()
            ->assertExactJson(['error' => 'この操作を実行する権限がありません。']);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)->assertNoContent();
        $this->assertDatabaseMissing('attendances', ['id' => $attendance->id]);

        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertNotFound()
            ->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);
    }

    public function test_guests_cannot_write(): void
    {
        $attendance = $this->attendance(User::factory()->create(), '2026-06-03');

        $this->postJson('/api/v1/attendance-records', [
            'date' => '2026-06-04',
            'clock_in' => '09:00:00',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

        $this->putJson('/api/v1/attendance-records/'.$attendance->id, ['comment' => '不可'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->deleteJson('/api/v1/attendance-records/'.$attendance->id)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    private function attendance(User $user, string $date): Attendance
    {
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'date' => $date,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);
        $attendance->rests()->create([
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);

        return $attendance;
    }
}
