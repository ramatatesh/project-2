<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Info(
 * title="منصة خبرات - Khibrat HR SaaS API",
 * version="1.0.0",
 * description="التوثيق الرسمي لكافة واجهات برمجة التطبيقات (APIs) لمنصة خبرات لإدارة الموارد البشرية"
 * )
 * @OA\Server(
 * url="https://web-production-f32da.up.railway.app",
 * description="سيرفر التطوير المحلي"
 * )
 * @OA\SecurityScheme(
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
     * @OA\Property(property="user", type="object"),
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
     * path="/api/auth/forgot-password",
     * summary="طلب رابط إعادة تعيين كلمة المرور (نسيان كلمة المرور)",
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
     * description="تم توليد رمز إعادة التعيين بنجاح",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="Password reset link has been generated."),
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

            $token = Str::random(60);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $token,
                    'created_at' => now(),
                ]
            );

            SendPasswordResetEmailJob::dispatch($user->email, $token);

            return $this->successResponse('Password reset link has been generated.', [
                'email' => $user->email,
            ]);
        } catch (\Throwable $th) {
            Log::error('Password reset request failed', ['error' => $th->getMessage()]);

            return $this->errorResponse('Unable to process password reset request.', 500);
        }
    }

    /**
     * @OA\Post(
     * path="/api/auth/reset-password",
     * summary="إعادة تعيين كلمة المرور الجديدة باستخدام الـ Token",
     * tags={"المصادقة (Authentication)"},
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"email","token","password"},
     * @OA\Property(property="email", type="string", format="email", example="hr@khibrat.com"),
     * @OA\Property(property="token", type="string", example="abcdef123456..."),
     * @OA\Property(property="password", type="string", format="password", example="new_password_123")
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
     * description="الـ Token غير صحيح أو منتهي الصلاحية"
     * )
     * )
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $record = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->where('token', $request->token)
                ->first();

            if (! $record) {
                return $this->errorResponse('Invalid or expired password reset token.', 400);
            }

            $user = User::where('email', $request->email)->first();

            if (! $user) {
                return $this->errorResponse('User not found.', 404);
            }

            $user->update([
                'password_hash' => Hash::make($request->password),
            ]);

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

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
