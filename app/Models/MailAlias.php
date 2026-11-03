<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fork-only: forwards mail for one address to a mailbox of the built-in mail server.
 */
class MailAlias extends Model
{
    protected $fillable = ['address', 'destination'];
}
