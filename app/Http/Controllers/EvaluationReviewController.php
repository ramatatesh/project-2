<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitEvaluationReviewRequest;
use App\Http\Resources\EvaluationReviewResource;
use App\Models\EvaluationReview;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="My Evaluations",
 *   description="Endpoints for reviewers to submit self/manager/peer reviews"
 * )
 */
class EvaluationReviewController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Get(
     *   path="/api/evaluations/my-reviews",
     *   summary="Get pending/completed/expired reviews assigned to the authenticated user",
     *   tags={"My Evaluations"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"pending","completed","expired"})),
     *
     *   @OA\Response(response=200, description="Reviews retrieved successfully")
     * )
     */
    public function myReviews(Request $request): JsonResponse
{
    $this->evaluationService->expirePendingReviews();

    $query = EvaluationReview::where('reviewer_id', auth()->id())
        ->with(['cycle.template.questions', 'employee.user', 'employee.department']);

    if ($request->filled('status')) {
        $query->where('status', $request->input('status'));
    }

    $reviews = $query->orderByDesc('created_at')->get();

    $completed = $reviews
        ->where('status', EvaluationReview::STATUS_COMPLETED)
        ->count();

    $pending = $reviews
        ->where('status', EvaluationReview::STATUS_PENDING)
        ->count();

    $total = $reviews->count();

    $completionPercentage = $total > 0
        ? round(($completed / $total) * 100)
        : 0;

    return response()->json([
        'success' => true,

        'completed' => $completed,
        'pending' => $pending,
        'completion_percentage' => $completionPercentage,

        'data' => EvaluationReviewResource::collection($reviews),
    ]);
}

    /**
     * @OA\Get(
     *   path="/api/evaluations/my-reviews/{review}",
     *   summary="Get review details including questions",
     *   tags={"My Evaluations"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="review", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Review retrieved successfully")
     * )
     */
    public function show(EvaluationReview $review): JsonResponse
    {
        if ($review->reviewer_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'This review is not assigned to you.'], 403);
        }

        $this->evaluationService->expireReviewIfPastDue($review);
        $review->refresh();

        return response()->json([
            'success' => true,
            'data' => new EvaluationReviewResource($this->evaluationService->getReviewDetails($review)),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/evaluations/my-reviews/{review}/submit",
     *   summary="Submit a review with answers",
     *   tags={"My Evaluations"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="review", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"answers"},
     *
     *       @OA\Property(property="answers", type="array", @OA\Items(type="object"))
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="Review submitted successfully"),
     *   @OA\Response(response=403, description="Cycle is closed")
     * )
     */
    public function submit(SubmitEvaluationReviewRequest $request, EvaluationReview $review): JsonResponse
    {
        if ($review->reviewer_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'This review is not assigned to you.'], 403);
        }

        try {
            $this->evaluationService->submitReview($review, $request->validated()['answers']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully.',
            'data' => new EvaluationReviewResource($review->fresh(['answers.question'])),
        ]);
    }
}
