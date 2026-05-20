<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappLog extends Model
{
    protected $table = 'whatsapp_log';
    protected $fillable = [
        'number',
        'user_id',
        'msg',
        'dep_id',
        'business_phone_number_id',
    ];
}
