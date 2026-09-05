<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add MyPet Plus to the existing dynamic project catalog. Connection URLs,
     * API keys, admin URL, and login credentials remain administrator-managed
     * through the existing Projects and Project Management screens.
     */
    public function up(): void
    {
        Project::firstOrCreate(
            ['slug' => 'mypetplus'],
            [
                'name' => 'MyPet Plus',
                'description' => 'Veterinary care platform',
                'integration_type' => 'api',
                'api_auth_type' => 'bearer',
                'sso_enabled' => false,
                'is_active' => true,
            ]
        );
    }

    /**
     * Preserve administrator-entered project data if this migration is rolled
     * back; removing a project here could delete active credential mappings.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
