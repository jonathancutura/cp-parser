<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class User extends Model {
	
	protected $guarded = [];
	protected $table   = 'users';
	public function person() {
		return $this->belongsTo('Person', 'person_id');
	}
}