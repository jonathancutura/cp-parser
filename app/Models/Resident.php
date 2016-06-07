<?php 
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Resident extends Model
{
	protected $guarded = [];
	protected $table   = 'residents';
	public function person() {
		return $this->belongsTo('App\Models\Person', 'person_id');
	}
	public function tempoDevice() {
		return $this->belongsTo('App\Models\TempoDevice', 'tempodv_id');
	}
}