<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Rest;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. テスト用ユーザーの作成
        $user1 = User::create([
            'name' => 'テスト太郎（一般）',
            'email' => 'user1@example.com',
            'password' => Hash::make('password'),
            'role' => 1,
            'email_verified_at' => now(),
        ]);

        $user2 = User::create([
            'name' => 'テスト次郎（一般）',
            'email' => 'user2@example.com',
            'password' => Hash::make('password'),
            'role' => 1,
            'email_verified_at' => now(),
        ]);

        $user3 = User::create([
            'name' => '管理者花子',
            'email' => 'user3@example.com',
            'password' => Hash::make('password'),
            'role' => 2, // 管理者権限
            'email_verified_at' => now(),
        ]);

        // 2. user1 の過去勤怠データの生成（採点要件に合わせたダミーデータ）
        $now = Carbon::now('Asia/Tokyo');

        // 過去5ヶ月分の通常勤務データ生成
        for ($month = 5; $month >= 1; $month--) {
            $targetMonth = $now->copy()->subMonths($month);
            $daysInMonth = $targetMonth->daysInMonth;
            $count = 0;

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = $targetMonth->copy()->day($day);
                if ($date->isWeekday() && $count < 15) {
                    $attendance = Attendance::create([
                        'user_id' => $user1->id,
                        'date' => $date->format('Y-m-d'),
                        'clock_in' => '09:00:00',
                        'clock_out' => '18:00:00',
                        'status' => 3, // 退勤済
                        'remarks' => '通常勤務',
                    ]);

                    Rest::create([
                        'attendance_id' => $attendance->id,
                        'start_time' => '12:00:00',
                        'end_time' => '13:00:00',
                    ]);

                    $count++;
                }
            }
        }

        // 当月分のデータ生成
        $currentMonthDays = [
            // 通常10日
            ['clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'remark' => '通常勤務', 'count' => 10],
            // 残業3日
            ['clock_in' => '09:00:00', 'clock_out' => '20:00:00', 'remark' => '残業', 'count' => 3],
            // 遅刻2日
            ['clock_in' => '09:30:00', 'clock_out' => '18:00:00', 'remark' => '遅刻', 'count' => 2],
            // 早退1日
            ['clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'remark' => '早退', 'count' => 1],
            // 長時間労働1日
            ['clock_in' => '08:00:00', 'clock_out' => '21:00:00', 'remark' => '長時間労働', 'count' => 1],
        ];

        $dayIndex = 1;
        foreach ($currentMonthDays as $pattern) {
            for ($i = 0; $i < $pattern['count']; $i++) {
                while ($now->copy()->day($dayIndex)->isWeekend()) {
                    $dayIndex++;
                }
                $date = $now->copy()->day($dayIndex);

                $attendance = Attendance::create([
                    'user_id' => $user1->id,
                    'date' => $date->format('Y-m-d'),
                    'clock_in' => $pattern['clock_in'],
                    'clock_out' => $pattern['clock_out'],
                    'status' => 3,
                    'remarks' => $pattern['remark'],
                ]);

                Rest::create([
                    'attendance_id' => $attendance->id,
                    'start_time' => '12:00:00',
                    'end_time' => '13:00:00',
                ]);

                $dayIndex++;
            }
        }
    }
}