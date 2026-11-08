<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use HandlesApiErrors;
    /**
     * Display a listing of companies.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Company::query();

        // Super admin sees all, others see only their company
        if (!$user->isSuperAdmin()) {
            $query->where('id', $user->company_id);
        }

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('vat', 'like', "%{$search}%");
            });
        }
        $query->orderBy('created_at', 'desc');
        $companies = $query->with('users', 'projectAccesses.project')
            ->paginate($request->get('per_page', 15));

        return response()->json($companies);
    }

    /**
     * Store a newly created company.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Only super admin can create companies
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can create companies');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'vat' => 'nullable|string|unique:companies,vat',
            'address' => 'nullable|string',
            'status' => 'nullable|in:pending,active,suspended',
            'settings' => 'nullable|array',
        ]);

        $company = Company::create($validated);

        return response()->json($company, 201);
    }

    /**
     * Display the specified company.
     */
    public function show(Request $request, Company $company)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $company->id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $company->load('users', 'projectAccesses.project', 'customers', 'signupRequests');

        // Get the latest signup request with requested projects
        $signupRequest = $company->signupRequests()->latest()->first();
        
        $responseData = $company->toArray();
        
        if ($signupRequest) {
            // Get requested projects (can be empty array)
            $requestedProjectIds = $signupRequest->requested_projects ?? [];
            
            $requestedProjects = [];
            if (!empty($requestedProjectIds) && is_array($requestedProjectIds)) {
                // Load the actual project details for requested projects
                $requestedProjects = \App\Models\Project::whereIn('id', $requestedProjectIds)
                    ->select('id', 'name', 'slug', 'description', 'integration_type')
                    ->get()
                    ->toArray();
            }
            
            $responseData['signup_request'] = [
                'id' => $signupRequest->id,
                'status' => $signupRequest->status,
                'requested_projects' => $requestedProjects,
                'requested_at' => $signupRequest->created_at?->toISOString(),
                'reviewed_at' => $signupRequest->reviewed_at?->toISOString(),
            ];
        }

        return response()->json($responseData);
    }

    /**
     * Update the specified company.
     */
    public function update(Request $request, Company $company)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $company->id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'vat' => 'sometimes|string|unique:companies,vat,' . $company->id,
            'address' => 'nullable|string',
            'status' => 'sometimes|in:pending,active,suspended',
            'settings' => 'nullable|array',
        ]);

        $company->update($validated);

        return response()->json($company);
    }

    /**
     * Remove the specified company.
     */
    public function destroy(Request $request, Company $company)
    {
        $user = $request->user();

        // Only super admin can delete companies
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can delete companies');
        }

        $company->delete();

        return response()->json(['message' => 'Company deleted successfully']);
    }

    /**
     * Get projects accessible by a company.
     */
    public function projects(Request $request, Company $company)
    {
        $user = $request->user();

        // Only super admin can view company projects
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can view company projects');
        }

        $accesses = \App\Models\CompanyProjectAccess::where('company_id', $company->id)
            ->with(['project', 'projectUsers.user'])
            ->get();

        // Include project users in response
        $accessesData = $accesses->map(function ($access) {
            $accessData = $access->toArray();
            $accessData['project_users'] = $access->projectUsers->map(function ($pu) {
                return [
                    'id' => $pu->id,
                    'user_id' => $pu->user_id,
                    'user' => $pu->user ? [
                        'id' => $pu->user->id,
                        'name' => $pu->user->name,
                        'email' => $pu->user->email,
                        'role' => $pu->user->role,
                    ] : null,
                    'external_user_id' => $pu->external_user_id,
                    'external_username' => $pu->external_username,
                    'status' => $pu->status,
                ];
            });
            return $accessData;
        });

        return response()->json($accessesData);
    }

    /**
     * List lead categories granted to a company.
     */
    public function categories(Request $request, Company $company)
    {
        $user = $request->user();
        if (! $user->isSuperAdmin() && (int) $user->company_id !== (int) $company->id) {
            abort(403, 'Access denied');
        }

        $categories = $company->categories()
            ->orderBy('name')
            ->get(['categories.id', 'categories.name', 'categories.description']);

        return response()->json($categories);
    }

    /**
     * Grant a lead category to a company (super admin only).
     */
    public function grantCategoryAccess(Request $request, Company $company)
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            abort(403, 'Only super admin can grant category access');
        }

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        $company->categories()->syncWithoutDetaching([(int) $validated['category_id']]);

        return response()->json([
            'message' => 'Category access granted',
            'categories' => $company->categories()->orderBy('name')->get(['categories.id', 'categories.name']),
        ]);
    }

    /**
     * Revoke a lead category from a company (super admin only).
     */
    public function revokeCategoryAccess(Request $request, Company $company, int $categoryId)
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            abort(403, 'Only super admin can revoke category access');
        }

        $company->categories()->detach($categoryId);

        return response()->json([
            'message' => 'Category access revoked',
            'categories' => $company->categories()->orderBy('name')->get(['categories.id', 'categories.name']),
        ]);
    }

    /**
     * Grant project access to a company.
     */
    public function grantProjectAccess(Request $request, Company $company)
    {
        $user = $request->user();

        // Only super admin can grant access
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can grant project access');
        }

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'status' => 'sometimes|in:pending,active,suspended,revoked',
            'api_credentials' => 'nullable|array',
            'api_credentials.api_key' => 'nullable|string',
            'api_credentials.api_secret' => 'nullable|string',
            'external_company_id' => 'nullable|string|max:255',
        ]);

        // Check if access already exists
        $existingAccess = \App\Models\CompanyProjectAccess::where('company_id', $company->id)
            ->where('project_id', $validated['project_id'])
            ->first();

        if ($existingAccess) {
            $oldStatus = $existingAccess->status;
            
            // Update existing access
            $existingAccess->update([
                'status' => $validated['status'] ?? 'active',
                'external_company_id' => $validated['external_company_id'] ?? $existingAccess->external_company_id,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);

            // Store API credentials if provided
            if (isset($validated['api_credentials']) && !empty($validated['api_credentials'])) {
                \Illuminate\Support\Facades\Log::info('Updating API credentials for project access', [
                    'access_id' => $existingAccess->id,
                    'has_api_key' => isset($validated['api_credentials']['api_key']),
                    'has_api_secret' => isset($validated['api_credentials']['api_secret']),
                ]);
                $existingAccess->setEncryptedApiCredentials($validated['api_credentials']);
                $existingAccess->save();
            }

            // Load project relationship
            $existingAccess->load('project');
            $project = $existingAccess->project;

            \Illuminate\Support\Facades\Log::info('Updating existing project access', [
                'access_id' => $existingAccess->id,
                'company_id' => $company->id,
                'project_id' => $project->id ?? null,
                'project_slug' => $project->slug ?? null,
                'old_status' => $oldStatus,
                'new_status' => $existingAccess->status,
            ]);

            // Create company_project_users entries if they don't exist
            $this->createCompanyProjectUsers($existingAccess);
            
            // Refresh access but keep project relationship
            $existingAccess->refresh();
            $existingAccess->load('project');
            $project = $existingAccess->project; // Re-assign after refresh

            $registrationResult = null;
            
            // Register users to doctor project if status is active (always try if it's doctor project)
            $newStatus = $existingAccess->status;
            if ($newStatus === 'active' && $project && $project->slug === 'mydoctor') {
                \Illuminate\Support\Facades\Log::info('Doctor project detected (update), proceeding with registration', [
                    'access_id' => $existingAccess->id,
                    'project_id' => $project->id,
                    'project_slug' => $project->slug,
                    'access_status' => $newStatus,
                    'old_status' => $oldStatus,
                ]);
                try {
                    \Illuminate\Support\Facades\Log::info('Attempting to register users for doctor project (update)', [
                        'access_id' => $existingAccess->id,
                        'project_id' => $project->id,
                        'project_slug' => $project->slug,
                    ]);
                    
                    $registrationService = app(\App\Services\DoctorProjectRegistrationService::class);
                    $registrationResult = $registrationService->registerUsersForDoctorProject($existingAccess);
                    
                    \Illuminate\Support\Facades\Log::info('Registration result (update)', [
                        'access_id' => $existingAccess->id,
                        'result' => $registrationResult,
                    ]);
                } catch (\Exception $e) {
                    $registrationResult = [
                        'success' => false,
                        'error' => [
                            'message' => $e->getMessage(),
                            'type' => get_class($e),
                        ],
                        'message' => 'Registration failed: ' . $e->getMessage(),
                    ];
                }
            }
            
            // Register users to TG Calabria project if status is active and project is tg-calabria
            if ($newStatus === 'active' && $project && $project->slug === 'tg-calabria') {
                \Illuminate\Support\Facades\Log::info('TG Calabria project detected (update), proceeding with registration', [
                    'access_id' => $existingAccess->id,
                    'project_id' => $project->id,
                    'project_slug' => $project->slug,
                    'access_status' => $newStatus,
                    'old_status' => $oldStatus,
                ]);
                try {
                    \Illuminate\Support\Facades\Log::info('Attempting to register users for TG Calabria project (update)', [
                        'access_id' => $existingAccess->id,
                        'project_id' => $project->id,
                        'project_slug' => $project->slug,
                    ]);
                    
                    $registrationService = app(\App\Services\TGCalabriaProjectRegistrationService::class);
                    $registrationResult = $registrationService->registerUsersForTGCalabriaProject($existingAccess);
                    
                    \Illuminate\Support\Facades\Log::info('TG Calabria registration result (update)', [
                        'access_id' => $existingAccess->id,
                        'result' => $registrationResult,
                    ]);
                } catch (\Exception $e) {
                    return $this->errorResponse(
                        'Failed to register users to TG Calabria project',
                        $e,
                        500,
                        ['access_id' => $existingAccess->id, 'project_id' => $project->id]
                    );
                    
                    $registrationResult = [
                        'success' => false,
                        'message' => 'Registration failed: ' . $e->getMessage(),
                    ];
                }
            }

            $responseData = $existingAccess->load(['project', 'projectUsers.user'])->toArray();
            if ($registrationResult) {
                $responseData['registration_result'] = $registrationResult;
            }

            \Illuminate\Support\Facades\Log::info('Project access updated successfully', [
                'access_id' => $existingAccess->id,
                'has_registration_result' => !is_null($registrationResult),
            ]);

            return response()->json($responseData);
        }

        // Create new access
        $access = \App\Models\CompanyProjectAccess::create([
            'company_id' => $company->id,
            'project_id' => $validated['project_id'],
            'status' => $validated['status'] ?? 'active',
            'external_company_id' => $validated['external_company_id'] ?? null,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        if (isset($validated['api_credentials'])) {
            $access->setEncryptedApiCredentials($validated['api_credentials']);
            $access->save();
        }

        // Load project relationship
        $access->load('project');
        $project = $access->project;

        // Create company_project_users entries for all active users in the company
        $this->createCompanyProjectUsers($access);
        
        // Refresh access but keep project relationship
        $access->refresh();
        $access->load('project');
        $project = $access->project; // Re-assign after refresh

        $registrationResult = null;
        
        // Register users to doctor project if this is the doctor project and access is active
        if ($access->status === 'active' && $project && $project->slug === 'mydoctor') {
            \Illuminate\Support\Facades\Log::info('Doctor project detected, proceeding with registration', [
                'access_id' => $access->id,
                'project_id' => $project->id,
                'project_slug' => $project->slug,
                'access_status' => $access->status,
            ]);
            try {
                \Illuminate\Support\Facades\Log::info('Attempting to register users for doctor project', [
                    'access_id' => $access->id,
                    'project_id' => $project->id,
                    'project_slug' => $project->slug,
                ]);
                
                $registrationService = app(\App\Services\DoctorProjectRegistrationService::class);
                $registrationResult = $registrationService->registerUsersForDoctorProject($access);
                
                \Illuminate\Support\Facades\Log::info('Registration result', [
                    'access_id' => $access->id,
                    'result' => $registrationResult,
                ]);
            } catch (\Exception $e) {
                return $this->errorResponse(
                    'Failed to register users to doctor project',
                    $e,
                    500,
                    ['access_id' => $access->id, 'project_id' => $project->id]
                );
                
                $registrationResult = [
                    'success' => false,
                    'message' => 'Registration failed: ' . $e->getMessage(),
                ];
            }
        }
        
        // Register users to TG Calabria project if this is the TG Calabria project and access is active
        if ($access->status === 'active' && $project && $project->slug === 'tg-calabria') {
            \Illuminate\Support\Facades\Log::info('TG Calabria project detected, proceeding with registration', [
                'access_id' => $access->id,
                'project_id' => $project->id,
                'project_slug' => $project->slug,
                'access_status' => $access->status,
            ]);
            try {
                \Illuminate\Support\Facades\Log::info('Attempting to register users for TG Calabria project', [
                    'access_id' => $access->id,
                    'project_id' => $project->id,
                    'project_slug' => $project->slug,
                ]);
                
                $registrationService = app(\App\Services\TGCalabriaProjectRegistrationService::class);
                $registrationResult = $registrationService->registerUsersForTGCalabriaProject($access);
                
                \Illuminate\Support\Facades\Log::info('TG Calabria registration result', [
                    'access_id' => $access->id,
                    'result' => $registrationResult,
                ]);
            } catch (\Exception $e) {
                return $this->errorResponse(
                    'Failed to register users to TG Calabria project',
                    $e,
                    500,
                    ['access_id' => $access->id, 'project_id' => $project->id]
                );
                
                $registrationResult = [
                    'success' => false,
                    'message' => 'Registration failed: ' . $e->getMessage(),
                ];
            }
        }

        $responseData = $access->load(['project', 'projectUsers.user'])->toArray();
        if ($registrationResult) {
            $responseData['registration_result'] = $registrationResult;
        }

        \Illuminate\Support\Facades\Log::info('Project access created successfully', [
            'access_id' => $access->id,
            'has_registration_result' => !is_null($registrationResult),
        ]);

        return response()->json($responseData, 201);
    }

    /**
     * Revoke project access from a company.
     */
    public function revokeProjectAccess(Request $request, Company $company, int $projectId)
    {
        $user = $request->user();

        // Only super admin can revoke access
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can revoke project access');
        }

        $access = \App\Models\CompanyProjectAccess::where('company_id', $company->id)
            ->where('project_id', $projectId)
            ->firstOrFail();

        $access->update([
            'status' => 'revoked',
        ]);

        return response()->json(['message' => 'Project access revoked successfully']);
    }

    /**
     * Update project access status.
     */
    public function updateProjectAccess(Request $request, Company $company, int $projectId)
    {
        $user = $request->user();

        // Only super admin can update access
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can update project access');
        }

        $validated = $request->validate([
            'status' => 'required|in:pending,active,suspended,revoked',
        ]);

        $access = \App\Models\CompanyProjectAccess::where('company_id', $company->id)
            ->where('project_id', $projectId)
            ->firstOrFail();

        $access->update($validated);

        return response()->json($access->load('project'));
    }

    /**
     * Create company_project_users entries for all active users in the company.
     */
    private function createCompanyProjectUsers(\App\Models\CompanyProjectAccess $access): void
    {
        // Load company relationship if not loaded
        if (!$access->relationLoaded('company')) {
            $access->load('company');
        }
        
        $company = $access->company;
        
        if (!$company) {
            \Illuminate\Support\Facades\Log::error('Company not found for creating project users', [
                'access_id' => $access->id,
                'company_id' => $access->company_id,
            ]);
            return;
        }
        
        // Get all active users in the company
        $users = \App\Models\User::where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        \Illuminate\Support\Facades\Log::info('Creating company_project_users entries', [
            'access_id' => $access->id,
            'company_id' => $company->id,
            'users_found' => $users->count(),
            'user_ids' => $users->pluck('id')->toArray(),
        ]);

        $createdCount = 0;
        foreach ($users as $user) {
            // Create company_project_user entry if it doesn't exist
            $projectUser = \App\Models\CompanyProjectUser::firstOrCreate(
                [
                    'company_project_access_id' => $access->id,
                    'user_id' => $user->id,
                ],
                [
                    'status' => 'active',
                ]
            );
            
            if ($projectUser->wasRecentlyCreated) {
                $createdCount++;
            }
        }

        \Illuminate\Support\Facades\Log::info('Created company_project_users entries', [
            'access_id' => $access->id,
            'company_id' => $company->id,
            'total_users' => $users->count(),
            'newly_created' => $createdCount,
            'already_existed' => $users->count() - $createdCount,
        ]);
    }

    /**
     * Manually trigger user registration for a project (for debugging/testing).
     */
    public function registerUsersToProject(Request $request, Company $company, int $projectId)
    {
        $user = $request->user();

        // Only super admin can trigger registration
        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can trigger user registration');
        }

        $access = \App\Models\CompanyProjectAccess::where('company_id', $company->id)
            ->where('project_id', $projectId)
            ->firstOrFail();

        $access->load('project');
        $project = $access->project;

        if ($project->slug !== 'mydoctor') {
            return response()->json([
                'success' => false,
                'message' => 'This endpoint is only for doctor project',
            ], 400);
        }

        // Ensure company_project_users entries exist
        $this->createCompanyProjectUsers($access);
        $access->refresh();

        try {
            $registrationService = app(\App\Services\DoctorProjectRegistrationService::class);
            $result = $registrationService->registerUsersForDoctorProject($access);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Registration completed',
                'results' => $result['results'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to register users',
                $e,
                500,
                ['access_id' => $access->id]
            );
        }
    }
}
