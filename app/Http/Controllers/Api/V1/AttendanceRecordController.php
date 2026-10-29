<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAttendanceRecordRequest;
use App\Http\Requests\Api\V1\StoreAttendanceRecordRequest;
use App\Http\Requests\Api\V1\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\Attendance;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AttendanceRecordController extends Controller
{
    /**
     * 勤怠一覧を取得する
     */
    public function index(IndexAttendanceRecordRequest $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 20);

        $records = Attendance::query()
            ->with(['user', 'rests'])
            ->when($request->filled('user_id'), function ($query) use ($request): void {
                $query->where('user_id', $request->integer('user_id'));
            })
            ->when($request->filled('date'), function ($query) use ($request): void {
                $query->whereDate('date', $request->input('date'));
            })
            ->when($request->filled('month'), function ($query) use ($request): void {
                $month = $request->date('month', '!Y-m');
                $query->whereYear('date', $month->year)->whereMonth('date', $month->month);
            })
            ->latest('date')
            ->paginate($perPage);

        return AttendanceRecordResource::collection($records);
    }

    /**
     * 勤怠詳細を取得する
     */
    public function show(Attendance $attendanceRecord): AttendanceRecordResource
    {
        $attendanceRecord->load(['user', 'breaks', 'applications']);

        return new AttendanceRecordResource($attendanceRecord);
    }

    /**
     * 勤怠を登録する
     */
    public function store(StoreAttendanceRecordRequest $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validated();

        $attendance = $request->user()->attendances()->create([
            'date' => $validated['date'],
            'clock_in' => $validated['clock_in'],
            'clock_out' => $validated['clock_out'] ?? null,
            'remarks' => $validated['comment'] ?? null,
            'status' => empty($validated['clock_out'])
                ? Attendance::STATUS_WORKING
                : Attendance::STATUS_CLOCKED_OUT,
        ]);

        $attendance->load(['user', 'breaks']);

        return (new AttendanceRecordResource($attendance))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 勤怠を更新する
     */
    public function update(UpdateAttendanceRecordRequest $request, Attendance $attendanceRecord): AttendanceRecordResource
    {
        $this->authorize('update', $attendanceRecord);

        $validated = $request->validated();
        $attributes = [];

        if (array_key_exists('date', $validated)) {
            $attributes['date'] = $validated['date'];
        }

        if (array_key_exists('clock_in', $validated)) {
            $attributes['clock_in'] = $validated['clock_in'];
        }

        if (array_key_exists('clock_out', $validated)) {
            $attributes['clock_out'] = $validated['clock_out'];
            $attributes['status'] = empty($validated['clock_out'])
                ? Attendance::STATUS_WORKING
                : Attendance::STATUS_CLOCKED_OUT;
        }

        if (array_key_exists('comment', $validated)) {
            $attributes['remarks'] = $validated['comment'];
        }

        $attendanceRecord->update($attributes);
        $attendanceRecord->load(['user', 'breaks']);

        return new AttendanceRecordResource($attendanceRecord);
    }

    /**
     * 勤怠を削除する
     */
    public function destroy(Attendance $attendanceRecord): Response
    {
        $this->authorize('delete', $attendanceRecord);

        $attendanceRecord->delete();

        return response()->noContent();
    }
}
