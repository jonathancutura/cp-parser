<?php
namespace App\Http\Controllers;

use App\Http\Repositories\DumpRepository;

class DumpController extends Controller
{
	private static $rpoDump = null;

	public function __construct(DumpRepository $rpoDump) 
	{
		self::$rpoDump = $rpoDump;
	}
	
    public function process() {
		return self::$rpoDump->process();
    }
}