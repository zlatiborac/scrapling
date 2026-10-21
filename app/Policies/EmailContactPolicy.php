<?php

namespace App\Policies;

use App\Models\EmailContact;
use App\Models\User;

class EmailContactPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, EmailContact $contact): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, EmailContact $contact): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, EmailContact $contact): bool
    {
        return false;
    }
}
