<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorrectionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'attendance_id',
        'date',
        'clock_in',
        'clock_out',
        'remarks',
        'status',
    ];

    /**
     * 修正申請は1人のユーザーに属する
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 修正申請は1つの勤怠記録に属する
     */
    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }
}