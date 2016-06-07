<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dump extends Model
{
	protected $table = 'dump';	
	protected $fillable = ['data', 'type', 'device_id','is_parsed'];
	protected $connection = 'dumpdBCon';

}
