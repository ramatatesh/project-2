<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyLoginOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
//use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use App\Models\PasswordResetOtp;
use App\Models\LoginOtp;
use App\Jobs\SendPasswordResetOtpJob;
use App\Jobs\SendLoginOtpJob;
//use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
//use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
//use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Info(
 * title="منصة خبرات - Khibrat HR SaaS API",
 * version="1.0.0",
 * description="التوثيق الرسمي لكافة واجهات برمجة التطبيقات (APIs) لمنصة خبرات لإدارة الموارد البشرية"
 * )
 * * @OA\Server(
 * url="/",
 * description="السيرفر الافتراضي الحالي المباشر (الديناميكي)"
 * )
 * * @OA\SecurityScheme(
 * securityScheme="sanctum",
 * type="http",
 * scheme="bearer",
 * bearerFormat="JWT",
 * description="أدخلي الـ Token الذي حصلتِ عليه من الـ Login هنا لتفعيل الصلاحيات"
 * )
 */
class AuthController extends Controller
{
    /**
     * @OA\Post(
     * path="/api/auth/login",
     * summary="تسجيل دخول المستخدم للمنصة",
     * tags={"المصادقة (Authentication)"},
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"email","password"},
     * @OA\Property(property="email", type="string", format="email", example="hr@khibrat.com"),
     * @OA\Property(property="password", type="string", format="password", example="123456")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="تم تسجيل الدخول بنجاح وإعادة الـ Token",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="Login successful."),
     * @OA\Property(property="data", type="object",
     * @OA\Property(property="user", type="object",
     *   @OA\Property(property="id", type="string", format="uuid"),
     *   @OA\Property(property="company_id", type="string", format="uuid"),
     *   @OA\Property(property="full_name", type="string"),
     *   @OA\Property(property="email", type="string", format="email"),
     *   @OA\Property(property="role", type="string"),
     *   @OA\Property(property="status", type="string"),
     *   @OA\Property(property="is_first_login", type="boolean"),
     *   @OA\Property(property="profile_completed", type="boolean", description="If false, the frontend should prompt the user to finish profile setup via PUT /api/profile (profile_image) and POST /api/profile/documents (identity_image). Completing it is not mandatory right after login.", example=false)
     * ),
     * @OA\Property(property="company", type="object"),
     * @OA\Property(property="token", type="string", example="1|lhA7G...")
     * )
     * )
     * ),
     * @OA\Response(
     * response=401,
     * description="بيانات الدخول غير صحيحة"
     * ),
     * @OA\Response(
     * response=403,
     * description="الحساب غير نشط"
     * )
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $credentials = $request->only(['email', 'password']);

            $user = User::where('email', $credentials['email'])->first();

            if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
                return $this->errorResponse('Invalid credentials.', 401);
            }

            if ($user->status !== 'active') {
                return $this->errorResponse('User account is inactive.', 403);
            }

            if ($user->two_factor_enabled) {
                $otp = rand(1000, 9999);

                LoginOtp::where('email', $user->email)->delete();

                LoginOtp::create([
                    'email' => $user->email,
                    'otp' => (string) $otp,
                    'expires_at' => now()->addMinutes(10),
                ]);

                SendLoginOtpJob::dispatch($user->email, (string) $otp);

                return $this->successResponse('Verification code sent to your email.', [
                    'requires_2fa' => true,
                    'email' => $user->email,
                ]);
            }

            $token = $user->createToken('auth-token', [$user->role])->plainTextToken;

            $user->load('company');

            return $this->successResponse('Login successful.', [
                'user' => [
                    'id' => $user->id,
                    'company_id' => $user->company_id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'is_first_login' => $user->is_first_login,
                    'profile_completed' => $user->profile_completed,
                ],
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->name,
                ] : null,
                'token' => $token,
            ]);
        } catch (\Throwable $th) {
            Log::error('Auth login failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to process login request.', 500);
        }
    }

    /**
     * @OA\Post(
     * path="/api/auth/verify-login-otp",
     * summary="التحقق من رمز الدخول بخطوتين وإصدار التوكن",
     * tags={"المصادقة (Authentication)"},
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"email","otp"},
     * @OA\Property(property="email", type="string", format="email", example="hr@khibrat.com"),
     * @OA\Property(property="otp", type="string", example="1234")
     * )
     * ),
     * @OA\Response(response=200, description="تم تسجيل الدخول بنجاح وإعادة الـ Token"),
     * @OA\Response(response=400, description="رمز التحقق غير صحيح أو منتهي الصلاحية")
     * )
     */
    public function verifyLoginOtp(VerifyLoginOtpRequest $request): JsonResponse
    {
        try {
            $otp = LoginOtp::where('email', $request->email)
                ->where('otp', $request->otp)
                ->where('expires_at', '>', now())
                ->latest()
                ->first();

            if (! $otp) {
                return $this->errorResponse('Invalid or expired verification code.', 400);
            }

            $user = User::where('email', $request->email)->first();

            if (! $user) {
                return $this->errorResponse('User not found.', 404);
            }

            if ($user->status !== 'active') {
                return $this->errorResponse('User account is inactive.', 403);
            }

            LoginOtp::where('email', $request->email)->delete();

            $token = $user->createToken('auth-token', [$user->role])->plainTextToken;

            $user->load('company');

            return $this->successResponse('Login successful.', [
                'user' => [
                    'id' => $user->id,
                    'company_id' => $user->company_id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'is_first_login' => $user->is_first_login,
                    'profile_completed' => $user->profile_completed,
                ],
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->name,
                ] : null,
                'token' => $token,
            ]);
        } catch (\Throwable $th) {
            Log::error('Login OTP verification failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to verify the code.', 500);
        }
    }

    /**
     * @OA\Post(
     * path="/api/auth/forgot-password",
     * summary="إرسال رمز OTP لإعادة تعيين كلمة المرور",
     * tags={"المصادقة (Authentication)"},
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"email"},
     * @OA\Property(property="email", type="string", format="email", example="hr@khibrat.com")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="تم إرسال رمز OTP إلى البريد الإلكتروني.",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="OTP sent successfully."),
     * @OA\Property(property="data", type="object",
     * @OA\Property(property="email", type="string", example="hr@khibrat.com")
     * )
     * )
     * ),
     * @OA\Response(
     * response=404,
     * description="البريد الإلكتروني غير مسجل في النظام"
     * )
     * )
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (! $user) {
                return $this->errorResponse('No user found for the provided email address.', 404);
            }

            $otp = rand(1000,9999);

            PasswordResetOtp::where('email', $user->email)->delete();

            PasswordResetOtp::create([
              'email'=>$user->email,
              'otp' => (string)$otp,
              'expires_at'=>now()->addMinutes(10),
            ]);

            SendPasswordResetOtpJob::dispatch($user->email, $otp);

            return $this->successResponse( 'OTP sent successfully' );

        } catch (\Throwable $th) {
            Log::error('Password reset request failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to process password reset request.', 500);
        }
    }


    /**
 * @OA\Post(
 *     path="/api/auth/resend-otp",
 *     summary="إعادة إرسال رمز OTP",
 *     tags={"المصادقة (Authentication)"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"email"},
 *             @OA\Property(
 *                 property="email",
 *                 type="string",
 *                 format="email",
 *                 example="hr@khibrat.com"
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="OTP resent successfully"
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="User not found"
 *     )
 * )
 */
public function resendOtp(ForgotPasswordRequest $request): JsonResponse
{
    try {

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->errorResponse(
                'No user found for the provided email address.',
                404
            );
        }

        // منع الإرسال المتكرر خلال دقيقة واحدة
        $lastOtp = PasswordResetOtp::where('email', $user->email)
            ->latest()
            ->first();

        if ($lastOtp && $lastOtp->created_at->diffInSeconds(now()) < 60) {
            return $this->errorResponse(
                'Please wait before requesting another OTP.',
                429
            );
        }

        $otp = rand(1000, 9999);

        PasswordResetOtp::where('email', $user->email)->delete();

        PasswordResetOtp::create([
            'email' => $user->email,
            'otp' => (string) $otp,
            'expires_at' => now()->addMinutes(10),
            'verified' => false,
        ]);

        SendPasswordResetOtpJob::dispatch(
            $user->email,
            $otp
        );

        return $this->successResponse(
            'OTP resent successfully.'
        );

    } catch (\Throwable $th) {

        Log::error('Resend OTP failed', [
            'error' => $th->getMessage(),
        ]);

        return $this->errorResponse(
            'Unable to resend OTP.',
            500
        );
    }
}

    /**
 * @OA\Post(
 *     path="/api/auth/verify-otp",
 *     summary="التحقق من رمز OTP لإعادة تعيين كلمة المرور",
 *     tags={"المصادقة (Authentication)"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"email","otp"},
 *             @OA\Property(
 *                 property="email",
 *                 type="string",
 *                 format="email",
 *                 example="hr@khibrat.com"
 *             ),
 *             @OA\Property(
 *                 property="otp",
 *                 type="string",
 *                 example="1234"
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="OTP verified successfully"
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Invalid or expired OTP"
 *     )
 * )
 */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {

     $otp = PasswordResetOtp::where('email',$request->email)
        ->where('otp',$request->otp)
        ->where('verified',false)
        ->first();


     if(!$otp){
          return $this->errorResponse(
             'Invalid OTP',
             400
            );
        }


     if($otp->expires_at < now()){
         return $this->errorResponse(
             'OTP expired',
             400
            );
        }


     $otp->update([
         'verified'=>true
        ]);


     return $this->successResponse(
         'OTP verified successfully'
        );
    }

    /**
     * @OA\Post(
     * path="/api/auth/reset-password",
     * summary="إعادة تعيين كلمة المرور بعد التحقق من رمز OTP",
     * description="كلمة المرور الجديدة يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، ورمز خاص - وإلا يُرجع 422 مع رسالة توضح تحديداً أي شرط لم يتحقق.",
     * tags={"المصادقة (Authentication)"},
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"email","password","password_confirmation"},
     * @OA\Property(property="email", type="string", format="email", example="hr@khibrat.com"),
     * @OA\Property(property="password", type="string", format="password", example="New@Pass2026"),
     * @OA\Property(property="password_confirmation", type="string", format="password", example="New@Pass2026")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="تم تحديث كلمة المرور بنجاح",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="Password updated successfully."),
     * @OA\Property(property="data", type="object",
     * @OA\Property(property="email", type="string", example="hr@khibrat.com")
     * )
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="لم يتم التحقق من رمز OTP أو انتهت صلاحيته"
     * )
     * )
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $otp = PasswordResetOtp::where('email', $request->email)
               ->where('verified', true)
               ->where('expires_at', '>', now())
               ->first();


             if (!$otp) {
                 return $this->errorResponse(
                 'OTP verification required.', 400);
                }

            $user = User::where('email', $request->email)->first();

            if (! $user) {
                return $this->errorResponse('User not found.', 404);
            }

            $user->update([
                'password_hash' => Hash::make($request->password),
            ]);

            PasswordResetOtp::where('email', $request->email)->delete();

            return $this->successResponse('Password updated successfully.', [
                'email' => $user->email,
            ]);
        } catch (\Throwable $th) {
            Log::error('Password reset failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to reset password.', 500);
        }
    }

    /**
     * @OA\Post(
     * path="/api/auth/complete-first-login",
     * summary="تغيير كلمة المرور الإلزامية عند تسجيل الدخول الأول للمنصة",
     * description="كلمة المرور الجديدة يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، ورمز خاص - وإلا يُرجع 422 مع رسالة توضح تحديداً أي شرط لم يتحقق.",
     * tags={"المصادقة (Authentication)"},
     * security={{"sanctum":{}}},
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"password", "password_confirmation"},
     * @OA\Property(property="password", type="string", format="password", minLength=8, example="New@Pass2026"),
     * @OA\Property(property="password_confirmation", type="string", format="password", example="New@Pass2026")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="تم تحديث كلمة المرور وإغلاق حالة الدخول الأول بنجاح",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="Initial password changed successfully. Welcome to Khibrat!"),
     * @OA\Property(property="data", type="object", nullable=true)
     * )
     * ),
     * @OA\Response(
     * response=401,
     * description="المستخدم غير مصادق عليه (Unauthenticated)"
     * ),
     * @OA\Response(
     * response=422,
     * description="خطأ في التحقق من المدخلات (مثال: عدم تطابق كلمة المرور أو ضعفها)"
     * )
     * )
     */
    public function completeFirstLogin(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            // 1. التحقق من المدخلات بالشروط الصارمة المطلوبة
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    \Illuminate\Validation\Rules\Password::min(8)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ], [
                'password.required' => 'كلمة المرور مطلوبة.',
                'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
                'password.min' => 'يجب أن تحتوي كلمة المرور على 8 أحرف على الأقل.',
                'password.letters' => 'يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.',
                'password.mixed' => 'يجب أن تحتوي كلمة المرور على حرف كبير وحرف صغير.',
                'password.numbers' => 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
                'password.symbols' => 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل.',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation error', 422, $validator->errors());
            }


            $user = auth()->user();

            if (! $user) {
                return $this->errorResponse('Unauthenticated.', 401);
            }


            $user->update([
                'password_hash' => Hash::make($request->password),
                'is_first_login' => false,
            ]);

            return $this->successResponse('Initial password changed successfully. Welcome to Khibrat!');

        } catch (\Throwable $th) {
            Log::error('Complete first login failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to complete password update process.', 500);
        }
    }

    /**
     * @OA\Post(
     * path="/api/auth/logout",
     * summary="تسجيل خروج المستخدم (إبطال الـ Token الحالي فقط)",
     * tags={"المصادقة (Authentication)"},
     * security={{"sanctum":{}}},
     * @OA\Response(
     * response=200,
     * description="تم تسجيل الخروج بنجاح",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="Logged out successfully.")
     * )
     * ),
     * @OA\Response(
     * response=401,
     * description="المستخدم غير مصادق عليه (Unauthenticated)"
     * )
     * )
     */
    public function logout(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->successResponse('Logged out successfully.');
        } catch (\Throwable $th) {
            Log::error('Logout failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to process logout request.', 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/auth/two-factor",
     *     summary="تفعيل أو تعطيل التحقق بخطوتين للحساب الحالي",
     *     tags={"المصادقة (Authentication)"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"enabled"},
     *             @OA\Property(property="enabled", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="تم تحديث حالة التحقق بخطوتين بنجاح",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Two-factor authentication setting updated."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="two_factor_enabled", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="المستخدم غير مصادق عليه (Unauthenticated) - يجب وضع التوكن في زر Authorize فوق"),
     *     @OA\Response(response=422, description="خطأ في التحقق من المدخلات")
     * )
     */
    public function toggleTwoFactor(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'enabled' => ['required', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation error', 422, $validator->errors());
            }

            $user = auth()->user();

            if (! $user) {
                return $this->errorResponse('Unauthenticated.', 401);
            }

            $user->update([
                'two_factor_enabled' => $request->boolean('enabled'),
            ]);

            return $this->successResponse('Two-factor authentication setting updated.', [
                'two_factor_enabled' => $user->two_factor_enabled,
            ]);
        } catch (\Throwable $th) {
            Log::error('Toggle two-factor failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to update two-factor setting.', 500);
        }
    }

    protected function successResponse(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function errorResponse(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

}
