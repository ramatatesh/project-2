<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateEvaluationTemplateRequest;
use App\Http\Requests\StoreEvaluationTemplateRequest;
use App\Http\Requests\UpdateEvaluationTemplateRequest;
use App\Http\Resources\EvaluationTemplateResource;
use App\Models\EvaluationTemplate;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="Evaluation Templates",
 *   description="Manage evaluation templates and their criteria"
 * )
 */
class EvaluationTemplateController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-templates",
     *   summary="List evaluation templates",
     *   tags={"Evaluation Templates"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="archived", in="query", @OA\Schema(type="boolean")),
     *
     *   @OA\Response(response=200, description="Templates retrieved successfully",
     *
     *     @OA\JsonContent(@OA\Property(property="success", type="boolean"), @OA\Property(property="data", type="array", @OA\Items(type="object"))))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $archived = $request->boolean('archived', false);

        $templates = EvaluationTemplate::where('company_id', auth()->user()->company_id)
            ->where('is_archived', $archived)
            ->withCount('cycles')
            ->with('questions')
            ->get();

        return response()->json([
            'success' => true,
            'data' => EvaluationTemplateResource::collection($templates),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-templates",
     *   summary="Create an evaluation template",
     *   tags={"Evaluation Templates"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"name"},
     *
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="description", type="string"),
     *       @OA\Property(property="is_active", type="boolean"),
     *       @OA\Property(property="is_archived", type="boolean"),
     *       @OA\Property(property="questions", type="array", @OA\Items(type="object"))
     *     )
     *   ),
     *
     *   @OA\Response(response=201, description="Template created successfully",
     *
     *     @OA\JsonContent(@OA\Property(property="success", type="boolean"), @OA\Property(property="data", type="object")))
     * )
     */
    public function store(StoreEvaluationTemplateRequest $request): JsonResponse
    {
        $template = $this->evaluationService->createTemplate(
            $request->validated(),
            auth()->user()->company_id
        );

        return response()->json([
            'success' => true,
            'data' => new EvaluationTemplateResource($template),
        ], 201);
    }

    /**
     * @OA\Get(
     *   path="/api/hr/evaluation-templates/{template}",
     *   summary="Get a single evaluation template",
     *   tags={"Evaluation Templates"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Template retrieved successfully")
     * )
     */
    public function show(EvaluationTemplate $template): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);

        return response()->json([
            'success' => true,
            'data' => new EvaluationTemplateResource($template->load('questions')),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/api/hr/evaluation-templates/{template}",
     *   summary="Update an evaluation template",
     *   tags={"Evaluation Templates"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *
     *   @OA\Response(response=200, description="Template updated successfully")
     * )
     */
    public function update(UpdateEvaluationTemplateRequest $request, EvaluationTemplate $template): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);

        $updated = $this->evaluationService->updateTemplate($template, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new EvaluationTemplateResource($updated),
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/api/hr/evaluation-templates/{template}",
     *   summary="Delete an evaluation template",
     *   tags={"Evaluation Templates"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(response=200, description="Template deleted successfully")
     * )
     */
    public function destroy(EvaluationTemplate $template): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);
        $this->evaluationService->deleteTemplate($template);

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully.',
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/hr/evaluation-templates/{template}/duplicate",
     *   summary="Import/duplicate a template from archive",
     *   tags={"Evaluation Templates"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="template", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"name"},
     *
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="archive_source", type="boolean")
     *     )
     *   ),
     *
     *   @OA\Response(response=201, description="Template duplicated successfully")
     * )
     */
    public function duplicate(DuplicateEvaluationTemplateRequest $request, EvaluationTemplate $template): JsonResponse
    {
        $this->evaluationService->ensureOwnsCompany($template, auth()->user()->company_id);

        $newTemplate = $this->evaluationService->duplicateTemplate(
            $template,
            $request->validated()['name'],
            $request->boolean('archive_source', false)
        );

        return response()->json([
            'success' => true,
            'message' => 'Template imported successfully.',
            'data' => new EvaluationTemplateResource($newTemplate),
        ], 201);
    }
}
