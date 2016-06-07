<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Beacon extends Model
{
	protected $table   = 'beacons';
	protected $guarded = [];

	public function floor() {
		return $this->belongsTo('App\Models\FacilityFloor', 'floor_id');
	}

}
