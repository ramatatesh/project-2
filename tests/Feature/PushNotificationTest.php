<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendPushNotificationJob;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationCycle;
use App\Models\EvaluationPolicy;
use App\Models\EvaluationReview;
use App\Models\EvaluationTemplate;
use App\Models\EvaluationTemplateQuestion;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    private User $employeeUser;

    private Employee $employee;

    private User $managerUser;

    private Employee $managerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Notify Co',
            'email' => 'notify@company.test',
            'address' => 'Address',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm-notify@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr-notify@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Manager User',
            'email' => 'manager-notify@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Engineering Manager',
            'base_salary' => 3000,
            'hire_date' => '2020-01-01',
            'employment_type' => 'full-time',
            'is_active' => false,
        ]);

        $this->department->update(['manager_id' => $this->managerEmployee->id]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee-notify@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1500,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_register_a_device(): void
    {
        $this->postJson('/api/devices', [
            'fcm_token' => 'test-fcm-token-12345',
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_employee_can_register_and_refresh_fcm_token(): void
    {
        $this->actingAs($this->employeeUser);

        $this->postJson('/api/devices', [
            'fcm_token' => 'test-fcm-token-12345',
            'platform' => 'android',
            'device_name' => 'Pixel',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $this->employeeUser->id,
            'fcm_token' => 'test-fcm-token-12345',
            'platform' => 'android',
            'is_active' => true,
        ]);

        $this->postJson('/api/devices', [
            'fcm_token' => 'test-fcm-token-12345',
            'platform' => 'android',
            'device_name' => 'Pixel 2',
        ])->assertOk();

        $this->assertEquals(1, UserDevice::query()->where('fcm_token', 'test-fcm-token-12345')->count());
        $this->assertDatabaseHas('user_devices', [
            'fcm_token' => 'test-fcm-token-12345',
            'device_name' => 'Pixel 2',
            'user_id' => $this->employeeUser->id,
        ]);
    }

    public function test_token_is_reassigned_when_another_user_registers_it(): void
    {
        $this->actingAs($this->employeeUser)
            ->postJson('/api/devices', [
                'fcm_token' => 'shared-device-token-999',
                'platform' => 'android',
            ])
            ->assertOk();

        $this->actingAs($this->managerUser)
            ->postJson('/api/devices', [
                'fcm_token' => 'shared-device-token-999',
                'platform' => 'android',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'fcm_token' => 'shared-device-token-999',
            'user_id' => $this->managerUser->id,
            'is_active' => true,
        ]);
        $this->assertEquals(1, UserDevice::query()->where('fcm_token', 'shared-device-token-999')->count());
    }

    public function test_employee_can_unregister_only_their_own_token(): void
    {
        $this->actingAs($this->employeeUser)
            ->postJson('/api/devices', [
                'fcm_token' => 'employee-token-aaa',
                'platform' => 'android',
            ])
            ->assertOk();

        $this->actingAs($this->managerUser)
            ->postJson('/api/devices', [
                'fcm_token' => 'manager-token-bbb',
                'platform' => 'android',
            ])
            ->assertOk();

        $this->actingAs($this->employeeUser)
            ->postJson('/api/devices/unregister', [
                'fcm_token' => 'manager-token-bbb',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'fcm_token' => 'manager-token-bbb',
            'user_id' => $this->managerUser->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->employeeUser)
            ->postJson('/api/devices/unregister', [
                'fcm_token' => 'employee-token-aaa',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'fcm_token' => 'employee-token-aaa',
            'user_id' => $this->employeeUser->id,
            'is_active' => false,
        ]);
    }

    public function test_launching_a_cycle_creates_notifications_for_reviewers_without_failing(): void
    {
        Queue::fake();

        $template = $this->createTemplateAndPolicy();

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Notify Cycle',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/launch")
            ->assertOk()
            ->assertJsonPath('data.created_reviews', 2);

        $selfReview = EvaluationReview::query()
            ->where('evaluation_cycle_id', $cycle->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $this->assertNotNull($selfReview);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->employeeUser->id,
            'type' => Notification::TYPE_EVALUATION_ASSIGNED,
            'related_id' => $selfReview->id,
            'related_table' => 'evaluation_reviews',
            'delivery_channel' => Notification::CHANNEL_PUSH,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->managerUser->id,
            'type' => Notification::TYPE_EVALUATION_ASSIGNED,
            'related_table' => 'evaluation_reviews',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 2);
    }

    public function test_fcm_failure_does_not_fail_cycle_launch(): void
    {
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToTokens')->andThrow(new \RuntimeException('FCM unavailable'));
        });

        $template = $this->createTemplateAndPolicy();

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'FCM Fail Cycle',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/launch")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('evaluation_reviews', [
            'evaluation_cycle_id' => $cycle->id,
            'reviewer_id' => $this->employeeUser->id,
        ]);
    }

    public function test_review_detail_is_forbidden_for_another_user(): void
    {
        Queue::fake();
        $template = $this->createTemplateAndPolicy();

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => 'Access Cycle',
            'start_date' => now()->subDay(),
            'end_date' => now()->addWeek(),
            'status' => EvaluationCycle::STATUS_DRAFT,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->hrManager)
            ->postJson("/api/hr/evaluation-cycles/{$cycle->id}/launch")
            ->assertOk();

        $selfReview = EvaluationReview::query()
            ->where('evaluation_cycle_id', $cycle->id)
            ->where('review_type', EvaluationReview::TYPE_SELF)
            ->first();

        $this->actingAs($this->managerUser)
            ->getJson("/api/evaluations/my-reviews/{$selfReview->id}")
            ->assertForbidden();

        $this->actingAs($this->employeeUser)
            ->getJson("/api/evaluations/my-reviews/{$selfReview->id}")
            ->assertOk();
    }

    private function createTemplateAndPolicy(): EvaluationTemplate
    {
        $template = EvaluationTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Notify Template',
            'is_active' => true,
            'is_archived' => false,
        ]);

        EvaluationTemplateQuestion::create([
            'evaluation_template_id' => $template->id,
            'question' => 'Quality',
            'response_type' => EvaluationTemplateQuestion::RESPONSE_TYPE_RATING,
            'sort_order' => 0,
        ]);

        EvaluationPolicy::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'peer_reviews_count' => 0,
                'apply_review_to_salary' => false,
            ]
        );

        return $template;
    }
}
