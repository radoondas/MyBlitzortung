<?php

require_once 'classes/Data.class.php';

/* For compatibility. */
function bo_db($query = '', $die = true)
{
	return BoDb::query($query, $die);
}

// Load config from database
function bo_get_conf($name, &$changed=0)
{
	return BoData::get($name, $changed);
}

// Save config in database
function bo_set_conf($name, $data)
{
	if ($data === null)
		return BoData::delete($data);
	else
		return BoData::set($name, $data);
}


function bo_db_recreate_strike_keys($quiet = false)
{
	if (!$quiet)
	{
		echo "Updating database keys.\n";
		echo "WARNING: This may take several minutes or longer!\n";
		echo "Please wait until the page as fully loaded!\n";
		echo "Executing Database commands:\n";
	}
	
	$byte2mysql[1] = 'TINYINT';
	$byte2mysql[2] = 'SMALLINT';
	$byte2mysql[3] = 'MEDIUMINT';
	$byte2mysql[4] = 'INT';
	$maxbytes = 4;
	
	//Get the columns an data types
	$cols = array();
	$res = BoDb::query("SHOW COLUMNS FROM ".BO_DB_PREF."strikes");
	while($row = $res->fetch_assoc())
	{
		$bytes = 0;

		foreach($byte2mysql as $byte => $type)
		{
			if (strpos(strtoupper($row['Type']), $type.'(') !== false)
			{
				$bytes = $byte;
				break;
			}
		}
		
		$cols[$row['Field']] = $bytes;
	}
	
	//Get existing keys
	$keys['timelatlon'] = BoDb::query("SHOW INDEX FROM `".BO_DB_PREF."strikes` WHERE Key_name='timelatlon_index'")->num_rows;
	$keys['time']       = BoDb::query("SHOW INDEX FROM `".BO_DB_PREF."strikes` WHERE Key_name='time_index'")->num_rows;
	$keys['latlon']     = BoDb::query("SHOW INDEX FROM `".BO_DB_PREF."strikes` WHERE Key_name='latlon_index'")->num_rows;


	//Set the new config
	$keys_enabled = (BO_DB_EXTRA_KEYS === true);
	$bytes_time   = $keys_enabled ? intval(BO_DB_EXTRA_KEYS_TIME_BYTES)   : 0;
	$bytes_latlon = $keys_enabled ? intval(BO_DB_EXTRA_KEYS_LATLON_BYTES) : 0;
	$bytes_time   = 0 < $bytes_time   && $bytes_time   <= $maxbytes ? $bytes_time   : 0;
	$bytes_latlon = 0 < $bytes_latlon && $bytes_latlon <= $maxbytes ? $bytes_latlon : 0;
	$mercator     = BO_DB_STRIKES_MERCATOR === true;
	
	$sql_alter = array();
	
	//1. Remove keys if needed
	if ($keys['timelatlon'] && (!$bytes_time || !$bytes_latlon))
		$sql_alter[] = 'DROP INDEX `timelatlon_index`';

	if ($keys['time'] && !$bytes_time)
		$sql_alter[] = 'DROP INDEX `time_index`';

	if ($keys['latlon'] && !$bytes_latlon)
		$sql_alter[] = 'DROP INDEX `latlon_index`';

	//2. Remove columns if needed
	if (isset($cols['time_x']) && !$bytes_time)
		$sql_alter[] = 'DROP `time_x`';
	
	if (isset($cols['lat_x']) && !$bytes_latlon)
		$sql_alter[] = 'DROP `lat_x`';
	
	if (isset($cols['lon_x']) && !$bytes_latlon)
		$sql_alter[] = 'DROP `lon_x`';

	if (isset($cols['lat_merc']) && !$mercator)
		$sql_alter[] = 'DROP `lat_merc`';

	if (isset($cols['lon_merc']) && !$mercator)
		$sql_alter[] = 'DROP `lon_merc`';
	

	if (!empty($sql_alter))
		bo_db_recreate_strike_keys_db('ALTER TABLE '.BO_DB_PREF.'strikes '.implode(', ',$sql_alter), $quiet);

		
	$sql_alter = array();
	
	//3. Add/change columns if needed
	if (!isset($cols['time_x']) && $bytes_time)
	{
		BoData::set('db_keys_update', 1);
		$sql_alter[] = 'ADD `time_x` '.$byte2mysql[$bytes_time].' UNSIGNED NOT NULL';
	}
	else if (isset($cols['time_x']) && $bytes_time && $cols['time_x'] != $bytes_time)
	{
		BoData::set('db_keys_update', 1);
		$sql_alter[] = 'CHANGE `time_x` `time_x` '.$byte2mysql[$bytes_time].' UNSIGNED NOT NULL';
	}
		
	if (!isset($cols['lat_x']) && $bytes_latlon)
	{
		BoData::set('db_keys_update', 1);
		$sql_alter[] = 'ADD `lat_x` '.$byte2mysql[$bytes_latlon].' UNSIGNED NOT NULL';
	}
	else if (isset($cols['lat_x']) && $bytes_latlon && $cols['lat_x'] != $bytes_latlon)
	{
		BoData::set('db_keys_update', 1);
		$sql_alter[] = 'CHANGE `lat_x` `lat_x` '.$byte2mysql[$bytes_latlon].' UNSIGNED NOT NULL';
	}

	if (!isset($cols['lon_x']) && $bytes_latlon)
	{
		BoData::set('db_keys_update', 1);
		$sql_alter[] = 'ADD `lon_x` '.$byte2mysql[$bytes_latlon].' UNSIGNED NOT NULL';
	}
	else if (isset($cols['lon_x']) && $bytes_latlon && $cols['lon_x'] != $bytes_latlon)
	{
		BoData::set('db_keys_update', 1);
		$sql_alter[] = 'CHANGE `lon_x` `lon_x` '.$byte2mysql[$bytes_latlon].' UNSIGNED NOT NULL';
	}

	if (!isset($cols['lat_merc']) && $mercator)
	{
		BoData::set('db_mercator_update', 1);
		$sql_alter[] = 'ADD `lat_merc` INT UNSIGNED NOT NULL';
	}

	if (!isset($cols['lon_merc']) && $mercator)
	{
		BoData::set('db_mercator_update', 1);
		$sql_alter[] = 'ADD `lon_merc` INT UNSIGNED NOT NULL';
	}
	
	if (!empty($sql_alter))
		bo_db_recreate_strike_keys_db('ALTER TABLE '.BO_DB_PREF.'strikes '.implode(', ',$sql_alter), $quiet);

	
	
	//4. Add keys if needed
	$sql_alter = array();
	if (!$keys['timelatlon'] && $bytes_time && $bytes_latlon)
		$sql_alter[] = 'ADD INDEX `timelatlon_index` (`lat_x`,`lon_x`,`time_x`)';

	//if (!$keys['time'] && $bytes_time)
	//	$sql_alter[] = 'ADD INDEX `time_index` (`time_x`)';

	if (!$keys['latlon'] && $bytes_latlon)
		$sql_alter[] = 'ADD INDEX `latlon_index` (`lat_x`,`lon_x`)';
		
	if (!empty($sql_alter))
		bo_db_recreate_strike_keys_db('ALTER TABLE '.BO_DB_PREF.'strikes '.implode(', ',$sql_alter), $quiet);

		
	//5. update values
	list($t1, $t2, $p1, $p2) = unserialize(BoData::get('db_keys_settings'));
	
	if ( BoData::get('db_keys_update') == 1
			|| $t1 != BO_DB_EXTRA_KEYS_TIME_START
			|| $t2 != BO_DB_EXTRA_KEYS_TIME_DIV_MINUTES
			|| $p1 != BO_DB_EXTRA_KEYS_LAT_DIV
			|| $p2 != BO_DB_EXTRA_KEYS_LON_DIV
		)
	{
		$ok = true;
		
		if ($bytes_time)
		{
			$time_vals   = pow(2, 8 * $bytes_time);
			$time_start  = strtotime(BO_DB_EXTRA_KEYS_TIME_START);
			$time_div    = (double)BO_DB_EXTRA_KEYS_TIME_DIV_MINUTES;
			$sql = 'UPDATE `'.BO_DB_PREF.'strikes` SET time_x=(FLOOR((UNIX_TIMESTAMP(time)-'.$time_start.')/60/'.$time_div.')%'.$time_vals.')';
			$ok = bo_db_recreate_strike_keys_db($sql, $quiet) >= 0;
		}
		
		if ($ok && $bytes_latlon)
		{
			$latlon_vals = pow(2, 8 * $bytes_latlon);
			$lat_div     = (double)BO_DB_EXTRA_KEYS_LAT_DIV;
			$lon_div     = (double)BO_DB_EXTRA_KEYS_LON_DIV;
			
			$sql = 'UPDATE `'.BO_DB_PREF.'strikes` SET lat_x=FLOOR(((90+lat)%'.$lat_div.')/'.$lat_div.'*'.$latlon_vals.'), lon_x=FLOOR(((180+lon)%'.$lon_div.')/'.$lon_div.'*'.$latlon_vals.')';
			$ok = bo_db_recreate_strike_keys_db($sql, $quiet) >= 0;
		}
		
		if ($ok)
		{
			BoData::set('db_keys_update', 0);
			BoData::set('db_keys_settings', 
					serialize(array(BO_DB_EXTRA_KEYS_TIME_START, 
									BO_DB_EXTRA_KEYS_TIME_DIV_MINUTES, 
									BO_DB_EXTRA_KEYS_LAT_DIV, 
									BO_DB_EXTRA_KEYS_LON_DIV)));
		}
	}

	BoData::set('db_mercator_update', 1);
	if ( BoData::get('db_mercator_update') )
	{
		$sql = 'UPDATE `'.BO_DB_PREF.'strikes` SET '.
				'lat_merc='.bo_sql_lat2tiley('lat', BO_DB_STRIKES_MERCATOR_SCALE, false).', '.
				'lon_merc='.bo_sql_lon2tilex('lon', BO_DB_STRIKES_MERCATOR_SCALE, false).
				'WHERE lat_merc=0 AND lon_merc=0';
		
		$ok = bo_db_recreate_strike_keys_db($sql, $quiet) >= 0;
		
		if ($ok)
			BoData::set('db_mercator_update', 0);
	}
	
	if (!$quiet)
		echo "\n\nFinished! ";
	
	return;
}

function bo_db_recreate_strike_keys_db($sql, $quiet = false)
{
	if (!$quiet)
		echo "\n * $sql: ";
	
	flush();
	$ok = BoDb::query($sql, false);

	if (!$quiet)
		echo _BL($ok !== false ? 'OK' : 'FAIL');
	
	flush();

	return $ok;
}

?>