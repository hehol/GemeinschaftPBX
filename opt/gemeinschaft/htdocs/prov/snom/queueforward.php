<?php
/*******************************************************************\
*            Gemeinschaft - asterisk cluster gemeinschaft
* 
* $Revision: 3307 $
* 
* Copyright 2007, amooma GmbH, Bachstr. 126, 56566 Neuwied, Germany,
* http://www.amooma.de/
*
* Author: Andreas Neugebauer <neugebauer@loca.net> - LocaNet oHG
* 
* This program is free software; you can redistribute it and/or
* modify it under the terms of the GNU General Public License
* as published by the Free Software Foundation; either version 2
* of the License, or (at your option) any later version.
* 
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
* 
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
* MA 02110-1301, USA.
\*******************************************************************/

# caution: earlier versions of Snom firmware do not like
# indented XML

define( 'GS_VALID', true );  /// this is a parent file

require_once( '../../../inc/conf.php' );
require_once( GS_DIR .'inc/db_connect.php' );
include_once( GS_DIR .'inc/gs-lib.php' );
include_once( GS_DIR .'inc/gs-fns/gs_queues_get.php' );
include_once( GS_DIR .'inc/gs-fns/gs_queue_callforward_activate.php' );
include_once( GS_DIR .'inc/gs-fns/gs_queue_callforward_get.php' );
include_once( GS_DIR .'inc/gs-fns/gs_queue_callforward_set.php' );

header( 'Content-Type: application/x-snom-xml; charset=utf-8' );
# the Content-Type header is ignored by the Snom
header( 'Expires: 0' );
header( 'Pragma: no-cache' );
header( 'Cache-Control: private, no-cache, must-revalidate' );
header( 'Vary: *' );


function snomXmlEsc( $str )
{
	return str_replace(
		array('<', '>', '"'   , "\n"),
		array('_', '_', '\'\'', ' ' ),
		$str);
	# the stupid Snom does not understand &lt;, &gt, &amp;, &quot; or &apos;
	# - neither as named nor as numbered entities
}

function _ob_send()
{
	if (! headers_sent()) {
		header( 'Content-Type: application/x-snom-xml; charset=utf-8' );
		# the Content-Type header is ignored by the Snom
		header( 'Content-Length: '. (int)@ob_get_length() );
	}
	@ob_end_flush();
	die();
}

function _err( $msg='' )
{
	@ob_end_clean();
	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>', "\n",
	     '<SnomIPPhoneText>', "\n",
	       '<Title>', 'Error', '</Title>', "\n",
	       '<Text>', snomXmlEsc( 'Error: '. $msg ), '</Text>', "\n",
	     '</SnomIPPhoneText>', "\n";
	_ob_send();
}

function getUserID( $ext )
{
	global $db;
	
	if (! preg_match('/^\d+$/', $ext))
		_err( 'Invalid username' );
	
	$user_id = (int)$db->executeGetOne( 'SELECT `_user_id` FROM `ast_sipfriends` WHERE `name`=\''. $db->escape($ext) .'\'' );
	if ($user_id < 1)
		_err( 'Unknown user' );
	return $user_id;
}


if (! gs_get_conf('GS_SNOM_PROV_ENABLED')) {
	gs_log( GS_LOG_DEBUG, "Snom provisioning not enabled" );
	_err( 'Not enabled' );
}

//get the queue, if existst

$queue_ext = preg_replace('/[^\d]$/', '', @$_REQUEST['queue']);

$queues = @gs_queues_get();
if (isGsError($queues)) {
        _err('Fehler beim Abfragen der Queues.');
	return;  # return to parent file
} elseif (! is_array($queues)) {
	_err('Fehler beim Abfragen der Queues.');
	return;  # return to parent file
}
                                  
$queue = null;
if ($queue_ext != '') {
	foreach ($queues as $q) {
		if ($q['name'] == $queue_ext) {
			$queue = $q;
			break;
		}
	}
}
                                                                                                                                  


$type = trim( @$_REQUEST['t'] );
if (! in_array( $type, array('internal','external','std','var','timeout'), true )) {
	$type = false;
}


$db = gs_db_slave_connect();



$tmp = array(
	15=>array('k' => 'internal' ,
	          'v' => gs_get_conf('GS_CLIR_INTERNAL', "von intern") ),
	25=>array('k' => 'external',
	          'v' => gs_get_conf('GS_CLIR_EXTERNAL', "von extern" ) ),

);

kSort($tmp);
foreach ($tmp as $arr) {
	$typeToTitle[$arr['k']] = $arr['v'];
}


$url_snom_queueforward = GS_PROV_SCHEME .'://'. GS_PROV_HOST . (GS_PROV_PORT ? ':'.GS_PROV_PORT : '') . GS_PROV_PATH .'snom/queueforward.php';
$url_snom_menu = GS_PROV_SCHEME .'://'. GS_PROV_HOST . (GS_PROV_PORT ? ':'.GS_PROV_PORT : '') . GS_PROV_PATH .'snom/menu.php';

$cases = array(
	'always' => 'immer',  
	'full'   => 'voll',
	'timeout'=> 'keine Antw.',
	'empty'=> 'leer'  
);
$actives = array(
	'no'  => 'Aus',
	'std' => 'Std.',
	'var' => 'Tmp.'
);
                                                                


function defineBackKey()
{
	global $softkeys, $keys, $user, $type, $mac, $url_snom_queueforward;
	
	
	echo '<SoftKeyItem>',
	       '<Name>#</Name>',
	       '<URL>' ,$url_snom_queueforward, '?m=',$mac, '&u=',$user, '</URL>',
	     '</SoftKeyItem>', "\n";
	# Snom does not understand &amp; !
}


function defineBackMenu()
{
	global $user, $type, $mac, $url_snom_menu;
	
	$args = array();
		$args[] = 'm='. $mac;
		$args[] = 'u='. $user;
		$args[] = 't=forward';
	
	echo '<SoftKeyItem>',
	       '<Name>#</Name>',
	       '<URL>', $url_snom_menu, '?', implode('&', $args), '</URL>',
	     '</SoftKeyItem>', "\n";
	# Snom does not understand &amp; !
}




################################## SET FEATURE {

if($type != false && isset($_REQUEST['value']) && $queue != null){


	$value = trim( @$_REQUEST['value'] );
	$user = trim( @ $_REQUEST['u'] );
	$user_id = getUserID( $user );
	$user_name = $db->executeGetOne( 'SELECT `user` FROM `users` WHERE `id`=\''. $db->escape($user_id) .'\'' );


	$callforwards = @gs_queue_callforward_get( $queue_ext );
	if (isGsError($callforwards)) {
        	 _err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} elseif (! is_array($callforwards)) {
        	_err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} else $queue_exists = true;
	
	
	# find best match for std number
	#
	$number = array();
	$number['std'] = '';
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_std'] != '') {
				$number['std'] = $_info['number_std'];
				break;
			}
		}
	}	
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_std'] != '' && $_info['active']=='std') {
				$number['std'] = $_info['number_std'];
				break;
			}
		}
	}

	# find best match for var number
	#
	$number['var'] = '';
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_var'] != '') {
				$number['var'] = $_info['number_var'];
				break;
			}
		}
	}
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_var'] != '' && $_info['active']=='var') {
				$number['var'] = $_info['number_var'];
				break;
			}
		}
	}


 $timeout = 15;
$timeout = (int)@$callforwards['internal']['timeout']['timeout'];	
	
	$write = 0;
	
	
	if(($type == 'internal' || $type == 'external') &&  isset($_REQUEST['key']) && isset($actives[$value])){
	
	 	
		$key = trim( @$_REQUEST['key'] );
		if(isset($cases[$key])){
			$callforwards[$type][$key]['active'] = $value;
			unset($_REQUEST['key']);	
			$write = 1;
		}
	
	}
	else if($type == 'timeout'){
	
		$value =  abs((int)$value);
		if ($value < 1) $value = 1;
		$timeout = $value;
		
		$write = 1;
		$type = false;
	}
	else if($type == 'var' || $type == 'std'){
		$number[$type] =   preg_replace('/[^\d]/', '', $value);
		$write = 1;
		unset($_REQUEST['value']);
		$type = false;
		
	}

	if($write == 1){
	
	 
		foreach ($cases as $case => $gnore2) {
			 $ret = gs_queue_callforward_set( $queue_ext, 'internal', $case, 'std', $number['std'], $timeout );
			 $ret = gs_queue_callforward_set( $queue_ext, 'internal', $case, 'var', $number['var'], $timeout );
			 $ret = gs_queue_callforward_activate( $queue_ext, 'internal', $case, $callforwards['internal'][$case]['active'] );
			                                 
		}
		foreach ($cases as $case => $gnore2) {
			 $ret = gs_queue_callforward_set( $queue_ext, 'external', $case, 'std', $number['std'], $timeout );
			 $ret = gs_queue_callforward_set( $queue_ext, 'external', $case, 'var', $number['var'], $timeout );
			 $ret = gs_queue_callforward_activate( $queue_ext, 'external', $case, $callforwards['external'][$case]['active'] );
			                                 
		}
		           
	}
	
}

################################# SET FEATURE }  




#################################### SET PROBERTIES {
if (($type == 'internal' || $type == 'external') && !isset( $_REQUEST['key'])) {
	
	$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['m'] )));
	$user = trim( @ $_REQUEST['u'] );
	$user_id = getUserID( $user );
	

	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>',"\n";
	
	$user_id_check = $db->executeGetOne( 'SELECT `user_id` FROM `phones` WHERE `mac_addr`=\''. $db->escape($mac) .'\'' );
	if ($user_id != $user_id_check)
		_err( 'Not authorized' );
	
	$remote_addr = @$_SERVER['REMOTE_ADDR'];
	$remote_addr_check = $db->executeGetOne( 'SELECT `current_ip` FROM `users` WHERE `id`=\''. $user_id.'\''   );
	if ($remote_addr != $remote_addr_check)
		_err( 'Not authorized' );
	
	$callforwards = @gs_queue_callforward_get( $queue_ext );
	if (isGsError($callforwards)) {
        	 _err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} elseif (! is_array($callforwards)) {
        	_err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} else $queue_exists = true;
	
	
	
	
	echo '<SnomIPPhoneMenu>', "\n";
	echo '<Title>', snomXmlEsc( $typeToTitle[$type] ), '</Title>', "\n";
	foreach($cases as $case => $v){	
		echo '<MenuItem>',"\n";
		echo '<Name>',snomXmlEsc($v . ': ' . $actives[$callforwards[$type][$case]['active']]),'</Name>',"\n";
		echo '<URL>';
		echo  $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=',$type,'&queue=',$queue_ext,'&key=',$case;
		echo '</URL>',"\n";  
		echo '</MenuItem>',"\n";
	}

	defineBackKey();
	echo '</SnomIPPhoneMenu>', "\n",
	_ob_send();
}
#################################### SELECT PROBERITES }



#################################### SET CF-STATES {
if ($type == 'internal' || $type == 'external' && isset( $_REQUEST['key'])) {
	
	$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['m'] )));
	$user = trim( @ $_REQUEST['u'] );
	$user_id = getUserID( $user );
	$key = trim( @ $_REQUEST['key'] );

	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>',"\n";

	$user_id_check = $db->executeGetOne( 'SELECT `user_id` FROM `phones` WHERE `mac_addr`=\''. $db->escape($mac) .'\'' );
	if ($user_id != $user_id_check)
		_err( 'Not authorized' );
	
	$remote_addr = @$_SERVER['REMOTE_ADDR'];
	$remote_addr_check = $db->executeGetOne( 'SELECT `current_ip` FROM `users` WHERE `id`=\''. $user_id.'\''   );
	if ($remote_addr != $remote_addr_check)
		_err( 'Not authorized' );
	
	//ask db for akt settings	
	$callforwards = @gs_queue_callforward_get( $queue_ext );
	if (isGsError($callforwards)) {
        	 _err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} elseif (! is_array($callforwards)) {
        	_err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} else $queue_exists = true;


	
	if(isset($cases[$key])){

		$val = 'no';
		$val = $callforwards[$type][$key]['active'];
		
		
		echo '<SnomIPPhoneMenu>',"\n";
		echo '<Title>', snomXmlEsc($typeToTitle[$type] . ':  ' . $cases[$key] ), '</Title>', "\n";
		echo '<MenuItem';
		if($val == 'no')echo ' sel=true';
		echo'>', "\n";
		echo '<Name>',snomXmlEsc($actives['no']),'</Name>',"\n";
		echo '<URL>';
		echo  $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=',$type, '&queue=',$queue_ext,'&key='.$key.'&value=no';
		echo '</URL>',"\n";  
		echo '</MenuItem>',"\n";
		
		echo '<MenuItem';
		if($val == 'std')echo ' sel=true';
		echo'>', "\n";
		echo '<Name>',snomXmlEsc($actives['std']),'</Name>',"\n";
		echo '<URL>';
		echo  $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=',$type, '&queue=',$queue_ext,'&key='.$key.'&value=std';
		echo '</URL>',"\n";  
		echo '</MenuItem>',"\n";
		
		echo '<MenuItem';
		if($val == 'var')echo ' sel=true';
		echo'>', "\n";
		echo '<Name>',snomXmlEsc($actives['var']),'</Name>',"\n";
		echo '<URL>';
		echo  $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=',$type, '&queue=',$queue_ext,'&key='.$key.'&value=var';
		echo '</URL>',"\n";  
		echo '</MenuItem>',"\n";
		defineBackKey();
		echo '</SnomIPPhoneMenu>',"\n";		
	_ob_send();
	}
	
}
#################################### SET CF-STATES }



#################################### SET PHONENUMBERS {
if ($type == 'std' || $type == 'var' && !isset( $_REQUEST['value'])) {
	
	$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['m'] )));
	$user = trim( @ $_REQUEST['u'] );
	$user_id = getUserID( $user );
	
	if( $type == 'varnumber')$Title = 'temp. Nummer';
	else $Title = 'Standardnummer';

	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>',"\n";
	
	$user_id_check = $db->executeGetOne( 'SELECT `user_id` FROM `phones` WHERE `mac_addr`=\''. $db->escape($mac) .'\'' );
	if ($user_id != $user_id_check)
		_err( 'Not authorized' );
	
	$remote_addr = @$_SERVER['REMOTE_ADDR'];
	$remote_addr_check = $db->executeGetOne( 'SELECT `current_ip` FROM `users` WHERE `id`=\''. $user_id.'\''   );
	if ($remote_addr != $remote_addr_check)
		_err( 'Not authorized' );
	
	$callforwards = @gs_queue_callforward_get( $queue_ext );
	if (isGsError($callforwards)) {
        	 _err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} elseif (! is_array($callforwards)) {
        	_err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} else $queue_exists = true;
	
	
	# find best match for std number
	#
	$number = array();
	$number['std'] = '';
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_std'] != '') {
				$number['std'] = $_info['number_std'];
				break;
			}
		}
	}	
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_std'] != '' && $_info['active']=='std') {
				$number['std'] = $_info['number_std'];
				break;
			}
		}
	}

	# find best match for var number
	#
	$number['var'] = '';
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_var'] != '') {
				$number['var'] = $_info['number_var'];
				break;
			}
		}
	}
	foreach ($callforwards as $_source => $_cases) {
		foreach ($_cases as $_case => $_info) {
			if ($_info['number_var'] != '' && $_info['active']=='var') {
				$number['var'] = $_info['number_var'];
				break;
			}
		}
	}

	 
		
		echo '<SnomIPPhoneInput>',"\n";
		echo '<Title>',snomXmlEsc($Title),'</Title>',"\n";
		echo '<Prompt>Prompt</Prompt>',"\n";
		echo '<URL>';
		echo  $url_snom_queueforward;
		echo '</URL>',"\n";
		echo '<InputItem>',"\n";
		echo '<DisplayName>neue Nummer</DisplayName>',"\n";
		echo '<QueryStringParam>','m=',$mac, '&u=',$user, '&t=',$type,'&queue=',$queue_ext,'&value' ,'</QueryStringParam>',"\n";
		echo '<DefaultValue>',$number[$type],'</DefaultValue>',"\n";
		echo '<InputFlags>t</InputFlags>',"\n";
		echo '</InputItem >',"\n";
			
		
		 defineBackKey();
		echo '</SnomIPPhoneMenu>', "\n";
		

	_ob_send();
}
#################################### SELECT PHONENUMBERS }

#################################### SET TIMEOUT {
if ($type == 'timeout' && !isset( $_REQUEST['value']) && $queue != null) {
	
	$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['m'] )));
	$user = trim( @ $_REQUEST['u'] );
	$user_id = getUserID( $user );
	
	$Title = 'Timeout bei keine Antwort';

	
	$user_id_check = $db->executeGetOne( 'SELECT `user_id` FROM `phones` WHERE `mac_addr`=\''. $db->escape($mac) .'\'' );
	if ($user_id != $user_id_check)
		_err( 'Not authorized' );
	
	$remote_addr = @$_SERVER['REMOTE_ADDR'];
	$remote_addr_check = $db->executeGetOne( 'SELECT `current_ip` FROM `users` WHERE `id`=\''. $user_id.'\''   );
	if ($remote_addr != $remote_addr_check)
		_err( 'Not authorized' );
	

	$callforwards = @gs_queue_callforward_get( $queue_ext );
	if (isGsError($callforwards)) {
        	 _err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} elseif (! is_array($callforwards)) {
        	_err('Fehler beim Abfragen der Rufumleitungen der Queue.');
	} else $queue_exists = true;
	
	# find best match for unavail timeout
	#
	if ( @$callforwards['internal']['timeout']['active'] != 'no' && @$callforwards['external']['timeout']['active'] != 'no' )
	{ 
		$timeout = ceil((
			(int)@$callforwards['internal']['timeout']['timeout'] +
			(int)@$callforwards['external']['timeout']['timeout']
			)/2);	
		} elseif (@$callforwards['internal']['timeout']['active'] != 'no') {
			$timeout = (int)@$callforwards['internal']['timeout']['timeout'];
		} elseif (@$callforwards['external']['timeout']['active'] != 'no') {
			$timeout = (int)@$callforwards['external']['timeout']['timeout'];
		} else {
			$timeout = 15;
		}


	

	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>',"\n";
		
		echo '<SnomIPPhoneInput>',"\n";
		echo '<Title>',snomXmlEsc($Title),'</Title>',"\n";
		echo '<Prompt>Prompt</Prompt>',"\n";
		echo '<URL>';
		echo  $url_snom_queueforward;
		echo '</URL>',"\n";
		echo '<InputItem>',"\n";
		echo '<DisplayName>neue Timeout</DisplayName>',"\n";
		echo '<QueryStringParam>','m=',$mac, '&u=',$user,'&queue=',$queue_ext, '&t=timeout&value' ,'</QueryStringParam>',"\n";
		echo '<DefaultValue>',$timeout,'</DefaultValue>',"\n";
		echo '<InputFlags>n</InputFlags>',"\n";
		echo '</InputItem >',"\n";
			
		
		 defineBackKey();
		echo '</SnomIPPhoneMenu>', "\n";
		

	_ob_send();
}
#################################### SELECT TIMEOUT}


#################################### OPTIONS SCREEN {
if (! $type && $queue != null) {
	
	$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['m'] )));
	$user = trim( @$_REQUEST['u'] );
	$user_id = getUserID( $user );
	
	
	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>', "\n",
	     '<SnomIPPhoneMenu>', "\n",
	       '<Title>Rufumleitung Q ',snomXmlEsc($queue_ext),'</Title>', "\n\n";
	
	echo '<MenuItem>', "\n",
	        '<Name>', snomXmlEsc('Standardnummer'), '</Name>', "\n",
	        '<URL>', $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=std','&queue=',$queue_ext,'</URL>', "\n",
	        '</MenuItem>', "\n\n";
	echo '<MenuItem>', "\n",
	        '<Name>', snomXmlEsc('temp. Nummer'), '</Name>', "\n",
	        '<URL>', $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=var','&queue=',$queue_ext, '</URL>', "\n",
	        '</MenuItem>', "\n\n";
	                                                                   
	
	
	
	foreach ($typeToTitle as $t => $title) {
		
		echo '<MenuItem>', "\n",
		       '<Name>', snomXmlEsc($title), '</Name>', "\n",
		       '<URL>', $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=',$t, '&queue=',$queue_ext,'</URL>', "\n",
		     '</MenuItem>', "\n\n";
		# in XML the & must normally be encoded as &amp; but not for
		# the stupid Snom!
	}
	
	echo '<MenuItem>',"\n";
	echo '<Name>',snomXmlEsc('Timeout keine Antw. '),'</Name>',"\n";
	echo '<URL>';
	echo  $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&t=timeout','&queue=',$queue_ext;
	echo '</URL>',"\n";  
	echo '</MenuItem>',"\n";
	defineBackKey();	
	echo '</SnomIPPhoneMenu>', "\n";
	_ob_send();	
}

#################################### OPTIONS SCREEN}

#################################### INITIAL SCREEN {
if (! $type && $queue == null) {
	
	$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['m'] )));
	$user = trim( @$_REQUEST['u'] );
	$user_id = getUserID( $user );
	
	
	ob_start();
	echo '<?','xml version="1.0" encoding="utf-8"?','>', "\n",
	     '<SnomIPPhoneMenu>', "\n",
	       '<Title>Rufumleitung Queues</Title>', "\n\n";
	                                                                   
	
	foreach ($queues as $qkey => $qname) {
		
		echo '<MenuItem>', "\n",
		       '<Name>', snomXmlEsc($qname['name']), '</Name>', "\n",
		       '<URL>', $url_snom_queueforward, '?m=',$mac, '&u=',$user, '&queue=',$qname['name'],'</URL>', "\n",
		     '</MenuItem>', "\n\n";
		# in XML the & must normally be encoded as &amp; but not for
		# the stupid Snom!
	}
	defineBackMenu();
	        
	echo '</SnomIPPhoneMenu>', "\n";
	_ob_send();	
}

#################################### INITIAL SCREEN}

?>