<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoicePayLink extends Model
{
    protected $fillable = ['company_id', 'document_id', 'token'];
}
