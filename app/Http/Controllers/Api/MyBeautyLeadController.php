<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Services\MyBeautyLeadService;
use Illuminate\Http\Request;

class MyBeautyLeadController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private MyBeautyLeadService $leadService)
    {
    }

    /**
     * Proxy My Beauty registrations using the standard Project API settings.
     * Keeps the project API key exclusively on the CRM server.
     */
    public function index(Request $request, Project $project)
    {
        if ($project->slug !== 'mybeauty') {
            return response()->json(['message' => 'This endpoint is only available for My Beauty.'], 404);
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
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
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
