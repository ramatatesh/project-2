<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadProfileDocumentsRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Tag(
 *   name="Profile",
 *   description="Employee self-service profile viewing, editing, and document upload"
 * )
 */
class ProfileController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/profile",
     *   summary="Get the authenticated user's profile",
     *   description="Returns personal info for the logged-in user. Employee-only fields (job title, department, hire date, documents) are included when an employee record exists; otherwise they are null without error.",
     *   tags={"Profile"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Profile retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="full_name", type="string", example="Ahmad Ali"),
     *         @OA\Property(property="email", type="string", example="ahmad@company.test"),
     *         @OA\Property(property="phone", type="string", nullable=true, example="+963999999999"),
     *         @OA\Property(property="date_of_birth", type="string", format="date", nullable=true, example="1995-05-20"),
     *         @OA\Property(property="gender", type="string", nullable=true, example="male"),
     *         @OA\Property(property="nationality", type="string", nullable=true, example="Syrian"),
     *         @OA\Property(property="residence", type="string", nullable=true, example="Damascus"),
     *         @OA\Property(property="job_title", type="string", nullable=true, example="Backend Developer"),
     *         @OA\Property(property="department", type="object", nullable=true,
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="name", type="string", example="Engineering")
     *         ),
     *         @OA\Property(property="hire_date", type="string", format="date", nullable=true, example="2022-01-01"),
     *         @OA\Property(property="profile_image_url", type="string", nullable=true),
     *         @OA\Property(property="profile_completed", type="boolean", example=false)
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function show(): JsonResponse
    {
        $user = auth()->user()->load(['employee.department', 'employee.document']);

        return response()->json([
            'success' => true,
            'data' => $this->profilePayload($user),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/profile",
     *   summary="Update phone, residence, and/or profile image",
     *   description="Updates only phone, residence, and profile_image. Multipart/form-data on PUT is supported via server-side parsing (PHP does not fill $_POST/$_FILES for PUT natively). JSON PUT without files also works. profile_completed becomes true automatically when both profile_image and identity_image exist.",
     *   tags={"Profile"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         @OA\Property(property="phone", type="string", nullable=true, pattern="^09[0-9]{8}$", example="0999999999", description="يجب أن يبدأ بـ 09 ويتكون من 10 أرقام"),
     *         @OA\Property(property="residence", type="string", nullable=true, example="Damascus, Syria"),
     *         @OA\Property(property="profile_image", type="string", format="binary", description="Personal profile picture (optional)")
     *       )
     *     ),
     *     @OA\MediaType(
     *       mediaType="application/json",
     *       @OA\Schema(
     *         @OA\Property(property="phone", type="string", nullable=true, pattern="^09[0-9]{8}$", example="0999999999", description="يجب أن يبدأ بـ 09 ويتكون من 10 أرقام"),
     *         @OA\Property(property="residence", type="string", nullable=true, example="Damascus, Syria")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Profile updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Profile updated successfully."),
     *       @OA\Property(property="data", type="object")
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Employee record required to upload profile image"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = auth()->user();
        $data = $request->validated();

        $userUpdates = [];
        if (array_key_exists('phone', $data)) {
            $userUpdates['phone'] = $data['phone'];
        }
        if (array_key_exists('residence', $data)) {
            $userUpdates['residence'] = $data['residence'];
        }

        if ($userUpdates !== []) {
            $user->update($userUpdates);
        }

        if ($request->hasFile('profile_image')) {
            $employee = $user->employee;

            if (! $employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee record not found.',
                ], 403);
            }

            $document = $employee->document ?? new EmployeeDocument(['employee_id' => $employee->id]);

            if ($document->profile_image_path) {
                Storage::disk('public')->delete($document->profile_image_path);
            }

            $document->employee_id = $employee->id;
            $document->profile_image_path = $request->file('profile_image')
                ->store("employee_documents/{$employee->id}", 'public');
            $document->save();

            $this->syncProfileCompleted($user, $employee);
        }

        $user->refresh()->load(['employee.department', 'employee.document']);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $this->profilePayload($user),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/profile/documents",
     *   summary="Upload identity and optional university certificate documents",
     *   description="Uploads identity_image (required) and university_certificate (optional). Creates or updates the employee_documents row. Does not change phone, residence, or other profile fields. Sets profile_completed to true when both profile_image and identity_image are present.",
     *   tags={"Profile"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         required={"identity_image"},
     *         @OA\Property(property="identity_image", type="string", format="binary", description="Photo of the national ID / identity document"),
     *         @OA\Property(property="university_certificate", type="string", format="binary", description="University certificate photo/scan (optional)")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Documents uploaded successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Documents uploaded successfully."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="profile_completed", type="boolean", example=true),
     *         @OA\Property(property="documents", type="object",
     *           @OA\Property(property="profile_image_url", type="string", nullable=true),
     *           @OA\Property(property="identity_image_url", type="string", nullable=true),
     *           @OA\Property(property="university_certificate_url", type="string", nullable=true)
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found for this user"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function uploadDocuments(UploadProfileDocumentsRequest $request): JsonResponse
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403);
        }

        $document = $employee->document ?? new EmployeeDocument(['employee_id' => $employee->id]);

        $fieldToColumn = [
            'identity_image' => 'identity_image_path',
            'university_certificate' => 'university_certificate_path',
        ];

        foreach ($fieldToColumn as $field => $column) {
            if (! $request->hasFile($field)) {
                continue;
            }

            if ($document->{$column}) {
                Storage::disk('public')->delete($document->{$column});
            }

            $document->{$column} = $request->file($field)->store("employee_documents/{$employee->id}", 'public');
        }

        $document->employee_id = $employee->id;
        $document->save();

        $this->syncProfileCompleted($user, $employee);

        $document->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Documents uploaded successfully.',
            'data' => [
                'profile_completed' => (bool) $user->fresh()->profile_completed,
                'documents' => [
                    'profile_image_url' => $document->profile_image_path ? Storage::disk('public')->url($document->profile_image_path) : null,
                    'identity_image_url' => $document->identity_image_path ? Storage::disk('public')->url($document->identity_image_path) : null,
                    'university_certificate_url' => $document->university_certificate_path ? Storage::disk('public')->url($document->university_certificate_path) : null,
                ],
            ],
        ]);
    }

    private function profilePayload(User $user): array
    {
        $employee = $user->employee;
        $document = $employee?->document;

        return [
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'date_of_birth' => $user->birth_date,
            'gender' => $user->gender,
            'nationality' => $user->nationality,
            'residence' => $user->residence,
            'job_title' => $employee?->job_title,
            'department' => $employee?->department ? [
                'id' => $employee->department->id,
                'name' => $employee->department->name,
            ] : null,
            'hire_date' => $employee?->hire_date,
            'profile_image_url' => $document?->profile_image_path
                ? Storage::disk('public')->url($document->profile_image_path)
                : null,
            'profile_completed' => (bool) $user->profile_completed,
        ];
    }

    /**
     * profile_completed becomes true only when both profile_image and identity_image exist.
     */
    private function syncProfileCompleted(User $user, Employee $employee): void
    {
        $document = $employee->document()->first();

        $completed = $document
            && filled($document->profile_image_path)
            && filled($document->identity_image_path);

        $user->update(['profile_completed' => $completed]);
    }
}
