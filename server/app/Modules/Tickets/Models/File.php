<?php

namespace App\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    protected $table = 'tk_files';

    protected $fillable = ['ticket_id', 'user_id', 'name', 'path', 'size'];

    protected $hidden = ['path'];
}
