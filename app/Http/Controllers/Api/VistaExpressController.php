<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Http\Controllers\Controller;
use App\Models\CompanyProjectAccess;
use App\Models\Project;
use App\Services\VistaExpressService;
use Illuminate\Http\Request;

class VistaExpressController extends Controller
{
    use HandlesApiErrors;

    /**
     * The slug this integration is bound to. Matches the project seeded by
     * 2026_09_28_000000_add_vistaexpress_project.
     */
    private const SLUG = 'vista-express';

    /**
     * Detail resources, exposed as {resource}/{id} and proxied to
     * {api_base_url}/crm/{resource}/{id} upstream.
     */
    private const DETAIL_RESOURCES = ['products', 'orders'];

    public function __construct(private VistaExpressService $service)
    {
    }

    /**
     * Proxy an Opti Amazon / Vista Express read-only collection.
     *
     * This mirrors the MyPet Plus integration: the project's own API settings
     * are used server-side so the external key never reaches the browser, and
     * the tenant's project access is enforced before any upstream call.
     */
    public function index(Request $request, Project $project, string $resource)
    {
        if ($guard = $this->guardProject($request, $project)) {
            return $guard;
        }

        if (!in_array($resource, VistaExpressService::RESOURCES, true)) {
            return $this->unknownResource($resource);
        }

        try {
            $filters = $request->validate($this->rulesFor($resource));

            return response()->json(
                $this->service->fetch($project, $resource, $filters)
            );
        } catch (\RuntimeException $exception) {
            return $this->upstreamFailure($project, $resource, $exception);
        }
    }

    /**
     * Proxy a single product or order, including its related records.
     */
    public function show(Request $request, Project $project, string $resource, int $id)
    {
        if ($guard = $this->guardProject($request, $project)) {
            return $guard;
        }

        if (!in_array($resource, self::DETAIL_RESOURCES, true)) {
            return $this->unknownResource($resource);
        }

        try {
            return response()->json(
                $this->service->fetchOne($project, $resource, $id)
            );
        } catch (\RuntimeException $exception) {
            return $this->upstreamFailure($project, $resource, $exception);
        }
    }

    /**
     * Confirm the project is the Vista Express project and that the caller may
     * read it. Returns a ready-to-return response on failure, or null to proceed.
     */
    private function guardProject(Request $request, Project $project)
    {
        if ($project->slug !== self::SLUG) {
            return response()->json([
                'message' => 'This endpoint is only available for the Vista Express project.',
            ], 404);
        }

        $user = $request->user();

        if (!$user->isSuperAdmin()) {
            $hasAccess = CompanyProjectAccess::where('company_id', $user->company_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAccess) {
                return response()->json([
                    'message' => 'Access denied: No access to this project.',
                ], 403);
            }
        }

        return null;
    }

    private function unknownResource(string $resource)
    {
        return response()->json([
            'message' => "Unknown Vista Express resource '{$resource}'.",
            'available' => VistaExpressService::RESOURCES,
        ], 404);
    }

    private function upstreamFailure(Project $project, string $resource, \RuntimeException $exception)
    {
        return $this->errorResponse($exception->getMessage(), $exception, 502, [
            'project_id' => $project->id,
            'resource' => $resource,
        ]);
    }

    /**
     * Whitelist the query parameters per resource. Nothing is forwarded to the
     * external project unless it is declared here, so a crafted query string
     * cannot be used to probe the upstream API.
     *
     * @return array<string, string>
     */
    private function rulesFor(string $resource): array
    {
        $common = [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];

        $specific = match ($resource) {
            'overview', 'warehouse' => [
                'months' => 'nullable|integer|min:1|max:24',
            ],
            'users' => [
                'role' => 'nullable|in:buyer,seller,admin',
                'search' => 'nullable|string|max:120',
                'status' => 'nullable|in:active,blocked',
                'verified' => 'nullable|in:yes,no',
            ],
            'sellers' => [
                'search' => 'nullable|string|max:120',
                'status' => 'nullable|string|max:40',
                'onboarding_status' => 'nullable|string|max:40',
            ],
            'products' => [
                'search' => 'nullable|string|max:120',
                'store_id' => 'nullable|integer',
                'category_id' => 'nullable|integer',
                'type' => 'nullable|string|max:40',
                'status' => 'nullable|in:all,live,pending,inactive,muted,boosted,out_of_stock',
            ],
            'orders' => [
                'search' => 'nullable|string|max:120',
                'payment_status' => 'nullable|string|max:40',
                'payment_method' => 'nullable|string|max:40',
                'store_id' => 'nullable|integer',
            ],
            'warehouse/products' => [
                'search' => 'nullable|string|max:120',
                'category_id' => 'nullable|integer',
                'availability' => 'nullable|in:all,in_stock,low_stock,out_of_stock',
                'include_drafts' => 'nullable|boolean',
            ],
            'warehouse/orders' => [
                'search' => 'nullable|string|max:120',
                'status' => 'nullable|string|max:40',
                'payment_status' => 'nullable|string|max:40',
            ],
            'ads' => [
                'search' => 'nullable|string|max:120',
                'status' => 'nullable|string|max:40',
                'payment_status' => 'nullable|string|max:40',
                'seller_id' => 'nullable|integer',
            ],
            'leads' => [
                'source' => 'nullable|in:registration,referral_click,referral_conversion',
                'search' => 'nullable|string|max:120',
                'role' => 'nullable|in:buyer,seller',
                'status' => 'nullable|in:all,unverified,verified,converted',
            ],
            default => [],
        };

        return array_merge($common, $specific);
    }
}
