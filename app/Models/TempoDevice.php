<?php 
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TempoDevice extends Model
{
	protected $guarded = [];
	protected $table   = 'tempo_devices';

	public function facility(){
		return $this->belongsTo('App\Models\Facility');
	}

}