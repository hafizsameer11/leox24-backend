<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SignupRequestController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\OpportunityController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\MyPetPlusLeadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/signup-requests', [SignupRequestController::class, 'store']); // Public company registration
Route::get('/projects/public', [ProjectController::class, 'publicList']); // Public project list for registration
Route::get('/subscription-plans', [SubscriptionController::class, 'plans']); // Public subscription plans
Route::get('/subscription/success', [SubscriptionController::class, 'success']); // Public - Stripe redirects here
Route::post('/subscription/activate', [SubscriptionController::class, 'activateSubscription']); // Public - called after Stripe payment

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Authentication
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Users
    Route::apiResource('users', UserController::class);
    Route::get('/users/{user}/plain-password', [UserController::class, 'getPlainPassword']);

    // Companies
    Route::apiResource('companies', CompanyController::class);
    Route::get('/companies/{company}/projects', [CompanyController::class, 'projects']); // Super admin only
    Route::post('/companies/{company}/projects/grant', [CompanyController::class, 'grantProjectAccess']); // Super admin only
    Route::delete('/companies/{company}/projects/{projectId}', [CompanyController::class, 'revokeProjectAccess']); // Super admin only
    Route::put('/companies/{company}/projects/{projectId}', [CompanyController::class, 'updateProjectAccess']); // Super admin only
    Route::post('/companies/{company}/projects/{projectId}/register-users', [CompanyController::class, 'registerUsersToProject']); // Super admin only - manual registration trigger

    // Signup Requests (Admin only - viewing and approval)
    Route::get('/signup-requests', [SignupRequestController::class, 'index']);
    Route::put('/signup-requests/{signupRequest}/approve', [SignupRequestController::class, 'approve']);
    Route::put('/signup-requests/{signupRequest}/reject', [SignupRequestController::class, 'reject']);

    // Projects
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::post('/projects', [ProjectController::class, 'store']); // Super admin only
    Route::put('/projects/{project}', [ProjectController::class, 'update']); // Super admin only
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']); // Super admin only
    Route::post('/projects/{project}/sso/redirect', [ProjectController::class, 'generateSSORedirect']);
    Route::get('/projects/{project}/iframe-callback', [ProjectController::class, 'iframeCallback']);
    Route::get('/projects/{project}/mypetplus/leads', [MyPetPlusLeadController::class, 'index']);

    // TG Calabria Project
    Route::post('/projects/{projectId}/tg-calabria/login', [\App\Http\Controllers\Api\TGCalabriaController::class, 'login']);
    Route::get('/projects/{projectId}/tg-calabria/categories', [\App\Http\Controllers\Api\TGCalabriaController::class, 'getCategories']);
    Route::get('/projects/{projectId}/tg-calabria/news', [\App\Http\Controllers\Api\TGCalabriaController::class, 'getNews']);
    Route::get('/projects/{projectId}/tg-calabria/news/stats', [\App\Http\Controllers\Api\TGCalabriaController::class, 'getNewsStats']);
    Route::post('/projects/{projectId}/tg-calabria/news', [\App\Http\Controllers\Api\TGCalabriaController::class, 'createArticle']);

    // Project Management (Super Admin only)
    Route::apiResource('project-manages', \App\Http\Controllers\Api\ProjectManageController::class);
    Route::get('/project-manages/project/{projectId}/credentials', [\App\Http\Controllers\Api\ProjectManageController::class, 'getProjectCredentials']);
    Route::get('/projects/{projectId}/users-credentials', [\App\Http\Controllers\Api\ProjectManageController::class, 'getProjectUsersCredentials']);

    // Customers
    Route::apiResource('customers', CustomerController::class);
    Route::post('/customers/merge', [CustomerController::class, 'merge']);

    // Leads
    Route::get('/leads/export', [LeadController::class, 'export']);
    Route::apiResource('leads', LeadController::class);
    
    // Categories
    Route::apiResource('categories', CategoryController::class);
    
    // Media Library
    Route::get('/media', [MediaController::class, 'index']);
    Route::post('/media/upload', [MediaController::class, 'upload']);
    
    // Email Bulk Management
    Route::get('/emails/senders', [EmailController::class, 'senders']);
    Route::get('/emails', [EmailController::class, 'index']);
    Route::post('/emails/upload', [EmailController::class, 'upload']);
    Route::post('/emails/send', [EmailController::class, 'send']);

    // Direct SMS (super admin)
    Route::post('/sms/send', [SmsController::class, 'send']);
    
    // Follow-ups for customers
    Route::get('/customers/{customer}/follow-ups', [FollowUpController::class, 'index']);
    Route::post('/customers/{customer}/follow-ups', [FollowUpController::class, 'store']);
    
    // Follow-ups for leads
    Route::get('/leads/{lead}/follow-ups', [FollowUpController::class, 'indexForLead']);
    Route::post('/leads/{lead}/follow-ups', [FollowUpController::class, 'storeForLead']);
    
    // Follow-up management
    Route::put('/follow-ups/{followUp}', [FollowUpController::class, 'update']);
    Route::post('/follow-ups/{followUp}/complete', [FollowUpController::class, 'complete']);
    Route::delete('/follow-ups/{followUp}', [FollowUpController::class, 'destroy']);
    Route::get('/follow-ups/upcoming', [FollowUpController::class, 'upcoming']);

    // Dashboard
    Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);
    Route::get('/dashboard/pipeline', [DashboardController::class, 'pipeline']);
    Route::get('/dashboard/leads', [DashboardController::class, 'leads']);
    Route::get('/dashboard/lead-sources', [DashboardController::class, 'leadSources']);
    Route::get('/dashboard/top-operators', [DashboardController::class, 'topOperators']);

    // Opportunities
    Route::apiResource('opportunities', OpportunityController::class);
    Route::post('/opportunities/{opportunity}/convert', [OpportunityController::class, 'convert']);

    // Tasks
    Route::apiResource('tasks', TaskController::class);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{task}/assign', [TaskController::class, 'assign']);

    // Notes
    Route::apiResource('notes', NoteController::class);
    Route::post('/notes/{note}/pin', [NoteController::class, 'pin']);

    // Documents
    Route::apiResource('documents', DocumentController::class);
    Route::post('/documents/upload', [DocumentController::class, 'upload']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);

    // Activity Logs
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/activity-logs/{activityLog}', [ActivityLogController::class, 'show']);

    // Reports
    Route::apiResource('reports', ReportController::class);
    Route::post('/reports/{report}/generate', [ReportController::class, 'generate']);
    Route::post('/reports/{report}/schedule', [ReportController::class, 'schedule']);

    // Webhooks
    Route::apiResource('webhooks', WebhookController::class);
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test']);
    Route::get('/webhooks/{webhook}/logs', [WebhookController::class, 'logs']);

    // Custom Fields
    Route::apiResource('custom-fields', CustomFieldController::class);
    Route::post('/custom-fields/{customField}/values', [CustomFieldController::class, 'setValue']);

    // Calls - Specific routes must come BEFORE apiResource to avoid route conflicts
    Route::get('/calls/stats', [CallController::class, 'stats']);
    Route::get('/calls/operators', [CallController::class, 'operators']);
    Route::get('/calls/today', [CallController::class, 'today']);
    Route::get('/calls/export-template', [CallController::class, 'exportTemplate']);
    Route::post('/calls/import', [CallController::class, 'importCalls']);
    Route::apiResource('calls', CallController::class);
    Route::post('/calls/{call}/complete', [CallController::class, 'complete']);
    Route::post('/calls/{call}/initiate', [CallController::class, 'initiateCall']);
    Route::post('/calls/{call}/sms', [CallController::class, 'sendSMS']);
    Route::post('/calls/{call}/whatsapp', [CallController::class, 'sendWhatsApp']);

    // Support Tickets
    Route::apiResource('support-tickets', SupportTicketController::class);
    Route::post('/support-tickets/{supportTicket}/assign', [SupportTicketController::class, 'assign']);
    Route::post('/support-tickets/{supportTicket}/close', [SupportTicketController::class, 'close']);

    // Campaigns - Specific routes must come BEFORE apiResource to avoid route conflicts
    Route::get('/campaigns/stats', [CampaignController::class, 'stats']);
    Route::post('/campaigns/{campaign}/payment/checkout', [CampaignController::class, 'createPaymentCheckout']);
    Route::post('/campaigns/payment/success', [CampaignController::class, 'handlePaymentSuccess']);
    Route::post('/campaigns/{campaign}/activate', [CampaignController::class, 'activate']);
    Route::apiResource('campaigns', CampaignController::class);

    // Subscriptions
    Route::get('/subscription', [SubscriptionController::class, 'index']);
    Route::post('/subscription/checkout', [SubscriptionController::class, 'createCheckoutSession']); // Legacy - can be removed
    Route::post('/subscription/renewal-checkout', [SubscriptionController::class, 'createRenewalCheckout']);
    Route::get('/subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/subscription/cancel-subscription', [SubscriptionController::class, 'cancelSubscription']);

    // Subscription Plans Management (Super Admin only)
    Route::get('/subscription-plans/admin', [SubscriptionController::class, 'listPlans']);
    Route::post('/subscription-plans', [SubscriptionController::class, 'createPlan']);
    Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
    Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'deletePlan']);
});

// Webhooks (no auth required)
Route::post('/subscription/webhook', [SubscriptionController::class, 'webhook']);
Route::post('/calls/twilio/status', [CallController::class, 'twilioStatusWebhook']);
Route::get('/calls/twilio/twiml', [CallController::class, 'twilioTwiML']);

// Communications
Route::middleware('auth:sanctum')->post('/communications/whatsapp/send', [\App\Http\Controllers\Api\CommunicationController::class, 'sendWhatsApp']);
