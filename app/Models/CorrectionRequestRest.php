<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectionRequestRest extends Model
{
    use HasFactory;

    protected $fillable = [
        'correction_request_id',
        'start_time',
        'end_time',
    ];

    /**
     * 申請上の休憩は1件の修正申請に属する。
     */
    public function correctionRequest(): BelongsTo
    {
        return $this->belongsTo(CorrectionRequest::class);
    }
}
