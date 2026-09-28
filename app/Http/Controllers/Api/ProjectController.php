<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Services\RateLimitService;
use App\Services\SSOService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use HandlesApiErrors;
    public function __construct(
        private SSOService $ssoService,
        private RateLimitService $rateLimitService
    ) {}

    /**
     * Display a public list of active projects (for registration form).
     */
    public function publicList(Request $request)
    {
        $projects = Project::where('is_active', true)
            ->select('id', 'name', 'description', 'slug')
            ->get();

        return response()->json($projects);
    }

    /**
     * Display a listing of accessible projects.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            // Super admin sees all projects (with optional filter)
            $query = Project::query();
            
            if ($request->has('is_active')) {
                if ($request->is_active === 'true') {
                    $query->active();
                } elseif ($request->is_active === 'false') {
                    $query->where('is_active', false);
                }
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }
            
            $projects = $query->with('companyAccesses.company')->get();
        } else {
            // Regular users see only projects they have access to
            $projectIds = $user->getAccessibleProjectIds();

            if (empty($projectIds)) {
                $projects = collect([]);
            } else {
                $projects = Project::whereIn('id', $projectIds)
                    ->active()
                    ->get();
            }
        }

        return response()->json($projects);
    }

    /**
     * Store a newly created project (Super Admin only).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can create projects');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:projects,slug',
            'description' => 'nullable|string',
            'integration_type' => 'required|in:api,iframe,hybrid',
            'api_base_url' => 'nullable|url|max:500',
            'api_auth_type' => 'nullable|in:bearer,basic,oauth2,custom',
            'api_key' => 'nullable|string',
            'api_secret' => 'nullable|string',
            'api_signup_endpoint' => 'nullable|string|max:255',
            'api_login_endpoint' => 'nullable|string|max:255',
            'api_sso_endpoint' => 'nullable|string|max:255',
            'admin_panel_url' => 'nullable|url|max:500',
            'iframe_width' => 'nullable|string|max:50',
            'iframe_height' => 'nullable|string|max:50',
            'iframe_sandbox' => 'nullable|string|max:255',
            'sso_enabled' => 'sometimes|boolean',
            'sso_method' => 'nullable|in:jwt,oauth2,redirect,legacy_unsafe',
            'sso_token_expiry' => 'nullable|integer|min:60',
            'sso_redirect_url' => 'nullable|url|max:500',
            'sso_callback_url' => 'nullable|url|max:500',
            'driver_class' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        // Encrypt API credentials if provided. A blank value means "no change"
        // rather than "store an encrypted empty string", which is what the
        // Projects screen promises when it labels a key field "leave blank to
        // keep current" — the form submits the whole object on every save.
        $this->encryptCredential($validated, 'api_key');
        $this->encryptCredential($validated, 'api_secret');

        $project = Project::create($validated);

        return response()->json($project, 201);
    }

    /**
     * Update the specified project (Super Admin only).
     */
    public function update(Request $request, Project $project)
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can update projects');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100|unique:projects,slug,' . $project->id,
            'description' => 'nullable|string',
            'integration_type' => 'sometimes|in:api,iframe,hybrid',
            'api_base_url' => 'nullable|url|max:500',
            'api_auth_type' => 'nullable|in:bearer,basic,oauth2,custom',
            'api_key' => 'nullable|string',
            'api_secret' => 'nullable|string',
            'api_signup_endpoint' => 'nullable|string|max:255',
            'api_login_endpoint' => 'nullable|string|max:255',
            'api_sso_endpoint' => 'nullable|string|max:255',
            'admin_panel_url' => 'nullable|url|max:500',
            'iframe_width' => 'nullable|string|max:50',
            'iframe_height' => 'nullable|string|max:50',
            'iframe_sandbox' => 'nullable|string|max:255',
            'sso_enabled' => 'sometimes|boolean',
            'sso_method' => 'nullable|in:jwt,oauth2,redirect,legacy_unsafe',
            'sso_token_expiry' => 'nullable|integer|min:60',
            'sso_redirect_url' => 'nullable|url|max:500',
            'sso_callback_url' => 'nullable|url|max:500',
            'driver_class' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        // Encrypt API credentials if provided. Blank values are dropped so an
        // edit that does not retype a key cannot destroy the stored credential.
        $this->encryptCredential($validated, 'api_key');
        $this->encryptCredential($validated, 'api_secret');

        $project->update($validated);

        return response()->json($project);
    }

    /**
     * Encrypt a single project credential in place.
     *
     * A missing or whitespace-only value is removed from the payload so it is
     * simply not written, leaving any existing encrypted value untouched. This
     * is what allows the Projects form to submit `api_key: ''` on every save
     * without wiping credentials the administrator entered earlier.
     *
     * @param  array<string, mixed>  $validated
     */
    private function encryptCredential(array &$validated, string $field): void
    {
        if (!array_key_exists($field, $validated)) {
            return;
        }

        $value = $validated[$field];

        if ($value === null || (is_string($value) && trim($value) === '')) {
            unset($validated[$field]);

            return;
        }

        $validated[$field] = \Illuminate\Support\Facades\Crypt::encryptString((string) $value);
    }

    /**
     * Remove the specified project (Super Admin only).
     */
    public function destroy(Request $request, Project $project)
    {
        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            abort(403, 'Only super admin can delete projects');
        }

        $project->delete();

        return response()->json(['message' => 'Project deleted successfully'], 204);
    }

    /**
     * Display the specified project.
     */
    public function show(Request $request, Project $project)
    {
        $user = $request->user();

        // Check access for non-super-admin
        if (!$user->isSuperAdmin()) {
            $hasAccess = CompanyProjectAccess::where('company_id', $user->company_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                abort(403, 'Access denied: No access to this project');
            }
        }

        return response()->json($project);
    }

    /**
     * Generate SSO redirect URL.
     */
    public function generateSSORedirect(Request $request, Project $project)
    {
        $user = $request->user();
        $company = $user->company;

        // Check access
        $access = CompanyProjectAccess::where('company_id', $company->id)
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->firstOrFail();

        // Special handling for doctor project (mydoctor)
        if ($project->slug === 'mydoctor') {
            try {
                // Get project users from company_project_users
                $projectUsers = \App\Models\CompanyProjectUser::where('company_project_access_id', $access->id)
                    ->where('status', 'active')
                    ->with('user')
                    ->get();
                
                $loginService = app(\App\Services\DoctorProjectLoginService::class);
                $result = $loginService->loginAndFetchData($access, $user);
                
                if ($result['success']) {
                    return response()->json([
                        'success' => true,
                        'project_slug' => 'mydoctor',
                        'data' => $result['data'],
                        'project_users' => $projectUsers->map(function ($pu) {
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
                        }),
                        'message' => $result['message'] ?? 'Login successful',
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => $result['message'] ?? 'Failed to login to doctor project',
                    ], 400);
                }
            } catch (\Exception $e) {
                return $this->errorResponse(
                    'Failed to login to doctor project',
                    $e,
                    500,
                    ['user_id' => $user->id, 'project_id' => $project->id]
                );
            }
        }

        // Special handling for TG Calabria project
        if ($project->slug === 'tg-calabria') {
            try {
                $loginService = app(\App\Services\TGCalabriaLoginService::class);
                $result = $loginService->loginAndFetchStats($access, $user);
                
                if ($result['success']) {
                    // Fetch categories for the form
                    $categoriesResult = $loginService->fetchCategories($result['token']);
                    
                    return response()->json([
                        'success' => true,
                        'project_slug' => 'tg-calabria',
                        'data' => $result['data'],
                        'token' => $result['token'],
                        'categories' => $categoriesResult['data'] ?? [],
                        'message' => $result['message'] ?? 'Login successful',
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => $result['message'] ?? 'Failed to login to TG Calabria project',
                    ], 400);
                }
            } catch (\Exception $e) {
                return $this->errorResponse(
                    'Failed to login to TG Calabria project',
                    $e,
                    500,
                    ['user_id' => $user->id, 'project_id' => $project->id]
                );
            }
        }

        // Check rate limit
        if (!$this->rateLimitService->checkRateLimit($access)) {
            return response()->json(['error' => 'Rate limit exceeded'], 429);
        }

        // Generate SSO redirect URL (top-level)
        $redirectUrl = $this->ssoService->getSSORedirectUrl($access, $project, $user);

        return response()->json([
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * Create article for TG Calabria project.
     */
    public function createTGCalabriaArticle(Request $request, Project $project)
    {
        try {
            $user = $request->user();
            
            // Validate user has access
            $access = CompanyProjectAccess::where('company_id', $user->company_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->firstOrFail();

            // Only TG Calabria project
            if ($project->slug !== 'tg-calabria') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid project',
                ], 400);
            }

            // Validate required fields
            $validated = $request->validate([
                'title' => 'required|string',
                'slug' => 'nullable|string',
                'summary' => 'required|string',
                'content' => 'required|string',
                'categoryId' => 'required|string',
                'isFeatured' => 'boolean',
                'status' => 'required|in:DRAFT,PUBLISHED',
                'isBreaking' => 'boolean',
                'tags' => 'nullable|array',
                'mainImage' => 'nullable|string',
            ]);

            // Get token from database
            $projectUser = CompanyProjectUser::where('company_project_access_id', $access->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$projectUser || !$projectUser->external_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication token not found. Please refresh the page.',
                ], 401);
            }

            // Check token expiration
            if ($projectUser->token_expires_at && $projectUser->token_expires_at->isPast()) {
                // Token expired, need to refresh
                $loginService = app(\App\Services\TGCalabriaLoginService::class);
                $result = $loginService->loginAndFetchStats($access, $user);
                
                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Session expired. Please refresh the page.',
                    ], 401);
                }
                
                $token = $result['token'];
            } else {
                $token = $projectUser->external_token;
            }

            // Create article via service
            $loginService = app(\App\Services\TGCalabriaLoginService::class);
            $result = $loginService->createArticle($token, $validated);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['data'],
                    'message' => $result['message'] ?? 'Article created successfully',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to create article',
                ], 400);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create article',
                $e,
                500,
                ['user_id' => $request->user()->id ?? 'unknown']
            );
        }
    }

    /**
     * Handle iframe callback after SSO.
     */
    public function iframeCallback(Request $request, Project $project)
    {
        $user = $request->user();
        $company = $user->company;

        $access = CompanyProjectAccess::where('company_id', $company->id)
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->firstOrFail();

        // Return iframe page data
        return response()->json([
            'project' => $project,
            'iframe_url' => $project->admin_panel_url,
            'iframe_width' => $project->iframe_width,
            'iframe_height' => $project->iframe_height,
            'iframe_sandbox' => $project->iframe_sandbox,
        ]);
    }
}
