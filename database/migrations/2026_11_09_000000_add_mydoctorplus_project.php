<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the read-only My Doctor Plus CRM connector. The administrator adds
     * the deployment-specific API base URL and CRM API key through Projects;
     * no credentials or patient data are ever stored in this migration.
     */
    public function up(): void
    {
        Project::firstOrCreate(
            ['slug' => 'mydoctorplus'],
            [
                'name' => 'My Doctor Plus',
                'description' => 'Healthcare platform — registrations, subscriptions and commercial analytics',
                'integration_type' => 'api',
                'api_auth_type' => 'custom',
                'api_base_url' => 'https://mydoctoradmin.mydoctorplus.it/api',
                'admin_panel_url' => 'https://mydoctoradmin.mydoctorplus.it',
                'sso_enabled' => false,
                'is_active' => true,
            ]
        );
    }

    /**
     * Preserve configured credentials and project access on rollback.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
