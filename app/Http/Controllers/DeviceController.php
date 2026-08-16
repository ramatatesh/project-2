<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Requests\UnregisterDeviceRequest;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Devices",
 *   description="Register and unregister Firebase Cloud Messaging tokens for the authenticated user"
 * )
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $deviceService) {}

    /**
     * @OA\Post(
     *   path="/api/devices",
     *   summary="Register or refresh the current device FCM token",
     *   tags={"Devices"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(required=true,
     *     @OA\JsonContent(
     *       required={"fcm_token"},
     *       @OA\Property(property="fcm_token", type="string"),
     *       @OA\Property(property="platform", type="string", enum={"android","ios","web"}),
     *       @OA\Property(property="device_name", type="string", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="Device registered successfully"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=422, description="Validation failed")
     * )
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $device = $this->deviceService->register(
            $request->user(),
            $request->validated('fcm_token'),
            $request->validated('platform'),
            $request->validated('device_name'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Device registered successfully.',
            'data' => [
                'id' => $device->id,
                'platform' => $device->platform,
                'is_active' => (bool) $device->is_active,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/devices/unregister",
     *   summary="Deactivate the current device FCM token",
     *   tags={"Devices"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(required=true,
     *     @OA\JsonContent(
     *       required={"fcm_token"},
     *       @OA\Property(property="fcm_token", type="string")
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="Device unregistered successfully"),
     *   @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function unregister(UnregisterDeviceRequest $request): JsonResponse
    {
        $this->deviceService->unregister(
            $request->user(),
            $request->validated('fcm_token'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Device unregistered successfully.',
        ]);
    }
}
