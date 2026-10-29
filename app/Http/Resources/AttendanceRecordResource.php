<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class AttendanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'user' => new UserResource($this->whenLoaded('user')),
            'date' => Carbon::parse($this->date)->toDateString(),
            'clock_in' => $this->formatTime($this->clock_in),
            'clock_out' => $this->formatTime($this->clock_out),
            'total_time' => $this->formatDuration($this->workMinutes()),
            'total_break_time' => $this->formatDuration($this->breakMinutes()),
            'comment' => $this->remarks,
            'breaks' => AttendanceBreakResource::collection($this->whenLoaded('breaks')),
            'applications' => ApplicationResource::collection($this->whenLoaded('applications')),
        ];
    }

    private function workMinutes(): ?int
    {
        if (! $this->clock_in || ! $this->clock_out) {
            return null;
        }

        $span = $this->toMinutes($this->clock_out) - $this->toMinutes($this->clock_in);

        return max(0, $span - $this->breakMinutes());
    }

    private function breakMinutes(): int
    {
        return (int) $this->loadedBreaks()->sum(function ($rest): int {
            if (! $rest->start_time || ! $rest->end_time) {
                return 0;
            }

            return max(0, $this->toMinutes($rest->end_time) - $this->toMinutes($rest->start_time));
        });
    }

    private function loadedBreaks(): Collection
    {
        if ($this->relationLoaded('breaks')) {
            return $this->breaks;
        }

        if ($this->relationLoaded('rests')) {
            return $this->rests;
        }

        return collect();
    }

    private function toMinutes(mixed $time): int
    {
        $parsed = Carbon::parse($time);

        return ($parsed->hour * 60) + $parsed->minute;
    }

    private function formatDuration(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function formatTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return Carbon::parse($time)->format('H:i:s');
    }
}
