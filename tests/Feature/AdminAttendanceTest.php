<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_daily_list_shows_today_and_adjacent_days(): void
    {
        $admin = User::factory()->create(['role' => 2]);
        $staff = User::factory()->create(['name' => '一般花子']);
        $today = Carbon::now('Asia/Tokyo')->startOfDay();
        Attendance::query()->create([
            'user_id' => $staff->id,
            'date' => $today->toDateString(),
            'clock_in' => '09:15:00',
            'clock_out' => '18:15:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);

        $this->actingAs($admin)
            ->get('/admin/attendance/list')
            ->assertOk()
            ->assertSee($today->format('Y/m/d'))
            ->assertSee('一般花子')
            ->assertSee('09:15')
            ->assertSee('18:15');

        $previous = $today->copy()->subDay()->toDateString();
        $next = $today->copy()->addDay()->toDateString();

        $this->actingAs($admin)
            ->get('/admin/attendance/list?date='.$previous)
            ->assertSee($today->copy()->subDay()->format('Y/m/d'));

        $this->actingAs($admin)
            ->get('/admin/attendance/list?date='.$next)
            ->assertSee($today->copy()->addDay()->format('Y/m/d'));
    }

    public function test_admin_can_open_detail_and_sees_validation_messages(): void
    {
        $admin = User::factory()->create(['role' => 2]);
        $staff = User::factory()->create(['name' => '修正対象']);
        $attendance = Attendance::query()->create([
            'user_id' => $staff->id,
            'date' => '2026-05-06',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);

        $this->actingAs($admin)
            ->get('/admin/attendance/'.$attendance->id)
            ->assertOk()
            ->assertSee('修正対象')
            ->assertSee('09:00')
            ->assertSee('18:00');

        $this->actingAs($admin)
            ->from('/admin/attendance/'.$attendance->id)
            ->post('/admin/attendance/'.$attendance->id, [
                'new_clock_in' => '19:00',
                'new_clock_out' => '09:00',
                'comment' => '管理者修正',
            ])->assertSessionHasErrors(['new_clock_in' => '出勤時間もしくは退勤時間が不適切な値です']);

        $this->actingAs($admin)
            ->from('/admin/attendance/'.$attendance->id)
            ->post('/admin/attendance/'.$attendance->id, [
                'new_clock_in' => '09:00',
                'new_clock_out' => '18:00',
                'new_break_in' => ['19:00'],
                'new_break_out' => ['20:00'],
                'comment' => '管理者修正',
            ])->assertSessionHasErrors([
                'new_break_in.0' => '休憩時間が不適切な値です',
                'new_break_out.0' => '休憩時間もしくは退勤時間が不適切な値です',
            ]);

        $this->actingAs($admin)
            ->from('/admin/attendance/'.$attendance->id)
            ->post('/admin/attendance/'.$attendance->id, [
                'new_clock_in' => '09:00',
                'new_clock_out' => '18:00',
                'comment' => '',
            ])->assertSessionHasErrors(['comment' => '備考を記入してください']);
    }

    public function test_staff_list_and_monthly_navigation(): void
    {
        $admin = User::factory()->create(['role' => 2, 'name' => '管理者']);
        $staff = User::factory()->create([
            'name' => 'スタッフ太郎',
            'email' => 'staff@example.com',
        ]);
        $month = Carbon::now('Asia/Tokyo')->startOfMonth();
        $attendance = Attendance::query()->create([
            'user_id' => $staff->id,
            'date' => $month->toDateString(),
            'clock_in' => '08:30:00',
            'clock_out' => '17:30:00',
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);

        $this->actingAs($admin)
            ->get('/admin/staff/list')
            ->assertOk()
            ->assertSee('スタッフ太郎')
            ->assertSee('staff@example.com')
            ->assertDontSee('管理者');

        $previous = $month->copy()->subMonthNoOverflow()->format('Y-m');
        $next = $month->copy()->addMonthNoOverflow()->format('Y-m');

        $this->actingAs($admin)
            ->get('/admin/attendance/staff/'.$staff->id)
            ->assertOk()
            ->assertSee($month->format('Y/m'))
            ->assertSee('08:30')
            ->assertSee(route('admin.attendance.detail', $attendance->id), false);

        $this->actingAs($admin)
            ->get('/admin/attendance/staff/'.$staff->id.'?date='.$previous)
            ->assertSee($month->copy()->subMonthNoOverflow()->format('Y/m'));

        $this->actingAs($admin)
            ->get('/admin/attendance/staff/'.$staff->id.'?date='.$next)
            ->assertSee($month->copy()->addMonthNoOverflow()->format('Y/m'));
    }

    public function test_general_user_cannot_open_admin_pages(): void
    {
        $user = User::factory()->create(['role' => 1]);

        $this->actingAs($user)->get('/admin/staff/list')->assertForbidden();
    }
}
