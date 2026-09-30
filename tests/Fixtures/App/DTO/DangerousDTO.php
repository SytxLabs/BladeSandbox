<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO;

use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;

final readonly class DangerousDTO extends BaseDTO
{
    public function __construct(array $data = [])
    {
    }

    public function dangerousModel(): User
    {
        return User::first();
    }
}
