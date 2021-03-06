<?php
/*******************************************************************\
*            Gemeinschaft - asterisk cluster gemeinschaft
* 
* $Revision$
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

defined('GS_VALID') or die('No direct access.');
include_once( GS_DIR .'inc/gs-lib.php' );
include_once( GS_DIR .'inc/gs-fns/gs_ami_events.php' );


/***********************************************************
*    set a callerid for a user
***********************************************************/

function gs_user_callerid_set( $user, $number = "", $dest )
{
	if (! preg_match( '/^[a-z0-9\-_.]+$/', $user ))
		return new GsError( 'User must be alphanumeric.' );
	if (! preg_match( '/^[\d]+$/', $number ) && $number != "")
		return new GsError( 'Number must be numeric.' );
	if ($dest != 'internal' && $dest != 'external')
		return new GsError( 'No destination.' );
	
	# connect to db
	#
	$db = gs_db_master_connect();
	if (! $db)
		return new GsError( 'Could not connect to database.' );
	
	# get user_id
	#
	$user_id = $db->executeGetOne( 'SELECT `id` FROM `users` WHERE `user`=\''. $db->escape($user) .'\'' );
	if ($user_id < 1)
		return new GsError( 'Unknown user.' );
	
	# add number
	#
	$ok = $db->execute( 'UPDATE `users_callerids` SET `selected` = 0 WHERE `user_id`='. $user_id . ' AND `dest`=\'' . $db->escape($dest) . '\'' );
	if (! $ok)
		return new GsError( 'Failed to set callerid unselected.' );
	
	if ($number != "") {
	
		$count = $db->executeGetOne( 'SELECT COUNT(*) FROM `users_callerids` WHERE `user_id`=' . $user_id .' AND `dest`=\'' . $db->escape($dest) . '\' AND  `number` =\'' .  $db->escape($number) . '\'');
		
		 if($count != 1)
		 	return new GsError( 'Outbound number ' . $number . ' not allowed.' );		
		
		$ok = $db->execute( 'UPDATE `users_callerids` SET `selected` = 1 WHERE `user_id`=' . $user_id .' AND `dest`=\'' . $db->escape($dest) . '\' AND  `number` =\'' .  $db->escape($number) . '\'');
		if (! $ok)
			return new GsError( 'Failed to set callerid.' );	
	}	
	
        if ( GS_BUTTONDAEMON_USE == true ) {
        	$user_name = $db->executeGetOne( 'SELECT `name` FROM `ast_sipfriends` WHERE `_user_id`=\''. $db->escape($user_id) .'\'' );
        	if (! $user_name)
        		return new GsError( 'Unknown user.' );
		gs_clip_changed_ui($user_name);
	}

	return true;
}


?>