<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Task;
use App\Models\Customer;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use HandlesApiErrors;
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Task::with(['assignee', 'creator', 'taskable']);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->has('overdue')) {
            $query->overdue();
        }
        if ($request->has('taskable_type') && $request->has('taskable_id')) {
            $query->where('taskable_type', $request->taskable_type)
                ->where('taskable_id', $request->taskable_id);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'due_date');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'taskable_type' => 'nullable|string',
            'taskable_id' => 'nullable|integer',
            'assigned_to' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'due_date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'estimated_hours' => 'nullable|integer|min:0',
            'is_recurring' => 'sometimes|boolean',
            'recurrence_pattern' => 'nullable|string',
            'reminders' => 'nullable|array',
        ]);

        $user = $request->user();
        $validated = $this->normalizeCustomerTaskable($validated, $user);
        $validated['company_id'] = $validated['_customer_company_id'] ?? $user->company_id;
        unset($validated['_customer_company_id']);
        $validated['created_by'] = $user->id;
        $validated['status'] = $validated['status'] ?? 'pending';

        $task = DB::transaction(function () use ($validated) {
            $task = Task::create($validated);
            $this->activityLogService->logCreated($task);
            return $task;
        });

        return response()->json($task->load(['assignee', 'creator']), 201);
    }

    /** Accept the UI-safe Customer alias while storing Laravel's real morph class. */
    private function normalizeCustomerTaskable(array $validated, $user): array
    {
        if (($validated['taskable_type'] ?? null) !== 'Customer') {
            return $validated;
        }

        $customer = Customer::findOrFail($validated['taskable_id'] ?? 0);
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated['taskable_type'] = Customer::class;
        $validated['_customer_company_id'] = $customer->company_id;
        return $validated;
    }

    public function show(Task $task)
    {
        $this->activityLogService->logViewed($task);
        return response()->json($task->load(['assignee', 'creator', 'taskable', 'subtasks', 'notes']));
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'due_date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'progress' => 'nullable|integer|min:0|max:100',
            'estimated_hours' => 'nullable|integer|min:0',
            'actual_hours' => 'nullable|integer|min:0',
            'reminders' => 'nullable|array',
        ]);

        $oldValues = $task->getAttributes();

        $task = DB::transaction(function () use ($task, $validated) {
            // If status changed to completed, set completed_at and progress
            if (isset($validated['status']) && $validated['status'] === 'completed') {
                $validated['completed_at'] = now();
                $validated['progress'] = 100;
            }

            $task->update($validated);
            $this->activityLogService->logUpdated($task, $oldValues, $task->getAttributes());
            return $task;
        });

        return response()->json($task->load(['assignee', 'creator']));
    }

    public function destroy(Task $task)
    {
        $this->activityLogService->logDeleted($task);
        $task->delete();
        return response()->json(null, 204);
    }

    public function complete(Request $request, Task $task)
    {
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'progress' => 100,
        ]);

        $this->activityLogService->log('completed', $task, $task);

        return response()->json($task->load(['assignee', 'creator']));
    }

    public function assign(Request $request, Task $task)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $task->update($validated);
        $this->activityLogService->log('assigned', $task, $task, null, null, ['assigned_to' => $validated['assigned_to']]);

        return response()->json($task->load(['assignee', 'creator']));
    }
}
