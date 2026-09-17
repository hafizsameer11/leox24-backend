<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get companies
        $alpha = Company::where('vat', 'IT12345678901')->first();
        $beta = Company::where('vat', 'IT98765432109')->first();

        $users = [
            // Company Admins
            [
                'email' => 'admin@alpha.com',
                'name' => 'Alpha Admin',
                'password' => 'password',
                'role' => 'company_admin',
                'company_id' => $alpha?->id,
                'status' => 'active',
            ],
            [
                'email' => 'admin@beta.com',
                'name' => 'Beta Admin',
                'password' => 'password',
                'role' => 'company_admin',
                'company_id' => $beta?->id,
                'status' => 'active',
            ],
            // Managers
            [
                'email' => 'manager@alpha.com',
                'name' => 'Alpha Manager',
                'password' => 'password',
                'role' => 'manager',
                'company_id' => $alpha?->id,
                'status' => 'active',
                'permissions' => ['crm_marketing', 'call_center'],
            ],
            // Staff
            [
                'email' => 'staff@alpha.com',
                'name' => 'Alpha Staff',
                'password' => 'password',
                'role' => 'staff',
                'company_id' => $alpha?->id,
                'status' => 'active',
            ],
            [
                'email' => 'staff@beta.com',
                'name' => 'Beta Staff',
                'password' => 'password',
                'role' => 'staff',
                'company_id' => $beta?->id,
                'status' => 'active',
            ],
        ];

        foreach ($users as $userData) {
            $password = $userData['password'];
            unset($userData['password']);

            User::firstOrCreate(
                ['email' => $userData['email']],
                array_merge($userData, [
                    'password' => Hash::make($password),
                ])
            );
        }

        $this->command->info('Sample users created: ' . count($users));
        $this->command->info('Test users:');
        $this->command->info('  - admin@alpha.com / password (Company Admin)');
        $this->command->info('  - manager@alpha.com / password (Manager)');
        $this->command->info('  - staff@alpha.com / password (Staff)');
    }
}
