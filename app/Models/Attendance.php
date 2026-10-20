<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    /**
     * 一括割り当て可能な属性
     */
    protected $fillable = [
        'user_id',
        'date',
        'clock_in',
        'clock_out',
        'status',
        'remarks',
    ];

    /**
     * 勤怠記録は1人のユーザーに属する
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 勤怠記録は複数の休憩記録を持つ
     */
    public function rests()
    {
        return $this->hasMany(Rest::class);
    }

    /**
     * 勤怠記録は複数の修正申請を持つ
     */
    public function correctionRequests()
    {
        return $this->hasMany(CorrectionRequest::class);
    }
}