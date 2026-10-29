<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceBreakResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'break_in' => $this->formatTime($this->start_time),
            'break_out' => $this->formatTime($this->end_time),
        ];
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return Carbon::parse($time)->format('H:i:s');
    }
}
