<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendanceRecordPolicy
{
    /**
     * 管理者は更新・削除を許可する
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === 2) {
            return true;
        }

        return null;
    }

    /**
     * 本人の勤怠だけ更新できる
     */
    public function update(User $user, Attendance $attendanceRecord): bool
    {
        return $user->id === $attendanceRecord->user_id;
    }

    /**
     * 本人の勤怠だけ削除できる
     */
    public function delete(User $user, Attendance $attendanceRecord): bool
    {
        return $user->id === $attendanceRecord->user_id;
    }
}
