<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CorrectionRequest extends Model
{
    use HasFactory;

    /** 承認待ち */
    public const STATUS_PENDING = 0;

    /** 承認済み */
    public const STATUS_APPROVED = 1;

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
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'integer',
    ];

    /**
     * 修正申請は1人のユーザーに属する。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 修正申請は1つの勤怠記録に属する。
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id', 'id');
    }

    /**
     * 修正申請に含まれる休憩の変更案。
     */
    public function proposedRests(): HasMany
    {
        return $this->hasMany(CorrectionRequestRest::class)->orderBy('id');
    }

    /**
     * フォームの new_break_in / new_break_out を申請の休憩として保存する。
     * 両方空の行は休憩なしとして除く。
     *
     * @param  array<int|string, mixed>  $breakIns
     * @param  array<int|string, mixed>  $breakOuts
     */
    public function storeProposedBreaks(array $breakIns, array $breakOuts): void
    {
        collect(array_unique(array_merge(array_keys($breakIns), array_keys($breakOuts))))
            ->sortBy(fn (int|string $index): int => (int) $index)
            ->each(function (int|string $index) use ($breakIns, $breakOuts): void {
                $start = trim((string) ($breakIns[$index] ?? ''));
                $end = trim((string) ($breakOuts[$index] ?? ''));

                if ($start === '' && $end === '') {
                    return;
                }

                $this->proposedRests()->create([
                    'start_time' => Carbon::parse($start)->format('H:i:s'),
                    'end_time' => Carbon::parse($end)->format('H:i:s'),
                ]);
            });
    }

    /**
     * 承認時に、勤怠の休憩を申請内容で置き換える。
     */
    public function applyProposedBreaks(): void
    {
        $attendance = $this->attendance;
        $attendance->rests()->delete();

        $this->proposedRests->each(function (CorrectionRequestRest $rest) use ($attendance): void {
            $attendance->rests()->create([
                'start_time' => Carbon::parse($rest->start_time)->format('H:i:s'),
                'end_time' => Carbon::parse($rest->end_time)->format('H:i:s'),
            ]);
        });
    }

    /**
     * 一覧表示用の承認状態
     */
    public function getApprovalStatusAttribute(): string
    {
        return $this->status === self::STATUS_APPROVED ? '承認済み' : '承認待ち';
    }

    /**
     * 一覧の申請理由。実カラムは remarks
     */
    public function getCommentAttribute(): string
    {
        return $this->remarks ?? '';
    }

    /**
     * 一覧の申請日。登録日時を使う
     */
    public function getApplicationDateAttribute(): mixed
    {
        return $this->created_at;
    }

    /**
     * 承認画面の対象日
     */
    public function getNewDateAttribute(): Carbon
    {
        return Carbon::parse($this->attributes['date']);
    }

    /**
     * 承認画面の出勤時刻
     */
    public function getNewClockInAttribute(): string
    {
        return $this->formatClock($this->clock_in);
    }

    /**
     * 承認画面の退勤時刻
     */
    public function getNewClockOutAttribute(): string
    {
        return $this->formatClock($this->clock_out);
    }

    /**
     * 承認画面の休憩。申請に保存した変更案を表示する
     */
    public function getProposalBreaksAttribute(): Collection
    {
        return $this->proposedRests
            ->map(function (CorrectionRequestRest $rest): object {
                return (object) [
                    'break_in' => $rest->start_time,
                    'break_out' => $rest->end_time,
                ];
            })
            ->values();
    }

    private function formatClock(mixed $time): string
    {
        if (! is_string($time) || $time === '') {
            return '';
        }

        return Carbon::parse($time)->format('H:i');
    }
}