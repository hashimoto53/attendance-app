<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceCorrectionRequest;
use App\Models\Attendance;
use App\Models\CorrectionRequest;
use App\Models\Rest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    /**
     * 打刻画面を表示する
     */
    public function index(): View
    {
        $now = $this->now();
        $attendance = $this->todayAttendance($now);
        $status = $attendance->status ?? Attendance::STATUS_OFF_DUTY;

        return view('attendance.index', [
            'attendance' => $attendance,
            'status' => $status,
            'statusLabel' => Attendance::STATUS_LABELS[$status],
            'formattedDate' => $this->formatDate($now),
            'formattedTime' => $now->format('H:i'),
            'canClockIn' => $status === Attendance::STATUS_OFF_DUTY,
            'canClockOut' => $status === Attendance::STATUS_WORKING,
            'canRestStart' => $status === Attendance::STATUS_WORKING,
            'canRestEnd' => $status === Attendance::STATUS_ON_BREAK,
        ]);
    }

    /**
     * 勤怠一覧画面を表示する
     */
    public function list(Request $request): View
    {
        $date = $this->resolveMonth($request->query('date'));

        $attendances = $this->user()
            ->attendances()
            ->with('rests')
            ->whereYear('date', $date->year)
            ->whereMonth('date', $date->month)
            ->orderBy('id')
            ->get()
            ->keyBy(function (Attendance $attendance): string {
                return Carbon::parse($attendance->date)->toDateString();
            });

        $formattedAttendanceRecords = collect(range(1, $date->daysInMonth))
            ->map(function (int $day) use ($date, $attendances): array {
                $current = $date->copy()->day($day);

                return $this->formatAttendanceRecord(
                    $current,
                    $attendances->get($current->toDateString())
                );
            });

        return view('user.user-attendance-list', [
            'date' => $date,
            'previousMonth' => $date->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $date->copy()->addMonthNoOverflow()->format('Y-m'),
            'formattedAttendanceRecords' => $formattedAttendanceRecords,
        ]);
    }

    /**
     * 勤怠詳細画面を表示する
     */
    public function detail(int $id): View
    {
        $attendance = $this->findOwnAttendance($id);
        $pending = $this->findPendingCorrection($attendance->id);
        $isPending = $pending !== null && (int) $pending->attendance_id === (int) $attendance->id;

        return view('user.user-detail', [
            'user' => $this->user(),
            'isPending' => $isPending,
            'data' => $this->formatDetail($attendance, $isPending ? $pending : null),
        ]);
    }

    /**
     * 勤怠の修正申請を登録する
     */
    public function requestCorrection(AttendanceCorrectionRequest $request, int $id): RedirectResponse
    {
        $attendance = $this->findOwnAttendance($id);

        if ($this->findPendingCorrection($attendance->id)) {
            return redirect()->route('attendance.detail', $attendance->id);
        }

        $attendance->correctionRequests()->create([
            'user_id' => $this->user()->id,
            'attendance_id' => $attendance->id,
            'date' => Carbon::parse($attendance->date)->toDateString(),
            'clock_in' => Carbon::parse($request->input('new_clock_in'))->format('H:i:s'),
            'clock_out' => Carbon::parse($request->input('new_clock_out'))->format('H:i:s'),
            'remarks' => $request->input('comment'),
            'status' => CorrectionRequest::STATUS_PENDING,
        ]);

        return redirect()->route('attendance.detail', $attendance->id);
    }

    /**
     * 出勤打刻
     */
    public function clockIn(): RedirectResponse
    {
        $user = $this->user();
        $now = $this->now();
        $attendance = $this->todayAttendance($now);

        if ($attendance && $attendance->status !== Attendance::STATUS_OFF_DUTY) {
            return redirect()->route('attendance.index');
        }

        DB::transaction(function () use ($user, $attendance, $now) {
            if ($attendance) {
                $attendance->update([
                    'clock_in' => $now->format('H:i:s'),
                    'status' => Attendance::STATUS_WORKING,
                ]);

                return;
            }

            $user->attendances()->create([
                'date' => $now->toDateString(),
                'clock_in' => $now->format('H:i:s'),
                'status' => Attendance::STATUS_WORKING,
            ]);
        });

        return redirect()->route('attendance.index');
    }

    /**
     * 退勤打刻
     */
    public function clockOut(): RedirectResponse
    {
        $now = $this->now();
        $attendance = $this->todayAttendance($now);

        if (! $attendance || $attendance->status !== Attendance::STATUS_WORKING) {
            return redirect()->route('attendance.index');
        }

        $attendance->update([
            'clock_out' => $now->format('H:i:s'),
            'status' => Attendance::STATUS_CLOCKED_OUT,
        ]);

        return redirect()->route('attendance.index');
    }

    /**
     * 休憩開始
     */
    public function restStart(): RedirectResponse
    {
        $now = $this->now();
        $attendance = $this->todayAttendance($now);

        if (! $attendance || $attendance->status !== Attendance::STATUS_WORKING) {
            return redirect()->route('attendance.index');
        }

        DB::transaction(function () use ($attendance, $now) {
            $attendance->rests()->create([
                'start_time' => $now->format('H:i:s'),
            ]);

            $attendance->update([
                'status' => Attendance::STATUS_ON_BREAK,
            ]);
        });

        return redirect()->route('attendance.index');
    }

    /**
     * 休憩終了
     */
    public function restEnd(): RedirectResponse
    {
        $now = $this->now();
        $attendance = $this->todayAttendance($now);

        if (! $attendance || $attendance->status !== Attendance::STATUS_ON_BREAK) {
            return redirect()->route('attendance.index');
        }

        DB::transaction(function () use ($attendance, $now) {
            $rest = $attendance->rests()
                ->whereNull('end_time')
                ->latest('id')
                ->first();

            if ($rest) {
                $rest->update([
                    'end_time' => $now->format('H:i:s'),
                ]);
            }

            $attendance->update([
                'status' => Attendance::STATUS_WORKING,
            ]);
        });

        return redirect()->route('attendance.index');
    }

    /**
     * 勤怠の基準時刻（日本時間）
     */
    private function now(): Carbon
    {
        return Carbon::now('Asia/Tokyo');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * ログインユーザー自身の勤怠を、休憩付きで取得する
     */
    private function findOwnAttendance(int $id): Attendance
    {
        return $this->user()
            ->attendances()
            ->with([
                'rests' => function ($query): void {
                    $query->orderBy('id');
                },
            ])
            ->findOrFail($id);
    }

    /**
     * この勤怠IDに承認待ちの修正申請がある場合だけ返す
     */
    private function findPendingCorrection(int $attendanceId): ?CorrectionRequest
    {
        return CorrectionRequest::query()
            ->where('attendance_id', $attendanceId)
            ->where('user_id', $this->user()->id)
            ->where('status', CorrectionRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * 詳細画面用の表示データ
     *
     * @return array<string, mixed>
     */
    private function formatDetail(Attendance $attendance, ?CorrectionRequest $pending): array
    {
        $date = Carbon::parse($attendance->date);

        $isPending = $pending !== null && (int) $pending->attendance_id === (int) $attendance->id;

        return [
            'id' => $attendance->id,
            'application' => $isPending ? $pending : null,
            'is_pending' => $isPending,
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
     * 当日の勤怠記録を取得する
     */
    private function todayAttendance(Carbon $now): ?Attendance
    {
        return $this->user()
            ->attendances()
            ->where('date', $now->toDateString())
            ->latest('id')
            ->first();
    }

    private function formatDate(Carbon $now): string
    {
        return $now->format('Y年n月j日').'('.$this->weekday($now).')';
    }

    /**
     * 一覧に表示する月を決める。未指定・不正値は今月。
     */
    private function resolveMonth(mixed $value): Carbon
    {
        $currentMonth = $this->now()->startOfMonth();

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
     * 1日分の表示用配列を作る。記録がない項目は空にする。
     */
    private function formatAttendanceRecord(Carbon $current, ?Attendance $attendance): array
    {
        $breakMinutes = $attendance ? $this->breakMinutes($attendance->rests) : 0;
        $workMinutes = null;

        if ($attendance?->clock_in && $attendance?->clock_out) {
            $workMinutes = max(
                0,
                Carbon::parse($attendance->clock_in)->diffInMinutes(Carbon::parse($attendance->clock_out)) - $breakMinutes
            );
        }

        return [
            'id' => $attendance?->id,
            'date' => $current->format('m/d').'('.$this->weekday($current).')',
            'clock_in' => $attendance?->clock_in ? Carbon::parse($attendance->clock_in)->format('H:i') : '',
            'clock_out' => $attendance?->clock_out ? Carbon::parse($attendance->clock_out)->format('H:i') : '',
            'total_break_time' => $this->formatDuration($breakMinutes),
            'total_time' => $this->formatDuration($workMinutes),
        ];
    }

    /**
     * 完了している休憩の合計分数
     *
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

    /**
     * 分数を Blade が時刻として解釈できる文字列にする。0分は空白。
     */
    private function formatDuration(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    private function weekday(Carbon $date): string
    {
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return $weekdays[$date->dayOfWeek];
    }
}
