<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Rest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AdminAttendanceController extends Controller
{
    /**
     * 管理者の日次勤怠一覧を表示する
     */
    public function list(Request $request): View
    {
        $date = $this->resolveDate($request->query('date'));

        $attendanceRecords = Attendance::query()
            ->with('rests')
            ->where('date', $date->toDateString())
            ->orderBy('user_id')
            ->get()
            ->each(function (Attendance $attendance): void {
                $breakMinutes = $this->breakMinutes($attendance->rests);
                $attendance->setAttribute('total_break_time', $this->formatDuration($breakMinutes));
                $attendance->setAttribute('total_time', $this->formatDuration($this->workMinutes($attendance, $breakMinutes)));
            });

        $users = User::query()
            ->whereIn('id', $attendanceRecords->pluck('user_id')->unique()->all())
            ->orderBy('id')
            ->get();

        return view('admin.admin-attendance-list', [
            'date' => $date,
            'previousDay' => $date->copy()->subDay()->toDateString(),
            'nextDay' => $date->copy()->addDay()->toDateString(),
            'users' => $users,
            'attendanceRecords' => $attendanceRecords,
        ]);
    }

    /**
     * 表示日。未指定・不正値は本日（日本時間）。
     */
    private function resolveDate(mixed $value): Carbon
    {
        $today = Carbon::now('Asia/Tokyo')->startOfDay();

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $today;
        }

        try {
            return Carbon::parse($value, 'Asia/Tokyo')->startOfDay();
        } catch (\Exception $exception) {
            return $today;
        }
    }

    /**
     * @param  Collection<int, Rest>  $rests
     */
    private function breakMinutes(Collection $rests): int
    {
        return (int) $rests->sum(function (Rest $rest): int {
            if (! $rest->start_time || ! $rest->end_time) {
                return 0;
            }

            return Carbon::parse($rest->start_time)->diffInMinutes(Carbon::parse($rest->end_time));
        });
    }

    private function workMinutes(Attendance $attendance, int $breakMinutes): ?int
    {
        if (! $attendance->clock_in || ! $attendance->clock_out) {
            return null;
        }

        return max(
            0,
            Carbon::parse($attendance->clock_in)->diffInMinutes(Carbon::parse($attendance->clock_out)) - $breakMinutes
        );
    }

    /**
     * 0分は空白。Blade が時刻として読める文字列を返す。
     */
    private function formatDuration(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
