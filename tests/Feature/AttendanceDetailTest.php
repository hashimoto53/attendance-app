<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_the_current_month_and_moves_between_months(): void
    {
        $user = User::factory()->create();
        $month = Carbon::now('Asia/Tokyo')->startOfMonth();
        Attendance::query()->create([
            'user_id' => $user->id,
            'date' => $month->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);

        $this->actingAs($user)
            ->get('/attendance/list')
            ->assertOk()
            ->assertSee($month->format('Y/m'))
            ->assertSee('09:00')
            ->assertSee(route('attendance.detail', Attendance::query()->first()->id), false);

        $previous = $month->copy()->subMonthNoOverflow()->format('Y-m');
        $next = $month->copy()->addMonthNoOverflow()->format('Y-m');

        $this->actingAs($user)
            ->get('/attendance/list?date='.$previous)
            ->assertSee($month->copy()->subMonthNoOverflow()->format('Y/m'));

        $this->actingAs($user)
            ->get('/attendance/list?date='.$next)
            ->assertSee($month->copy()->addMonthNoOverflow()->format('Y/m'));
    }

    public function test_detail_shows_the_owner_name_date_and_stamps(): void
    {
        $user = User::factory()->create(['name' => '詳細太郎']);
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);
        $attendance->rests()->create([
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);

        $this->actingAs($user)
            ->get('/attendance/detail/'.$attendance->id)
            ->assertOk()
            ->assertSee('詳細太郎')
            ->assertSee('5月1日')
            ->assertSee('09:00')
            ->assertSee('18:00')
            ->assertSee('12:00')
            ->assertSee('13:00');
    }

    public function test_correction_validation_messages(): void
    {
        $user = User::factory()->create();
        $attendance = $this->attendance($user);

        $this->actingAs($user)
            ->post('/attendance/detail/'.$attendance->id, [
                'new_clock_in' => '19:00',
                'new_clock_out' => '09:00',
                'comment' => '修正',
            ])->assertSessionHasErrors(['new_clock_in' => '出勤時間もしくは退勤時間が不適切な値です']);

        $this->actingAs($user)
            ->post('/attendance/detail/'.$attendance->id, [
                'new_clock_in' => '09:00',
                'new_clock_out' => '18:00',
                'new_break_in' => ['19:00'],
                'new_break_out' => ['19:30'],
                'comment' => '修正',
            ])->assertSessionHasErrors([
                'new_break_in.0' => '休憩時間が不適切な値です',
                'new_break_out.0' => '休憩時間もしくは退勤時間が不適切な値です',
            ]);

        $this->actingAs($user)
            ->post('/attendance/detail/'.$attendance->id, [
                'new_clock_in' => '09:00',
                'new_clock_out' => '18:00',
                'comment' => '',
            ])->assertSessionHasErrors(['comment' => '備考を記入してください']);
    }

    public function test_correction_request_is_listed_and_can_be_approved(): void
    {
        $user = User::factory()->create(['name' => '申請太郎']);
        $admin = User::factory()->create(['role' => 2]);
        $attendance = $this->attendance($user);

        $this->actingAs($user)->post('/attendance/detail/'.$attendance->id, [
            'new_clock_in' => '10:00',
            'new_clock_out' => '19:00',
            'comment' => '打刻を修正',
        ])->assertRedirect('/attendance/detail/'.$attendance->id);

        $this->actingAs($user)
            ->get('/stamp_correction_request/list')
            ->assertOk()
            ->assertSee('承認待ち')
            ->assertSee('打刻を修正')
            ->assertSee(route('attendance.detail', $attendance->id), false);

        $requestId = $attendance->correctionRequests()->first()->id;

        $this->actingAs($admin)
            ->get('/stamp_correction_request/list')
            ->assertSee('申請太郎')
            ->assertSee('打刻を修正');

        $this->actingAs($admin)
            ->get('/stamp_correction_request/approve/'.$requestId)
            ->assertOk()
            ->assertSee('打刻を修正')
            ->assertSee('10:00');

        $this->actingAs($admin)
            ->post('/stamp_correction_request/approve/'.$requestId)
            ->assertRedirect('/stamp_correction_request/approve/'.$requestId);

        $attendance->refresh();
        $this->assertSame('10:00:00', Carbon::parse($attendance->clock_in)->format('H:i:s'));
        $this->assertSame('19:00:00', Carbon::parse($attendance->clock_out)->format('H:i:s'));
        $this->assertSame('打刻を修正', $attendance->remarks);
        $this->assertSame(1, (int) $attendance->correctionRequests()->first()->status);

        $this->actingAs($user)
            ->get('/stamp_correction_request/list')
            ->assertSee('承認済み');
    }

    private function attendance(User $user): Attendance
    {
        return Attendance::query()->create([
            'user_id' => $user->id,
            'date' => '2026-05-04',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);
    }
}
