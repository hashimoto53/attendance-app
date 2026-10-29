<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceCorrectionRequest;
use App\Models\Attendance;
use App\Models\Rest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * 管理者の勤怠詳細を表示する
     */
    public function detail(int $id): View
    {
        $attendance = $this->findAttendance($id);

        return view('admin.admin-detail', [
            'user' => $attendance->user,
            'attendanceRecord' => $this->formatDetail($attendance),
        ]);
    }

    /**
     * 管理者として勤怠を直接修正する
     */
    public function update(AttendanceCorrectionRequest $request, int $id): RedirectResponse
    {
        $attendance = $this->findAttendance($id);

        DB::transaction(function () use ($attendance, $request): void {
            $attendance->update([
                'clock_in' => Carbon::parse($request->input('new_clock_in'))->format('H:i:s'),
                'clock_out' => Carbon::parse($request->input('new_clock_out'))->format('H:i:s'),
                'remarks' => $request->input('comment'),
                'status' => Attendance::STATUS_CLOCKED_OUT,
            ]);

            $attendance->rests()->delete();

            $breakIns = $request->input('new_break_in', []);
            $breakOuts = $request->input('new_break_out', []);
            $indexes = collect(array_unique(array_merge(
                is_array($breakIns) ? array_keys($breakIns) : [],
                is_array($breakOuts) ? array_keys($breakOuts) : []
            )))->sort()->values();

            $indexes->each(function (int|string $index) use ($attendance, $breakIns, $breakOuts): void {
                $start = is_array($breakIns) ? trim((string) ($breakIns[$index] ?? '')) : '';
                $end = is_array($breakOuts) ? trim((string) ($breakOuts[$index] ?? '')) : '';

                if ($start === '' && $end === '') {
                    return;
                }

                $attendance->rests()->create([
                    'start_time' => Carbon::parse($start)->format('H:i:s'),
                    'end_time' => Carbon::parse($end)->format('H:i:s'),
                ]);
            });
        });

        return redirect()->route('admin.attendance.detail', $attendance->id);
    }

    /**
     * 一般ユーザーの一覧を表示する
     */
    public function staffList(): View
    {
        $users = User::query()
            ->where('role', 1)
            ->orderBy('id')
            ->get();

        return view('admin.staff-list', [
            'users' => $users,
        ]);
    }

    /**
     * 指定した一般ユーザーの月次勤怠を表示する
     */
    public function staffAttendance(Request $request, int $id): View
    {
        $user = User::query()->where('role', 1)->findOrFail($id);
        $date = $this->resolveMonth($request->query('date'));

        $formattedAttendanceRecords = $this->monthlyAttendanceRows($user, $date);

        return view('admin.staff-attendance-list', [
            'user' => $user,
            'date' => $date,
            'previousMonth' => $date->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $date->copy()->addMonthNoOverflow()->format('Y-m'),
            'formattedAttendanceRecords' => $formattedAttendanceRecords,
        ]);
    }

    /**
     * 指定スタッフ・指定月の勤怠を CSV でダウンロードする
     */
    public function export(Request $request): StreamedResponse
    {
        $user = User::query()
            ->where('role', 1)
            ->findOrFail($request->integer('user_id'));
        $date = $this->resolveMonth($request->input('year_month'));
        $rows = $this->monthlyAttendanceRows($user, $date)
            ->filter(function (array $row): bool {
                return ! empty($row['id']);
            })
            ->values();

        $filename = sprintf('attendance_%d_%s.csv', $user->id, $date->format('Y-m'));

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['日付', '出勤', '退勤', '休憩', '合計', '備考']);

            $rows->each(function (array $row) use ($handle): void {
                fputcsv($handle, [
                    $row['date'],
                    $row['clock_in'],
                    $row['clock_out'],
                    $this->formatClock($row['total_break_time']),
                    $this->formatClock($row['total_time']),
                    $row['remarks'],
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function monthlyAttendanceRows(User $user, Carbon $date): Collection
    {
        $attendances = $user->attendances()
            ->with('rests')
            ->whereYear('date', $date->year)
            ->whereMonth('date', $date->month)
            ->orderBy('id')
            ->get()
            ->keyBy(function (Attendance $attendance): string {
                return Carbon::parse($attendance->date)->toDateString();
            });

        return collect(range(1, $date->daysInMonth))
            ->map(function (int $day) use ($date, $attendances): array {
                $current = $date->copy()->day($day);

                return $this->formatAttendanceRecord(
                    $current,
                    $attendances->get($current->toDateString())
                );
            });
    }

    private function formatClock(?string $duration): string
    {
        if ($duration === null || $duration === '') {
            return '';
        }

        return Carbon::parse($duration)->format('G:i');
    }

    private function findAttendance(int $id): Attendance
    {
        return Attendance::query()
            ->with([
                'user',
                'rests' => function ($query): void {
                    $query->orderBy('id');
                },
            ])
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(Attendance $attendance): array
    {
        $date = Carbon::parse($attendance->date);

        return [
            'id' => $attendance->id,
            'year' => $date->format('Y年'),
            'date' => $date->format('n月j日'),
            'clock_in' => $this->formatTime($attendance->clock_in),
            'clock_out' => $this->formatTime($attendance->clock_out),
            'breaks' => $attendance->rests->map(function (Rest $rest): array {
                return [
                    'break_in' => $this->formatTime($rest->start_time),
                    'break_out' => $this->formatTime($rest->end_time),
                ];
            })->all(),
            'comment' => $attendance->remarks ?? '',
        ];
    }

    private function formatTime(mixed $time): string
    {
        if (! is_string($time) || $time === '') {
            return '';
        }

        return Carbon::parse($time)->format('H:i');
    }

    /**
     * 表示月。未指定・不正値は今月（日本時間）。
     */
    private function resolveMonth(mixed $value): Carbon
    {
        $currentMonth = Carbon::now('Asia/Tokyo')->startOfMonth();

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $value)) {
            return $currentMonth;
        }

        try {
            return Carbon::parse($value, 'Asia/Tokyo')->startOfMonth();
        } catch (\Exception $exception) {
            return $currentMonth;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAttendanceRecord(Carbon $current, ?Attendance $attendance): array
    {
        $breakMinutes = $attendance ? $this->breakMinutes($attendance->rests) : 0;
        $workMinutes = $attendance ? $this->workMinutes($attendance, $breakMinutes) : null;

        return [
            'id' => $attendance?->id,
            'date' => $current->format('m/d').'('.$this->weekday($current).')',
            'clock_in' => $attendance?->clock_in ? Carbon::parse($attendance->clock_in)->format('H:i') : '',
            'clock_out' => $attendance?->clock_out ? Carbon::parse($attendance->clock_out)->format('H:i') : '',
            'total_break_time' => $this->formatDuration($breakMinutes),
            'total_time' => $this->formatDuration($workMinutes),
            'remarks' => $attendance?->remarks ?? '',
        ];
    }

    private function weekday(Carbon $date): string
    {
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return $weekdays[$date->dayOfWeek];
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
