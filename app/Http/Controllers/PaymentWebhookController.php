<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptionService)
    {
    }

    /**
     * @OA\Post(
     * path="/api/payments/callback",
     * summary="استقبال إشعارات بوابات الدفع (Webhook Callback)",
     * description="هذا الـ API مخصص لاستقبال التحديثات الفورية والآلية من بوابة الدفع الخارجية (مثل شام كاش) عند نجاح أو فشل عملية الدفع لتحديث حالة الشركة واشتراكها تلقائياً.",
     * tags={"بوابات الدفع والاشتراكات (Payments & Subscriptions)"},
     * @OA\RequestBody(
     * required=true,
     * description="بيانات المعاملة المالية المرسلة من سيرفر بوابة الدفع",
     * @OA\JsonContent(
     * required={"transaction_reference", "success"},
     * @OA\Property(property="transaction_reference", type="string", format="uuid", example="e17a3ba2-04cd-4d06-806b-46d10bc28dd1", description="الرقم المرجعي الفريد للمعاملة المالية"),
     * @OA\Property(property="success", type="boolean", example=true, description="حالة نجاح عملية الدفع (true للنجاح، false للفشل)")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="تم معالجة الإشعار بنجاح وتفعيل حساب الشركة والمستخدم الإداري",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="company", type="object", description="بيانات الشركة التي تم تفعيلها"),
     * @OA\Property(property="user", type="object", description="بيانات حساب مدير الشركة الذي تم إنشاؤه")
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="طلب خاطئ - نقص في البيانات المرسلة أو فشل في معالجة الدفع",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=false),
     * @OA\Property(property="message", type="string", example="Missing transaction_reference أو Payment failed.")
     * )
     * )
     * )
     */
    public function callback(Request $request): JsonResponse
    {
        $payload = $request->only(['transaction_reference', 'success']);

        Log::info('Payment webhook received', $payload);

        $success = filter_var($payload['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $reference = $payload['transaction_reference'] ?? null;

        if (! $reference) {
            return response()->json(['success' => false, 'message' => 'Missing transaction_reference'], 400);
        }

        $result = $this->subscriptionService->finalizePayment($reference, $success);

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
