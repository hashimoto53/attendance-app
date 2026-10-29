<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/attendance/report')->assertRedirect('/login');
    }

    public function test_report_summarizes_the_signed_in_users_attendance(): void
    {
        $user = User::factory()->create();
        $month = Carbon::now('Asia/Tokyo')->startOfMonth();

        $this->workedDay($user, $month->copy()->day(1), '09:00:00', '18:00:00');
        $this->workedDay($user, $month->copy()->day(2), '09:30:00', '18:00:00');
        $this->workedDay($user, $month->copy()->day(3), '09:00:00', '17:00:00');
        $this->workedDay($user, $month->copy()->day(4), '08:00:00', '21:00:00');

        $response = $this->actingAs($user)->get('/attendance/report');

        $response->assertOk();
        $response->assertViewHas('summary', function (array $summary): bool {
            return $summary['total_work_minutes'] === 2070
                && $summary['total_overtime_minutes'] === 240
                && $summary['avg_work_minutes'] === 517;
        });
        $response->assertViewHas('monthlyTrend', function ($trend) use ($month): bool {
            $current = collect($trend)->firstWhere('month', $month->format('Y-m'));

            return count($trend) === 6
                && $current['work_minutes'] === 2070
                && $current['overtime_minutes'] === 240;
        });
        $response->assertViewHas('anomalies', [
            'late_count' => 1,
            'early_leave_count' => 1,
            'long_work_count' => 1,
        ]);
    }

    public function test_report_is_safe_when_the_user_has_no_attendance(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/attendance/report');

        $response->assertOk();
        $response->assertViewHas('summary', [
            'total_work_minutes' => 0,
            'total_overtime_minutes' => 0,
            'avg_work_minutes' => 0,
            'work_day_count' => 0,
        ]);
        $response->assertViewHas('monthlyTrend', function ($trend): bool {
            return count($trend) === 6
                && collect($trend)->every(fn (array $row): bool => $row['work_minutes'] === 0 && $row['overtime_minutes'] === 0);
        });
        $response->assertViewHas('anomalies', [
            'late_count' => 0,
            'early_leave_count' => 0,
            'long_work_count' => 0,
        ]);
    }

    private function workedDay(User $user, Carbon $date, string $clockIn, string $clockOut): void
    {
        $attendance = Attendance::query()->create([
            'user_id' => $user->id,
            'date' => $date->toDateString(),
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);
        $attendance->rests()->create([
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);
    }
}
