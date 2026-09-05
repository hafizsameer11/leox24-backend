<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $projects = [
            [
                'name' => 'OptyShop',
                'slug' => 'optyshop',
                'description' => 'E-commerce platform',
                'integration_type' => 'hybrid',
                'api_base_url' => 'https://api.optyshop.com',
                'api_auth_type' => 'bearer',
                'api_key' => Crypt::encryptString('sample-api-key'),
                'api_secret' => Crypt::encryptString('sample-api-secret'),
                'api_signup_endpoint' => '/api/v1/users/signup',
                'api_sso_endpoint' => '/auth/sso',
                'admin_panel_url' => 'https://admin.optyshop.com',
                'sso_redirect_url' => 'https://admin.optyshop.com/auth/sso',
                'sso_callback_url' => 'http://localhost:5173/projects/1/iframe',
                'is_active' => true,
            ],
            [
                'name' => 'TG Calabria',
                'slug' => 'tg-calabria',
                'description' => 'News portal',
                'integration_type' => 'iframe',
                'admin_panel_url' => 'https://admin.tgcalabria.com',
                'sso_redirect_url' => 'https://admin.tgcalabria.com/auth/sso',
                'sso_callback_url' => 'http://localhost:5173/projects/2/iframe',
                'is_active' => true,
            ],
            [
                'name' => 'MyDoctor+',
                'slug' => 'mydoctor',
                'description' => 'Medical platform',
                'integration_type' => 'api',
                'api_base_url' => 'https://api.mydoctor.com',
                'api_auth_type' => 'bearer',
                'api_key' => Crypt::encryptString('sample-api-key'),
                'api_secret' => Crypt::encryptString('sample-api-secret'),
                'api_signup_endpoint' => '/api/v1/organizations',
                'is_active' => true,
            ],
            [
                'name' => 'MyPet Plus',
                'slug' => 'mypetplus',
                'description' => 'Veterinary care platform',
                'integration_type' => 'api',
                'api_auth_type' => 'bearer',
                'sso_enabled' => false,
                'is_active' => true,
            ],
        ];

        foreach ($projects as $project) {
            Project::firstOrCreate(
                ['slug' => $project['slug']],
                $project
            );
        }

        $this->command->info('Sample projects created');
    }
}
