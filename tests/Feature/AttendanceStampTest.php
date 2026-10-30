<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_screen_shows_the_current_date(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now('Asia/Tokyo');
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        $this->actingAs($user)
            ->get('/attendance')
            ->assertOk()
            ->assertSee($now->format('Y年n月j日').'('.$weekdays[$now->dayOfWeek].')', false);
    }

    public function test_status_label_follows_the_attendance_state(): void
    {
        $user = User::factory()->create();
        $today = Carbon::now('Asia/Tokyo')->toDateString();

        $this->actingAs($user)->get('/attendance')->assertSee('勤務外');

        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'date' => $today,
            'clock_in' => '09:00:00',
            'status' => Attendance::STATUS_WORKING,
        ]);
        $this->actingAs($user)->get('/attendance')->assertSee('出勤中');

        $attendance->update(['status' => Attendance::STATUS_ON_BREAK]);
        $this->actingAs($user)->get('/attendance')->assertSee('休憩中');

        $attendance->update([
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);
        $this->actingAs($user)->get('/attendance')->assertSee('退勤済');
    }

    public function test_clock_in_changes_status_and_is_disabled_after_clock_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/attendance')->assertSee('>出勤</button>', false);
        $this->assertButtonEnabled($this->actingAs($user)->get('/attendance')->getContent(), 'clock-in');

        $this->actingAs($user)->post('/attendance/clock-in')->assertRedirect('/attendance');
        $this->actingAs($user)->get('/attendance')->assertSee('出勤中');

        $this->actingAs($user)->post('/attendance/clock-out')->assertRedirect('/attendance');
        $clockedOut = $this->actingAs($user)->get('/attendance');
        $clockedOut->assertSee('退勤済');
        $clockedOut->assertSee('お疲れ様でした。');
        $this->assertButtonDisabled($clockedOut->getContent(), 'clock-in');
    }

    public function test_clock_in_and_clock_out_are_listed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/attendance/clock-in');
        $this->actingAs($user)->post('/attendance/clock-out');

        $attendance = Attendance::query()->where('user_id', $user->id)->first();
        $clockIn = Carbon::parse($attendance->clock_in)->format('H:i');
        $clockOut = Carbon::parse($attendance->clock_out)->format('H:i');

        $this->actingAs($user)
            ->get('/attendance/list')
            ->assertOk()
            ->assertSee($clockIn)
            ->assertSee($clockOut);
    }

    public function test_break_can_be_started_and_ended_more_than_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/attendance/clock-in');

        $working = $this->actingAs($user)->get('/attendance');
        $working->assertSee('出勤中');
        $this->assertButtonEnabled($working->getContent(), 'break-in');

        $this->actingAs($user)->post('/attendance/rest-start');
        $onBreak = $this->actingAs($user)->get('/attendance');
        $onBreak->assertSee('休憩中');
        $this->assertButtonEnabled($onBreak->getContent(), 'break-out');

        $this->actingAs($user)->post('/attendance/rest-end');
        $workingAgain = $this->actingAs($user)->get('/attendance');
        $workingAgain->assertSee('出勤中');
        $this->assertButtonEnabled($workingAgain->getContent(), 'break-in');

        $this->actingAs($user)->post('/attendance/rest-start');
        $this->actingAs($user)->get('/attendance')->assertSee('休憩中');
        $this->assertButtonEnabled($this->actingAs($user)->get('/attendance')->getContent(), 'break-out');
    }

    public function test_completed_break_is_shown_on_the_list(): void
    {
        $user = User::factory()->create();
        $today = Carbon::now('Asia/Tokyo')->toDateString();
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'date' => $today,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);
        $attendance->rests()->create([
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);

        $this->actingAs($user)
            ->get('/attendance/list')
            ->assertSee('09:00')
            ->assertSee('18:00')
            ->assertSee('1:00');
    }

    private function assertButtonEnabled(string $html, string $action): void
    {
        $this->assertSame(1, preg_match('/class="attendance__button--submit--'.preg_quote($action, '/').'"[^>]*>/', $html, $matches));
        $this->assertStringNotContainsString('disabled', $matches[0]);
    }

    private function assertButtonDisabled(string $html, string $action): void
    {
        $this->assertSame(1, preg_match('/class="attendance__button--submit--'.preg_quote($action, '/').'"[^>]*>/', $html, $matches));
        $this->assertStringContainsString('disabled', $matches[0]);
    }
}
