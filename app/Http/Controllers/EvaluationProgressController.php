<?php

namespace App\Http\Controllers;

use App\Http\Resources\EvaluationProgressResource;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Evaluation Progress",
 *   description="Monitor evaluation progress and send reminders"
 * )
 */
class EvaluationProgressController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles/{cycle}/progress",
     *   summary="Get progress for every employee in a cycle",
     *   tags={"Evaluation Progress"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Progress retrieved successfully")
     * )
     */
    public function progress(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        $this->evaluationService->expirePendingReviews($cycle->id);

        $items = $this->evaluationService->getProgressForCycle($cycle);

        return response()->json([
            'success' => true,
            'data' => EvaluationProgressResource::collection($items),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-cycles/{cycle}/employees/{employee}/reminder",
     *   summary="Send a reminder to an employee about pending reviews",
     *   tags={"Evaluation Progress"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Reminder queued successfully")
     * )
     */
    public function sendReminder(EvaluationCycle $cycle, Employee $employee): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        if ($employee->company_id !== auth()->user()->company_id) {
            return response()->json(['success' => false, 'message' => 'Employee not found for this company.'], 404);
        }

        $this->evaluationService->sendReminder($cycle, $employee);

        return response()->json([
            'success' => true,
            'message' => 'Reminder queued successfully. It will be sent when notifications are enabled.',
        ]);
    }
}
