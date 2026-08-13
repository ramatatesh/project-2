<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssistantMessage;
use App\Models\EmployeeAssistantSession;
use App\Models\LeaveType;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Services\Ai\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmployeeAssistantSessionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private User $employeeUser;

    private Employee $employee;

    private User $otherEmployeeUser;

    private Employee $otherEmployee;

    private User $crossTenantUser;

    private Employee $crossTenantEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Chat Co',
            'email' => 'chat@company.test',
            'address' => 'Damascus',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Chat Co',
            'email' => 'other-chat@company.test',
            'address' => 'Aleppo',
            'phone' => '+963111111',
            'status' => 'active',
        ]);

        $department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $otherDept = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->otherCompany->id,
            'name' => 'Sales',
            'is_active' => true,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'أحمد الموظف',
            'email' => 'employee@chat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $this->otherEmployeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Colleague',
            'email' => 'colleague@chat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->otherEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->otherEmployeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'job_title' => 'Designer',
            'base_salary' => 1200,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $this->crossTenantUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->otherCompany->id,
            'full_name' => 'Other Tenant',
            'email' => 'cross@chat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->crossTenantEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->crossTenantUser->id,
            'company_id' => $this->otherCompany->id,
            'department_id' => $otherDept->id,
            'job_title' => 'Sales',
            'base_salary' => 900,
            'hire_date' => '2023-01-01',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_create_session(): void
    {
        $this->postJson('/api/employee/assistant/sessions')->assertStatus(401);
    }

    public function test_authenticated_employee_can_create_session_with_welcome_greeting(): void
    {
        $response = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->assertCreated()
            ->assertJsonPath('success', true);

        $sessionId = $response->json('data.id');
        $this->assertNotEmpty($sessionId);

        $this->assertDatabaseHas('employee_assistant_sessions', [
            'id' => $sessionId,
            'user_id' => $this->employeeUser->id,
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
        ]);

        $welcome = $response->json('data.messages.0.message');
        $this->assertStringContainsString('أحمد الموظف', $welcome);
        $this->assertStringContainsString('كيف يمكنني مساعدتك', $welcome);
        $this->assertSame('assistant', $response->json('data.messages.0.role'));
    }

    public function test_session_belongs_to_authenticated_user_only(): void
    {
        $mine = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $this->actingAs($this->otherEmployeeUser)
            ->getJson("/api/employee/assistant/sessions/{$mine}")
            ->assertStatus(404);

        $this->actingAs($this->crossTenantUser)
            ->getJson("/api/employee/assistant/sessions/{$mine}")
            ->assertStatus(404);
    }

    public function test_employee_can_list_only_own_sessions(): void
    {
        $mine = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $this->actingAs($this->otherEmployeeUser)
            ->postJson('/api/employee/assistant/sessions');

        $response = $this->actingAs($this->employeeUser)
            ->getJson('/api/employee/assistant/sessions')
            ->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($mine, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_employee_can_send_message_and_messages_are_stored(): void
    {
        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $this->mockGemini('بقي لديك 12 يوم إجازة.');

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'كم بقي من رصيد إجازاتي؟',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.answer', 'بقي لديك 12 يوم إجازة.')
            ->assertJsonPath('data.session_id', $sessionId);

        $this->assertDatabaseHas('employee_assistant_messages', [
            'employee_assistant_session_id' => $sessionId,
            'role' => EmployeeAssistantMessage::ROLE_USER,
            'message' => 'كم بقي من رصيد إجازاتي؟',
        ]);

        $this->assertDatabaseHas('employee_assistant_messages', [
            'employee_assistant_session_id' => $sessionId,
            'role' => EmployeeAssistantMessage::ROLE_ASSISTANT,
            'message' => 'بقي لديك 12 يوم إجازة.',
        ]);
    }

    public function test_gemini_is_called_with_history_and_current_context(): void
    {
        config(['services.gemini.chat_history_limit' => 20]);

        LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Paid Free Days Leave Allocation',
            'allocation_value' => 14,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $capturedSystem = null;
        $capturedPrompt = null;
        $capturedHistory = null;

        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('generateContent')
            ->once()
            ->andReturnUsing(function (string $system, string $prompt, array $history = []) use (&$capturedSystem, &$capturedPrompt, &$capturedHistory) {
                $capturedSystem = $system;
                $capturedPrompt = $prompt;
                $capturedHistory = $history;

                return 'بقي لديك 14 يوم.';
            });
        $this->app->instance(GeminiService::class, $mock);

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'كم بقي من رصيد إجازاتي؟',
            ])
            ->assertOk();

        $this->assertNotNull($capturedHistory);
        $this->assertNotEmpty($capturedHistory);
        $this->assertSame('assistant', $capturedHistory[0]['role']);
        $this->assertStringContainsString('أحمد الموظف', $capturedHistory[0]['text']);
        $this->assertStringContainsString('SOURCE OF TRUTH', $capturedPrompt);
        $this->assertStringContainsString('leaves', $capturedPrompt);
        $this->assertStringContainsString('Do NOT greet again', $capturedSystem);
    }

    public function test_chat_history_sent_to_gemini_is_limited(): void
    {
        config(['services.gemini.chat_history_limit' => 2]);

        $session = EmployeeAssistantSession::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'title' => 'Test',
        ]);

        foreach (range(1, 5) as $i) {
            $message = new EmployeeAssistantMessage([
                'id' => Str::uuid()->toString(),
                'employee_assistant_session_id' => $session->id,
                'role' => $i % 2 === 1 ? EmployeeAssistantMessage::ROLE_USER : EmployeeAssistantMessage::ROLE_ASSISTANT,
                'message' => "msg-{$i}",
            ]);
            $message->created_at = now()->addMinutes($i);
            $message->updated_at = now()->addMinutes($i);
            $message->save();
        }

        $capturedHistory = null;
        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('generateContent')
            ->once()
            ->andReturnUsing(function (string $system, string $prompt, array $history = []) use (&$capturedHistory) {
                $capturedHistory = $history;

                return 'ok';
            });
        $this->app->instance(GeminiService::class, $mock);

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$session->id}/messages", [
                'message' => 'سؤال جديد',
            ])
            ->assertOk();

        $this->assertCount(2, $capturedHistory);
        $this->assertSame('msg-4', $capturedHistory[0]['text']);
        $this->assertSame('msg-5', $capturedHistory[1]['text']);
    }

    public function test_second_message_instructs_no_repeated_greeting_and_new_session_has_new_greeting(): void
    {
        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $systems = [];
        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('generateContent')
            ->twice()
            ->andReturnUsing(function (string $system) use (&$systems) {
                $systems[] = $system;

                return 'جواب';
            });
        $this->app->instance(GeminiService::class, $mock);

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'كم بقي من إجازاتي؟',
            ])
            ->assertOk();

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'طيب منها كم يوم سنوية؟',
            ])
            ->assertOk();

        $this->assertCount(2, $systems);
        $this->assertStringContainsString('Do NOT greet again', $systems[0]);
        $this->assertStringContainsString('continuing chat session', $systems[1]);

        $second = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->assertCreated();

        $this->assertStringContainsString('أحمد الموظف', $second->json('data.messages.0.message'));
        $this->assertNotSame($sessionId, $second->json('data.id'));
    }

    public function test_gemini_failure_keeps_user_message_without_assistant_reply(): void
    {
        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('generateContent')
            ->once()
            ->andThrow(new RuntimeException('AI service is temporarily unavailable.'));
        $this->app->instance(GeminiService::class, $mock);

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'كم بقي من إجازاتي؟',
            ])
            ->assertStatus(503);

        $this->assertDatabaseHas('employee_assistant_messages', [
            'employee_assistant_session_id' => $sessionId,
            'role' => 'user',
            'message' => 'كم بقي من إجازاتي؟',
        ]);

        $this->assertSame(
            1,
            EmployeeAssistantMessage::where('employee_assistant_session_id', $sessionId)
                ->where('role', 'assistant')
                ->count()
        );
    }

    public function test_invalid_and_oversized_messages_are_rejected(): void
    {
        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [])
            ->assertStatus(422);

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => str_repeat('ا', 2001),
            ])
            ->assertStatus(422);
    }

    public function test_session_message_does_not_include_other_employee_salary_in_prompt(): void
    {
        SalaryRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->otherEmployee->id,
            'month' => (int) now()->month,
            'year' => (int) now()->year,
            'base_salary' => 7777,
            'overtime_amount' => 0,
            'bonus_amount' => 0,
            'late_deduction' => 0,
            'absent_deduction' => 0,
            'loan_deduction' => 0,
            'manual_bonus' => 0,
            'manual_deduction' => 0,
            'net_salary' => 7777,
            'status' => SalaryRecord::STATUS_PAID,
            'closed_at' => now(),
        ]);

        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $capturedPrompt = null;
        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('generateContent')
            ->once()
            ->andReturnUsing(function (string $system, string $prompt) use (&$capturedPrompt) {
                $capturedPrompt = $prompt;

                return 'لا يمكن مشاركة رواتب الآخرين.';
            });
        $this->app->instance(GeminiService::class, $mock);

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'شو راتب الموظف Colleague؟',
            ])
            ->assertOk();

        $this->assertStringNotContainsString('7777', $capturedPrompt);
    }

    public function test_legacy_chat_endpoint_still_works_without_session(): void
    {
        $this->mockGemini('جواب مباشر');

        $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/chat', [
                'message' => 'هل عندي تقييم؟',
            ])
            ->assertOk()
            ->assertJsonPath('data.answer', 'جواب مباشر');
    }

    public function test_employee_can_delete_own_session_only(): void
    {
        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $this->actingAs($this->otherEmployeeUser)
            ->deleteJson("/api/employee/assistant/sessions/{$sessionId}")
            ->assertStatus(404);

        $this->actingAs($this->employeeUser)
            ->deleteJson("/api/employee/assistant/sessions/{$sessionId}")
            ->assertOk();

        $this->assertDatabaseMissing('employee_assistant_sessions', [
            'id' => $sessionId,
        ]);
    }

    public function test_show_session_returns_messages(): void
    {
        $sessionId = $this->actingAs($this->employeeUser)
            ->postJson('/api/employee/assistant/sessions')
            ->json('data.id');

        $this->mockGemini('12 يوم');

        $this->actingAs($this->employeeUser)
            ->postJson("/api/employee/assistant/sessions/{$sessionId}/messages", [
                'message' => 'كم بقي من إجازاتي؟',
            ])
            ->assertOk();

        $this->actingAs($this->employeeUser)
            ->getJson("/api/employee/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('success', true);

        $messages = $this->actingAs($this->employeeUser)
            ->getJson("/api/employee/assistant/sessions/{$sessionId}")
            ->json('data.messages.data');

        $this->assertGreaterThanOrEqual(3, count($messages));
    }

    private function mockGemini(string $answer): void
    {
        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('generateContent')
            ->once()
            ->andReturn($answer);
        $this->app->instance(GeminiService::class, $mock);
    }
}
