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
     * user1 は採点用パターン、user2 は平日の実運用に近い勤怠を作る。
     */
    public function run(): void
    {
        $today = Carbon::now('Asia/Tokyo')->startOfDay();
        $user1 = User::query()->where('email', 'user1@example.com')->firstOrFail();
        $user2 = User::query()->where('email', 'user2@example.com')->firstOrFail();

        $this->seedUser1($user1, $today);
        $this->seedUser2($user2, $today);
    }

    /**
     * 過去5ヶ月は各月平日15日。当月は1日から17日までの17日に勤務パターンを順に割り当てる。
     */
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
        $currentMonthLimit = $today->copy()->startOfMonth()->day(17);

        while ($cursor->lte($currentMonthLimit) && $index < count($patterns)) {
            [$clockIn, $clockOut, $remarks] = $patterns[$index];
            $this->createAttendance($user, $cursor, $clockIn, $clockOut, $remarks);
            $index++;
            $cursor->addDay();
        }
    }

    /**
     * 過去5ヶ月の初日から本日までの平日。出勤時刻を日によって少しずらす。
     */
    private function seedUser2(User $user, Carbon $today): void
    {
        $shifts = [
            ['09:00:00', '18:00:00', '12:00:00', '13:00:00', '通常勤務'],
            ['08:50:00', '18:10:00', '12:00:00', '13:00:00', '通常勤務'],
            ['09:10:00', '18:20:00', '12:15:00', '13:05:00', '通常勤務'],
            ['09:00:00', '19:00:00', '12:00:00', '13:00:00', '残業'],
            ['09:20:00', '18:00:00', '12:00:00', '12:45:00', '遅刻'],
        ];

        $cursor = $today->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $index = 0;

        while ($cursor->lte($today)) {
            if ($cursor->isWeekday()) {
                [$clockIn, $clockOut, $breakIn, $breakOut, $remarks] = $shifts[$index % count($shifts)];
                $this->createAttendance($user, $cursor, $clockIn, $clockOut, $remarks, $breakIn, $breakOut);
                $index++;
            }

            $cursor->addDay();
        }
    }

    private function createAttendance(
        User $user,
        Carbon $date,
        string $clockIn,
        string $clockOut,
        string $remarks,
        string $breakIn = '12:00:00',
        string $breakOut = '13:00:00'
    ): void {
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
            'start_time' => $breakIn,
            'end_time' => $breakOut,
        ]);
    }
}
