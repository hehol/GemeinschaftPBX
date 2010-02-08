<?php
/*******************************************************************\
*            Gemeinschaft - asterisk cluster gemeinschaft
* 
* $Revision$
* 
* Copyright 2007, amooma GmbH, Bachstr. 126, 56566 Neuwied, Germany,
* http://www.amooma.de/
*
* Andreas Neugebauer <neugebauer@loca.net> - LocaNet oHG
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

defined('GS_VALID') or die('No direct access.');
include_once( GS_DIR .'inc/gs-lib.php' );
require_once( GS_DIR .'inc/get-listen-to-ids.php' );
include_once( GS_DIR .'inc/gs-fns/gs_hosts_get.php' );
include_once( GS_DIR .'inc/gs-fns/gs_host_by_id_or_ip.php' );

/***********************************************************
*    delete all voicemails of a user
***********************************************************/

function gs_vm_del_all( $user )
{
	if (! preg_match( '/^[a-z0-9\-_.]+$/', $user ))
		return new GsError( 'User must be alphanumeric.' );
	
	# connect to db
	#
	$db = gs_db_master_connect();
	if (! $db)
		return new GsError( 'Could not connect to database.' );
	
	# get user_id, nobody_index and softkey_profile_id
	#
	$rs = $db->execute( 'SELECT `id`  FROM `users` WHERE `user`=\''. $db->escape($user) .'\'' );
	if (! $rs)
		return new GsError( 'DB error.' );
	if (! ($r = $rs->fetchRow()))
		return new GsError( 'Unknown user.' );
	$user_id            = (int)$r['id'];
	
	# get host_id
	#
	$host_id = (int)$db->executeGetOne( 'SELECT `host_id` FROM `users` WHERE `id`='. $user_id );

	# get all hosts
	# 
	$islocal = false;
	
	
	$hosts = @ gs_hosts_get();
	if (isGsError( $hosts ))
		return new GsError( $hosts->getMsg() );
	if (! is_array( $hosts ))
		return new GsError( 'Failed to get hosts.' );
	
	$GS_INSTALLATION_TYPE_SINGLE = gs_get_conf('GS_INSTALLATION_TYPE_SINGLE');
	if (! $GS_INSTALLATION_TYPE_SINGLE) {
		# get our host IDs
		#
		$our_host_ids = @ gs_get_listen_to_ids();

		if (isGsError( $our_host_ids ))
			return new GsError( $our_host_ids->getMsg() );
		if (! is_array( $our_host_ids ))
			return new GsError( 'Failed to get our host IDs.' );
		
		if ( ! in_array( $host_id, $our_host_ids) ) {
		
			$hosts = @ gs_hosts_get();
			if (isGsError( $hosts ))
				return new GsError( $hosts->getMsg() );
			if (! is_array( $hosts ))
				return new GsError( 'Failed to get hosts.' );
			
			foreach ( $hosts as $hs ) {
				if ( $hs [ 'id' ] == $host_id ) {
					$host = $hs;
					break;
				}
			}
			if ( ! is_array ( $host ) )
				return new GsError( 'Failed to find hosts.' );		
		}
		else {
			$islocal = true;
		}
			
	}
	else {
		$islocal = true;
	}
	
	# get user's sip name
	#
	$ext = $db->executeGetOne( 'SELECT `name` FROM `ast_sipfriends` WHERE `_user_id`='. $user_id );
	

	
		if ( $islocal ) {
			$vmdir = '/var/spool/asterisk/voicemail/default/' . $ext;

			recursiveDelete( $vmdir );

			@exec( '/opt/gemeinschaft/dialplan-scripts/vm-postexec ' . qsa('default') .' '. qsa($ext) .' '. qsa('999') .' 1>>/dev/null 2>>/dev/null', $out, $err );
		}
		else {
			$cmd = '/opt/gemeinschaft/sbin/vm-del-all '. $ext ;
			
			$cmd = $sudo .'ssh -o StrictHostKeyChecking=no -o BatchMode=yes -l root '. qsa($host['host']) .' '. qsa($cmd);
			
			@ exec( $sudo . $cmd .' 1>>/dev/null 2>>/dev/null', $out, $err );
			$ok = $ok && ($err==0);
			
			if (! $ok)
				return new GsError( 'Failed to delete voicemails from foreign host.' );
		}

	return true;
}

function recursiveDelete($str){
	if(is_file($str)){
		return @unlink($str);
	}
	elseif(is_dir($str)){
		$scan = glob(rtrim($str,'/').'/*');
		foreach($scan as $index=>$path){
			recursiveDelete($path);
		}
		return;
	}
}


?>