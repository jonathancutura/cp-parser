<?php 
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Person extends Model
{
	protected $guarded = [];
	protected $table   = 'persons';
	public function resident() {
		return $this->hasOne('App\Models\Resident', 'person_id');
	}
}