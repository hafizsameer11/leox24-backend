<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create or update the CRM super-admin account.
        // The lookup by role also migrates the existing seeded super-admin
        // account instead of leaving the old credentials in the database.
        $superAdminEmail = 'Pieroporchia07@gmail.com';
        $superAdminPassword = '110208Kr..';
        $superAdmin = User::where('email', $superAdminEmail)->first()
            ?? User::where('role', 'super_admin')->first();

        $superAdminData = [
            'email' => $superAdminEmail,
            'name' => 'Super Admin',
            'password' => Hash::make($superAdminPassword),
            'role' => 'super_admin',
            'status' => 'active',
            'company_id' => null, // Super admin has no company
        ];

        if ($superAdmin) {
            $superAdmin->update($superAdminData);
        } else {
            User::create($superAdminData);
        }

        $this->command->info('Super admin account configured.');
        $this->command->info("Email: {$superAdminEmail}");

        // Seed in order (companies first, then users, then projects, then customers)
        $this->call(CompanySeeder::class);
        $this->call(ProjectSeeder::class);
        $this->call(SubscriptionPlanSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(CustomerSeeder::class);
        
        // Create test data for doctor project (optional - for testing)
        $this->call(DoctorTestSeeder::class);

        $this->command->info('');
        $this->command->info('✅ Database seeded successfully!');
        $this->command->info('');
        $this->command->info('Test Accounts:');
        $this->command->info("  Super Admin: {$superAdminEmail}");
        $this->command->info('  Company Admin: admin@alpha.com / password');
        $this->command->info('  Manager: manager@alpha.com / password');
        $this->command->info('  Staff: staff@alpha.com / password');
        $this->command->info('  Doctor Test User: sohaib@gmail.com / 11221122');
    }
}
