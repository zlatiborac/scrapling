<?php

namespace App\Policies;

use App\Models\EmailScan;
use App\Models\User;

class EmailScanPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, EmailScan $scan): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, EmailScan $scan): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, EmailScan $scan): bool
    {
        return false;
    }
}
