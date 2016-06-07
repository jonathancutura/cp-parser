<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityFloor extends Model{
	
	protected $table = 'facility_floors';
	protected $fillable = ['name', 'facility_id', 'floor_number', 'floorplan_image', 'plots'];

}
