<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'date' => Carbon::parse($this->date)->toDateString(),
            'clock_in' => $this->formatTime($this->clock_in),
            'clock_out' => $this->formatTime($this->clock_out),
            'comment' => $this->remarks,
            'status' => $this->status,
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
