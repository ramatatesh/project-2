<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Tag(
 *   name="Company Profile",
 *   description="Company info page (logo, tagline, about, contact details). General Manager can view and edit; every other tenant role (HR Manager, Department Manager, Employee) can only view their own company's page."
 * )
 */
class CompanyProfileController extends Controller
{
    /**
     * @OA\Get(
     *   path="/api/company/profile",
     *   summary="Get the current user's company profile page (read-only)",
     *   description="Available to any authenticated tenant user (General Manager, HR Manager, Department Manager, Employee). Always resolves the company from auth()->user()->company_id - no company_id is ever accepted from the client, so a user can never view another company's profile.",
     *   tags={"Company Profile"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Company profile retrieved successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid", example="a1b2c3d4-e5f6-7890-abcd-ef1234567890"),
     *         @OA\Property(property="name", type="string", example="Khibrat HR"),
     *         @OA\Property(property="logo_url", type="string", nullable=true, example="http://localhost/storage/company_logos/a1b2c3d4-e5f6-7890-abcd-ef1234567890/logo.png"),
     *         @OA\Property(property="tagline", type="string", nullable=true, example="شريك التمكين الرقمي المعتمد"),
     *         @OA\Property(property="about", type="string", nullable=true, example="نبذة بسيطة عن الشركة ومجال عملها."),
     *         @OA\Property(property="phone", type="string", example="+963111222333"),
     *         @OA\Property(property="email", type="string", format="email", example="info@khibrat.example"),
     *         @OA\Property(property="address", type="string", example="دمشق، منطقة الروضة")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function show(): JsonResponse
    {
        $company = Company::findOrFail(auth()->user()->company_id);

        return response()->json([
            'success' => true,
            'data' => $this->profilePayload($company),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/company/profile",
     *   summary="Update the company profile page (General Manager only)",
     *   description="Only the General Manager of the company can edit this page. Updates name, phone, email, address, tagline, about, and optionally the logo. If no new logo file is sent, the previously saved logo is kept untouched; if a new one is sent, the old logo file is deleted from storage and replaced.",
     *   tags={"Company Profile"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         required={"name","phone","email","address"},
     *         @OA\Property(property="name", type="string", maxLength=255, example="Khibrat HR"),
     *         @OA\Property(property="phone", type="string", maxLength=30, example="+963111222333"),
     *         @OA\Property(property="email", type="string", format="email", maxLength=255, example="info@khibrat.example"),
     *         @OA\Property(property="address", type="string", maxLength=500, example="دمشق، منطقة الروضة"),
     *         @OA\Property(property="tagline", type="string", nullable=true, maxLength=255, example="شريك التمكين الرقمي المعتمد"),
     *         @OA\Property(property="about", type="string", nullable=true, maxLength=3000, example="نبذة بسيطة عن الشركة ومجال عملها."),
     *         @OA\Property(property="logo", type="string", format="binary", nullable=true, description="jpg/jpeg/png/webp, max 4MB. Omit to keep the current logo.")
     *       )
     *     ),
     *     @OA\MediaType(
     *       mediaType="application/json",
     *       @OA\Schema(
     *         required={"name","phone","email","address"},
     *         @OA\Property(property="name", type="string", maxLength=255, example="Khibrat HR"),
     *         @OA\Property(property="phone", type="string", maxLength=30, example="+963111222333"),
     *         @OA\Property(property="email", type="string", format="email", maxLength=255, example="info@khibrat.example"),
     *         @OA\Property(property="address", type="string", maxLength=500, example="دمشق، منطقة الروضة"),
     *         @OA\Property(property="tagline", type="string", nullable=true, maxLength=255, example="شريك التمكين الرقمي المعتمد"),
     *         @OA\Property(property="about", type="string", nullable=true, maxLength=3000, example="نبذة بسيطة عن الشركة ومجال عملها.")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Company profile updated successfully",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="message", type="string", example="Company profile updated successfully."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="name", type="string", example="Khibrat HR"),
     *         @OA\Property(property="logo_url", type="string", nullable=true),
     *         @OA\Property(property="tagline", type="string", nullable=true),
     *         @OA\Property(property="about", type="string", nullable=true),
     *         @OA\Property(property="phone", type="string"),
     *         @OA\Property(property="email", type="string", format="email"),
     *         @OA\Property(property="address", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="Only the General Manager can edit the company profile, or the company is frozen (status=suspended) - message 'Company is frozen.'"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $company = Company::findOrFail(auth()->user()->company_id);
        $data = $request->validated();

        $company->name = $data['name'];
        $company->phone = $data['phone'];
        $company->email = $data['email'];
        $company->address = $data['address'];

        if (array_key_exists('tagline', $data)) {
            $company->tagline = $data['tagline'];
        }

        if (array_key_exists('about', $data)) {
            $company->about = $data['about'];
        }

        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }

            $company->logo_path = $request->file('logo')->store("company_logos/{$company->id}", 'public');
        }

        $company->save();

        return response()->json([
            'success' => true,
            'message' => 'Company profile updated successfully.',
            'data' => $this->profilePayload($company),
        ]);
    }

    private function profilePayload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'logo_url' => $company->logo_path ? Storage::disk('public')->url($company->logo_path) : null,
            'tagline' => $company->tagline,
            'about' => $company->about,
            'phone' => $company->phone,
            'email' => $company->email,
            'address' => $company->address,
        ];
    }
}
