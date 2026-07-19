<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEvaluationQuestionRequest;
use App\Http\Requests\UpdateEvaluationQuestionRequest;
use App\Http\Resources\EvaluationTemplateQuestionResource;
use App\Models\EvaluationTemplate;
use App\Models\EvaluationTemplateQuestion;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *   name="Evaluation Questions",
 *   description="Manage evaluation template criteria/questions"
 * )
 */
class EvaluationQuestionController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-templates/{template}/questions",
     *   summary="Add a question to a template",
     *   tags={"Evaluation Questions"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"question","response_type"},
     *
     *       @OA\Property(property="question", type="string"),
     *       @OA\Property(property="response_type", type="string"),
     *       @OA\Property(property="sort_order", type="integer"),
     *       @OA\Property(property="weight", type="number")
     *     )
     *   ),
     *
     *   @OA\Response(response=201, description="Question added successfully")
     * )
     */
    public function store(StoreEvaluationQuestionRequest $request, EvaluationTemplate $template): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);

        $data = $request->validated();
        $question = EvaluationTemplateQuestion::create([
            'evaluation_template_id' => $template->id,
            'question' => $data['question'],
            'response_type' => $data['response_type'],
            'sort_order' => $data['sort_order'] ?? 0,
            'weight' => $data['weight'] ?? 1,
        ]);

        return response()->json([
            'success' => true,
            'data' => new EvaluationTemplateQuestionResource($question),
        ], 201);
    }

    /**
     * @OA\Put(
     *   path="/api/hr/evaluation-templates/{template}/questions/{question}",
     *   summary="Update a template question",
     *   tags={"Evaluation Questions"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="question", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *   @OA\Response(response=200, description="Question updated successfully")
     * )
     */
    public function update(UpdateEvaluationQuestionRequest $request, EvaluationTemplate $template, EvaluationTemplateQuestion $question): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);

        if ($question->evaluation_template_id !== $template->id) {
            return response()->json(['success' => false, 'message' => 'Question does not belong to this template.'], 404);
        }

        $question->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new EvaluationTemplateQuestionResource($question),
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/api/hr/evaluation-templates/{template}/questions/{question}",
     *   summary="Delete a template question",
     *   tags={"Evaluation Questions"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="question", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Question deleted successfully")
     * )
     */
    public function destroy(EvaluationTemplate $template, EvaluationTemplateQuestion $question): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);

        if ($question->evaluation_template_id !== $template->id) {
            return response()->json(['success' => false, 'message' => 'Question does not belong to this template.'], 404);
        }

        $question->delete();

        return response()->json(['success' => true, 'message' => 'Question deleted successfully.']);
    }
}
