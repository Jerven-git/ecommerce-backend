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

        $users = [
            [
                'email' => 'kannalatayada@gmail.com',
                'name' => 'Admin User',
                'role_ids' => [$superAdmin->id],
            ],
            [
                'email' => 'info.pageone@gmail.com',
                'name' => 'Admin User 2',
                'role_ids' => [$admin->id],
            ],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'is_admin' => true,
                ]
            );

            $user->roles()->syncWithoutDetaching($data['role_ids']);
        }

        $this->command->info('Admin users seeded successfully!');
        $this->command->info('Password for seeded users: password');

        SiteConfig::updateOrCreate(
            [],
            [
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
            ]
        );

        $this->command->info('Site config contact settings seeded!');
    }
}