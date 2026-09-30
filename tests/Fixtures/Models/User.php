<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];

    public $timestamps = false;

    public function getName(): string
    {
        return (string) $this->getAttribute('name');
    }
}
