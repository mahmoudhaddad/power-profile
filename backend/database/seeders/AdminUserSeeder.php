<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'mahmoud@powerprofile.com'],
            [
                'name'              => 'Mahmoud',
                'password'          => Hash::make('Mahmoud'),
                'is_admin'          => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
