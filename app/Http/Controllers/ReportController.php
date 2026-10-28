<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Rest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ReportController extends Controller
{
    private const STANDARD_START_MINUTES = 9 * 60;

    private const STANDARD_END_MINUTES = 18 * 60;

    private const STANDARD_WORK_MINUTES = 8 * 60;

    private const LONG_WORK_MINUTES = 10 * 60;

    /**
     * ログインユーザーの過去6ヶ月の勤怠レポートを表示する。
     */
    public function index(Request $request): View
    {
        $endMonth = $this->resolveMonth($request->query('date'));
        /** @var User $user */
        $user = Auth::user();

        $daily = $this->dailyRecords($user, $endMonth);
        $months = $this->monthKeys($endMonth);
        $byMonth = $daily->groupBy('month');

        $monthlyTrend = $months->map(function (string $month) use ($byMonth): array {
            $rows = $byMonth->get($month, collect());

            return [
                'month' => $month,
                'work_minutes' => (int) $rows->sum('work_minutes'),
                'overtime_minutes' => (int) $rows->sum('overtime_minutes'),
            ];
        })->values();

        $worked = $daily->where('has_work', true);
        $totalWork = (int) $worked->sum('work_minutes');
        $currentMonth = $byMonth->get($endMonth->format('Y-m'), collect());

        return view('reports.index', [
            'date' => $endMonth,
            'previousMonth' => $endMonth->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $endMonth->copy()->addMonthNoOverflow()->format('Y-m'),
            'summary' => [
                'total_work_minutes' => $totalWork,
                'total_overtime_minutes' => (int) $worked->sum('overtime_minutes'),
                'avg_work_minutes' => $worked->isEmpty() ? 0 : intdiv($totalWork, $worked->count()),
                'work_day_count' => $worked->count(),
            ],
            'monthlyTrend' => $monthlyTrend,
            'anomalies' => [
                'late_count' => $currentMonth->where('is_late', true)->count(),
                'early_leave_count' => $currentMonth->where('is_early_leave', true)->count(),
                'long_work_count' => $currentMonth->where('is_long_work', true)->count(),
            ],
        ]);
    }

    /**
     * 集計対象の終了月。未指定・不正値は今月（日本時間）。
     */
    private function resolveMonth(mixed $value): Carbon
    {
        $currentMonth = Carbon::now('Asia/Tokyo')->startOfMonth();

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $currentMonth;
        }

        try {
            $month = Carbon::createFromFormat('!Y-m', $value, 'Asia/Tokyo');

            return $month === false ? $currentMonth : $month->startOfMonth();
        } catch (\Exception $exception) {
            return $currentMonth;
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function monthKeys(Carbon $endMonth): Collection
    {
        return collect(range(0, 5))->map(function (int $offset) use ($endMonth): string {
            return $endMonth->copy()->subMonthsNoOverflow(5 - $offset)->format('Y-m');
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function dailyRecords(User $user, Carbon $endMonth): Collection
    {
        $start = $endMonth->copy()->subMonthsNoOverflow(5)->startOfMonth();

        return $user->attendances()
            ->with('rests')
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $endMonth->copy()->endOfMonth()->toDateString())
            ->get()
            ->map(function (Attendance $attendance): array {
                return $this->summarizeDay($attendance);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeDay(Attendance $attendance): array
    {
        $clockIn = $this->toMinutes($attendance->clock_in);
        $clockOut = $this->toMinutes($attendance->clock_out);
        $breakMinutes = $this->breakMinutes($attendance->rests);
        $workMinutes = null;

        if ($clockIn !== null && $clockOut !== null && $clockOut >= $clockIn) {
            $workMinutes = max(0, $clockOut - $clockIn - $breakMinutes);
        }

        return [
            'month' => Carbon::parse($attendance->date)->format('Y-m'),
            'has_work' => $workMinutes !== null,
            'work_minutes' => $workMinutes ?? 0,
            'overtime_minutes' => $workMinutes === null ? 0 : max(0, $workMinutes - self::STANDARD_WORK_MINUTES),
            'is_late' => $clockIn !== null && $clockIn > self::STANDARD_START_MINUTES,
            'is_early_leave' => $clockOut !== null && $clockOut < self::STANDARD_END_MINUTES,
            'is_long_work' => $workMinutes !== null && $workMinutes > self::LONG_WORK_MINUTES,
        ];
    }

    /**
     * @param  Collection<int, Rest>  $rests
     */
    private function breakMinutes(Collection $rests): int
    {
        return (int) $rests->sum(function (Rest $rest): int {
            $start = $this->toMinutes($rest->start_time);
            $end = $this->toMinutes($rest->end_time);

            if ($start === null || $end === null || $end < $start) {
                return 0;
            }

            return $end - $start;
        });
    }

    private function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}
