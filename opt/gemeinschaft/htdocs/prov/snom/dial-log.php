<?php
/*******************************************************************\
*            Gemeinschaft - asterisk cluster gemeinschaft
* 
* $Revision$
* 
* Copyright 2007, amooma GmbH, Bachstr. 126, 56566 Neuwied, Germany,
* http://www.amooma.de/
* Stefan Wintermeyer <stefan.wintermeyer@amooma.de>
* Philipp Kempgen <philipp.kempgen@amooma.de>
* Peter Kozak <peter.kozak@amooma.de>
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
require_once( dirName(__FILE__) .'/../../../inc/conf.php' );
include_once( GS_DIR .'inc/db_connect.php' );
include_once( GS_DIR .'inc/gettext.php' );
require_once( GS_DIR .'inc/gs-fns/gs_user_watchedmissed.php' );
require_once( GS_DIR .'inc/gs-fns/gs_ami_events.php' );
include_once( GS_DIR .'inc/langhelper.php' );
require_once( GS_DIR .'inc/snom-fns.php' );

header( 'Content-Type: application/x-snom-xml; charset=utf-8' );
# the Content-Type header is ignored by the Snom
header( 'Expires: 0' );
header( 'Pragma: no-cache' );
header( 'Cache-Control: private, no-cache, must-revalidate' );
header( 'Vary: *' );

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

if (! gs_get_conf('GS_SNOM_PROV_ENABLED')) {
	gs_log( GS_LOG_DEBUG, "Snom provisioning not enabled" );
	snom_textscreen( __('Fehler'), __('Nicht aktiviert') );
}


$user = trim( @ $_REQUEST['user'] );
if (! preg_match('/^\d+$/', $user))
	snom_textscreen( __('Fehler'), __('Ungültiger Benutzer') );

$mac = preg_replace('/[^\dA-Z]/', '', strToUpper(trim( @$_REQUEST['mac'] )));

$type = trim( @ $_REQUEST['type'] );
if (! in_array( $type, array('in','out','missed','qin','qmissed'), true ))
	$type = false;

if ( isset($_REQUEST['delete']) )
	$delete = (int)$_REQUEST['delete'];

$db = gs_db_slave_connect();

# get user_id
#
$user_id = (int)$db->executeGetOne( 'SELECT `_user_id` FROM `ast_sipfriends` WHERE `name`=\''. $db->escape($user) .'\'' );
if ($user_id < 1)
	snom_textscreen( __('Fehler'), __('Unbekannter Benutzer') );

# user/ip/mac check
$user_id_check = $db->executeGetOne( 'SELECT `user_id` FROM `phones` WHERE `mac_addr`=\''. $db->escape($mac) .'\'' );
if ($user_id != $user_id_check) snom_textscreen( __('Fehler'), __('Keine Berechtigung') );

$remote_addr = @$_SERVER['REMOTE_ADDR'];
$remote_addr_check = $db->executeGetOne( 'SELECT `current_ip` FROM `users` WHERE `id`='. $user_id );
if ($remote_addr != $remote_addr_check) snom_textscreen( __('Fehler'), __('Keine Berechtigung') );

unset($remote_addr_check);
unset($remote_addr);
unset($user_id_check);

// setup i18n stuff
gs_setlang( gs_get_lang_user($db, $user, GS_LANG_FORMAT_GS) );
gs_loadtextdomain( 'gemeinschaft-gui' );
gs_settextdomain( 'gemeinschaft-gui' );

$typeToTitle = array(
	'out'    => __("Gew\xC3\xA4hlt"),
	'missed' => __("Verpasst"),
	'in'     => __("Angenommen"),
	'qmissed' => __("WS Verpasst"),
	'qin'     => __("WS Angenommen")
);


if ( $type == 'qin' || $type == 'qmissed' )
        $is_queue = true;
else
        $is_queue = false;

ob_start();


$url_snom_dl = GS_PROV_SCHEME .'://'. GS_PROV_HOST . (GS_PROV_PORT ? ':'.GS_PROV_PORT : '') . GS_PROV_PATH .'snom/dial-log.php';

if ( (isset($delete)) && $type) {

        $tp = $type;
        $queue_null = "IS NULL";
        if ( $type == "qin" ) {
                $tp = "in";
                $queue_null = "IS NOT NULL";
        }
        else if ( $type == "qmissed" ) {
                $tp = "missed";
                $queue_null = "IS NOT NULL";
        }
        
 

	$query =
'SELECT
	MAX(`timestamp`) `ts`, `number`, `remote_name`, `remote_user_id`, `queue_id`,
	COUNT(*) `num_calls`
FROM `dial_log`
WHERE
	`user_id`='. $user_id .' AND
	`type`=\''. $tp .'\' AND
	`queue_id` ' . $queue_null . '
GROUP BY `number`,`queue_id`
ORDER BY `ts` DESC
LIMIT ' . $delete . ',1';

	$rs = $db->execute( $query );
	$r = $rs->fetchRow();

$DB = gs_db_master_connect();
	
	$DB->execute(
'DELETE FROM `dial_log`
WHERE
	`user_id`=' . $user_id . ' AND
	`type`=\'' . $tp . '\' AND
	`number`=\'' . $r['number'] . '\' AND
	`queue_id`' . (($r['queue_id'] > 0) ? '='.$r['queue_id'] : ' IS NULL')
	);
}

#################################### INITIAL SCREEN {
if (! $type) {
	
	# delete outdated entries
	#
	$DB = gs_db_master_connect();
	
	$DB->execute( 'DELETE FROM `dial_log` WHERE `user_id`='. $user_id .' AND `timestamp`<'. (time()-(int)GS_PROV_DIAL_LOG_LIFE) );
	
	
	
	echo '<?','xml version="1.0" encoding="utf-8"?','>', "\n";
	echo
		'<SnomIPPhoneMenu>', "\n",
			'<Title>', __('Anruflisten') ,'</Title>', "\n";
	
	foreach ($typeToTitle as $t => $title) {
		
		$queue_null = "IS NOT NULL";
		if ( $t == 'qin' ) {
		        $tp = 'in';
                }
                else if ( $t == 'qmissed' ) {
                         $tp = 'missed';
                }
                else {
                        $tp = $t;
                        $queue_null = "IS NULL";
                }
                
		
		$num_calls = (int)$db->executeGetOne( 'SELECT COUNT(*) FROM `dial_log` WHERE `user_id`='. $user_id .' AND `type`=\''. $tp .'\' AND `queue_id` ' . $queue_null );
		//if ($num_calls > 0) {
			echo
				"\n",
				'<MenuItem>', "\n",
					'<Name>', snom_xml_esc( $title ) ,'</Name>', "\n",
					'<URL>', $url_snom_dl ,'?user=',$user, '&mac=',$mac, '&type=',$t, '</URL>', "\n",
				'</MenuItem>', "\n";
			# Snom does not understand &amp; !
		//}
	}
	
	echo
		"\n",
		'</SnomIPPhoneMenu>';
	
}
#################################### INITIAL SCREEN }



#################################### DIAL LOG {
else {

        $queue_null = "IS NOT NULL";
        if ( $type == 'qin' ) {
                $tp = 'in';
        }
        else if ( $type == 'qmissed' ) {
                $tp = 'missed';
        }
        else {
                $tp = $type;
                $queue_null = "IS NULL";
        }
	
	echo '<?','xml version="1.0" encoding="utf-8"?','>', "\n";
	
	$query =
'SELECT
	MAX(`timestamp`) `ts`, `number`, `remote_name`, `remote_user_id`, `queue_id`,
	COUNT(*) `num_calls`
FROM `dial_log`
WHERE
	`user_id`='. $user_id .' AND
	`type`=\''. $tp .'\' AND ' .
	 '`queue_id` ' . $queue_null . 
' GROUP BY `number`,`queue_id`
ORDER BY `ts` DESC
LIMIT 20';
	$rs = $db->execute( $query );
	
	echo
		'<SnomIPPhoneDirectory>', "\n",
			'<Title>', snom_xml_esc( $typeToTitle[$type] ) ,
			($rs->numRows() == 0 ? ' ('.snom_xml_esc(__('keine')).')' : '') ,
			'</Title>', "\n";
	
	while ($r = $rs->fetchRow()) {
		
		unset($num_calls);
		if ($r['num_calls'] > 0) {
			$num_calls = (int)$db->executeGetOne(
'SELECT
	COUNT(*)
FROM `dial_log`
WHERE
		`user_id`=' . $user_id . ' AND
		`number`=\'' . $r['number'] . '\' AND
		`type`=\'' . $tp . '\' AND
		`queue_id`' . (($r['queue_id'] > 0) ? '='.$r['queue_id'] : ' IS NULL') . ' AND
		`read` < 1 AND ' .
		 '`queue_id` ' . $queue_null
			);
		}
		
		$entry_name = '';
		/*
		if ($r['queue_id'] > 0)
			$entry_name = 'WS: ';
                */
		$entry_name .= $r['number'];
		if ($r['remote_name'] != '') {
			$entry_name .= ' '. $r['remote_name'];
		}
		if (date('dm') == date('dm', (int)$r['ts']))
			$when = date('H:i', (int)$r['ts']);
		else
			$when = date('d.m.', (int)$r['ts']);
		if ( strlen($entry_name) < 1 )
			$entry_name = __('anonym');
		$entry_name = $when .'  '. $entry_name;
		if ($num_calls > 1) {
			$entry_name .= ' ('. $num_calls .')';
		}
		echo
			"\n",
			'<DirectoryEntry>', "\n",
				'<Name>', snom_xml_esc( $entry_name ) ,'</Name>', "\n",
				'<Telephone>', snom_xml_esc( $r['number'] ) ,'</Telephone>', "\n",
			'</DirectoryEntry>', "\n";
		
	}

	echo '<SoftKeyItem>',
		'<Name>F2</Name>',
		'<Label>' ,snom_xml_esc(__('Löschen')),'</Label>',
		'<URL>', $url_snom_dl, '?user=', $user, '&mac=',$mac, '&type=', $type, '&delete={index}</URL>',
		'</SoftKeyItem>', "\n";

	echo
		"\n",
		'</SnomIPPhoneDirectory>';
	if ( $tp == 'missed') {
	 	gs_user_watchedmissed( $user_id, $is_queue );
	}
	if ( GS_BUTTONDAEMON_USE == true ) {
		gs_user_missedcalls_ui( $user, $is_queue);
	}
	
}
#################################### DIAL LOG }


_ob_send();

?>
