<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $names = ['太郎', '次郎', '三郎', '四郎', '五郎', '六郎'];

        foreach ($names as $index => $name) {
            $number = $index + 1;

            $user = User::create([
                'name' => 'テスト'.$name.'（一般）',
                'email' => 'user'.$number.'@example.com',
                'password' => Hash::make('password'),
                'role' => 1,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();
        }

        $admin = User::create([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 2,
        ]);

        $admin->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $this->call(AttendanceSeeder::class);
    }
}
