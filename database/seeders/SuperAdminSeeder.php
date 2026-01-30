<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'aztalal77@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('33@Ri1448'),
                'role' => 'admin',
                'status' => 'approved',
                'is_active' => true,
            ]
        );
    }
}
