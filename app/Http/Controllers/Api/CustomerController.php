<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Services\ActivityLogService;
use App\Services\CustomerDeduplicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private CustomerDeduplicationService $deduplicationService,
        private ActivityLogService $activityLogService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Customer::query()->with('category:id,name');

        if (!$user->isSuperAdmin()) {
            $query->where('company_id', $user->company_id);
        } elseif ($request->has('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('second_last_name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('tax_code', 'like', "%{$search}%")
                    ->orWhere('vat', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate($this->customerRules(null, true));
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : $user->company_id;

        $this->ensureCategoryAccess($validated['category_id'] ?? null, $companyId);
        $validated['created_by'] = $user->id;
        $validated['date_added'] = $validated['date_added'] ?? now()->toDateString();
        $customer = $this->deduplicationService->findOrCreateCustomer($validated, $companyId);

        if ($customer->wasRecentlyCreated) {
            $this->activityLogService->logCreated($customer);
        }

        return response()->json($customer->load(['createdBy:id,name,email', 'category:id,name,description']), 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->ensureAccess($request, $customer);

        $customer->load([
            'opportunities.assignee',
            'tasks.assignee',
            'tasks.creator',
            'notes.user',
            'documents.user',
            'company',
            'createdBy:id,name,email',
            'category:id,name,description',
        ]);

        $activityLogs = ActivityLog::where('model_type', Customer::class)
            ->where('model_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->with('user')
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'customer' => $customer,
            'stats' => [
                'opportunities_count' => $customer->opportunities()->count(),
                'open_opportunities_count' => $customer->opportunities()->open()->count(),
                'total_opportunities_value' => $customer->opportunities()->sum('value'),
                'tasks_count' => $customer->tasks()->count(),
                'pending_tasks_count' => $customer->tasks()->pending()->count(),
                'completed_tasks_count' => $customer->tasks()->completed()->count(),
                'notes_count' => $customer->notes()->count(),
                'documents_count' => $customer->documents()->count(),
                'activity_logs_count' => $activityLogs->count(),
            ],
            'activity_logs' => $activityLogs,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->ensureAccess($request, $customer);
        $validated = $request->validate($this->customerRules($customer));
        $this->ensureCategoryAccess($validated['category_id'] ?? null, $customer->company_id);
        $oldValues = $customer->getAttributes();
        $customer->update($validated);
        $this->activityLogService->logUpdated($customer, $oldValues, $customer->getAttributes());

        return response()->json($customer->fresh(['createdBy:id,name,email', 'category:id,name,description']));
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->ensureAccess($request, $customer);
        $this->activityLogService->logDeleted($customer);
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    /** Store a user-authored customer activity alongside automatic activity records. */
    public function storeActivity(Request $request, Customer $customer)
    {
        $this->ensureAccess($request, $customer);
        $validated = $request->validate([
            'action' => 'required|string|max:60',
            'description' => 'required|string|max:2000',
            'severity' => 'nullable|in:info,warning,error,critical',
        ]);
        $user = $request->user();
        $activity = ActivityLog::create([
            'company_id' => $customer->company_id,
            'user_id' => $user->id,
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'model_type' => Customer::class,
            'model_id' => $customer->id,
            'action' => $validated['action'],
            'description' => $validated['description'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'properties' => ['manual' => true],
            'severity' => $validated['severity'] ?? 'info',
        ]);

        return response()->json($activity->load('user'), 201);
    }

    public function merge(Request $request)
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array|min:2',
            'customer_ids.*' => 'exists:customers,id',
            'primary_customer_id' => 'required|exists:customers,id',
        ]);
        $primary = Customer::findOrFail($validated['primary_customer_id']);
        $this->ensureAccess($request, $primary);
        $customer = $this->deduplicationService->mergeCustomers(
            $validated['customer_ids'],
            $validated['primary_customer_id']
        );

        return response()->json(['message' => 'Customers merged successfully', 'customer' => $customer]);
    }

    private function ensureAccess(Request $request, Customer $customer): void
    {
        $user = $request->user();
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }
    }

    private function customerRules(?Customer $customer = null, bool $creating = false): array
    {
        $ignoreId = $customer?->id;
        return [
            'email' => $creating
                ? ['required', 'email', Rule::unique('customers', 'email')]
                : ['sometimes', 'email', Rule::unique('customers', 'email')->ignore($ignoreId)],
            'phone' => $creating
                ? ['required', 'string', 'max:80', Rule::unique('customers', 'phone')]
                : ['sometimes', 'string', 'max:80', Rule::unique('customers', 'phone')->ignore($ignoreId)],
            'vat' => ['nullable', 'string', 'max:120', Rule::unique('customers', 'vat')->ignore($ignoreId)],
            'customer_code' => ['nullable', 'string', 'max:80', Rule::unique('customers', 'customer_code')->ignore($ignoreId)],
            'category_id' => 'nullable|integer|exists:categories,id',
            'title' => 'nullable|string|max:30',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'second_last_name' => 'nullable|string|max:120',
            'customer_group' => 'nullable|string|max:120',
            'gender' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:2000',
            'city' => 'nullable|string|max:120',
            'zip_code' => 'nullable|string|max:30',
            'state_province' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date',
            'place_of_birth' => 'nullable|string|max:160',
            'branch' => 'nullable|string|max:120',
            'date_added' => 'nullable|date',
            'tax_code' => 'nullable|string|max:120',
            'pec_email' => 'nullable|email|max:255',
            'tax_code_fe' => 'nullable|string|max:120',
            'phone_secondary' => 'nullable|string|max:80',
            'mobile' => 'nullable|string|max:80',
            'fax' => 'nullable|string|max:80',
            'privacy_date' => 'nullable|date',
            'privacy_consent_processing' => 'sometimes|boolean',
            'marketing_consent' => 'sometimes|boolean',
            'profiling_consent' => 'sometimes|boolean',
            'send_sms' => 'sometimes|boolean',
            'send_mail' => 'sometimes|boolean',
            'send_newsletter' => 'sometimes|boolean',
            'billing_address' => 'nullable|string|max:2000',
            'billing_city' => 'nullable|string|max:120',
            'billing_zip_code' => 'nullable|string|max:30',
            'billing_state' => 'nullable|string|max:120',
            'billing_country' => 'nullable|string|max:100',
            'family_members' => 'nullable|string|max:4000',
            'language' => 'nullable|string|max:10',
            'private_notes' => 'nullable|string|max:4000',
            'notes' => 'nullable|string|max:4000',
            'occupation' => 'nullable|string|max:160',
            'vision_problem' => 'nullable|string|max:255',
            'hobbies' => 'nullable|string|max:500',
            'acquired_by' => 'nullable|string|max:160',
            'promotion' => 'nullable|string|max:255',
            'referred_by' => 'nullable|string|max:255',
        ];
    }

    /** Ensure a customer can only be assigned a category from the same CRM company. */
    private function ensureCategoryAccess(?int $categoryId, mixed $companyId): void
    {
        if (!$categoryId) {
            return;
        }

        $category = Category::withoutGlobalScopes()->findOrFail($categoryId);

        if ((string) $category->company_id !== (string) $companyId) {
            abort(422, 'The selected category is not available for this company.');
        }
    }
}
