<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Document;
use App\Models\Customer;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    use HandlesApiErrors;
    protected $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    public function index(Request $request)
    {
        $query = Document::with(['user', 'documentable']);

        // Filters
        if ($request->has('documentable_type') && $request->has('documentable_id')) {
            $query->where('documentable_type', $request->documentable_type)
                ->where('documentable_id', $request->documentable_id);
        }
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function upload(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'documentable_type' => 'nullable|string',
            'documentable_id' => 'nullable|integer',
            'name' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $validated = $this->normalizeCustomerDocumentable($validated, $user);
        $companyId = $validated['_customer_company_id'] ?? $user->company_id;
        unset($validated['_customer_company_id']);
        $file = $request->file('file');

        $path = $file->store('documents/' . $companyId, 'private');
        
        $document = Document::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'documentable_type' => $validated['documentable_type'] ?? null,
            'documentable_id' => $validated['documentable_id'] ?? null,
            'name' => $validated['name'] ?? $file->getClientOriginalName(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_public' => $validated['is_public'] ?? false,
            'status' => 'active',
        ]);

        $this->activityLogService->logCreated($document);

        return response()->json($document->load(['user']), 201);
    }

    /** Accept the UI-safe Customer alias while storing Laravel's real morph class. */
    private function normalizeCustomerDocumentable(array $validated, $user): array
    {
        if (($validated['documentable_type'] ?? null) !== 'Customer') {
            return $validated;
        }

        $customer = Customer::findOrFail($validated['documentable_id'] ?? 0);
        if (!$user->isSuperAdmin() && $customer->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated['documentable_type'] = Customer::class;
        $validated['_customer_company_id'] = $customer->company_id;
        return $validated;
    }

    public function show(Document $document)
    {
        $this->activityLogService->logViewed($document);
        return response()->json($document->load(['user', 'documentable']));
    }

    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'sometimes|boolean',
            'status' => 'sometimes|in:draft,active,archived,deleted',
        ]);

        $oldValues = $document->getAttributes();
        $document->update($validated);
        $this->activityLogService->logUpdated($document, $oldValues, $document->getAttributes());

        return response()->json($document->load(['user']));
    }

    public function destroy(Document $document)
    {
        // Delete file from storage
        if (Storage::disk('private')->exists($document->path)) {
            Storage::disk('private')->delete($document->path);
        }

        $this->activityLogService->logDeleted($document);
        $document->delete();

        return response()->json(null, 204);
    }

    public function download(Document $document)
    {
        if (!Storage::disk('private')->exists($document->path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $this->activityLogService->log('downloaded', $document, $document);

        return Storage::disk('private')->download(
            $document->path,
            $document->original_name
        );
    }
}
