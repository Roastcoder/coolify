<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fork-only: a domain served by the built-in mail server (see App\Services\MailServer).
 * The oldest domain is the primary one; the server hostname is mail.<primary>.
 */
class MailDomain extends Model
{
    protected $fillable = ['name'];
}
