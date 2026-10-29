<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRecordRequest extends FormRequest
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
        $attendance = $this->route('attendanceRecord');
        $attendanceId = $attendance instanceof Attendance ? $attendance->id : $attendance;
        $userId = $attendance instanceof Attendance ? $attendance->user_id : null;

        return [
            'date' => [
                'bail',
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('attendances', 'date')
                    ->ignore($attendanceId)
                    ->where(fn ($query) => $query->where('user_id', $userId)),
            ],
            'clock_in' => ['bail', 'sometimes', 'required', 'date_format:H:i:s'],
            'clock_out' => ['bail', 'sometimes', 'nullable', 'date_format:H:i:s'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => '勤怠日は必須です。',
            'date.date_format' => '勤怠日は YYYY-MM-DD 形式で指定してください。',
            'date.unique' => 'この日付の勤怠は既に登録されています。',
            'clock_in.required' => '出勤時刻は必須です。',
            'clock_in.date_format' => '出勤時刻は HH:MM:SS 形式で指定してください。',
            'clock_out.date_format' => '退勤時刻は HH:MM:SS 形式で指定してください。',
            'comment.max' => '備考は 255 文字以内で入力してください。',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $attendance = $this->route('attendanceRecord');
            $clockIn = $this->exists('clock_in')
                ? $this->input('clock_in')
                : ($attendance instanceof Attendance ? $attendance->clock_in : null);
            $clockOut = $this->exists('clock_out')
                ? $this->input('clock_out')
                : ($attendance instanceof Attendance ? $attendance->clock_out : null);

            if (! is_string($clockIn) || ! is_string($clockOut) || $clockIn === '' || $clockOut === '') {
                return;
            }

            if (! preg_match('/^\d{2}:\d{2}:\d{2}$/', $clockIn) || ! preg_match('/^\d{2}:\d{2}:\d{2}$/', $clockOut)) {
                return;
            }

            if ($clockOut <= $clockIn) {
                $validator->errors()->add('clock_out', '退勤時刻は出勤時刻より後の時刻を指定してください。');
            }
        });
    }
}
