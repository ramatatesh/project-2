<?php

namespace App\Http\Controllers;

use App\Http\Resources\EvaluationScoreResource;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationScore;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Evaluation Results",
 *   description="View and finalize evaluation results"
 * )
 */
class EvaluationResultController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles/{cycle}/final-results",
     *   summary="List final scores for all employees in a cycle",
     *   tags={"Evaluation Results"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Results retrieved successfully")
     * )
     */
    public function index(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        $scores = EvaluationScore::where('evaluation_cycle_id', $cycle->id)
            ->with('employee.user')
            ->orderByDesc('final_score')
            ->get();

        return response()->json([
            'success' => true,
            'data' => EvaluationScoreResource::collection($scores),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles/{cycle}/final-results/{employee}",
     *   summary="Get final score for a specific employee",
     *   description="Read-only: never creates, updates, or changes the status of any score record - viewing a result has no side effects, so an already-finalized score stays finalized.",
     *   tags={"Evaluation Results"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Result retrieved successfully")
     * )
     */
    public function show(EvaluationCycle $cycle, Employee $employee): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        if ($employee->company_id !== auth()->user()->company_id) {
            return response()->json(['success' => false, 'message' => 'Employee not found for this company.'], 404);
        }

        $score = $this->evaluationService->getEmployeeScore($cycle, $employee->id);

        return response()->json([
            'success' => true,
            'data' => new EvaluationScoreResource($score->load('employee.user')),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-cycles/{cycle}/final-results/{employee}/finalize",
     *   summary="Finalize an employee's evaluation score",
     *   tags={"Evaluation Results"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="employee", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Score finalized successfully")
     * )
     */
    public function finalize(EvaluationCycle $cycle, Employee $employee): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        if ($employee->company_id !== auth()->user()->company_id) {
            return response()->json(['success' => false, 'message' => 'Employee not found for this company.'], 404);
        }

        $score = $this->evaluationService->finalizeEmployeeScore($cycle, $employee->id, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'Employee score finalized successfully.',
            'data' => new EvaluationScoreResource($score->load('employee.user')),
        ]);
    }
}
