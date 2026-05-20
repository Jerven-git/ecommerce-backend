<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\SiteConfig;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
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

        $defaultStore = Store::firstOrCreate(
            ['slug' => Store::DEFAULT_SLUG],
            ['name' => 'Default Store', 'status' => 'active']
        );

        $users = [
            [
                'email' => 'kannalatayada@gmail.com',
                'name' => 'Admin User',
                'role_ids' => [$superAdmin->id],
                'store_id' => null,
            ],
            [
                'email' => 'info.pageone247@gmail.com',
                'name' => 'Admin User 2',
                'role_ids' => [$admin->id],
                'store_id' => $defaultStore->id,
            ],
            [
                'email' => 'francisian172@gmail.com',
                'name' => 'Admin User 3',
                'role_ids' => [$admin->id],
                'store_id' => $defaultStore->id,
            ],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'is_admin' => true,
                    'store_id' => $data['store_id'],
                ]
            );

            $user->roles()->syncWithoutDetaching($data['role_ids']);
        }

        $this->command->info('Admin users seeded successfully!');
        $this->command->info('Password for seeded users: password');

        app(CurrentStore::class)->set($defaultStore);

        SiteConfig::updateOrCreate(
            ['store_id' => $defaultStore->id],
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
