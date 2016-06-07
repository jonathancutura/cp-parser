<?php 
namespace App\Http\Repositories;
use Illuminate\Database\DatabaseManager;

use App\Models\Dump;
use App\Models\DumpPivot;
use App\Models\MovementType;
use App\Models\RoomType;
use App\Models\TempoDevice;
use App\Models\User;
use App\Models\Person;
use App\Models\Facility;
use App\Models\ParseData;
use App\Models\ResidentParseData;
use App\Models\BaseStationDataLog;
use App\Models\TempoDeviceDataLog;
use App\Models\Beacon;
use App\Models\BaseStation;
use App\Models\Resident;

class DumpRepository
{
	private function timezoneList()
	{
		$zones_array = array();
		$timestamp = time();
		foreach(timezone_identifiers_list() as $key => $zone){
			date_default_timezone_set($zone);
			$zones_array[$key]['diff_from_GMT'] = date('P', $timestamp);
			$zones_array[$key]['zone'] = $zone;
			$zones_array[$key]['id'] = $zone;
		}
		asort($zones_array);
		foreach ($zones_array as $key => $value) {
			$tz[] = ['id' => $key, 'diff_from_gmt' => $value['diff_from_GMT'], 'zone' => $value['zone']];
		}
		return $tz;
	}

	private function get( $id = null )
	{

		if (is_numeric($id))
		{
			$timezone = $this->timezoneList();

			foreach($timezone AS $zone)
			{
				if ($zone['id'] == $id)
				{
					return $zone;
				}

			}
		}

		return null;
	}

	private function getStrPos($haystack, $needle, $number)
	{

		if($number == '1') {
        	return strpos($haystack, $needle);
	    } elseif($number > '1') {
	        return strpos($haystack, $needle, $this->getStrPos($haystack, $needle, $number - 1) + strlen($needle));
	    } else {
	        App::abort(500, 'CP_ERR_STRPOS_RANGE_INVALID');
	    }
	}

	private function getSubStr($content, $number)
	{

		if( !$this->getStrPos($content, 'ddata', $number) ) {
			return false;
		}

		if( !$this->getStrPos($content, 'ddata', $number + 1) ) {
			return '{ ' . substr($content, $this->getStrPos($content, 'ddata', $number) - 1);
		}
		
		$length = abs($this->getStrPos($content, 'ddata', $number) - $this->getStrPos($content, 'ddata', $number + 1));
		$end = strrpos(substr($content, $this->getStrPos($content, 'ddata', $number) - 1, $length), '},');

		return '{ ' . substr($content, $this->getStrPos($content, 'ddata', $number) - 1, $end + 1) . ' }';
	}

	public function process() {

		$url = env('SOCKET_DOMAIN').':' .env('SOCKET_API_PORT') . '/notify';

		$parsed = null;
		$return = [];
		$dtype = 'NO_DTYPE_2_DATA_FOUND';
		$dutc = 'NO_DUTC_DATA_FOUND';
		$dser = 'NO_DSER_DATA_FOUND';
		$mtype_data = 'NO_MTYPE_DATA_FOUND';
		$rtype_data = ' NO_RTYPE_DATA_FOUND ';
		$notifyResult = 'NOTHING_TO_PARSE';
		$rtype_id = null;
		$parsed = '';

		$c_data = [];
		$mtype_value = null;
		$roomchg = null;

		if($dumpPivot = DumpPivot::where(['status'=>0, 'is_parsed' => 0])->first()) {
			$dumpPivot->status = 1;
			$dumpPivot->save();
			
			$dump = Dump::where('is_parsed',0)->whereBetween('id',array($dumpPivot->start_id, $dumpPivot->end_id))->get();

			foreach ($dump as $key => $value) {

				$n = 1;
				while ( $this->getSubStr($value->data, $n) !== false )
				{
					$data = json_decode($this->getSubStr($value->data, $n), true);
					
					if( $data['ddata']['dtype'] == 2 ) 
					{
						$dtype = 'DTYPE_2_EXISTS';

						if( isset($data['ddata']['dutc']) )
						{
							$dutc = $data['ddata']['dutc'];
						}

						if( isset($data['ddata']['dser']) )
						{
							$dser = $data['ddata']['dser'];
						}

						if( isset($data['ddata']['data']['mtype']) )
						{
							$MT = MovementType::where('mtype_value', $data['ddata']['data']['mtype'])->first();
							if($MT)
							{
								$mtype_data = strtolower(str_replace('_', ' ', $MT->mtype));
							}
						}
					}

					if( $data['ddata']['dtype'] == 3 ) #temporary
					{
						if( isset($data['ddata']['data']['rtype']) )
						{
							$RT = RoomType::where('rtype_value', $data['ddata']['data']['rtype'])->first();

							if($RT)
							{
								$rtype_data = strtolower(str_replace('_', ' ', $RT->rtype));
								$rtype_id  = $RT->id;
							}

						}
					}
					
					$n++;
				}
				
				$P = Person::whereHas('resident.tempoDevice', function($q) use ($dser) {
					$q->where('serial', $dser);
				})->first();
				
				if($TD = TempoDevice::with('facility')->where('serial', $dser)->first())
				{

					$facilityTimezone = ($TD) ? ( ($TD->facility) ? $TD->facility->timezone : NULL ) : null;
					if($facilityTimezone !== null)
					{
						$timezone = $this->get($facilityTimezone);	
						if( $timezone )
						{
							date_default_timezone_set($timezone['zone']);
						}
					}
				}

				if ( $P )
				{
					$dser = $P->firstname . ' ' . $P->lastname;
				}
				else
				{
					$dser = '(User ' . $dser . ')';
				}

				$parsed = $dutc . ': ' . $dser . ' is ' . $mtype_data . ' in ' . $rtype_data;

				$d = 1;
				while ( $this->getSubStr($value->data, $d) !== false )
				{	
					$ddata = json_decode($this->getSubStr($value->data, $d),true);
					
					$this->store_parse($ddata);

					$this->storeDeviceLog($ddata);

					if( $ddata['ddata']['dtype'] == 0 )
					{
						if( isset($ddata['ddata']['dser']) )
						{
							$c_data['hid'] = $ddata['ddata']['dser'];
						}

						if( isset($ddata['ddata']['dutc']) )
						{
							$c_data['comhub_dutc'] = $ddata['ddata']['dutc'];
						}

					}

					if( $ddata['ddata']['dtype'] == 2 ) 
					{

						if( isset($ddata['ddata']['dutc']) )
						{
							$c_data['dutc'] = $ddata['ddata']['dutc'];
						}

						if( isset($ddata['ddata']['dser']) )
						{
							$c_data['dser'] = $ddata['ddata']['dser'];
						}

						if( isset($ddata['ddata']['data']['mtype']) )
						{	
							$c_data['mtype_value'] = $ddata['ddata']['data']['mtype'];
						}

						if( isset($ddata['ddata']['data']['roomchg']) )
						{
							$c_data['roomchg'] =  $ddata['ddata']['data']['roomchg'];
						}

						if( isset($ddata['ddata']['data']['worn']) )
						{
							$c_data['worn'] =  $ddata['ddata']['data']['worn'];
						}	

						if( isset($ddata['ddata']['data']['blvl']) )
						{
							$c_data['blvl'] =  $ddata['ddata']['data']['blvl'];
						}

						if( isset($ddata['ddata']['data']['llvl']) )
						{
							$c_data['llvl'] =  $ddata['ddata']['data']['llvl'];
						}

						if( isset($ddata['ddata']['data']['uvlvl']) )
						{
							$c_data['uvlvl'] =  $ddata['ddata']['data']['uvlvl'];
						}

						if( isset($ddata['ddata']['data']['btn']) )
						{
							$c_data['btn'] =  $ddata['ddata']['data']['btn'];
						}

						if( isset($ddata['ddata']['data']['temp']) )
						{
							$c_data['temp'] =  $ddata['ddata']['data']['temp'];
						}

						if( isset($ddata['ddata']['data']['rh']) )
						{
							$c_data['rh'] =  $ddata['ddata']['data']['rh'];
						}

						if( isset($ddata['ddata']['data']['slvl']) )
						{
							$c_data['slvl'] =  $ddata['ddata']['data']['slvl'];
						}

						if( isset($ddata['ddata']['data']['onchrg']) )
						{
							$c_data['onchrg'] =  $ddata['ddata']['data']['onchrg'];
						}

						if( isset($ddata['ddata']['data']['verbose']) )
						{
							$c_data['verbose'] =  $ddata['ddata']['data']['verbose'];
						}

					}

					if( $ddata['ddata']['dtype'] == 3 ) #temporary
					{
						if( isset($ddata['ddata']['data']['rtype']) )
						{
							$c_data['rtype_value'] = $ddata['ddata']['data']['rtype'];
						}

						if( isset($ddata['ddata']['data']['rloc']) )
						{
							$c_data['rloc'] = $ddata['ddata']['data']['rloc'];
						}

						if( isset($ddata['ddata']['data']['blvl']) )
						{
							$c_data['beacon_blvl'] =  $ddata['ddata']['data']['blvl'];
						}
					}

					$d++;
				}

				$dutcTime = strtotime($dutc);
				$c_data['created_at'] = date('Y-m-d H:i:s', $dutcTime);

				

				$params['facility_id'] = ($TD) ? $TD->facility_id : NULL;
				$params['account_id'] = ($TD) ? $TD->account_id : NULL;
				$params['room_type_id'] = $rtype_id;
				$params['rloc'] = isset($c_data['rloc']) ? $c_data['rloc'] : NULL;
	

				$B = Beacon::with('floor')->where(['beacon_id' => $params['rloc'],'room_type_id' => $rtype_id, 
								'account_id' => $params['account_id'],'facility_id' => $params['facility_id'] ])->first(); 
				$Bdesc = ($B) ? $B->desc : 'NO_ASSIGNED_ROOM';
				$Bfloor = ($B) ? (($B->floor) ? $B->floor->floor_number : NULL) : NULL;
				$beacon_localtion = $Bfloor.' '.$Bdesc;
				
				$this->storeCleanParseData($c_data);  //store combined data

				$value->update(['is_parsed' => 1]);

				$parsed_obj = ['dutc' => date('Y-m-d H:i:s', $dutcTime) , 'data' => $dser. ' is '. $mtype_data . ' in '. $beacon_localtion . ' - ('. $rtype_data . ')'];

				$U = User::find(1);
				$data_string = json_encode(['message' => $parsed, 'data' => ['log', $U->email_confirmcode], 'parseRawD' => $c_data, 'parseD' => $parsed_obj ]);

				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
				    'Content-Type: application/json',                                                                                
				    'Content-Length: ' . strlen($data_string))                                                                       
				);                                                                                                                   
				 
				$res = curl_exec($ch);
				
				if( curl_errno($ch) )
				{
					$notifyResult = 'FAILED! ' . curl_error($ch) . ' - (' . curl_errno($ch) . ')';
				}

				else
				{
					curl_close($ch);
					$notifyResult = true;
				}
			}

			$dumpPivot->is_parsed = 1;
			$dumpPivot->save();

		}

		return ['success' => true];

	}

	private function store_parse($data)
	{

		$mdata = null;
		$rdata = null;
		$blvl = null;
		$llvl = null;
		$worn	= 1;
		$dhid = null;
		$ndser = null;
		$btn  = null;
		$rloc = null;
		$temp = null;
		$rh = null;
		$slvl = null;
		$onchrg = null;
		$verbose = null;
		$roomchg = null;
		$dutc = null;
		$dser = null;
		$dlogs = null;
		$dhver = null;
		$dsver = null;
		$uvlvl = null;

		if( $data['ddata']['dtype'] == 2 ) 
		{
		
			if( isset($data['ddata']['dutc']) ) # temporary
			{
				$dutc = $data['ddata']['dutc'];
			}

			if( isset($data['ddata']['dhid']) )
			{
				$dhid = $data['ddata']['dhid'];
			}

			if( isset($data['ddata']['dser']) )
			{
				$dser = $data['ddata']['dser'];
			}

			if( isset($data['ddata']['data']['mtype']) )
			{
				$MT = MovementType::where('mtype_value', $data['ddata']['data']['mtype'])->first();
				if($MT)
				{
					$mdata =  $MT->id;
				}
				else
				{
					$mdata = NULL;
				}
			}

			if( isset($data['ddata']['data']['blvl']) )
			{
				$blvl =  $data['ddata']['data']['blvl'];
			}

			if( isset($data['ddata']['data']['llvl']) )
			{
				$llvl =  $data['ddata']['data']['llvl'];
			}

			if( isset($data['ddata']['data']['uvlvl']) )
			{
				$uvlvl =  $data['ddata']['data']['uvlvl'];
			}
				
			if( isset($data['ddata']['data']['worn']) )
			{
				$worn =  $data['ddata']['data']['worn'];
			}	

			if( isset($data['ddata']['data']['btn']) )
			{
				$btn =  $data['ddata']['data']['btn'];
			}

			if( isset($data['ddata']['data']['temp']) )
			{
				$temp =  $data['ddata']['data']['temp'];
			}

			if( isset($data['ddata']['data']['rh']) )
			{
				$rh =  $data['ddata']['data']['rh'];
			}

			if( isset($data['ddata']['data']['slvl']) )
			{
				$slvl =  $data['ddata']['data']['slvl'];
			}

			if( isset($data['ddata']['data']['onchrg']) )
			{
				$onchrg =  $data['ddata']['data']['onchrg'];
			}

			if( isset($data['ddata']['data']['verbose']) )
			{
				$verbose =  $data['ddata']['data']['verbose'];
			}

			if( isset($data['ddata']['data']['roomchg']) )
			{
				$roomchg =  $data['ddata']['data']['roomchg'];
			}
			
			if( isset($data['ddata']['data']['rtype']) )
			{
				$RT = RoomType::where('rtype_value', $data['ddata']['data']['rtype'])->first();
				if($RT)
				{
					$rdata =  $RT->id; 
				}
				else
				{
					$rdata = NULL; 
				}
				
			}

			$rloc = null;
			

		}

		if( $data['ddata']['dtype'] == 0 ) #temporary
		{
			
			if( isset($data['ddata']['dutc']) )
			{
				$dutc = $data['ddata']['dutc'];
			}

			if( isset($data['ddata']['dser']) )
			{
				$dser = $data['ddata']['dser'];
			}
				
			if( isset($data['ddata']['dhid']) )
			{
				$dhid = $data['ddata']['dhid'];
			}
			
			$mdata = null;
			$rdata = null;
			$worn = null;
			$btn = null;
			$rloc = null;
			$temp = null;
			$rh = null;
			$slvl = null;
			$llvl = null;
			$onchrg = null;
			$verbose = null;
			$roomchg = null;
		}

		if( $data['ddata']['dtype'] == 3 ) #temporary
		{
			if( isset($data['ddata']['data']['rtype']) )
			{
				$RT = RoomType::where('rtype_value', $data['ddata']['data']['rtype'])->first();
				if($RT)
				{
					$rdata =  $RT->id; 
				}
				else
				{
					$rdata = NULL; 
				}
				
			}

			if( isset($data['ddata']['data']['rloc']) )
			{
				$rloc =  $data['ddata']['data']['rloc'];
			}

			if($ndser)
			{
				$dser = $ndser;
			}

			if( isset($data['ddata']['dhid']) )
			{
				$dhid = $data['ddata']['dhid'];
			}

			if( isset($data['ddata']['dser']) )
			{
				$dser = $data['ddata']['dser'];
			}

			if( isset($data['ddata']['dutc']) )
			{
				$dutc = $data['ddata']['dutc'];
			}

			if( isset($data['ddata']['data']['blvl']) )
			{
				$blvl =  $data['ddata']['data']['blvl'];
			}

			$mdata = null;
			$worn = null;
			$btn = null;
			$temp = null;
			$rh = null;
			$slvl = null;
			$onchrg = null;
			$verbose = null;
			$roomchg = null;

		}

		
		if( isset($data['ddata']['dhver']) )
		{
			$dhver = $data['ddata']['dhver'];
		}
		
		if( isset($data['ddata']['dsver']) )
		{
			$dsver = $data['ddata']['dsver'];
		}

		ParseData::create(['dtype' => $data['ddata']['dtype'], 
							'dutc' => $dutc, 
							'dser' => $dser, 
							'dhid' => $dhid,
							'mtype' => $mdata,
							'rtype' => $rdata,
							'blvl'  => $blvl,
							'llvl'	=> $llvl,
							'uvlvl' => $uvlvl,
							'worn'	=> $worn,
							'rloc'  => $rloc,
							'btn'   => $btn,
							'temp'  => $temp,
							'rh'  	=> $rh,
							'slvl'  => $slvl,
							'onchrg' => $onchrg,
							'verbose'=> $verbose,
							'roomchg' =>$roomchg,
							'dlog'	=> $dlogs,
							'dhver' => $dhver,
							'dsver'	=> $dsver
							]);

	}

	private function storeCleanParseData($data)
	{
		$mdata = null;
		$rdata = null;
		$blvl = null;
		$llvl = null;
		$worn	= 1;
		$btn  = null;
		$temp = null;
		$rh = null;
		$slvl = null;
		$onchrg = null;
		$verbose = null;
		$roomchg = null;
		$dutc = null;
		$dser = null;
		$clvl = null;
		$rloc = null;
		$resident_id = null;
		$uvlvl = null;
		$beacon_blvl = null;
		$beacon_id = null;
		$hid = null;
		$created_at = date('Y-m-d H:i:s');
		
			if( isset($data['dutc']) )
			{
				$dutc = $data['dutc'];
			}

			if( isset($data['dser']) )
			{
				$dser = $data['dser'];
			}
			
			if( isset($data['mtype_value']) )
			{
				$MT = MovementType::where('mtype_value', $data['mtype_value'])->first();
				if($MT)
				{
					$mdata =  $MT->id;
				}
				else
				{
					$mdata = NULL;
				}
			}

			if( isset($data['rtype_value']) )
			{
				$RT = RoomType::where('rtype_value', $data['rtype_value'])->first();
				if($RT)
				{
					$rdata =  $RT->id; 
				}
				else
				{
					$rdata = NULL; 
				}
				
			}

			if( isset($data['blvl']) )
			{
				$blvl =  $data['blvl'];
			}

			if( isset($data['llvl']) )
			{
				$llvl =  $data['llvl'];
			}

			if( isset($data['uvlvl']) )
			{
				$uvlvl =  $data['uvlvl'];
			}
				
			if( isset($data['worn']) )
			{
				$worn =  $data['worn'];
			}	

			if( isset($data['btn']) )
			{
				$btn =  $data['btn'];
			}

			if( isset($data['temp']) )
			{
				$temp =  $data['temp'];
			}

			if( isset($data['rh']) )
			{
				$rh =  $data['rh'];
			}

			if( isset($data['slvl']) )
			{
				$slvl =  $data['slvl'];
			}

			if( isset($data['onchrg']) )
			{
				$onchrg =  $data['onchrg'];
			}

			if( isset($data['verbose']) )
			{
				$verbose =  $data['verbose'];
			}

			if( isset($data['roomchg']) )
			{
				$roomchg =  $data['roomchg'];
			}

			if( isset($data['clvl']) )
			{
				$clvl =  $data['clvl'];
			}
			
			if( isset($data['rloc']) )
			{
				$rloc = $data['rloc'];
			}

			if( isset($data['beacon_blvl']) )
			{
				$beacon_blvl = $data['beacon_blvl'];
			}

			if( isset($data['hid']) )
			{
				$hid = $data['hid'];
			}

			if( isset($data['created_at']) )
			{
				$created_at = $data['created_at'];
			}

			if(!is_null($hid)){
				$BS = BaseStation::where('serial', $hid)->first();
				$facility_id = ($BS) ? $BS->facility_id : NULL;
				$account_id = ($BS) ? $BS->account_id : NULL;				
				$B = Beacon::where(['beacon_id' => $rloc,'room_type_id' => $rdata, 'account_id' => $account_id,'facility_id' => $facility_id ])->first(); 
				$beacon_id = ($B) ? $B->id : NULL;
			}

			if($beacon_blvl <= 20 ) // lowbat beacon
			{
				$alert_param['bat_number'] = $beacon_blvl;
				$alert_param['dtype'] = 3;
				$alert_param['rloc'] = $rloc;
				$alert_param['comhub_serial'] = $hid;
				$alert_param['room_type_id'] = $rdata;
				$this->triggerLowbat($alert_param);
			}

			if( !is_null($dser) ) 
			{

				$R = Resident::whereHas('tempoDevice', function($q) use ($dser) {
								$q->where('serial', $dser);
						})->first();

				if($R)
				{
					$resident_id = $R->id;
				}

				if(isset($data['rtype_value']) && $data['rtype_value'] == 15 ) // geo-fence
				{
					$alert_param['rloc'] = $rloc;
					$alert_param['resident_id'] = $resident_id;
					$alert_param['room_type_id'] = $rdata;
					$this->triggerLowbat($alert_param);
				}

				if($blvl <= 20 && $onchrg != 1) // lowbat
				{
					$alert_param['bat_number'] = $blvl;
					$alert_param['dtype'] = 2;
					$alert_param['dser'] = $dser;
					$alert_param['rloc'] = $rloc;
					$alert_param['room_type_id'] = $rdata;
					$this->triggerLowbat($alert_param);
				}

				if($btn == 255 ) // btn push
				{
					$alert_param['rloc'] = $rloc;
					$alert_param['resident_id'] = $resident_id;
					$alert_param['room_type_id'] = $rdata;
					$this->triggerLowbat($alert_param);
				}

				if($worn == 0 && $onchrg == 0) // not worn
				{
					$alert_param['rloc'] = $rloc;
					$alert_param['dutc'] = $dutc;
					$alert_param['resident_id'] = $resident_id;
					$alert_param['room_type_id'] = $rdata;
					$this->triggerLowbat($alert_param);
				}


				ResidentParseData::create(['dutc' => $dutc, 
								'resident_id' => $resident_id,
								'dser' => $dser, 
								'mtype' => $mdata,
								'rtype' => $rdata,
								'blvl'  => $blvl,
								'llvl'	=> $llvl,
								'uvlvl' => $uvlvl,
								'worn'	=> $worn,
								'btn'   => $btn,
								'temp'  => $temp,
								'rh'  	=> $rh,
								'slvl'  => $slvl,
								'onchrg' => $onchrg,
								'verbose'=> $verbose,
								'roomchg' =>$roomchg,
								'clvl' =>$clvl,
								'rloc' =>$rloc,
								'beacon_blvl' => $beacon_blvl,
								'beacon_id' => $beacon_id,
								'hid' => $hid,
								'created_at' => $created_at,
				]);
			}
		
	}


	//DEVICE LOG
	private function storeDeviceLog($data)
	{
		$tmplogs = [];
		if( isset($data['ddata']['dlogs']) && !empty($data['ddata']['dlogs']) ) 
		{
			if( is_array($data['ddata']['dlogs']) )
			{
				if(count($data['ddata']['dlogs']) > 0)
				{
					if($data['ddata']['dtype'] == 0)
					{
						$mdl = new BaseStationDataLog;
						foreach ($data['ddata']['dlogs'] as $key => $value) 
						{
							$ins['dutc']    = ($value['dtime']) ? date('Y-m-d H:i:s', strtotime($value['dtime'])) : NULL;
							$ins['ftype'] 	= isset($data['ddata']['ftype']) ? $data['ddata']['ftype'] : NULL;
							$ins['dser']	= isset($data['ddata']['dser']) ? $data['ddata']['dser'] : NULL;
							$ins['hmvn']	= isset($data['ddata']['hmvn']) ? $data['ddata']['hmvn'] : NULL;
							$ins['ipaddr']	= isset($data['ddata']['ipaddr']) ? $data['ddata']['ipaddr'] : NULL;
							$ins['sver'] 	= isset($data['ddata']['dsver']) ? $data['ddata']['dsver'] : NULL;
							$ins['hver']	= isset($data['ddata']['dhver']) ? $data['ddata']['dhver'] : NULL;
							$ins['dlog']	= ($value['dlog']) ? $value['dlog'] : NULL;
							$mdl::create($ins);
							$tmplogs[] = $ins;
						}
						$this->pushlogs(0, $tmplogs);
					}

					if($data['ddata']['dtype'] == 2)
					{
						
						$mdl = new TempoDeviceDataLog;
						foreach ($data['ddata']['dlogs'] as $key => $value) 
						{
							$ins['dutc']    = ($value['dtime']) ?  date('Y-m-d H:i:s', strtotime($value['dtime'])) : NULL;
							$ins['ftype'] 	= isset($data['ddata']['ftype']) ? $data['ddata']['ftype'] : NULL;
							$ins['dser']	= isset($data['ddata']['dser']) ? $data['ddata']['dser'] : NULL;
							$ins['tmvn']	= isset($data['ddata']['tmvn']) ? $data['ddata']['tmvn'] : NULL;
							$ins['amvn']	= isset($data['ddata']['amvn']) ? $data['ddata']['amvn'] : NULL;
							$ins['sver'] 	= isset($data['ddata']['dsver']) ? $data['ddata']['dsver'] : NULL;
							$ins['hver']	= isset($data['ddata']['dhver']) ? $data['ddata']['dhver'] : NULL;
							$ins['dlog']	= ($value['dlog']) ? $value['dlog'] : NULL;
							$mdl::create($ins);
							$tmplogs[] = $ins;
						}
						$this->pushlogs(2, $tmplogs);
					}
					
				}
			}
		}

		return ['success' => true ];
	}

	private function pushlogs($dtype, $raw)
	{

		$url = env('SOCKET_DOMAIN').':' .env('SOCKET_API_PORT') . '/devicelogs';
		$U = User::find(1);

		$data_string = json_encode(['data' => ['device_logs', $U->email_confirmcode,$dtype,$raw]]);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
		    'Content-Type: application/json',                                                                                
		    'Content-Length: ' . strlen($data_string))                                                                       
		);                                                                                                                   
		 
		$res = curl_exec($ch);
		
		if( curl_errno($ch) )
		{
			$notifyResult = 'FAILED! ' . curl_error($ch) . ' - (' . curl_errno($ch) . ')';
		}
		else
		{
			curl_close($ch);
			$notifyResult = true;
		}
	}

	private function triggerLowbat($input){

		$url = env('SOCKET_DOMAIN').'/v1/alert/lowbat';

		$data_string = json_encode($input);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
		    'Content-Type: application/json',                                                                                
		    'Content-Length: ' . strlen($data_string))                                                                       
		);                                                                                                                   
			 
		$res = curl_exec($ch);
		
		if( curl_errno($ch) )
		{
			$notifyResult = 'FAILED! ' . curl_error($ch) . ' - (' . curl_errno($ch) . ')';
		}
		else
		{
			curl_close($ch);
			$notifyResult = true;
		}
		

	}

	private function triggerGeoFence($input){
	
		$url = env('SOCKET_DOMAIN').'/v1/alert/geofence';

		$data_string = json_encode($input);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
		    'Content-Type: application/json',                                                                                
		    'Content-Length: ' . strlen($data_string))                                                                       
		);                                                                                                                   
			 
		$res = curl_exec($ch);
		
		if( curl_errno($ch) )
		{
			$notifyResult = 'FAILED! ' . curl_error($ch) . ' - (' . curl_errno($ch) . ')';
		}
		else
		{
			curl_close($ch);
			$notifyResult = true;
		}


	}

	private function triggerPushButton($input){
		
		$url = env('SOCKET_DOMAIN').'/v1/alert/pushbutton';

		$data_string = json_encode($input);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
		    'Content-Type: application/json',                                                                                
		    'Content-Length: ' . strlen($data_string))                                                                       
		);                                                                                                                   
			 
		$res = curl_exec($ch);
		
		if( curl_errno($ch) )
		{
			$notifyResult = 'FAILED! ' . curl_error($ch) . ' - (' . curl_errno($ch) . ')';
		}
		else
		{
			curl_close($ch);
			$notifyResult = true;
		}

	}

	private function triggerNotWorn($input){
	
		$url = env('SOCKET_DOMAIN').'/v1/alert/notworn';

		$data_string = json_encode($input);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
		    'Content-Type: application/json',                                                                                
		    'Content-Length: ' . strlen($data_string))                                                                       
		);                                                                                                                   
			 
		$res = curl_exec($ch);
		
		if( curl_errno($ch) )
		{
			$notifyResult = 'FAILED! ' . curl_error($ch) . ' - (' . curl_errno($ch) . ')';
		}
		else
		{
			curl_close($ch);
			$notifyResult = true;
		}

	}

}