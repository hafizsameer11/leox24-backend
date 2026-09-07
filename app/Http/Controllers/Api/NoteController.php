<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Note;
use App\Models\Customer;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoteController extends Controller
{
    use HandlesApiErrors;
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        $query = Note::with(['user']);

        // Filter by noteable
        if ($request->has('noteable_type') && $request->has('noteable_id')) {
            $query->where('noteable_type', $request->noteable_type)
                ->where('noteable_id', $request->noteable_id);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Show pinned first
        $query->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'noteable_type' => 'nullable|string',
            'noteable_id' => 'nullable|integer',
            'content' => 'required|string',
            'title' => 'nullable|string|max:255',
            'type' => 'sometimes|in:note,comment,call_log,meeting,email,other',
            'is_private' => 'sometimes|boolean',
            'shared_with' => 'nullable|array',
            'is_pinned' => 'sometimes|boolean',
            'is_important' => 'sometimes|boolean',
            'parent_note_id' => 'nullable|exists:notes,id',
        ]);

        $user = $request->user();
        $validated = $this->normalizeCustomerNoteable($validated, $user);
        $validated['company_id'] = $validated['_customer_company_id'] ?? $user->company_id;
        unset($validated['_customer_company_id']);
        $validated['user_id'] = $user->id;
        $validated['type'] = $validated['type'] ?? 'note';

        $note = DB::transaction(function () use ($validated) {
            $note = Note::create($validated);
            $this->activityLogService->logCreated($note);
            return $note;
        });

        return response()->json($note->load(['user']), 201);
    }

    /** Accept the UI-safe Customer alias while storing Laravel's real morph class. */
    private function normalizeCustomerNoteable(array $validated, $user): array
    {
        if (($validated['noteable_type'] ?? null) !== 'Customer') {
            return $validated;
        }

        $customer = Customer::findOrFail($validated['noteable_id'] ?? 0);
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated['noteable_type'] = Customer::class;
        $validated['_customer_company_id'] = $customer->company_id;
        return $validated;
    }

    public function show(Note $note)
    {
        return response()->json($note->load(['user', 'noteable']));
    }

    public function update(Request $request, Note $note)
    {
        $validated = $request->validate([
            'content' => 'sometimes|string',
            'title' => 'nullable|string|max:255',
            'is_private' => 'sometimes|boolean',
            'shared_with' => 'nullable|array',
            'is_pinned' => 'sometimes|boolean',
            'is_important' => 'sometimes|boolean',
        ]);

        $oldValues = $note->getAttributes();
        $note->update($validated);
        $this->activityLogService->logUpdated($note, $oldValues, $note->getAttributes());

        return response()->json($note->load(['user']));
    }

    public function destroy(Note $note)
    {
        $this->activityLogService->logDeleted($note);
        $note->delete();
        return response()->json(null, 204);
    }

    public function pin(Request $request, Note $note)
    {
        $note->update(['is_pinned' => !$note->is_pinned]);
        return response()->json($note);
    }
}
