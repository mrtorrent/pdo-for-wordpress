<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id$
 * @author	Justin Adie, rathercurious.net
 */

/**
 * this class replaces some preg functions with UDFs instead
 * 
 */

class PDO_SQLITE_UDFS{
	private $functions = array(	
								'month'=> 'month',
								'year'=> 'year',
								'day' => 'day',
								'unix_timestamp' => 'unix_timestamp',
								'now'=>'now',
								'char_length', 'char_length',
								'md5'=>'md5',
								'curdate'=>'now',
								'rand'=>'rand',
								'substring'=>'substring',
								'dayofmonth'=>'day',
								'second'=>'second',
								'minute'=>'minute',
								'hour'=>'hour',
								'date_format'=>'dateformat',
								'unix_timestamp'=>'unix_timestamp',
								'from_unixtime'=>'from_unixtime',
								'date_add'=>'date_add',
								'date_sub'=>'date_sub',
								'adddate'=>'date_add',
								'subdate'=>'date_sub',
								'localtime'=>'now',
								'localtimestamp'=>'now',
								'date'=>'date',
								'isnull'=>'isnull',
								'if' =>'_if',
								'regexpp'=>'regexp'
								);
								
	public function month($field){
		$t = strtotime($field);
		return date('n', $t);
	}
	public function year($field){
		$t = strtotime($field);
		return date('Y', $t);
	}
	public function day($field){
		$t = strtotime($field);
		return date('j', $t);
	}
	public function unix_timestamp($field = null){
		return is_null($field) ? time() : strtotime($field);
	}
	public function second($field){
		$t = strtotime($field);
		return intval( date("s", $t) );
	}
	public function minute($field){
		$t = strtotime($field);
		return  intval(date("i", $t));
	}
	public function hour($time){
		list($hours, $minutes, $seconds) = explode(":", $time);
		return intval($hours);
	}
	public function from_unixtime($field, $format=null){
		// $field is a timestamp
		//convert to ISO time
		$date = date("Y-m-d H:i:s", $field);
		//now submit to dateformat
		
		return is_null($format) ? $date : $self->dateformat($date, $format);
	}
	public function now(){
		return date("Y-m-d H:i:s");
	}
	public function char_length($field){
		return strlen($field);
	}
	public function md5($field){
		return md5($field);
	}
	public function rand(){
		return rand(0,1);
	}
	public function substring($text, $pos, $len=null){
		return substr($text, $pos-1, $len);
	}
	public function dateformat($date, $format){
		$mysql_php_dateformats = array ( '%a' => 'D', '%b' => 'M', '%c' => 'n', '%D' => 'jS', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%h' => 'h', '%I' => 'h', '%i' => 'i', '%j' => 'z', '%k' => 'G', '%l' => 'g', '%M' => 'F', '%m' => 'm', '%p' => 'A', '%r' => 'h:i:s A', '%S' => 's', '%s' => 's', '%T' => 'H:i:s', '%U' => 'W', '%u' => 'W', '%V' => 'W', '%v' => 'W', '%W' => 'l', '%w' => 'w', '%X' => 'Y', '%x' => 'o', '%Y' => 'Y', '%y' => 'y', );
		$t = strtotime($date);
		$format = strtr($format, $mysql_php_dateformats);
		$output =  date($format, $t);
		return $output;
	}
	
	public function date_add ($date, $interval){
		$t = strtotime($date);
		$interval = $this->deriveInterval($interval);
		return strtotime("+".$interval, $t);
	}
	public function date_sub($date, $interval){
		$t = strtotime($date);
		$interval = $this->deriveInterval($interval);
		return strtotime("-".$interval, $t);
	}
	private function deriveInterval($interval){
		$interval = trim(substr(trim($interval), 8));
		$parts = explode(' ', $interval);
		foreach($parts as $part){
			if (!empty($part)){
				$_parts[] = $part;
			}
		}
		$type = strtolower(end($_parts));
		switch ($type){
			case "second":
			case "minute":
			case "hour":
			case "day":
			case "week":
			case "month":
			case "year":
				if (intval($_parts[0]) > 1){
					$type .= 's';
				}
				return "$_parts[1] $_parts[2]";
			break;
			case "minute_second":
				list($minutes, $seconds) = explode (':', $_parts[0]);
				$minutes = intval($minutes);
				$seconds = intval($seconds);
				$minutes = ($minutes > 1) ? "$minutes minutes" : "$minutes minute";
				$seconds = ($seconds > 1) ? "$seconds seconds" : "$seconds second";
				return "$minutes $seconds";
			break;
			
			case "hour_second":
				list($hours, $minutes, $seconds) = explode (':', $_parts[0]);
				$hours = intval($hours);
				$minutes = intval($minutes);
				$seconds = intval($seconds);
				$hours = ($hours > 1) ? "$hours hours" : "$hours hour";
				$minutes = ($minutes > 1) ? "$minutes minutes" : "$minutes minute";
				$seconds = ($seconds > 1) ? "$seconds seconds" : "$seconds second";
				return "$hours $minutes $seconds";
			break;
			case "hour_minute":
				list($hours, $minutes) = explode (':', $_parts[0]);
				$hours = intval($hours);
				$minutes = intval($minutes);
				$hours = ($hours > 1) ? "$hours hours" : "$hours hour";
				$minutes = ($minutes > 1) ? "$minutes minutes" : "$minutes minute";
				return "$hours $minutes";
				break;
			case "day_second":
				$days = intval($_parts[0]);
				list($hours, $minutes, $seconds) = explode (':', $_parts[1]);
				$hours = intval($hours);
				$minutes = intval($minutes);
				$seconds = intval($seconds);
				$days = $days > 1 ? "$days days" : "$days day";
				$hours = ($hours > 1) ? "$hours hours" : "$hours hour";
				$minutes = ($minutes > 1) ? "$minutes minutes" : "$minutes minute";
				$seconds = ($seconds > 1) ? "$seconds seconds" : "$seconds second";
				return "$days $hours $minutes $seconds";
				break;
			case "day_minute":
				$days = intval($_parts[0]);
				list($hours, $minutes) = explode (':', $_parts[1]);
				$hours = intval($hours);
				$minutes = intval($minutes);
				$days = $days > 1 ? "$days days" : "$days day";
				$hours = ($hours > 1) ? "$hours hours" : "$hours hour";
				$minutes = ($minutes > 1) ? "$minutes minutes" : "$minutes minute";
				return "$days $hours $minutes";
				break;	
			case "day_hour":
				$days = intval($_parts[0]);
				$hours = intval($_parts[1]);
				$days = $days > 1 ? "$days days" : "$days day";
				$hours = ($hours > 1) ? "$hours hours" : "$hours hour";
				return "$days $hours";
				break;	
			case "year_month":
				list($years, $months) = explode ('-', $_parts[0]);
				$years = intval($years);
				$months = intval($months);
				$years = ($years > 1) ? "$years years" : "$years year";
				$months = ($months > 1) ? "$months months": "$months month";
				return "$years $months";
				break;
		}
	}
	
	public function date($date){
		return date("Y-m-d", strtotime($date));
	}
	
	public function isnull($field){
		return is_null($field);
	}
	
	public function _if($expression, $true, $false){
		return ($expression == true) ? $true : $false;
	}
	
	public function __construct(&$pdo){
		foreach ($this->functions as $f=>$t){
			$pdo->sqliteCreateFunction($f, array($this, $t));
		}
	}
	
	public function regexp($field, $pattern){
		$pattern = str_replace('/', '\/', $pattern);
		$pattern = "/" . $pattern ."/i";
		return preg_match ($pattern, $field);
	}
}
?>