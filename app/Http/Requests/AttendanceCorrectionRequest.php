<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_clock_in' => ['required', 'string'],
            'new_clock_out' => ['required', 'string'],
            'new_break_in' => ['nullable', 'array'],
            'new_break_out' => ['nullable', 'array'],
            'new_break_in.*' => ['nullable', 'string'],
            'new_break_out.*' => ['nullable', 'string'],
            'comment' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_clock_in.required' => '出勤時間もしくは退勤時間が不適切な値です',
            'new_clock_out.required' => '出勤時間もしくは退勤時間が不適切な値です',
            'comment.required' => '備考を記入してください',
            'comment.max' => '備考は255文字以内で入力してください',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateClockOrder($validator);
            $this->validateBreaks($validator);
        });
    }

    private function validateClockOrder(Validator $validator): void
    {
        $clockIn = $this->toMinutes($this->input('new_clock_in'));
        $clockOut = $this->toMinutes($this->input('new_clock_out'));

        if ($this->filled('new_clock_in') && $clockIn === null) {
            $validator->errors()->add('new_clock_in', '出勤時間もしくは退勤時間が不適切な値です');
        }

        if ($this->filled('new_clock_out') && $clockOut === null) {
            $validator->errors()->add('new_clock_out', '出勤時間もしくは退勤時間が不適切な値です');
        }

        if ($clockIn !== null && $clockOut !== null && $clockIn >= $clockOut) {
            $validator->errors()->add('new_clock_in', '出勤時間もしくは退勤時間が不適切な値です');
        }
    }

    private function validateBreaks(Validator $validator): void
    {
        $breakIns = $this->input('new_break_in', []);
        $breakOuts = $this->input('new_break_out', []);

        if (! is_array($breakIns) || ! is_array($breakOuts)) {
            return;
        }

        $clockIn = $this->toMinutes($this->input('new_clock_in'));
        $clockOut = $this->toMinutes($this->input('new_clock_out'));
        $indexes = array_unique(array_merge(array_keys($breakIns), array_keys($breakOuts)));

        foreach ($indexes as $index) {
            $startRaw = $breakIns[$index] ?? null;
            $endRaw = $breakOuts[$index] ?? null;
            $startFilled = is_string($startRaw) && trim($startRaw) !== '';
            $endFilled = is_string($endRaw) && trim($endRaw) !== '';

            if (! $startFilled && ! $endFilled) {
                continue;
            }

            $start = $this->toMinutes($startRaw);
            $end = $this->toMinutes($endRaw);

            if (! $startFilled || $start === null || ($clockIn !== null && $start < $clockIn) || ($clockOut !== null && $start > $clockOut)) {
                $validator->errors()->add('new_break_in.'.$index, '休憩時間が不適切な値です');
            }

            if (! $endFilled || $end === null || ($clockOut !== null && $end > $clockOut)) {
                $validator->errors()->add('new_break_out.'.$index, '休憩時間もしくは退勤時間が不適切な値です');
            } elseif ($start !== null && $end < $start) {
                $validator->errors()->add('new_break_out.'.$index, '休憩時間が不適切な値です');
            }
        }
    }

    private function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim($time))) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', trim($time)));

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}
