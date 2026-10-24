<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Enums\TeacherProfileVisibilityStatus;
use App\Http\Controllers\Controller;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = TeacherProfile::query()
            ->where('public_profile_status', TeacherProfileVisibilityStatus::PENDING)
            ->with(['user', 'city', 'subjects'])
            ->latest()
            ->get()
            ->map(fn (TeacherProfile $profile) => [
                'user_id' => $profile->user_id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
                'surname' => $profile->surname,
                'city' => $profile->city,
                'subjects' => $profile->subjects,
                'about' => $profile->about,
                'education' => $profile->education,
                'requested_at' => $profile->updated_at,
            ]);

        return response()->json(['profiles' => $profiles]);
    }

    public function approve(Request $request, TeacherProfile $teacherProfile): JsonResponse
    {
        abort_unless($teacherProfile->public_profile_status === TeacherProfileVisibilityStatus::PENDING, 422);

        $teacherProfile->update([
            'public_profile_status' => TeacherProfileVisibilityStatus::APPROVED,
            'public_profile_reviewed_at' => now(),
            'public_profile_reviewed_by' => $request->user()->id,
        ]);

        return response()->json(['status' => $teacherProfile->public_profile_status]);
    }

    public function reject(Request $request, TeacherProfile $teacherProfile): JsonResponse
    {
        abort_unless($teacherProfile->public_profile_status === TeacherProfileVisibilityStatus::PENDING, 422);

        $teacherProfile->update([
            'public_profile_status' => TeacherProfileVisibilityStatus::REJECTED,
            'public_profile_reviewed_at' => now(),
            'public_profile_reviewed_by' => $request->user()->id,
        ]);

        return response()->json(['status' => $teacherProfile->public_profile_status]);
    }
}
