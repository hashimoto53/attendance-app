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
        $this->createUser('テスト太郎（一般）', 'user1@example.com', 1);
        $this->createUser('テスト次郎（一般）', 'user2@example.com', 1);
        $this->createUser('管理者花子', 'user3@example.com', 2);

        $this->call(AttendanceSeeder::class);
    }

    private function createUser(string $name, string $email, int $role): void
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();
    }
}
