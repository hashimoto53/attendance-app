<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\CorrectionRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CorrectionRequestController extends Controller
{
    /** 管理者 */
    private const ROLE_ADMIN = 2;

    /**
     * 申請一覧を表示する。管理者は全ユーザー、一般ユーザーは自分の申請のみ。
     */
    public function list(): View
    {
        $user = $this->user();

        if ((int) $user->role === self::ROLE_ADMIN) {
            $applications = CorrectionRequest::query()
                ->with(['user', 'attendance'])
                ->orderByDesc('id')
                ->get();

            return view('admin.admin-application-list', [
                'applications' => $applications,
            ]);
        }

        $formattedApplications = $user->correctionRequests()
            ->with('attendance')
            ->orderByDesc('id')
            ->get()
            ->map(function (CorrectionRequest $correctionRequest): array {
                return [
                    'id' => $correctionRequest->id,
                    'attendance_id' => $correctionRequest->attendance_id,
                    'approval_status' => $correctionRequest->approval_status,
                    'date' => Carbon::parse($correctionRequest->date)->format('Y/m/d'),
                    'comment' => $correctionRequest->comment,
                    'application_date' => optional($correctionRequest->created_at)->format('Y/m/d') ?? '',
                ];
            });

        return view('user.user-application-list', [
            'user' => $user,
            'formattedApplications' => $formattedApplications,
        ]);
    }

    /**
     * 修正申請の承認画面を表示する
     */
    public function showApproveForm(int $attendance_correct_request_id): View
    {
        $this->ensureAdmin();

        $application = $this->findRequest($attendance_correct_request_id);

        return view('admin.admin-application-detail', [
            'user' => $application->user,
            'application' => $application,
        ]);
    }

    /**
     * 修正申請を承認し、対象の勤怠を申請内容で更新する
     */
    public function approve(int $attendance_correct_request_id): RedirectResponse
    {
        $this->ensureAdmin();

        $application = $this->findRequest($attendance_correct_request_id);

        if ($application->status === CorrectionRequest::STATUS_PENDING) {
            DB::transaction(function () use ($application): void {
                $application->attendance->update([
                    'date' => Carbon::parse($application->date)->toDateString(),
                    'clock_in' => Carbon::parse($application->clock_in)->format('H:i:s'),
                    'clock_out' => Carbon::parse($application->clock_out)->format('H:i:s'),
                    'remarks' => $application->remarks,
                    'status' => Attendance::STATUS_CLOCKED_OUT,
                ]);

                $application->update([
                    'status' => CorrectionRequest::STATUS_APPROVED,
                ]);
            });
        }

        return redirect()->route('request.approve.form', $application->id);
    }

    private function ensureAdmin(): void
    {
        if ((int) $this->user()->role !== self::ROLE_ADMIN) {
            abort(403);
        }
    }

    private function findRequest(int $id): CorrectionRequest
    {
        return CorrectionRequest::query()
            ->with(['user', 'attendance.rests'])
            ->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
