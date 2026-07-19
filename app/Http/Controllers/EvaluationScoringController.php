<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEvaluationScoreRequest;
use App\Http\Resources\EvaluationReviewResource;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationReview;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="Evaluation Scoring",
 *   description="Score submitted reviews and calculate results"
 * )
 */
class EvaluationScoringController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles/{cycle}/scorable-employees",
     *   summary="List employees who completed all their reviews",
     *   tags={"Evaluation Scoring"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Employees retrieved successfully")
     * )
     */
    public function scorableEmployees(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        return response()->json([
            'success' => true,
            'data' => $this->evaluationService->getEmployeesReadyForScoring($cycle),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles/{cycle}/scoring",
     *   summary="Get employee answers for scoring",
     *   tags={"Evaluation Scoring"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="employee_id", in="query", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="review_type", in="query", @OA\Schema(type="string", enum={"manager","self","peer"})),
     *
     *   @OA\Response(response=200, description="Scoring details retrieved successfully")
     * )
     */
    public function scoringDetails(Request $request, EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        $request->validate([
            'employee_id' => ['required', 'string', 'uuid', 'exists:employees,id'],
            'review_type' => ['nullable', 'string', 'in:manager,self,peer'],
        ]);

        $employee = Employee::findOrFail($request->input('employee_id'));
        if ($employee->company_id !== auth()->user()->company_id) {
            return response()->json(['success' => false, 'message' => 'Employee not found for this company.'], 404);
        }

        $reviews = $this->evaluationService->getScoringDetails(
            $cycle,
            $employee->id,
            $request->input('review_type')
        );

        return response()->json([
            'success' => true,
            'data' => EvaluationReviewResource::collection($reviews),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-cycles/{cycle}/reviews/{review}/score",
     *   summary="Store HR scores for a completed review",
     *   tags={"Evaluation Scoring"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="review", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"scores"},
     *
     *       @OA\Property(property="scores", type="array", @OA\Items(type="object"))
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="Scores saved successfully")
     * )
     */
    public function storeScores(StoreEvaluationScoreRequest $request, EvaluationCycle $cycle, EvaluationReview $review): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        if ($review->evaluation_cycle_id !== $cycle->id) {
            return response()->json(['success' => false, 'message' => 'Review does not belong to this cycle.'], 404);
        }

        try {
            $this->evaluationService->scoreReview($review, $request->validated()['scores']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scores saved and results updated.',
            'data' => new EvaluationReviewResource($review->fresh(['answers.question'])),
        ]);
    }
}
