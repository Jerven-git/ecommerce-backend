<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $admin = Role::firstOrCreate(['name' => 'admin']);

        $user = User::firstOrCreate(
            ['email' => 'kannalatayada@gmail.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'is_admin' => true,
            ]
        );

        $user->roles()->syncWithoutDetaching([$superAdmin->id]);

        $this->command->info('Super admin created successfully!');
        $this->command->info('Email: kannalatayada@gmail.com');
        $this->command->info('Password: password');

        $siteConfig = SiteConfig::first();

        if ($siteConfig) {
            $siteConfig->update([
                'contact_email' => 'sendekato@gmail.com',
                'contact_phone' => '11112222',
                'contact_entries' => [
                    [
                        'label' => 'General Inquiries',
                        'email' => 'sendekato@gmail.com',
                        'phone' => '11112222',
                    ],
                ],
            ]);
        } else {
            SiteConfig::create([
                'site_name' => 'My Store',
                'contact_email' => 'sendekato@gmail.com',
                'contact_phone' => '11112222',
                'contact_entries' => [
                    [
                        'label' => 'General Inquiries',
                        'email' => 'sendekato@gmail.com',
                        'phone' => '11112222',
                    ],
                ],
            ]);
        }

        $this->command->info('Site config contact settings seeded!');
    }
}
