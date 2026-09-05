<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Services\MyPetPlusLeadService;
use Illuminate\Http\Request;

class MyPetPlusLeadController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private MyPetPlusLeadService $leadService)
    {
    }

    /**
     * Proxy MyPet Plus registrations using the standard Project API settings.
     * This mirrors the existing project integration approach while keeping the
     * project API key exclusively on the CRM server.
     */
    public function index(Request $request, Project $project)
    {
        if ($project->slug !== 'mypetplus') {
            return response()->json(['message' => 'This endpoint is only available for MyPet Plus.'], 404);
        }

        $user = $request->user();
        if (!$user->isSuperAdmin()) {
            $hasAccess = CompanyProjectAccess::where('company_id', $user->company_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return response()->json(['message' => 'Access denied: No access to this project.'], 403);
            }
        }

        $filters = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:120',
            'name' => 'nullable|string|max:120',
            'email' => 'nullable|string|max:160',
            'role' => 'nullable|string|max:60',
            'status' => 'nullable|string|max:60',
            'specialization' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'area' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'documentType' => 'nullable|string|max:120',
        ]);

        try {
            return response()->json($this->leadService->fetch($project, $filters));
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception, 502, [
                'project_id' => $project->id,
            ]);
        }
    }
}
