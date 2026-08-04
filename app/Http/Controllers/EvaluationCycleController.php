<?php

namespace App\Http\Controllers;

use App\Exceptions\EvaluationCycleAlreadyClosedException;
use App\Http\Requests\StoreEvaluationCycleRequest;
use App\Http\Requests\UpdateEvaluationCycleRequest;
use App\Http\Resources\EvaluationCycleResource;
use App\Models\EvaluationCycle;
use App\Models\EvaluationReview;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Evaluation Cycles",
 *   description="Manage evaluation cycles"
 * )
 */
class EvaluationCycleController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles",
     *   summary="List evaluation cycles",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(response=200, description="Cycles retrieved successfully")
     * )
     */
    public function index(): JsonResponse
    {
        $cycles = EvaluationCycle::where('company_id', auth()->user()->company_id)
            ->with('template')
            ->withCount([
                'reviews as reviews_count',
                'reviews as completed_reviews_count' => function ($query) {
                    $query->where('status', EvaluationReview::STATUS_COMPLETED);
                },
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => EvaluationCycleResource::collection($cycles),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-cycles",
     *   summary="Create an evaluation cycle",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"name","evaluation_template_id","start_date","end_date"},
     *
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="evaluation_template_id", type="string", format="uuid"),
     *       @OA\Property(property="start_date", type="string", format="date"),
     *       @OA\Property(property="end_date", type="string", format="date")
     *     )
     *   ),
     *
     *   @OA\Response(response=201, description="Cycle created successfully")
     * )
     */
    public function store(StoreEvaluationCycleRequest $request): JsonResponse
    {
        $cycle = $this->evaluationService->createCycle(
            $request->validated(),
            auth()->user()->company_id
        );

        return response()->json([
            'success' => true,
            'data' => new EvaluationCycleResource($cycle->load('template')),
        ], 201);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-cycles/{cycle}",
     *   summary="Get an evaluation cycle",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Cycle retrieved successfully")
     * )
     */
    public function show(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        $cycle->load('template');
        $cycle->reviews_count = $cycle->reviews()->count();
        $cycle->completed_reviews_count = $cycle->reviews()->where('status', EvaluationReview::STATUS_COMPLETED)->count();

        return response()->json([
            'success' => true,
            'data' => new EvaluationCycleResource($cycle),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/hr/evaluation-cycles/{cycle}",
     *   summary="Update an evaluation cycle",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *   @OA\Response(response=200, description="Cycle updated successfully")
     * )
     */
    public function update(UpdateEvaluationCycleRequest $request, EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        if ($cycle->status === EvaluationCycle::STATUS_CLOSED) {
            return response()->json(['success' => false, 'message' => 'Closed cycles cannot be updated.'], 403);
        }

        $updated = $this->evaluationService->updateCycle($cycle, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new EvaluationCycleResource($updated->load('template')),
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/api/hr/evaluation-cycles/{cycle}",
     *   summary="Delete an evaluation cycle",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Cycle deleted successfully")
     * )
     */
    public function destroy(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);
        $this->evaluationService->deleteCycle($cycle);

        return response()->json([
            'success' => true,
            'message' => 'Cycle deleted successfully.',
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-cycles/{cycle}/launch",
     *   summary="Launch an evaluation cycle",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Cycle launched successfully")
     * )
     */
    public function launch(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        try {
            $result = $this->evaluationService->launchCycle($cycle);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cycle launched successfully.',
            'data' => $result,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-cycles/{cycle}/close",
     *   summary="Close an evaluation cycle",
     *   tags={"Evaluation Cycles"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="cycle", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Cycle closed successfully"),
     *   @OA\Response(response=409, description="Cycle is already closed")
     * )
     */
    public function close(EvaluationCycle $cycle): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($cycle, auth()->user()->company_id);

        try {
            $closed = $this->evaluationService->closeCycle($cycle);
        } catch (EvaluationCycleAlreadyClosedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cycle closed successfully.',
            'data' => new EvaluationCycleResource($closed->load('template')),
        ]);
    }
}
