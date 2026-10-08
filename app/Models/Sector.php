<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'hierarchical', 'site', 'block', 'cost_center', 'logo'])]
class Sector extends Model
{
    use SoftDeletes;
}
