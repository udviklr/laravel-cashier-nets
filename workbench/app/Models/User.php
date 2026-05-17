<?php

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Udviklr\CashierNets\Billable;

class User extends Authenticatable
{
    use Billable;

    protected $guarded = [];
}
