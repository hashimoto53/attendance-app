<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
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
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return $now->format('Y年n月j日').'('.$weekdays[$now->dayOfWeek].')';
    }
}
