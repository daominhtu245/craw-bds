<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnownAgent extends Model
{
    protected $fillable = ['author_key', 'reason', 'created_by'];
}
