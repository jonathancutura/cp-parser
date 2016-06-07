<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ResidentParseData extends Model {
	protected $table   = 'resident_parse_data';
	protected $guarded = [];
	public $timestamps = false;
	public function movement_type()
	{
		return $this->belongsTo('MovementType', 'mtype');
	}
	public function room_type()
	{
		return $this->belongsTo('RoomType', 'rtype');
	}
}