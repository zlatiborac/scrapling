<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Only create the requested development account once. Never reset its password.
        if (! User::where('username', 'vladmar')->exists()) {
            $user = new User;
            $user->name = 'vladmar';
            $user->username = 'vladmar';
            $user->email = 'vladmar@localhost.test';
            $user->password = 'tiborzla';
            $user->is_admin = true;
            $user->save();
        }
    }
}
