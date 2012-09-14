<?php
// $Id: updates.php 128 2010-12-01 16:12:04Z s242720-studenti $

  /*
Mainly, functions in this file act as interfaces to the database.
I tried to move out from this file all kind of operations that
do not require database reading or writing

Concurrency and file access are demanded to functions file_get and
file_put which are defined into file_access.php (together with
functions file_create, file_delete, whiteboard_create,
whiteboard_delete, check_permissions)
  */

// The update keys are defined outside of each update, to save disk
// space. This variable is used just into this file. Remember that php
// arrays are ordered.
$u_keys = array('page'      =>0,
                'objid'     =>1,
                'action'    =>2,
                'parameters'=>3,
                'update_id' =>4,
                'time'      =>5,
                'madeby'    =>6);
/*
 * Generate a timestamped salt signed with the server password
 */
function update_salt()
{
    global $passw_file;
    /*
     * The salt generation is made when loading any login page, so this
     * function comes before any other that could try to access to the
     * password file. In the majority of the cases, the file will exist
     * and just one read access to the file will be done.
     */
    if(!file_exists($passw_file)){
        $data = array('server_pass'=>rand(), 'user_pass'=>array());
        file_create($passw_file, $data);
    }
    $data = file_get($passw_file, 'r');
    $server_pass = $data['server_pass'];
    $rand = rand();
    $time = time();
    return $rand.':'.$time.':'.sign_salt($rand, $time, $server_pass);
}

/*
 * Check whether an user is already registered (his password exists) or not
 */
function checkuser($user)
{
    global $passw_file;
    // lookup the username in the database
    $d = file_get($passw_file, 'r');
    if (isset($d['user_pass'][$user]))
        return 'registered';
    else
        return 'not registered';
}

/*
 * Used by a web client to register a new user
 */
function register($user, $pass)
{
    global $passw_file;
    if (!check_permissions($user))
        return 'The administrator did not allow the registration of this user';

    list($h, $d) = file_get($passw_file, 'rw');
    // If already existing
    if (isset($d['user_pass'][$user])) {
        file_put($h);
        $result = 'Chosen username was already registered. Contact the administrator if you want to change the password';
    } else {
	// Otherwise do the registration
        $d['user_pass'][$user] = $pass;
        file_put($h, $d);
        $result = 'User name registered. Remember you password!';
    }
    return $result;
}

/*
 * Check if the credentials of the client are valid and match the
 * content of the database, return an empty string on success, an
 * error message otherwise
 */
function verify_credentials($signature)
{
    global $passw_file;
    // The time interval, to accept a server timestamp as valid (10
    // minutes in seconds, should be longer than cycle_timeout into
    // g['signer'] object into common.js)
    $server_timestamp_validity = 600;
    list($user, $rand, $time, $s_sig, $u_sig) = explode(':', $signature);
    if ((time() - $time) > $server_timestamp_validity)
        return 'The last message from the server is too old, please login again.';
    $data = file_get($passw_file, 'r');
    if ($s_sig != sign_salt($rand, $time, $data['server_pass']))
        return 'Wrong server signature';
    if ($u_sig != md5($rand.':'.$time.':'.$s_sig.':'.$data['user_pass'][$user]))
        return 'Wrong password';
    return '';
}

/*
 * Attempt to create a new whiteboard
 */
function create_whiteboard($wb, $user)
{
    if (!check_permissions($user, $wb, 'c'))
        return 'You are not allowed to create the whiteboard';
    // This is an attempt to climb the filesystem tree. The client
    // side javascript doesn't allows whiteboard names with a point
    if (strstr($wb, '.'))
        return '';
    // Initialize whiteboard data
    $data = array('next_usr_id'  => 1,
		  'next_upd_id'  => 1,
		  'delete_count' => 0,
		  'updates'      => array(),
		  'uids'         => array());
    if (whiteboard_create($wb, $data))
	return 'New whiteboard created';
    else
        return 'The whiteboard is already present';
    //This function simply returns a string. The reloading of the
    //login page will also show to the user the effect of his action
}

/*
 * Delete a whiteboard and all its related files. Returns an array
 * with a boolean for the succes of the operation, and a message for
 * the user. On success, the user will be forced to logout
 */
function delete_whiteboard($c)
{
    global $local_img_dir;
    // This is an attempt to climb the filesystem tree.
    if (strstr($c['wb'], '.'))
        return array(false, '');
    if (!check_permissions($c['user'], $c['wb'], 'd'))
        return array(false, 'You are not allowed to delete this whiteboard');

    if (whiteboard_delete($c['wb']))
        return array(true, 'Whiteboard deleted');
    else
        return array(true, 'The whiteboard was already deleted');
}

/*
 * Do the login for the given user on the given whiteboard,
 * initializing user data on this whiteboard if this is the first
 * login. When succeeds, this function returns a valid client_id for
 * future use, when it fails it returns the error message
 */
function login($wb, $user)
{
    global $layout, $wb_dir;
    if (!check_permissions($user, $wb, 'a'))
        return array(false, 'You don\'t have the permissions to access the whiteboard '.$wb);

    // Retrieve whiteboard data
    $wb_file = $wb_dir.$wb;
    list($h, $d) = file_get($wb_file, 'rw');
    if ($d === false)
        return array(false, 'Sorry, the choosen whiteboard has been deleted');
    $user_id = '';
    foreach ($d['uids'] as $i => $x) {
        if ($x['username'] == $user) {
            $user_id = $i;
            break;
        }
    }
    // If this is the first login for this whiteboard, initialize
    // whiteboard data concerning this user
    if ($user_id == '') {
        $user_id = $d['next_usr_id']++;
        $client_id = make_client_id($user_id, $user, $wb);
        $d['uids'][$user_id] =
            array('username' => $user,
                  // The first used session_id will actually be 1 (it
                  // is incremented by get_user_vars before being
                  // sent)
                  'session_id' => 0,
                  'width' => $layout['width'],
                  'height' => $layout['height'],
                  'side_w' => $layout['side_w'],
                  'slides' => '',
                  'client_id'=>$client_id,
                  'send_id'=> 0);
    } else {
        $client_id = $d['uids'][$user_id]['client_id'];
    }

    // Save changed whiteboard data
    file_put($h, $d);
    // The returned client_id must have all fields required on the
    // server side (see the parse_client_id call into main.php), to be
    // used by get_user_vars to output the application page
    // (app_page_send, bottom of main.php)
    $client_id_array = parse_client_id($client_id);
    $client_id_array['wb_file'] = $wb_file;
    return array(true, $client_id_array);
}

/*
 * Get from database the new records in order to keep the client
 * updated. The 'id' parameter is the id of the next update line that
 * the client has requested (the client has already received the
 * former lines).
 * This routine may not return immediately; it polls
 * the file every $update_wait microseconds for at most
 * $server_update_retry times until an update is found or
 * the timeout expires.
 */
function read($c, $id)
{
    global $server_update_timeout,$server_update_retry;
    global $u_keys;
    // Convert from milliseconds to microseconds for server-side
    // timeout (usleep takes microseconds)
    $server_update_timeout = $server_update_timeout * 1000;
    // The time interval between each check 
    $update_wait = (int)$server_update_timeout/$server_update_retry;
    register_waiter($c, true);
    // wait until we find new ids, or until maximum retry number
    for ($i = 0; $i < $server_update_retry; $i++) {
        // Read the database and retrieve the latest id
        $d = file_get($c['wb_file'], 'r');
        if ($d === false) {
            register_waiter($c, false);
            // File (whiteboard) deleted. This error will be ignored
            // client-side (function channel.received) because the
            // user is already logging out from the deleted whiteboard
            return array('error'=>'whiteboard deleted');
        } else {
            if ($d['next_upd_id'] > $id)
                break;
            else
                usleep($update_wait);
        }
    }
    $lines = array();
    $min = $id;
    $max = $d['next_upd_id'];
    $updates = $d['updates'];
    // Associate the keys to each update field, they will be used
    // for the names of the XML tags enclosing update data
    $keys = array_keys($u_keys);
    for ($i = $min; $i < $max; $i++) {
        // Some update id could be missing due to deleted objects and
        // cleanups
        if (isset($updates[$i]))
            $lines[] = array_combine($keys, $updates[$i]);
    }
    register_waiter($c, false);
    // Return to the client the new updates, and the id of the next
    // update it should ask for
    return array_merge(array('next_upd_id'=>$d['next_upd_id']), $lines);
}

/*
 * Insert in the database the updates sent by the client.
 */
function write($c, $send_id, $data)
{
    global $u_keys;
    // Read database
    list($h, $d) = file_get($c['wb_file'], 'rw');
    if ($d == false) {
        // This error is currently ignored on the client side
        return array('error'=>'whiteboard deleted');
    }
    $user_data = $d['uids'][$c['user_id']];
    if ($user_data['send_id'] < $send_id){
        // This is a duplicated 'write' request
        file_put($h, $d);
        return array('error'=>'duplicated');
    }
    // If the request is not duplicated, increment the counter. The
    // client will in turn increment its copy
    $user_data['send_id']++;
    $updates = $d['updates'];
    $upd_id = $d['next_upd_id'];
    $error = '';
    // Split the array of requests. Data are in the format:
    // update1;update2;update3; ...
    $array_lines = explode(';', $data);
    // Process each data
    foreach ($array_lines as $line) {
        // The order of the packed parameters is determined
        // client-side into the function sender_add, file
        // application.js
        list($page, $objid, $action, $parameters) = explode(':', $line);

        // Actions that do not involve other users (they won't be stored
        // into the database)
        if ($action=='slides') {
            $user_data['slides'] = urldecode($parameters);
	    continue;
        }

	// Actions to store
	switch ($action) {
	case 'clear':
	    // Filter all updates removing all objects on a given
	    // page
	    $updates = clear($page, $updates, $c['wb']);
	    break;

	case 'image':
	    // Copy the image into the server, filling update
	    // parameters. The function 'acquire_image' is called
	    // only here but is defined externally for
	    // readability. The user vars are needed because they
	    // contain the current svg sizes
	    $vars = build_user_vars($c['user_id'], $user_data);
	    $parameters = acquire_image($parameters, $objid, $c, $vars);
	    break;

	case 'delete':
	    // When too much deletes are done, perform a database cleanup
	    $d['delete_count']++;
	    if ($d['delete_count']>10){
		$updates = cleanup($updates, $c['wb']);
		$d['delete_count'] = 0;
	    }
	    break;

	case 'cleanup':
	    // 'cleanup' is an action available to the clients, to
	    // directly ask a database cleanup from the client
	    // side. Currently, it isn't associated to any user
	    // command
	    $updates = cleanup($updates, $c['wb']);
	    break;

	case 'chat':
	    // Encode some HTML entities to avoid a Cross Site
	    // Scripting attack using the chat (for this reason
	    // this conversion is made server-side). The chat
	    // action has just one parameter. This entities are
	    // allowed inside the XML messages sent to the client
	    // with ajax, but not all entities are (see
	    // http://www.w3.org/TR/xml/#sec-references section
	    // "Well-Formed Parsed Entities").
	    $parameters = str_replace('<', '&lt;', $parameters);
	    $parameters = str_replace('>', '&gt;', $parameters);
	    break;
	}

	// This occurs when acquire_image can't copy the remote
	// image on the server. The user could have written a
	// wrong address. Jump the database store step (the update
	// will be lost) and send data to the client to remove
	// the temporary image
	if ($parameters === false) {
	    $error = 'image-'.$objid.'-'.$page;
	    continue;
	}
	// Store an entry in the db
	$upd_id = $d['next_upd_id']++;
	// Store the update line, using $u_keys to provide the
	// numerical indexes.
	$new_update = array($u_keys['update_id'] =>$upd_id,
			    $u_keys['time']      =>time(),
			    $u_keys['madeby']    =>$c['user'],
			    $u_keys['page']      =>$page,
			    $u_keys['objid']     =>$objid,
			    $u_keys['action']    =>$action,
			    $u_keys['parameters']=>$parameters);
	ksort($new_update);
	$updates[$upd_id] = $new_update;
    }
    $d['updates'] = $updates;
    file_put($h, $d);
    // The result of the operation for the client
    if ($error=='') {
        // unused on the client side, it's just to fill the message
        return array('upd_id'=>$upd_id);
    } else {
        // Just the last of the errors on updates will be sent, now an
        // error can occurr just on image updates which get sent alone
        return array('error'=>$error);
    }
}

/*
 * Retrieve the variables of a whiteboard concerning a certain
 * user. This function is called every time that a new application
 * page is sent, and usually this requires a new session id for the
 * unicity of the objects built client side (parameter
 * $new_session_id).
 */
function get_user_vars($c, $new_session_id=true)
{
    $user_id = $c['user_id'];
    if ($new_session_id) {
        list($h, $d) = file_get($c['wb_file'], 'rw');
        if ($d === false) {
            // Whiteboard deleted. Upstream code must handle this
            // failure value
            return false;
	}
        $d['uids'][$user_id]['session_id']++;
        file_put($h, $d);
    } else {
        $d = file_get($c['wb_file'], 'r');
        if ($d === false)
            return false;
    }
    return build_user_vars($user_id, $d['uids'][$user_id]);
}

function resize($c, $key, $value)
{
    $u = $c['user_id']; // shorthand
    list($h, $d) = file_get($c['wb_file'], 'rw');
    if ($d === false)
        return 'Current whiteboard has been deleted';
    if ($key == 'sizes') {
        list($width, $height) = explode('-', $value);
        $d['uids'][$u]['width'] = $width;
        $d['uids'][$u]['height'] = $height;
    } else {
        // Else, the key is 'side_w', a single-value variable
	// which can be set directly
        $d['uids'][$u][$key] = $value;
    }
    file_put($h, $d);
    return 'The whiteboard has been resized';
}

/*
 * Import a pdf file (or an image) as background of whiteboard
 * pages. An image will take one page, a pdf file will take more
 * pages. Returned messages will be shown to the user
 */
function import($c, $filename, $size)
{
    global $local_img_dir, $public_img_dir, $layout;
    global $u_keys;
    // Check if the user really sent the file
    if ($size==0) {
        server_log('User '.$c['user'].' tried to import an empty file');
        return;
    }
    // Read the file
    try {
        $image = new Imagick($filename);
    } catch (Exception $e) {
        server_log('User '.$c['user'].' tried to import a non-image nor pdf file (Imagick failed opening the file)');
        return;
    }
    // Convert image type. The choosen format is jpg because it is
    // better handled by fpdf (for pdf export), while pngs with 16-bit
    // depth are not supported by fpdf
    $image->setImageFormat('jpg');

    $public_dir = $public_img_dir.$c['wb'].'/';
    $local_dir = $local_img_dir.$c['wb'].'/';
    $counter=0;
    foreach ($image as $page) {
        // Build a unique object id. This is the only place where the
        // server creates an object.
        $objid = $c['user_id'].'_imported_'.$counter;
        // Image public and local names
        $imagename = $objid.'.jpg';
        $public_name = $public_dir.$imagename;
        $local_name = $local_dir.$imagename;

        // Add two lines on the database: one to clear the page and
        // one with the new image element, on the right page
        $clear_update = array($u_keys['time']      =>time(),
                              $u_keys['madeby']    =>$c['user'],
                              $u_keys['page']      =>$counter,
                              $u_keys['objid']     =>'clear',
                              $u_keys['action']    =>'clear',
                              $u_keys['parameters']=>'clear');
        ksort($clear_update);
        // Parameters are: x and y coordinates on the top left corner
        // (zero value), width and height as the full svg canvas
        // (value 100)
        $parameters = '0|0|0|0|'.$public_name.'|100|100';
        // Store the update line, using $u_keys to provide the
        // numerical indexes.
        $image_update = array($u_keys['time']      =>time(),
                              $u_keys['madeby']    =>$c['user'],
                              $u_keys['page']      =>$counter,
                              $u_keys['objid']     =>$objid,
                              $u_keys['action']    =>'image',
                              $u_keys['parameters']=>$parameters);
        ksort($image_update);

        // Open the database for reading
        list($h, $d) = file_get($c['wb_file'], 'rw');
        if ($d === false) //This whiteboard has been deleted
            return;
        // Clear the current page (this deletes existent images also on
        // the filesystem) before creating the background image
        $d['updates'] = clear($counter, $d['updates'], $c['wb']);
        // Add the 'clear' update for clients
        $upd_id = $d['next_upd_id']++;
        $clear_update['upd_id'] = $upd_id;
        $d['updates'][$upd_id] = $clear_update;
        // Add the 'image' update
        $upd_id = $d['next_upd_id']++;
        $image_update['upd_id'] = $upd_id;
        $d['updates'][$upd_id] = $image_update;
        // This operation could be computationally expensive
        $ret = $image->writeImage($local_name);
        // Release the lock on the database only after the image has
        // been written. Otherwise, in an extremely unlucky case, the
        // whiteboard image folder could be missing because of a
        // whiteboard deletion
        file_put($h, $d);
        $counter++;
    }
}

/*
This function scans the update database doing the following actions:

    - apply to each object the translations and the deletions

    - pre-parse complex attributes (as 'd' in path and 'points' in
      polygon)

    - scale all parameters translating from global units to final
      image units (pixels)

The returned array is an array of pages, each one containing an array
of objects, each one containing its properties.
 
This function, as those in draw_image.php, is one of the most tightly
bound to the order of each action's parameters
*/
function export_whiteboard($c, $exp_mode, $exp_from, $exp_to, $user_vars)
{
    global $u_keys;
    // The type of updates that will be considered (also 'delete' will
    // be considered, even if it is missing here)
    $elements = array('path', 'line', 'multipath', 'rect', 'circle', 'polygon',
                      'polyline', 'text', 'link', 'image', 'move');
    // Update coordinates must be scaled into the x and y direction
    // depending on the action and on the parameter
    $directions = array(
	    'line'   =>array(4=>'x', 5=>'y', 6=>'x', 7=>'y'),
	    'circle' =>array(4=>'x', 5=>'y', 6=>'x'),
	    'rect'   =>array(4=>'x', 5=>'y', 6=>'x', 7=>'y'),
	    'text'   =>array(2=>'x', 3=>'y'),
	    'link'   =>array(2=>'x', 3=>'y'),
	    'image'  =>array(2=>'x', 3=>'y', 5=>'x', 6=>'y'));
    // Scaling factors for the horizontal and vertical directions
    $scale = array('x' => $user_vars['svg_w']/100,
                   'y' => $user_vars['svg_h']/100);
    $d = file_get($c['wb_file'], 'r');
    if ($d === false) {
        // The whiteboard has been deleted
        return array();
    }
    $lines = $d['updates'];
    $pages = array();
    // To generate new object ids for multipaths
    $fake_id_counter = 0;
    foreach ($lines as $line) {
        $objid  = $line[$u_keys['objid']];
        $action = $line[$u_keys['action']];
        $page_n = $line[$u_keys['page']];
        // Check if we should export this page (empty pages will be
        // skipped anyway because there are no updates related to
        // them)
        if ($exp_mode=='current'&&$page_n!=$exp_from)
            $page_condition = false;
        else if($exp_mode=='interval' && ($page_n<$exp_from||$page_n>$exp_to))
            $page_condition = false;
        // This is to skip chat create actions
        else if ($page_n==-1)
            $page_condition = false;
        else
            $page_condition = true;
        if (!$page_condition)
	    continue;

	// If the action type is not one of the visible elements,
	// don't do nothing but check if it is a delete action
	if (array_search($action, $elements)===false) {
	    if ($action=='delete')
		unset($pages[$page_n][$objid]);
	    continue;
	}
	// Create a new page slot if it doesn't exists
	if(!isset($pages[$page_n]))
	    $pages[$page_n] = array();
	// Parse parameters
	$par = explode('|', $line[$u_keys['parameters']]);
	// If this is a move action, add translation information
	// to an existing object
	if ($action=='move') {
	    // The combination of user actions can lead to a
	    // move update sent after a delete update on the
	    // same object, so check if the object is still
	    // present
	    if (isset($pages[$page_n][$objid])){
		// Even if several move actions could exist about the
		// same object, the last will overwrite the others
		$pages[$page_n][$objid]['move_x'] =
		    $par[0] * $scale['x'];
		$pages[$page_n][$objid]['move_y'] =
		    $par[1] * $scale['y'];
	    }
	} else {
	    // Else, this is a create action, so add the
	    // new object to the array
	    if ($action == 'path') {
		// When there is a multipath, several
		// following 'path' updates can have the same
		// object id. Store the previous path with a
		// fake id (move actions will be correctly
		// retrieved by the drawing functions).
		if(isset($pages[$page_n][$objid])){
		    $fake_id = $objid.'_multipath_'.$fake_id_counter;
		    $fake_id_counter++;
		    $pages[$page_n][$fake_id] = $pages[$page_n][$objid];
		}
		$pages[$page_n][$objid] = array('typ'=>$action,
						'par'=>$par);
		// Convert the 'd' attribute to an array of
		// points for the drawing functions
		$d = $par[4];
		$point_strings = explode(' L ', $d);
		$point_strings[0] = ltrim($point_strings[0], 'M');
		$points = array();
		foreach($point_strings as $string){
		    $coords = explode(',', $string);
		    $points[] = array($coords[0] * $scale['x'],
				      $coords[1] * $scale['y']);
		}
		$pages[$page_n][$objid]['points'] = $points;
	    } else if ($action == 'polygon' || $action == 'polyline') {
		// In a polygon/polyline, parse vertex points
		// and scale their coordinates
		$pairs = explode(' ', $par[4]);
		$pairs_array = array();
		foreach ($pairs as $i => $pair_str) {
		    $pair_arr = explode(',', $pair_str);
		    $pair_arr[0] = $pair_arr[0] * $scale['x'];
		    $pair_arr[1] = $pair_arr[1] * $scale['y'];
		    $pairs_array[] = $pair_arr;
		}
		$pages[$page_n][$objid] = array('typ'=>$action,
						'par'=>$par);
		$pages[$page_n][$objid]['points'] = $pairs_array;
	    } else {
		// Generic create action
		// Filter parameters that must be scaled
		if (isset($directions[$action])){
		    foreach($par as $i=>$p){
			if (isset($directions[$action][$i]))
			    $par[$i] = $p * $scale[$directions[$action][$i]];
		    }
		}
		// Add object
		$pages[$page_n][$objid] = array('typ'=>$action,
						'par'=>$par);
	    }
	}
	// If this page is empty (an object first created and
	// later deleted) remove it to avoid errors of the
	// drawing functions
	if (count($pages[$page_n]) < 1)
	    unset($pages[$page_n]);
    }
    return $pages;
}

function export_chat($c)
{
    global $u_keys;
    $d = file_get($c['wb_file'], 'r');
    if ($d === false)
        return 'Sorry, this whiteboard has been actually deleted';
    $result = '
Chat export of whiteboard '.$c['wb'].'
________________________________________________________________

';
    $updates = $d['updates'];
    foreach ($updates as $update){
        if ($update[$u_keys['action']] == 'chat'){
            $madeby = $update[$u_keys['madeby']];
            if ($madeby == $c['user']){
                $madeby = strtoupper($madeby);
            }
            // Decode the text (chat actions have just one parameter)
            $text = urldecode($update[$u_keys['parameters']]);
            // Convert HTML entities to plain text
            $text = html_entity_decode($text);
            $result .= '
   '.$madeby.'          '.date('h:i:s A - j M Y', $update[$u_keys['time']]).'
   ';
            for ($i=0; $i<strlen($madeby); $i++)
                $result .= '-';
            $result .= '

   '.$text.'

................................................................
';
        }
    }
    return $result;
}

/*
 * These two functions are near to avoid mistakes, even if
 * make_client_id is used just within this file. Don't use underscores
 * as separator because they can be present into the whiteboard
 * name. The client_id is never parsed client-side.
 */
function make_client_id($user_id, $user, $wb)
{
    return $user_id.'|'.$user.'|'.$wb;
}

function parse_client_id($client_id, $key='')
{
    $a = explode('|', $client_id);
    $parsed = array('user_id'=>$a[0], 'user'=>$a[1], 'wb'=>$a[2]);
    if ($key == '')
        return $parsed;
    else
        return $parsed[$key];
}

/*****************************************************************
 *
 *
 *  Following functions are called just within this file
 *
 *
 *****************************************************************/

/*
 * Delete all elements from the database for the current page.
 */
function clear($page, $updates, $wb)
{
    global $u_keys;
    foreach (array_keys($updates) as $i) {
        if ($updates[$i][$u_keys['page']] == $page) {
            if ($updates[$i][$u_keys['action']] == 'image')
                delete_image($updates[$i][$u_keys['parameters']], $wb);
            unset($updates[$i]);
        }
    }
    return $updates;
}

/*
 * Delete from the database all records that shouldn't be sent again
 * to the client: old records with action 'delete' or 'clear' and
 * deleted or cleared records
 */
function cleanup($updates, $wb)
{
    global $u_keys;

    // First pass: remove actions 'delete' and 'clear', and collect
    // informations on the updates to be deleted. the
    // delete_collection is indexed by object id, the clear_collection
    // is indexed by object page
    $delete_collection = array();
    $clear_collection  = array();
    foreach ($updates as $upd_id => $update){
        $action = $update[$u_keys['action']];
        if ($action == 'delete' || $action == 'clear'){
            switch($action){
            case 'delete':
                $delete_collection[$update[$u_keys['objid']]] = true;
                break;
            case 'clear':
                $page = $update[$u_keys['page']];
                if (isset($clear_collection[$page]))
                    $max_id = $clear_collection[$page];
                else
                    $max_id = -1;
                // This is a redundant check. updates are ordered
                // so this id must necessary be greater than the
                // previous one
                if ($upd_id > $max_id)
                    $clear_collection[$page] = $upd_id;
                break;    
            }
            unset($updates[$upd_id]);
        }
    }

    // Second pass: remove cleared or deleted objects
    foreach ($updates as $upd_id => $update){
        $page  = $update[$u_keys['page']];
        $objid = $update[$u_keys['objid']];
        $remove = false;
        if (isset($clear_collection[$page])){
            if ($clear_collection[$page] > $upd_id)
                $remove = true;
        }
        else if (isset($delete_collection[$objid])){
            // Check if we have to remove the image from the filesystem
            if($update[$u_keys['action']] == 'image')
                delete_image($update[$u_keys['parameters']], $wb);
            $remove = true;
        }
        if ($remove)
            unset($updates[$upd_id]);
    }
    return $updates;
}

/*
 * If this object is an image, delete it from the filesystem
 */
function delete_image($parameters, $wb)
{
    global $local_img_dir;
    $par_array = explode('|', $parameters);
    $url = urldecode($par_array[4]);
    $url_array = explode('/', $url);
    $file_name = $url_array[count($url_array)-1];
    // Here we build again the path of the file. The public image url
    // is different from its local path
    $file_name = $local_img_dir.'/'.$wb.'/'.$file_name;
    if(file_exists($file_name)){
        unlink($file_name);
    }
    else
        server_log('Required the deletion of file '.$file_name.', but the file doesn\'t exists.');
}

/*
 * Copy the remote image to the server, filling the update parameters
 * (change the remote url with a new url, fill in correct image sizes)
 */
function acquire_image($parameters, $objid, $c, $user_vars)
{
    global $local_img_dir, $public_img_dir;

    $par = explode('|', $parameters);
    $url = urldecode($par[4]);

    // Retrieve the scale ratio and clear the parameter slot (it
    // doesn't contains a valid color value, it could raise errors
    // into draw_image export functions which handle the colors)
    $ratio  = $par[0];
    $par[0] = '';

    // Retrieve the remote image. Exceptions are disabled, but the
    // return state is checked
    $read_handle = @fopen($url, 'rb');
    if (!$read_handle){
        server_log('acquire_image: '.$c['user'].' tried to add the image pointed '
                   .'by '.$url.' but the fopen function failed.');
        return false;
    }
    $contents = stream_get_contents($read_handle);
    if (!$contents)
        return false;

    // Write the new image file
    $ext = explode('.', $url);
    $ext = $ext[count($ext)-1];
    // The file name is used to build the write_name and the new url
    $file_name = $objid.'.'.$ext;
    $write_name = $local_img_dir.$c['wb'].'/'.$file_name;
    $write_handle = fopen($write_name, 'w+');
    fwrite($write_handle, $contents);
    // Check that the file is an image (mime_content_type is
    // deprecated, but the subtitute function, fileinfo, is not always
    // available with older php versions)
    if (strstr(mime_content_type($write_name), 'image')==false){
        server_log('acquire_image: '.$c['user'].' tried to include the '.$url.' '.
                   'file which is not an image, but has mime-type: '.
                   mime_content_type($write_name));
        unlink($write_name);
        return false;
    }
    $par[4] = urlencode($public_img_dir.$c['wb'].'/'.$file_name);

    // Set image sizes
    if (extension_loaded('imagick')) {
        // Retrieve the canvas sizes for the current user, to
        // correctly compute the image size in global units
        $image = new Imagick($write_name);
        $par[5] = (int)($ratio*$image->getImageWidth()/$user_vars['svg_w']);
        $par[6] = (int)($ratio*$image->getImageHeight()/$user_vars['svg_h']);
    } else {
        // If image magick is not present, the default sizes of the
        // image will be a quarter of the sizes of the whole
        // whiteboard (with a default ratio from the user. The default
        // ratio is 100, whiteboards sizes are 100/100 with global
        // measures)
        $par[5] = (int)($ratio/4);
        $par[6] = (int)($ratio/4);
    }
    $parameters = implode('|', $par);
    return $parameters;
}

function sign_salt($rand, $time, $pass)
{
    return md5($rand.':'.$time.':'.$pass);
}

/*
 * Build all user variables starting from user data. Some variables
 * are ready into the database, some are global variables, some others
 * must be "built"
 */
function build_user_vars($user_id, $user_data)
{
    global $layout;
    global $client_ajax_timeout, $client_update_timeout;
    // Global variables coming from the server side configuration file
    $vars = array('ajax_timeout'  =>$client_ajax_timeout,
                  'update_timeout'=>$client_update_timeout);

    // Variables which have the same name both into the database array
    // and into the returned array
    $common = array('client_id', 'side_w', 'slides', 'send_id',
                    'width', 'height');
    foreach($common as $key)
        $vars[$key] = $user_data[$key];
    
    // Variables with different names
    $vars['user'] = $user_data['username'];
    $vars['user_id'] = $user_id;

    // Variables to be built
    $vars['obj_prefix'] = $vars['user_id'].'_'.$user_data['session_id'];
    $width_difference = $layout['frame_narrow_width'] +
        $vars['side_w'] + $layout['margin'] + $layout['m_w'];
    $vars['svg_w'] = $vars['width']  - $width_difference;
    $vars['svg_h'] = $vars['height'] - $layout['frame_narrow_height'];

    return $vars;
}

/*
 * This function is a hook to register clients waiting for new lines
 * (into the 'read' function). This could be useful in the future to
 * monitor server load and protect the server.
 */
function register_waiter($c, $start)
{
}

?>
