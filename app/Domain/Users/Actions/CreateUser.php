<?php

namespace App\Domain\Users\Actions;

use App\Models\User;

class CreateUser
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'is_platform_admin' => (bool) ($data['is_platform_admin'] ?? false),
            'is_active' => true,
        ]);
    }
}
