<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeAssistantChatRequest;
use App\Services\Ai\EmployeeAssistantService;
use App\Services\Ai\EmployeeAssistantSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * @OA\Tag(
 *   name="Employee AI Assistant",
 *   description="Authenticated employee HR assistant (Gemini) with chat sessions + history. Context providers remain Laravel-authorized and are the source of truth; chat history is conversational context only."
 * )
 */
class EmployeeAssistantController extends Controller
{
    public function __construct(
        private readonly EmployeeAssistantService $assistantService,
        private readonly EmployeeAssistantSessionService $sessionService,
    ) {}

    /**
     * @OA\Post(
     *   path="/api/employee/assistant/sessions",
     *   summary="Start a new assistant chat session",
     *   description="Creates a session owned by auth()->user() and stores a one-time Arabic welcome greeting. Do not send user_id/employee_id/company_id.",
     *   tags={"Employee AI Assistant"},
     *   security={{"sanctum":{}}},
     *   @OA\Response(
     *     response=201,
     *     description="Session created with welcome message",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="title", type="string", nullable=true, example=null),
     *         @OA\Property(property="created_at", type="string", format="date-time"),
     *         @OA\Property(property="updated_at", type="string", format="date-time"),
     *         @OA\Property(property="messages", type="array",
     *           @OA\Items(
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="role", type="string", example="assistant"),
     *             @OA\Property(property="message", type="string", example="أهلاً بك، أحمد 👋\nكيف يمكنني مساعدتك؟"),
     *             @OA\Property(property="created_at", type="string", format="date-time")
     *           )
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function storeSession(): JsonResponse
    {
        [$user, $employee, $error] = $this->resolveEmployee();
        if ($error) {
            return $error;
        }

        $session = $this->sessionService->create($user, $employee);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $session->id,
                'title' => $session->title,
                'created_at' => optional($session->created_at)?->toIso8601String(),
                'updated_at' => optional($session->updated_at)?->toIso8601String(),
                'messages' => $session->messages
                    ->map(fn ($m) => $this->sessionService->serializeMessage($m))
                    ->values(),
            ],
        ], 201);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/assistant/sessions",
     *   summary="List the authenticated employee's chat sessions",
     *   tags={"Employee AI Assistant"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *   @OA\Response(response=200, description="Paginated sessions"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found")
     * )
     */
    public function indexSessions(Request $request): JsonResponse
    {
        [$user, $employee, $error] = $this->resolveEmployee();
        if ($error) {
            return $error;
        }

        $paginator = $this->sessionService->listForUser(
            $user,
            (int) $request->input('per_page', 15),
        );

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/api/employee/assistant/sessions/{session}",
     *   summary="Get a chat session with its messages",
     *   description="Only the session owner can access it. Returns 404 if the session does not belong to auth()->user().",
     *   tags={"Employee AI Assistant"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="session", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=50)),
     *   @OA\Response(response=200, description="Session with paginated messages"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found"),
     *   @OA\Response(response=404, description="Session not found")
     * )
     */
    public function showSession(Request $request, string $session): JsonResponse
    {
        [$user, $employee, $error] = $this->resolveEmployee();
        if ($error) {
            return $error;
        }

        $owned = $this->sessionService->findOwned($user, $session);
        if (! $owned) {
            return response()->json([
                'success' => false,
                'message' => 'Chat session not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessionService->show(
                $user,
                $owned,
                (int) $request->input('per_page', 50),
            ),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/employee/assistant/sessions/{session}/messages",
     *   summary="Send a message in a chat session",
     *   description="Saves the user message, builds current authorized employee context (source of truth), sends limited chat history + context to Gemini, saves the assistant reply. Example: 'كم بقي من رصيد إجازاتي؟' then 'طيب منها كم يوم سنوية؟'.",
     *   tags={"Employee AI Assistant"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="session", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"message"},
     *       @OA\Property(property="message", type="string", maxLength=2000, example="كم بقي من رصيد إجازاتي؟")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Assistant answer with persisted messages",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="answer", type="string", example="بقي لديك 12 يوم إجازة."),
     *         @OA\Property(property="session_id", type="string", format="uuid"),
     *         @OA\Property(property="user_message", type="object"),
     *         @OA\Property(property="assistant_message", type="object")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found"),
     *   @OA\Response(response=404, description="Session not found"),
     *   @OA\Response(response=422, description="Validation failed"),
     *   @OA\Response(response=429, description="Too many requests"),
     *   @OA\Response(response=503, description="AI service unavailable or misconfigured")
     * )
     */
    public function storeMessage(EmployeeAssistantChatRequest $request, string $session): JsonResponse
    {
        [$user, $employee, $error] = $this->resolveEmployee();
        if ($error) {
            return $error;
        }

        $owned = $this->sessionService->findOwned($user, $session);
        if (! $owned) {
            return response()->json([
                'success' => false,
                'message' => 'Chat session not found.',
            ], 404);
        }

        try {
            $result = $this->sessionService->sendMessage(
                $user,
                $employee,
                $owned,
                $request->validated()['message'],
            );
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Chat session not found.' ? 404 : 503;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/api/employee/assistant/sessions/{session}",
     *   summary="Delete a chat session owned by the authenticated employee",
     *   description="Deletes only chat history for this session. Does not delete HR data.",
     *   tags={"Employee AI Assistant"},
     *   security={{"sanctum":{}}},
     *   @OA\Parameter(name="session", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response=200, description="Deleted"),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found"),
     *   @OA\Response(response=404, description="Session not found")
     * )
     */
    public function destroySession(string $session): JsonResponse
    {
        [$user, $employee, $error] = $this->resolveEmployee();
        if ($error) {
            return $error;
        }

        $owned = $this->sessionService->findOwned($user, $session);
        if (! $owned) {
            return response()->json([
                'success' => false,
                'message' => 'Chat session not found.',
            ], 404);
        }

        $this->sessionService->delete($user, $owned);

        return response()->json([
            'success' => true,
            'message' => 'Chat session deleted.',
        ]);
    }

    /**
     * @OA\Post(
     *   path="/api/employee/assistant/chat",
     *   summary="Ask the employee AI assistant a one-shot question (legacy, no session)",
     *   description="Backward-compatible endpoint without chat history. Prefer session-based messaging for conversational context. Identity always from auth()->user().",
     *   tags={"Employee AI Assistant"},
     *   security={{"sanctum":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"message"},
     *       @OA\Property(property="message", type="string", maxLength=2000, example="كم بقي لي من رصيد الإجازات؟")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Assistant answer",
     *     @OA\JsonContent(
     *       @OA\Property(property="success", type="boolean", example=true),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="answer", type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=401, description="Unauthenticated"),
     *   @OA\Response(response=403, description="No employee record found"),
     *   @OA\Response(response=422, description="Validation failed"),
     *   @OA\Response(response=429, description="Too many requests"),
     *   @OA\Response(response=503, description="AI service unavailable or misconfigured")
     * )
     */
    public function chat(EmployeeAssistantChatRequest $request): JsonResponse
    {
        [$user, $employee, $error] = $this->resolveEmployee();
        if ($error) {
            return $error;
        }

        try {
            $result = $this->assistantService->chat(
                $user,
                $employee,
                $request->validated()['message'],
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'answer' => $result['answer'],
            ],
        ]);
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: ?JsonResponse}
     */
    private function resolveEmployee(): array
    {
        $user = auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 403)];
        }

        return [$user, $employee, null];
    }
}
