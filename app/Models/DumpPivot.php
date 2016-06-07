<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DumpPivot extends Model 
{
    protected $table = 'dump_parse_pivot';
    protected $fillable = ['status', 'is_parsed'];
    public $timestamps = false;
    protected $connection = 'dumpdBCon';
	
}
