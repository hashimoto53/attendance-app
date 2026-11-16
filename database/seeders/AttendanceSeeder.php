<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Rest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    /**
     * 一般ユーザーの平日勤怠と休憩を作る。
     * user1 はレポート採点用のパターン、それ以外は過去5ヶ月の全平日。
     */
    public function run(): void
    {
        $today = Carbon::now('Asia/Tokyo')->startOfDay();
        $users = User::query()->where('role', 1)->orderBy('id')->get();

        $users->each(function (User $user) use ($today): void {
            if ($user->email === 'user1@example.com') {
                $this->seedUser1($user, $today);

                return;
            }

            $this->seedWeekdays(
                $user,
                $today->copy()->subMonthsNoOverflow(5)->startOfMonth(),
                $today,
                '09:00:00',
                '18:00:00',
                '通常勤務'
            );
        });
    }

    private function seedUser1(User $user, Carbon $today): void
    {
        for ($monthsAgo = 5; $monthsAgo >= 1; $monthsAgo--) {
            $month = $today->copy()->subMonthsNoOverflow($monthsAgo)->startOfMonth();
            $created = 0;
            $cursor = $month->copy();

            while ($cursor->lte($month->copy()->endOfMonth()) && $created < 15) {
                if ($cursor->isWeekday()) {
                    $this->createAttendance($user, $cursor, '09:00:00', '18:00:00', '通常勤務');
                    $created++;
                }

                $cursor->addDay();
            }
        }

        $patterns = array_merge(
            array_fill(0, 10, ['09:00:00', '18:00:00', '通常勤務']),
            array_fill(0, 3, ['09:00:00', '20:00:00', '残業']),
            array_fill(0, 2, ['09:30:00', '18:00:00', '遅刻']),
            [['09:00:00', '17:00:00', '早退']],
            [['08:00:00', '21:00:00', '長時間労働']]
        );

        $index = 0;
        $cursor = $today->copy()->startOfMonth();

        while ($cursor->lte($today) && $index < count($patterns)) {
            if ($cursor->isWeekday()) {
                [$clockIn, $clockOut, $remarks] = $patterns[$index];
                $this->createAttendance($user, $cursor, $clockIn, $clockOut, $remarks);
                $index++;
            }

            $cursor->addDay();
        }

        $this->seedUser1LateOctober($user);
    }

    /**
     * 2026年10月23日〜31日の平日。月15日までの作成では入らない日。
     */
    private function seedUser1LateOctober(User $user): void
    {
        $dates = [
            '2026-10-23',
            '2026-10-26',
            '2026-10-27',
            '2026-10-28',
            '2026-10-29',
            '2026-10-30',
        ];

        foreach ($dates as $date) {
            $day = Carbon::parse($date, 'Asia/Tokyo')->startOfDay();

            $exists = Attendance::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $day->toDateString())
                ->exists();

            if ($exists) {
                continue;
            }

            $this->createAttendance($user, $day, '09:00:00', '18:00:00', '通常勤務');
        }
    }

    private function seedWeekdays(User $user, Carbon $from, Carbon $to, string $clockIn, string $clockOut, string $remarks): void
    {
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            if ($cursor->isWeekday()) {
                $this->createAttendance($user, $cursor, $clockIn, $clockOut, $remarks);
            }

            $cursor->addDay();
        }
    }

    private function createAttendance(User $user, Carbon $date, string $clockIn, string $clockOut, string $remarks): void
    {
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => $date->toDateString(),
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'status' => Attendance::STATUS_CLOCKED_OUT,
            'remarks' => $remarks,
        ]);

        Rest::create([
            'attendance_id' => $attendance->id,
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);
    }
}
