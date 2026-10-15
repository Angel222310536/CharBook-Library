<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommentLike extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['comment_id', 'user_id'];
}
