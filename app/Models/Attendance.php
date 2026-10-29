<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    /** 勤務外 */
    public const STATUS_OFF_DUTY = 0;

    /** 出勤中 */
    public const STATUS_WORKING = 1;

    /** 休憩中 */
    public const STATUS_ON_BREAK = 2;

    /** 退勤済 */
    public const STATUS_CLOCKED_OUT = 3;

    /**
     * 画面表示用のステータスラベル
     */
    public const STATUS_LABELS = [
        self::STATUS_OFF_DUTY => '勤務外',
        self::STATUS_WORKING => '出勤中',
        self::STATUS_ON_BREAK => '休憩中',
        self::STATUS_CLOCKED_OUT => '退勤済',
    ];

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
     * 属性の型
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'integer',
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
     * 公開APIの休憩。実体は rests
     */
    public function breaks()
    {
        return $this->hasMany(Rest::class);
    }

    /**
     * 勤怠記録は複数の修正申請を持つ
     */
    public function correctionRequests()
    {
        return $this->hasMany(CorrectionRequest::class, 'attendance_id', 'id');
    }

    /**
     * 公開APIの修正申請。実体は correction_requests
     */
    public function applications()
    {
        return $this->hasMany(CorrectionRequest::class, 'attendance_id', 'id');
    }
}