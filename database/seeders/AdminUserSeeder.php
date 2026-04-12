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
            ['email' => 'admin@paraswimming.at'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('Admin1234!'),
                'is_admin' => true,
                'club_id' => null,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Admin-User angelegt:');
        $this->command->table(
            ['E-Mail', 'Passwort', 'Rolle'],
            [['admin@paraswimming.at', 'Admin1234!', 'Administrator']]
        );
        $this->command->warn('Bitte das Passwort nach dem ersten Login ändern!');
    }
}
