<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the Opti Amazon / Vista Express marketplace to the dynamic project
     * catalog so it appears under Project Management -> Projects alongside
     * MyPet Plus, TG Calabria and MyDoctor+.
     *
     * No credentials are seeded. The super administrator enters the API Base
     * URL and the read-only CRM API key through the existing Projects screen,
     * exactly as for the other integrated projects.
     */
    public function up(): void
    {
        Project::firstOrCreate(
            ['slug' => 'vista-express'],
            [
                'name' => 'Vista Express',
                'description' => 'Opti Amazon optical marketplace',
                'integration_type' => 'api',
                'api_auth_type' => 'bearer',
                'admin_panel_url' => 'https://admin.vistaexpress.it',
                'sso_enabled' => false,
                'is_active' => true,
            ]
        );
    }

    /**
     * Preserve administrator-entered project data if this migration is rolled
     * back; removing a project here could delete an active credential mapping.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
