<?php

namespace App\Services\Ai;

use App\Models\Employee;
use App\Models\EmployeeAssistantMessage;
use App\Models\EmployeeAssistantSession;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class EmployeeAssistantSessionService
{
    public function __construct(
        private readonly EmployeeAssistantService $assistantService,
    ) {}

    /**
     * Create a new chat session owned by the authenticated employee and store the welcome greeting.
     */
    public function create(User $user, Employee $employee): EmployeeAssistantSession
    {
        $this->assertEmployeeOwnership($user, $employee);

        return DB::transaction(function () use ($user, $employee) {
            $session = EmployeeAssistantSession::create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'employee_id' => $employee->id,
                'company_id' => $user->company_id,
                'title' => null,
            ]);

            EmployeeAssistantMessage::create([
                'id' => Str::uuid()->toString(),
                'employee_assistant_session_id' => $session->id,
                'role' => EmployeeAssistantMessage::ROLE_ASSISTANT,
                'message' => $this->welcomeMessage($user),
            ]);

            return $session->fresh('messages');
        });
    }

    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return EmployeeAssistantSession::query()
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->through(fn (EmployeeAssistantSession $session) => $this->serializeSessionSummary($session));
    }

    /**
     * Resolve a session that belongs to the authenticated user/company, or null.
     */
    public function findOwned(User $user, string $sessionId): ?EmployeeAssistantSession
    {
        return EmployeeAssistantSession::query()
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->first();
    }

    public function show(User $user, EmployeeAssistantSession $session, int $perPage = 50): array
    {
        $this->assertSessionOwnership($user, $session);

        $perPage = max(1, min($perPage, 100));

        $messages = EmployeeAssistantMessage::query()
            ->where('employee_assistant_session_id', $session->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);

        $messages->getCollection()->transform(
            fn (EmployeeAssistantMessage $message) => $this->serializeMessage($message)
        );

        return [
            'id' => $session->id,
            'title' => $session->title,
            'created_at' => optional($session->created_at)?->toIso8601String(),
            'updated_at' => optional($session->updated_at)?->toIso8601String(),
            'messages' => $messages,
        ];
    }

    /**
     * Send a user message inside an owned session, persist history, and return the assistant answer.
     *
     * @return array{answer: string, session_id: string, user_message: array<string, mixed>, assistant_message: array<string, mixed>}
     */
    public function sendMessage(User $user, Employee $employee, EmployeeAssistantSession $session, string $message): array
    {
        $this->assertEmployeeOwnership($user, $employee);
        $this->assertSessionOwnership($user, $session);

        if ($session->employee_id !== $employee->id) {
            throw new RuntimeException('Employee record mismatch.');
        }

        $historyLimit = max(0, (int) config('services.gemini.chat_history_limit', 20));

        $priorMessages = EmployeeAssistantMessage::query()
            ->where('employee_assistant_session_id', $session->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($historyLimit > 0 && $priorMessages->count() > $historyLimit) {
            $priorMessages = $priorMessages->slice(-$historyLimit)->values();
        } elseif ($historyLimit === 0) {
            $priorMessages = $priorMessages->take(0);
        }

        $userMessage = EmployeeAssistantMessage::create([
            'id' => Str::uuid()->toString(),
            'employee_assistant_session_id' => $session->id,
            'role' => EmployeeAssistantMessage::ROLE_USER,
            'message' => $message,
        ]);

        if (! $session->title) {
            $session->title = mb_substr(trim($message), 0, 80) ?: null;
        }

        $session->touch();

        $historyTurns = $priorMessages->map(fn (EmployeeAssistantMessage $m) => [
            'role' => $m->role,
            'text' => $m->message,
        ])->all();

        try {
            $result = $this->assistantService->chatInSession(
                $user,
                $employee,
                $message,
                $historyTurns,
                isFirstUserMessage: $priorMessages
                    ->where('role', EmployeeAssistantMessage::ROLE_USER)
                    ->isEmpty(),
            );
        } catch (RuntimeException $e) {
            // User message is kept; no fake assistant reply is stored.
            throw $e;
        }

        $assistantMessage = EmployeeAssistantMessage::create([
            'id' => Str::uuid()->toString(),
            'employee_assistant_session_id' => $session->id,
            'role' => EmployeeAssistantMessage::ROLE_ASSISTANT,
            'message' => $result['answer'],
        ]);

        $session->touch();

        return [
            'answer' => $result['answer'],
            'session_id' => $session->id,
            'user_message' => $this->serializeMessage($userMessage),
            'assistant_message' => $this->serializeMessage($assistantMessage),
        ];
    }

    public function delete(User $user, EmployeeAssistantSession $session): void
    {
        $this->assertSessionOwnership($user, $session);
        $session->delete();
    }

    public function serializeSessionSummary(EmployeeAssistantSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'created_at' => optional($session->created_at)?->toIso8601String(),
            'updated_at' => optional($session->updated_at)?->toIso8601String(),
        ];
    }

    public function serializeMessage(EmployeeAssistantMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'message' => $message->message,
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];
    }

    public function welcomeMessage(User $user): string
    {
        $name = trim((string) $user->full_name);

        if ($name === '') {
            return __("Welcome 👋\nHow can I help you?");
        }

        return __('Welcome, :name 👋'."\n".'How can I help you?', ['name' => $name]);
    }

    private function assertEmployeeOwnership(User $user, Employee $employee): void
    {
        if ($employee->user_id !== $user->id || $employee->company_id !== $user->company_id) {
            throw new RuntimeException('Employee record mismatch.');
        }
    }

    private function assertSessionOwnership(User $user, EmployeeAssistantSession $session): void
    {
        if ($session->user_id !== $user->id || $session->company_id !== $user->company_id) {
            throw new RuntimeException('Chat session not found.');
        }
    }
}
