<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Services\MyDoctorPlusLeadService;
use Illuminate\Http\Request;

class MyDoctorPlusLeadController extends Controller
{
    use HandlesApiErrors;

    public function __construct(private MyDoctorPlusLeadService $leadService)
    {
    }

    /**
     * Proxy My Doctor Plus registrations, subscriptions and commercial
     * analytics. Project credentials stay encrypted on the CRM server.
     */
    public function index(Request $request, Project $project)
    {
        if ($project->slug !== 'mydoctorplus') {
            return response()->json(['message' => 'This endpoint is only available for My Doctor Plus.'], 404);
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
            'role' => 'nullable|string|in:ADMIN,DOCTOR,PATIENT,PHARMACY,PARAPHARMACY',
            'status' => 'nullable|string|in:PENDING,APPROVED,REJECTED,BLOCKED',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'area' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
            'subscriptionPlan' => 'nullable|string|max:120',
            'subscriptionStatus' => 'nullable|string|in:ACTIVE,EXPIRED,CANCELLED,NOT_SUBSCRIBED',
            'paymentStatus' => 'nullable|string|in:PENDING,SUCCESS,FAILED,REFUNDED',
            'fromDate' => 'nullable|date',
            'toDate' => 'nullable|date|after_or_equal:fromDate',
            'sortBy' => 'nullable|string|in:createdAt,updatedAt,name,role,status',
            'sortDirection' => 'nullable|string|in:asc,desc',
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
