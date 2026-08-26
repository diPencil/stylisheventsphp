<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = ['id'];

    // Production schema has `created_at` but no `updated_at`.
    // Tell Eloquent not to expect an `updated_at` column.
    public const UPDATED_AT = null;
}
