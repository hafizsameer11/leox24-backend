<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add My Beauty to the dynamic project catalog. Connection URL and API key
     * are configured after migrate (Projects screen or ops) so secrets stay out
     * of the migration file — same pattern as Vista Express / MyPet Plus.
     */
    public function up(): void
    {
        Project::firstOrCreate(
            ['slug' => 'mybeauty'],
            [
                'name' => 'My Beauty',
                'description' => 'Beauty salon marketplace platform',
                'integration_type' => 'api',
                'api_auth_type' => 'custom',
                'api_base_url' => 'https://api.mybeutyplus.it/api/v1',
                'admin_panel_url' => 'https://api.mybeutyplus.it',
                'sso_enabled' => false,
                'is_active' => true,
            ]
        );
    }

    /**
     * Preserve administrator-entered project data on rollback.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
