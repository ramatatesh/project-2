<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteProfileRequest;
use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Tag(
 *   name="Profile",
 *   description="Employee self-service profile completion (documents upload)"
 * )
 */
class ProfileController extends Controller
{
    /**
     * @OA\Post(
     *   path="/api/profile/complete",
     *   summary="Complete the logged-in employee's profile by uploading their documents",
     *   description="Not mandatory right after first login - the employee can complete this whenever they choose. Stores profile_image, identity_image and (optionally) university_certificate, then marks the user's profile_completed flag as true.",
     *   tags={"Profile"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         required={"profile_image","identity_image"},
     *         @OA\Property(property="profile_image", type="string", format="binary", description="Personal profile picture"),
     *         @OA\Property(property="identity_image", type="string", format="binary", description="Photo of the national ID / identity document"),
     *         @OA\Property(property="university_certificate", type="string", format="binary", description="University certificate photo/scan (optional)")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Profile completed successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Profile completed successfully."),
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
    public function complete(CompleteProfileRequest $request): JsonResponse
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
            'profile_image' => 'profile_image_path',
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

        $user->update(['profile_completed' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Profile completed successfully.',
            'data' => [
                'profile_completed' => true,
                'documents' => [
                    'profile_image_url' => $document->profile_image_path ? Storage::disk('public')->url($document->profile_image_path) : null,
                    'identity_image_url' => $document->identity_image_path ? Storage::disk('public')->url($document->identity_image_path) : null,
                    'university_certificate_url' => $document->university_certificate_path ? Storage::disk('public')->url($document->university_certificate_path) : null,
                ],
            ],
        ]);
    }
}
