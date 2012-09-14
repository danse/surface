<?php
// $Id: main.php 131 2010-12-09 18:13:10Z s242720-studenti $

  /*

 Main entry point to the server requests. The parameter which tells
 the kind of request is 'mode'.
 
 Where to find the called functions:

updates.php
 Contains functions which handles the update database: checkuser,
 login, register, verify_credentials, write, read, export, import,
 get_user_vars, resize, update_salt, parse_client_id

markup_send.php
 Includes functions app_page_send, login_page_send and xml_send

draw_image.php
 Includes the function draw_image

file_access.php
 function check_files

  */

function main()
{
    global $wb_dir;
    $R = $_REQUEST;

    // Check file status on the server
    if (check_files() == false)
        exit('Failed to initialize application files on the server '.
             '(maybe the webserver user does not have write permissions '.
             'on the data directories, or the $abs_root configuration setting '.
             'is wrong)');

    // For each known mode, tell if it requires authentication and
    // what are its required fields
    $modes =
        array('checkuser'  =>array('restricted'=>false,
                                   'fields'=>array('user')),
              'register'   =>array('restricted'=>false,
                                   'fields'=>array('user', 'pass')),
              'update_salt'=>array('restricted'=>false,
                                   'fields'=>array()),

              // All restricted modes have an additional 'signature'
              // field which is not listed here
              'createsess' =>array('restricted'=>true,
                                   'fields'=>array('wb_id', 'user')),
              'login'      =>array('restricted'=>true,
                                   'fields'=>array('wb_id', 'user')),
              'read'       =>array('restricted'=>true,
                                   'fields'=>array('client_id', 'upd_id')),
              'write'      =>array('restricted'=>true,
                                   'fields'=>array('client_id', 'send_id', 'data')),
              'resize'     =>array('restricted'=>true,
                                   'fields'=>array('client_id', 'key', 'value')),
              'import'     =>array('restricted'=>true,
                                   'fields'=>array('client_id')),
              'export'     =>array('restricted'=>true,
                                   'fields'=>array('client_id', 'exp_mode', 'exp_from', 'exp_to', 'export_extension')),
              'delete'     =>array('restricted'=>true,
                                   'fields'=>array('client_id')),
              'logout'     =>array('restricted'=>true,
                                   'fields'=>array('client_id', 'message'))
              );
    // Check that the request is well-formed. A malformed request
    // generates an empty $mode
    if (!isset($R['mode']) || !isset($modes[$R['mode']])) {
        $mode = '';
    } else {
        $mode = $R['mode'];
        $fields = $modes[$mode]['fields'];
        if ($modes[$mode]['restricted'])
            $fields[] = 'signature';
        foreach ($fields as $field) {
            if (!isset($R[$field])) {
                $mode = '';
            }
        }
    }

    // Centralized output variables: the type and the contents of the
    // output will be changed depending on switch branches, but if the
    // type remains empty, it means that the specific brach of the
    // switch does his output by himself (for particular cases like
    // the 'export' case)
    $o = array('type'=>'', 'content'=>'');

    if ($mode == '') {
        // Unknown request, return the login page
        $o['type'] = 'login';
    } else if (!$modes[$mode]['restricted']) { /* unrestricted mode */
	switch($R['mode']){
	case 'checkuser':
	    // Process the request made by onkeyup event
	    // on the input name field.
	    $o['type'] = 'xml';
	    $o['content'] = array('user' => checkuser($R['user']));
	    break;
	case 'register':
	    $o['type'] = 'login';
	    $o['content'] = register($R['user'], $R['pass']);
	    break;
	case 'update_salt':
	    $o['type'] = 'xml';
	    $o['content'] = array('salt'=>update_salt());
	    break;
	}
    } else {
	// Restricted modes (the client must provide a valid
	// 'signature' field for verify_credentials)
	// Verify credentials, returning an empty message on
	// success and the error message on failure
	$o['content'] = verify_credentials($R['signature']);
	if ($o['content'] != '') {
	    // on failure send a different response depending on
	    // the kind of request (ajax or direct)
	    if ($R['mode'] == 'read' || $R['mode'] == 'write') {
		$o['type'] = 'xml';
		$o['content'] = array('error'=>'credentials');
	    } else {
		$o['type'] = 'login';
	    }
	    //$o['content'] is already fine for this type
	} else {
	    // The requests come from the application page (all
	    // modes but 'createsess' and 'login') receive a
	    // client_id field with data about the client (user
	    // name, user id, whiteboard name)
	    if (isset($R['client_id'])) {
		// Extract client data from the client_id (from
		// string to array)
		$client_id = parse_client_id($R['client_id']);
		// Add a new element which is useful just server side
		$client_id['wb_file'] = $wb_dir.$client_id['wb'];
	    }
	    switch($R['mode']){
	    case 'createsess':
		$o['type']= 'login';
		$o['content'] = create_whiteboard($R['wb_id'], $R['user']);
		break;

	    case 'login':
		// Login is the only mode which has not a
		// client_id, but could need one. It creates it
		list($success, $result) = login($R['wb_id'], $R['user']);
		if ($success) {
		    $o['type'] = 'application';
		    $client_id = $result;
		} else{
		    $o['type'] = 'login';
		    $o['content'] = $result;
		}
		break;

	    case 'read':
		$o['type'] = 'xml';
		// Look for new update lines
		$o['content'] = read($client_id, $R['upd_id']);
		break;

	    case 'write':
		$o['type'] = 'xml';
		// Insert the updates received from the client in
		// the database
		$o['content'] = write($client_id, $R['send_id'], $R['data']);
		break;

	    case 'resize':
		$o['type'] = 'application';
		// Resize the user's whiteboard is like changing
		// an user variable
		$o['content'] = resize($client_id, $R['key'], $R['value']);
		break;

	    case 'import':
		// Import data and return a possible error message
		// to be shown to the user
		import($client_id, $_FILES['file']['tmp_name'],
			      $_FILES['file']['size']);
		break;

	    case 'export':
		// The result echoed by this mode will appear to
		// the user into a new page.
		if ($R['exp_mode'] == 'chat'){
		    header('Content-Type: text/plain');
		    echo export_chat($client_id);
		} else {
		    // Retrieve user variables which include the
		    // svg width and height
		    $v = get_user_vars($client_id, false);
		    if ($v === false){ // Very rare case
			echo 'Sorry, the whiteboard was deleted';
			return;
		    }
		    // Read the database and return pages ready to
		    // be drawn
		    $pages = export_whiteboard($client_id,
					       $R['exp_mode'],
					       $R['exp_from'],
					       $R['exp_to'],
					       $v);
		    if (count($pages)>0) {
			$ext = $R['export_extension'];
			// Actually draw the image file
			list($image, $header_string) =
			    draw_image($pages, $v, $ext);
			header($header_string);
			// Tell the browser to display the save
			// dialog (the extension is very important
			// for Windows users)
			header('Content-Disposition: attachment; filename="whiteboard.'.$ext.'"');
			echo $image;
		    } else {
			echo 'You asked to export the whiteboard content, '.
			    'but it is empty, so there is nothing to export '.
			    '(if you selected a page interval, check page numbers).';
		    }
		}
		break;

	    case 'delete':
		// Deletion of a session
		list($deleted, $msg) = delete_whiteboard($client_id);
		$o['type'] = ($deleted) ? 'login' : 'application';
		$o['content'] = $msg;
		break;

	    case 'logout':
		$o['type'] = 'login';
		$o['content'] = $R['message'];
		break;
	    }
	}
    }

    // update_salt and get_user_vars use the database and stay into
    // updates.php, while the _send functions just wraps the content
    // with markup and send, and they stay into markup_send.php
    switch ($o['type']) {
    case 'login':
        login_page_send(update_salt(), $o['content']);
        break;

    case 'xml':
        xml_send($o['content']);
        break;

    case 'application':
        $vars = get_user_vars($client_id);
        if ($vars === false)
            echo 'Sorry, the whiteboard was deleted'; // Very rare case
        else
            app_page_send($vars, $o['content']);
        break;

    default:
        // In the default case, the output has been already sent so
        // nothing to do here (export mode)
    }
}

// logging function which is used everywhere on the server side
function server_log($msg){
    global $activate_log, $log_file;
    if($activate_log)
        file_put_contents($log_file, date('l jS \of F Y h:i:s A').' '.$msg."\n", FILE_APPEND);
}

?>
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
<?php
// $Id: markup_send.php 132 2010-12-13 10:53:17Z s242720-studenti $

/*
 * Convert a php array to a xml file, following 2 levels of depth
 * (this means that a two-dimensional array gets correctly converted)
 */
function xml_send($object)
{
    // Prevent browsers from caching
    header('Expires: Fri, 25 Dec 1980 00:00:00 GMT'); // Time in the past
    header('Last-Modified: ' . gmdate( 'D, d M Y H:i:s') . 'GMT');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Content-Type: text/xml');

    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $x .= '<response>';

    // The core conversion
    foreach($object as $key=>$value){
        if(gettype($value)=='array')
            foreach($value as $key2=>$value2)
                $x .= '<'.$key2.'>'.$value2.'</'.$key2.'>';
        else
            $x .= '<'.$key.'>'.$value.'</'.$key.'>';
    }

    $x .= '</response>';
    echo $x;
}

/* HTML multiline strings are started with a newline that sets the
 * indentation of the new block, so each block coming from a script
 * will hopefully mantain the same indentation, making it easier to
 * identify its origin */

/* There are two functions called from the outside to write html:
 * "login_page_send" and "app_page_send". Both of them use the
 * common function "common_frame". */

/*
 * Create the login page
 */
function login_page_send($salt, $msg='')
{
    // Find existing sessions on the filesystem and make a radio input
    // with them
    global $wb_dir, $client_ajax_timeout;
    $found_sessions = '';
    $first = true;
    $h = opendir($wb_dir);
    if($h)
        while(($session=readdir($h))!==false )
            // Don't add debug files, lock files and hidden files. If
            // the file is valid, add a radio input with that name.
            // The range of valid names read here is coupled with the
            // client-side substitutions which remove the points and
            // the other ignored keywords (function session_create
            // file login.js)
            if((strpos($session, '-debug')===false) &&
               (strpos($session, '-lock')===false) &&
               (strpos($session, '.')===false)){
                $found_sessions .= '
      <input type="radio" name="wb_id" value="'.$session.'"';
                // The first entry must be "checked"
                if ($first){
                    $found_sessions .= ' checked="checked" ';
                    $first = false;
                }
                $found_sessions .= '>'.$session.'<br>';
            }
    closedir($h);

    $content = '
  <div class="login">
   <form method="post" id="login_form" >
    <table>
     <tr>
      <td>Choose session:</td>
      <td>'.$found_sessions.'</td>
      <td>
        <input name="createsession" type="button" value="Create new"
               onclick="session_create()" disabled="disabled">
      </td>
     </tr>
     <tr>
      <td>Name:</td>
      <td>
       <input name="user" type="text"
              onkeyup="g[\'login\'].validate(this.name)">
      </td>
      <td>
      </td>
     </tr>
     <tr>
      <td>Password:</td>
      <td>
       <input name="pass" type="password" onkeyup="g[\'login\'].validate(this.name);">
      </td>
      <td>
       <input name="submit" type="button" value="Register" disabled="disabled"
              onclick="g[\'login\'].submit()">
      </td>
     </tr>
    </table>
     <p id="message_space"></p>
     <input name="salt" type="hidden" value="'.$salt.'">
     <input name="ajax_timeout" type="hidden" value="'.$client_ajax_timeout.'">
     <input name="initial_message" type="hidden" value="'.$msg.'">
   </form>
  </div>';
    echo common_frame('Login', $content);
}

/*
 * Create the whiteboard page
 */
function app_page_send($client_vars, $msg='')
{
    // layout parameters
    global $layout;
    // retrieve the whiteboard name (parse_client_id is defined into
    // updates.php)
    $session = parse_client_id($client_vars['client_id'], 'wb');

    // Content width and height (taking away the space for container's
    // padding and margin and for the name panel)
    $content_w = $client_vars['width'] - $layout['frame_narrow_width'];
    $content_h = $client_vars['height'] - $layout['frame_narrow_height'];

    $svg_w = $content_w - $client_vars['side_w'] - $layout['margin'] - $layout['m_w'];
    $svg_h = $content_h;

    $content = '
             <!-- this div is just to avoid explorer\'s peekaboo bug,
             and the width is useful just to give layout to the div to
             avoid the bug -->
             <div>
               <div id="style_div" style="float:right"></div>
               <div id="menu_bar"> '.menu_nodes().' </div>
             </div>
             <div style="clear:both"><hr/></div>
             <div id="left_column" style="display:inline; float:left;">
               '.whiteboard_nodes($svg_w, $svg_h, $layout['m_w']).'
             </div>
             <div id="vertical_separator" onclick="inner_resize()"
                  style="height:'.$content_h.'px">
               &nbsp;
             </div>
             <div id="right_column" style="width: '.$client_vars['side_w'].'px; display: inline; float: left;">
               <div id="right_top" style="height:'.$content_h.'px">
                 '.chat_nodes($content_h, $layout['margin']).'
               </div>
               <div id="horizontal_separator" onclick="user_right_resize()"></div>
               <div id="right_bottom">
                 <!-- Here the javascript could insert an iframe -->
               </div>
             </div>
             <div id="notify_area" style="clear: both; height:30px; width:'.$content_w.'px">
               Logged as user <b>'.$client_vars['user'].'</b> for session <b>'.$session.'</b>
               <br>
               <div id="log_space">'.htmlentities($msg).'</div>
             </div>
             <!-- Client side session variables read by init() into common.js -->
             <div class="hidden">
               <form id="session_data">';
    foreach ($client_vars as $name=>$value)
        $content .= '
                 <input type="hidden" name="'.$name.'" value="'.$value.'">';
    $content .= '
               </form>
             </div>';
    echo common_frame('Whiteboard', $content);
}

/* Write an html frame which is mostly common to both pages, but if
 * $name is 'Whiteboard', different .js files will be included into
 * the head */

function common_frame($name, $content){
    // The php header is defined here because this function is called
    // for every written html page
    header('Content-type: text/html');
    $document = '
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
 <head>
  <title>Web Whiteboard</title>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

  <link href="style/common.css" rel="stylesheet" type="text/css" >';
    if($name == 'Whiteboard')
        $document .= '
  <link href="style/application.css" rel="stylesheet" type="text/css">';

    $document .= '

  <!-- Inclusion order affects variables visibility. Into
  \'common.js\' the global object "g" is defined -->
  <script type="text/javascript" src="client/common-pack.js"></script>';

    if($name == 'Whiteboard')
        $document .= '
  <!-- This meta tag must go before svg.js inclusion -->
  <meta name="svg.render.forceflash" content="false">
  <script type="text/javascript" src="client/svg.js" data-path="client/"></script>
  <script type="text/javascript" src="client/application-pack.js"></script>';
    else
        $document .= '
  <script type="text/javascript" src="client/login.js"></script>';
    $document .= '
 </head>
 <body>
  <noscript><p>This application requires JavaScript</p></noscript>';
    if($name == 'Whiteboard')
        $document .= '<img src="images/resize.png" alt="Resize"
                       onclick="frame_resize()">';
    else
        // A placeholder for the image, to make the login page look like
        // the app page
        $document .='<div style="width:20px; height:21px;">&nbsp;</div>';
    $document .= '
  <!-- the table\'s purpose is just centering while fitting its
  contents (divs can be centered only when their width is fixed) -->
  <table style="margin: auto">
<tbody>
    <tr>
      <td>
        <div id="container_div" class="container_div_s_default">
          <div id="name_panel">
            <div id="help_div" style="display:inline; float:right">
              <a href="user_guide.html" style="text-decoration:none" target="_blank">
                help
              </a>
            </div>
            '.$name.'
          </div>
          '.$content.'
        </div>
      </td>
    </tr>
</tbody>
  </table>
 </body>
</html>';
    return $document;
}

// Almost all variables of control_nodes function are server side
// variables which we want to give to the client side, stored as
// hidden input fields.
function menu_nodes(){
    $result = '
      <div id="control_default">';
    if (extension_loaded('imagick'))
        $result .= '
        <button onclick="show_div(\'menu_import\', true)">Import</button>';
    $result .= '
        <button onclick="show_div(\'menu_export\', true)">Export</button>
        <button onclick="show_div(\'menu_slides\', true)">Slides</button>
        <button onclick="del_ses()">Delete session</button>
        <button onclick="refresh()">Refresh</button>
        <button onclick="logout()">Logout</button>
      </div>
      <!-- Hidden control forms (hidden inputs will be added to some
           of them by the function show_form) -->
      <div id="menu_import" style="display:none">
        <form id="import_form" action="index.php"
              method="post" enctype="multipart/form-data">
          Choose a file to import (a <b>pdf</b> file or a <b>gif, png, jpg</b> image)
          <input name="file" type="file" size="20">
          <input name="mode" type="hidden" value="import">
          <input type="button" value="Import" onclick="import_submit()">
        </form>
        <!-- the following hidden iframe is used to upload the file in
        an asynchronous way (see javascript function import_submit)
        -->
        <iframe id="import_target_frame" name="import_target_frame" style="display:none"></iframe>
        <button onclick="show_div(\'menu_import\', false)">Cancel</button>
      </div>
      <div id="menu_export" style="display:none">
        <form id="export_form">
          Which page do you want to export?
          <div class="inline">
            <select name= "exp_mode" onchange="export_mode_change(event)">
              <!-- explorer requires explicit values -->
              <option value="current">current</option>
              <option value="all">all</option>
              <option value="interval">interval</option>
              <option value="chat">chat</option>
            </select>
          </div>
          <div id="export_pages" class="hidden">
            <input type="text" size="3" name="exp_from" value="0">
            <input type="text" size="3" name="exp_to" value="10">
          </div>
          <div id="export_extension_choice" class="inline">
            Choose the file format:&nbsp;
            <select name="export_extension">
              <option value="pdf">pdf</option>';
        if (extension_loaded('imagick'))
            $result .= '
              <option value="png">png</option>
              <option value="jpg">jpg</option>
              <option value="gif">gif</option>';
        $result .= '
            </select>
          </div>
          <input type="button" value="Export" name="export_submit_button"
                 onclick="export_submit()">
        </form>
        <button onclick="show_div(\'menu_export\', false)">Cancel</button>
      </div>
      <div id="menu_slides" style="display:none">
        <form id="slides_form">
          Insert the address for the slides:
          <input type="text" size="20" name="slides_address">
          <input type="button" value="Load slides" onclick="load_slides()">
        </form>
        <button onclick="show_div(\'menu_slides\', false)">Cancel</button>
        &nbsp;&nbsp;&nbsp;
        <button onclick="hide_slides()">Hide slides</button>
      </div>';
    return $result;
}

// The parameters are inner (extern minus padding) height and margin
function chat_nodes($h, $margin){
    // Subtract the horizontal resize panel and the two 1px borders
    $h = $h - $margin - 2;
    $out_h = $h * 0.8;
    $inp_h = $h * 0.2 - $margin;
    return '
      <div id="text_output" style="width: 100%; height:'.$out_h.'px"></div>
      <!-- this is just to separate the other two elements -->
      <div style="height:'.$margin.'px;"></div>
      <textarea id="text_input_t"
                onkeyup="chatOnKeyUp(event)"
                style="width: 100%; height:'.$inp_h.'px"></textarea>';
}

// Generate the svg file for the whiteboard
// w, h: whiteboard width and height
// m_w: menu width
// margin: margin size
// svg_w, svg_h: resulting svg width and height
function whiteboard_nodes($svg_w, $svg_h, $m_w){
    return '
      <div id="svg_container">
       <script type="image/svg+xml">
        <svg id="svg_root" width="'.($svg_w+1).'" height="'.($svg_h+1).'"
          xmlns="http://www.w3.org/2000/svg"
          xmlns:xlink="http://www.w3.org/1999/xlink">
          <!--clipPath id="clip_rect"><rect height="100px" width="100px" y="10px" x="10px"></rect></clipPath-->
         <!-- the position of this element (before the group that
          will keep all the shapes) is important to render it
          behind all other shapes -->
         <rect id="canvas" fill="white" width="'.$svg_w.'" height="'.$svg_h.'" />
         <g id="whiteboard_g"/>
        </svg>
       </script>
      </div>
      <div style="width: 10px; float:left">&nbsp</div>
      <div id="toolbox" style="width:'.$m_w.'px">'.
        create_palette("Fill").
        create_palette("Stroke").
        create_toolbox().'
      </div>
      <textarea id="textinput" onkeyup="whiteboardOnKeyUp(event)" '.
       'rows="5" cols="20" style="display:none"></textarea>';
}

// Generate a color palette. Each color sample is assigned to a class,
// so for each color code into the 'colors' array there exists a
// corresponding class into whiteboard.css. The color code will be
// retrieved by client side javascript to assign it to svg elements. I
// use this weird class structure for problems on client side with
// explorer: target.style isn't supported well as target.className by
// explorer, so to change the sample square appearence I need to use
// class names
function create_palette($type){
    $colors = array('000000', 'FFFFFF', 'A020F0', '0000CD',
                    '00FFFF', '49E20E', 'FF0000', 'FF8000',
                    'EEEE00', 'FF1CAE');
    $text = $type.':';
    $handler = 'handle'.$type;
    $id = strtolower($type);
    // Many of the following divs exist to do css styling
    $result = '
       <div class="color_table">
        <div class="color_selected">
         '.$text.'
         <div id="'.$id.'_sample" class="color_sample color_default">
         </div>
        </div>
        <div id="'.$id.'_palette" class="color_palette">';
    for($i=0; $i<count($colors); $i++)
        $result .= '<div class="color_sample color_'.$colors[$i].'" '.
            'onclick="'.$handler.'(event)">'.
            '</div>';
    $result .= '
        </div> 
       </div>';
    return $result;
}

function create_toolbox()
{
    $buttons = array('Path', 'Line', 'Polyline', 'Polygon', 'Rect',
                     'Circle', 'Text', 'Image', 'Link', 'Move', 'Edit',
                     'Delete', 'Select', 'Clear');
    $result = '
       <div id="buttons">';
    // Add buttons updating height
    foreach($buttons as $i => $value)
        $result .= '
        <input type="button" id="'.strtolower($value).'_button" '.
               'value="'.$value.'" '.
               'onclick="handleTool(event)" '.
               'class="draw_button"><br>';
    // Last three buttons have a different class. The page input
    // has an'id' to be accessed by the javascript
    $result .= '
        <input class="draw_button_little" type="button" value="&lt;" '.
               'onclick="handleTool(event)" >
        <input class="draw_button_little" type="text" value="0" '.
               'id="page_input" onkeyup ="handlePageInput(event)" >
        <input class="draw_button_little" type="button" value="&gt;" '.
               'onclick="handleTool(event)" >';
    // This hidden divs contain additional commands for different tools;
    // their name is binded client side with the values given to
    // variable additional_panel into function handleTool
    $result .='
        <div id="open_shape_style" class="hidden">
          Stroke width:<br>
          <input name="open_stroke" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'stroke-width\', event, /^\d+$/)"
                 value="2">
          <br>
          Stroke opacity:<br>
          <input name="open_opacity" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'opacity\', event, /(^1$)|(^0$)|(^0?\.\d+$)/)"
                 value="1">
          <br>
        </div>
        <div id="closed_shape_style" class="hidden">
          <!-- the name of these input fields match actual svg attributes -->
          Stroke width:<br>
          <input name="closed_stroke" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'stroke-width\', event, /^\d+$/)" value="2"
                 value="2">
          <br>
          <!-- Stroke opacity isn\'t supported by firefox\'s 3.6.10 native renderer -->
          Fill opacity:<br>
          <input name="closed_fill_opacity" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'fill-opacity\', event, /(^1$)|(^0$)|(^0?\.\d+$)/)"
                 value="0.0">
          <br>
        </div>
        <div id="image_style" class="hidden">
          Image scaling factor:<br>
          <input name="image_scaling" type="text" class="draw_button"
                 onkeyup="set_shape_attribute(\'scaling-factor\', event, /^\d+$/)"
                 value="100">
          <br>
        </div>
       </div>';
    return $result;
}

?>
<?php
// $Id: draw_image.php 122 2010-11-18 04:44:54Z s242720-studenti $

/*
 * Support routines to generate a pdf with the content
 * of the whiteboard.
 */
function draw_image($pages, $user_vars, $ext)
{
    $width  = $user_vars['svg_w'];
    $height = $user_vars['svg_h'];
    if ($ext == 'pdf') {
        $image = pdf_draw($pages, $width, $height);
        $header_string = 'Content-Type: application/pdf';
    } else {
	// Extension correctedness has already be checked into main.php
        // Page intervals can be exported just in the form of a pdf,
        // not in image form
        $page = array_pop($pages);
        $image = imagick_draw($page, $width, $height, $ext);
        // adjust jpg for the mimetype
        if ($ext == 'jpg')
            $ext = 'jpeg';
        $header_string = 'Content-Type: image/'.$ext;
    }
    return array($image, $header_string);
}

define('DEFAULT_WIDTH', 2);
define('FILL_DEFAULT_ALPHA', 0.5);
define('DEFAULT_ALPHA', 1);
define('DEFAULT_FONT_SIZE', 7);

function pdf_draw($pages, $width, $height)
{
    $pdf = new FPDF('P', 'pt', 'custom', $width, $height);
    $pdf->SetLineWidth(DEFAULT_WIDTH);
    // Don't change the font. Helvetica has been embedded into
    // fpdf.php files, and other font files were discarded (see
    // changes on top of fpdf.php file)
    $pdf->SetFont('Helvetica'); // XXX make configurable
    foreach ($pages as $objects) {
        $pdf->AddPage();
        $counter = 0;
        foreach($objects as $key => $object){
            $par = $object['par'];
            // Apply move actions if any: if this object is a multipath,
            // search move actions using the original id
            if (strpos($key, '_multipath_'))
                $objid = substr($key, 0, strpos($key, '_multipath_'));
            else
                $objid = $key;
            if (isset($objects[$objid]['move_x'])) {
                $mx = $objects[$objid]['move_x'];
                $my = $objects[$objid]['move_y'];
            } else {
                $mx = $my = 0;
	    }
            // parameters fixed for all shapes; the correspondance of other
            // parameters must be checked against those in shapes.js
            $stroke = hex2rgb($par[0]);
            $fill   = hex2rgb($par[1]);
            $par = array_slice($par, 2);
            switch ($object['typ']) {
            case 'path':
                list($opacity, $stroke_width, $d) = $par;
                $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
                $pdf->SetLineWidth($stroke_width);
                $pdf->SetAlpha($opacity);
                // There is an extra initial line between two identical
                // points, but this isn't a problem
                $x0 = $object['points'][0][0] + $mx;
                $y0 = $object['points'][0][1] + $my;
                foreach ($object['points'] as $coords) {
                    $x1 = $coords[0] + $mx;
                    $y1 = $coords[1] + $my;
                    $pdf->Line($x0, $y0, $x1, $y1);
                    $x0 = $x1;
                    $y0 = $y1;
                }
                break;

            case 'line':
                list($opacity, $stroke_width, $x1, $y1, $x2, $y2) = $par;
                $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
                $pdf->SetLineWidth($stroke_width);
                $pdf->SetAlpha($opacity);
                $pdf->Line($x1+$mx, $y1+$my, $x2+$mx, $y2+$my);
                break;

            case 'circle':
                list ($fill_opacity, $stroke_width, $cx, $cy, $r) = $par;
                // Draw stroke
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $pdf->Circle($cx+$mx,$cy+$my,$r, 'D');
                // Draw fill
                $pdf->SetAlpha($fill_opacity);
                $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']);
                $pdf->Circle($cx+$mx,$cy+$my,$r, 'F');
                break;

            case 'rect':
                list ($fill_opacity, $stroke_width, $x, $y, $width, $height) = $par;
                // Draw stroke
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $pdf->SetLineWidth($stroke_width);
                $pdf->rect($x+$mx,$y+$my,$width,$height,'D');
                // Draw fill
                $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']);
                $pdf->SetAlpha($fill_opacity);
                $pdf->rect($x+$mx,$y+$my,$width,$height,'F');
                break;

            case 'polygon':
                list($fill_opacity, $stroke_width, $pairs_str) = $par;
                $pdf->SetLineWidth($stroke_width);
                // Add an eventual 'move' action to points coordinates and
                // translate the points array into the form required by the
                // function interface (a flat array with 'x' on odd positions
                // and 'y' on even ones)
                $points = array();
                foreach($object['points'] as $point){
                    $points[] = (int)$point[0] + $mx;
                    $points[] = (int)$point[1] + $my;
                }
                // Draw stroke
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $pdf->polygon($points, 'D');
                // Draw fill
                $pdf->SetFillColor($fill['r'],$fill['g'],$fill['b']);
                $pdf->SetAlpha($fill_opacity);
                $pdf->polygon($points, 'F');
                break;

            case 'polyline':
                list($opacity, $stroke_width, ) = $par;
                $pdf->SetLineWidth($stroke_width);
                $pdf->SetAlpha($opacity);
                $pdf->SetDrawColor($stroke['r'],$stroke['g'],$stroke['b']);
                $points = $object['points'];
                $x0 = $points[0][0] + $mx;
                $y0 = $points[0][1] + $my;
                for($i = 1; $i < count($points); $i++){
                    $x1 = $points[$i][0] + $mx;
                    $y1 = $points[$i][1] + $my;
                    $pdf->Line($x0, $y0, $x1, $y1);
                    $x0 = $x1;
                    $y0 = $y1;
                }
                break;

            case 'text':
                list($x, $y, $content) = $par;
                $content = urldecode($content);
                $pdf->SetTextColor($fill['r'],$fill['g'],$fill['b']);
                //$pdf->SetFontSize($font_size);
                $rows = explode("\n", $content);
                foreach($rows as $row){
                    $pdf->Text($x+$mx, $y+$mx, $row);
                    //$y += $font_size;
                    $y += DEFAULT_FONT_SIZE;
                }
                break;

            case 'link':
                list($x, $y, $content) = $par;
                $content = urldecode($content);
                $pdf->SetTextColor(0,0,255);
                // This is the only case in which the current position is
                // used, so we don't need to restore original position after
                // writing the link
                $pdf->SetXY($x+$mx, $y+$my);
                //$pdf->Write(DEFAULT_FONT_SIZE, $content, $content);
                break;

            case 'image':
                list($x, $y, $content, $width, $height) = $par;
                // This function may fail if the GD library is not
                // present, or for some kind of images (for examples,
                // png with a depth of 16 bits). See the manual of
                // fpdf about the Image function
                try {
                    $pdf->Image(urldecode($content), $x+$mx, $y+$my,
				$width, $height);
                }
                catch(Exception $e) {
                    server_log('Failed to insert image ' . $content .
				': '.$e->getMessage());
                }
                break;
            }
            // Reset default values, in case they have changed
            $pdf->SetLineWidth(DEFAULT_WIDTH);
            $pdf->SetAlpha(DEFAULT_ALPHA);
        }
    }
    return $pdf->Output('', 'S');
}

// To convert from html notation to rgb values (used by pdf_draw)
function hex2rgb($hex)
{
    $color = str_replace('#','',$hex);
    $rgb = array('r' => hexdec(substr($color,0,2)),
                 'g' => hexdec(substr($color,2,2)),
                 'b' => hexdec(substr($color,4,2)));
    return $rgb;
}

function imagick_draw($objects, $width, $height, $ext)
{
    $draw = new ImagickDraw();
    foreach($objects as $key => $object){
        $par = $object['par'];
        // Apply move actions if any: if this object is a multipath,
        // search move actions using the original id
        if(strpos($key, '_multipath_'))
            $objid = substr($key, 0, strpos($key, '_multipath_'));
        else
            $objid = $key;
        if(isset($objects[$objid]['move_x'])){
            //(after the draw, we will come back to original draw
            // position)
            $draw->translate($objects[$objid]['move_x'],
                             $objects[$objid]['move_y']);
            $translated = true;
        }
        else
            $translated = false;
        $draw->setStrokeColor($par[0]);
        // Store the value of the fill color. For texts, this will be
        // set to stroke color
        $fill = $par[1];
        $draw->setFillColor($fill);
        $par = array_slice($par, 2);
        switch($object['typ']){
        case 'path':
            list($opacity, $stroke_width, $d) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setStrokeAlpha($opacity);
            $draw->setFillColor('none');
            $draw->pathStart();
            foreach($object['points'] as $coords){
                $draw->pathLineToAbsolute($coords[0], $coords[1]);
            }
            $draw->pathFinish();
            break;
        case 'line':
            list($opacity, $stroke_width, $x1, $y1, $x2, $y2) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setStrokeAlpha($opacity);
            $draw->line($x1, $y1, $x2, $y2);
            break;
        case 'circle':
            list ($fill_opacity, $stroke_width, $cx, $cy, $r) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setFillOpacity($fill_opacity);
            $draw->circle($cx, $cy, $cx+$r, $cy);
            break;
        case 'rect':
            list ($fill_opacity, $stroke_width, $x, $y, $w, $h) = $par;
            $draw->setStrokeWidth($stroke_width);
            $draw->setFillOpacity($fill_opacity);
            $draw->rectangle($x, $y, $x+$w, $y+$h);
            break;
        case 'polyline':
        case 'polygon':
            list($fill_opacity, $stroke_width, ) = $par;
            // Change the points array to adapt to the function interface
            // (change numerical indexes with 'x' and 'y' indexes for each
            // coordinate)
            $points = array();
            foreach($object['points'] as $point){
                $new_point = array('x'=>$point[0],
                                   'y'=>$point[1]);
                $points[] = $new_point;
            }
            $draw->setStrokeWidth($stroke_width);
            if($object['typ']=='polygon'){
                $draw->setFillOpacity($fill_opacity);
                $draw->polygon($points);
            }
            else{
                $draw->setFillColor('none');
                $draw->setStrokeAlpha($fill_opacity);
                $draw->polyline($points);
            }
            break;
        case 'text':
        case 'link':
            $draw->setStrokeWidth(0);
            // For Imagemagick, it is the Stroke color which
            // determines the text color, but for the SVG (and this
            // application) it is the fill color that matters
            if ($object['typ']=='link')
                $draw->setStrokeColor('blue');
            else
                $draw->setStrokeColor($fill);
            list($x, $y, $content) = $par;
            $content = urldecode($content);
            $draw->annotation($x, $y, $content);
            break;
        case 'image':
            list($x, $y, $content, $w, $h) = $par;
            // load the file for the contained image (this fopen checks
            // allow_url_fopen setting)
            $address = urldecode($content);
            try{
                $fp = fopen($address, 'r');
            }
            catch(Exception $e){
                // If an image can't be retrieved, simply skip that image
                server_log('Image '.$address.' could not be retrieved.');
                break;
            }
            $contained = new Imagick();
            $contained->readImageFile($fp);
            // insert into the draw, resizing too
            $draw->composite(imagick::COMPOSITE_DEFAULT, // Composite mode
                             $x, $y, $w, $h, $contained);
            break;
        }
        // If a translation has occurred, come back to the original
        // position
        if($translated)
            $draw->translate(-(int)$objects[$objid]['move_x'],
                             -(int)$objects[$objid]['move_y']);
        $draw->setStrokeWidth(DEFAULT_WIDTH);
        $draw->setStrokeAlpha(DEFAULT_ALPHA);
    }
    // The use of Imagick and ImagickDraw classes is taken from
    // imagick examples on php manual
    // http://it.php.net/manual/en/imagick.examples-1.php
    $image = new Imagick();
    $image->newImage($width, $height, 'white');
    $image->drawImage($draw);
    $image->setImageFormat($ext);
    return $image;
}

?>
<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.6                                                                 *
* Date:    2008-08-03                                                          *
* Author:  Olivier PLATHEY                                                     *
*******************************************************************************/

  /* $Id: fpdf.php 117 2010-11-16 22:18:44Z luigi $
   * Changes made by Francesco Occhipinti:
   * - Changed the costructor, it accepts a 'custom' format with user
       defined sizes
   * - Added functions: Circle, Ellipse, Polygon taken by the scripts
       (on the fpdf website), and merged with the code from script
       'Transparency' that provides the function SetAlpha
   * - Modified the function Error
   * - The font array for family 'helvetica' has been cutted from his
       file and pasted here (bottom of file), so there is no need for
       the file folder which was unused in practice. In order to use
       different families (or styles like bold and italics) the
       original font folder must be restored.
   * date: Sat Sep 25 09:54:01 CEST 2010
   */


define('FPDF_VERSION','1.6');

class FPDF
{
var $page;               //current page number
var $n;                  //current object number
var $offsets;            //array of object offsets
var $buffer;             //buffer holding in-memory PDF
var $pages;              //array containing pages
var $state;              //current document state
var $compress;           //compression flag
var $k;                  //scale factor (number of points in user unit)
var $DefOrientation;     //default orientation
var $CurOrientation;     //current orientation
var $PageFormats;        //available page formats
var $DefPageFormat;      //default page format
var $CurPageFormat;      //current page format
var $PageSizes;          //array storing non-default page sizes
var $wPt,$hPt;           //dimensions of current page in points
var $w,$h;               //dimensions of current page in user unit
var $lMargin;            //left margin
var $tMargin;            //top margin
var $rMargin;            //right margin
var $bMargin;            //page break margin
var $cMargin;            //cell margin
var $x,$y;               //current position in user unit
var $lasth;              //height of last printed cell
var $LineWidth;          //line width in user unit
var $CoreFonts;          //array of standard font names
var $fonts;              //array of used fonts
var $FontFiles;          //array of font files
var $diffs;              //array of encoding differences
var $FontFamily;         //current font family
var $FontStyle;          //current font style
var $underline;          //underlining flag
var $CurrentFont;        //current font info
var $FontSizePt;         //current font size in points
var $FontSize;           //current font size in user unit
var $DrawColor;          //commands for drawing color
var $FillColor;          //commands for filling color
var $TextColor;          //commands for text color
var $ColorFlag;          //indicates whether fill and text colors are different
var $ws;                 //word spacing
var $images;             //array of used images
var $PageLinks;          //array of links in pages
var $links;              //array of internal links
var $AutoPageBreak;      //automatic page breaking
var $PageBreakTrigger;   //threshold used to trigger page breaks
var $InHeader;           //flag set when processing header
var $InFooter;           //flag set when processing footer
var $ZoomMode;           //zoom display mode
var $LayoutMode;         //layout display mode
var $title;              //title
var $subject;            //subject
var $author;             //author
var $keywords;           //keywords
var $creator;            //creator
var $AliasNbPages;       //alias for total number of pages
var $PDFVersion;         //PDF version number
var $extgstates = array(); // For transparency

/*******************************************************************************
*                                                                              *
*                               Public methods                                 *
*                                                                              *
*******************************************************************************/
 function FPDF($orientation='P', $unit='mm', $format='A4', $custom_w=100, $custom_h=100)
{
	//Some checks
	$this->_dochecks();
	//Initialization of properties
	$this->page=0;
	$this->n=2;
	$this->buffer='';
	$this->pages=array();
	$this->PageSizes=array();
	$this->state=0;
	$this->fonts=array();
	$this->FontFiles=array();
	$this->diffs=array();
	$this->images=array();
	$this->links=array();
	$this->InHeader=false;
	$this->InFooter=false;
	$this->lasth=0;
	$this->FontFamily='';
	$this->FontStyle='';
	$this->FontSizePt=12;
	$this->underline=false;
	$this->DrawColor='0 G';
	$this->FillColor='0 g';
	$this->TextColor='0 g';
	$this->ColorFlag=false;
	$this->ws=0;
	//Standard fonts
	$this->CoreFonts=array('courier'=>'Courier', 'courierB'=>'Courier-Bold', 'courierI'=>'Courier-Oblique', 'courierBI'=>'Courier-BoldOblique',
		'helvetica'=>'Helvetica', 'helveticaB'=>'Helvetica-Bold', 'helveticaI'=>'Helvetica-Oblique', 'helveticaBI'=>'Helvetica-BoldOblique',
		'times'=>'Times-Roman', 'timesB'=>'Times-Bold', 'timesI'=>'Times-Italic', 'timesBI'=>'Times-BoldItalic',
		'symbol'=>'Symbol', 'zapfdingbats'=>'ZapfDingbats');
	//Scale factor
	if($unit=='pt')
		$this->k=1;
	elseif($unit=='mm')
		$this->k=72/25.4;
	elseif($unit=='cm')
		$this->k=72/2.54;
	elseif($unit=='in')
		$this->k=72;
	else
		$this->Error('Incorrect unit: '.$unit);
	//Page format
	$this->PageFormats=array('a3'=>array(841.89,1190.55),
                                 'a4'=>array(595.28,841.89),
                                 'a5'=>array(420.94,595.28),
                                 'letter'=>array(612,792),
                                 'legal'=>array(612,1008),
                                 'custom'=>array($custom_w, $custom_h));
	if(is_string($format))
		$format=$this->_getpageformat($format);
	$this->DefPageFormat=$format;
	$this->CurPageFormat=$format;
	//Page orientation
	$orientation=strtolower($orientation);
	if($orientation=='p' || $orientation=='portrait')
	{
		$this->DefOrientation='P';
		$this->w=$this->DefPageFormat[0];
		$this->h=$this->DefPageFormat[1];
	}
	elseif($orientation=='l' || $orientation=='landscape')
	{
		$this->DefOrientation='L';
		$this->w=$this->DefPageFormat[1];
		$this->h=$this->DefPageFormat[0];
	}
	else
		$this->Error('Incorrect orientation: '.$orientation);
	$this->CurOrientation=$this->DefOrientation;
	$this->wPt=$this->w*$this->k;
	$this->hPt=$this->h*$this->k;
	//Page margins (1 cm)
	$margin=28.35/$this->k;
	$this->SetMargins($margin,$margin);
	//Interior cell margin (1 mm)
	$this->cMargin=$margin/10;
	//Line width (0.2 mm)
	$this->LineWidth=.567/$this->k;
	//Automatic page break
	$this->SetAutoPageBreak(true,2*$margin);
	//Full width display mode
	$this->SetDisplayMode('fullwidth');
	//Enable compression
	$this->SetCompression(true);
	//Set default PDF version number
	$this->PDFVersion='1.3';
}

function SetMargins($left, $top, $right=null)
{
	//Set left, top and right margins
	$this->lMargin=$left;
	$this->tMargin=$top;
	if($right===null)
		$right=$left;
	$this->rMargin=$right;
}

function SetLeftMargin($margin)
{
	//Set left margin
	$this->lMargin=$margin;
	if($this->page>0 && $this->x<$margin)
		$this->x=$margin;
}

function SetTopMargin($margin)
{
	//Set top margin
	$this->tMargin=$margin;
}

function SetRightMargin($margin)
{
	//Set right margin
	$this->rMargin=$margin;
}

function SetAutoPageBreak($auto, $margin=0)
{
	//Set auto page break mode and triggering margin
	$this->AutoPageBreak=$auto;
	$this->bMargin=$margin;
	$this->PageBreakTrigger=$this->h-$margin;
}

function SetDisplayMode($zoom, $layout='continuous')
{
	//Set display mode in viewer
	if($zoom=='fullpage' || $zoom=='fullwidth' || $zoom=='real' || $zoom=='default' || !is_string($zoom))
		$this->ZoomMode=$zoom;
	else
		$this->Error('Incorrect zoom display mode: '.$zoom);
	if($layout=='single' || $layout=='continuous' || $layout=='two' || $layout=='default')
		$this->LayoutMode=$layout;
	else
		$this->Error('Incorrect layout display mode: '.$layout);
}

function SetCompression($compress)
{
	//Set page compression
	if(function_exists('gzcompress'))
		$this->compress=$compress;
	else
		$this->compress=false;
}

function SetTitle($title, $isUTF8=false)
{
	//Title of document
	if($isUTF8)
		$title=$this->_UTF8toUTF16($title);
	$this->title=$title;
}

function SetSubject($subject, $isUTF8=false)
{
	//Subject of document
	if($isUTF8)
		$subject=$this->_UTF8toUTF16($subject);
	$this->subject=$subject;
}

function SetAuthor($author, $isUTF8=false)
{
	//Author of document
	if($isUTF8)
		$author=$this->_UTF8toUTF16($author);
	$this->author=$author;
}

function SetKeywords($keywords, $isUTF8=false)
{
	//Keywords of document
	if($isUTF8)
		$keywords=$this->_UTF8toUTF16($keywords);
	$this->keywords=$keywords;
}

function SetCreator($creator, $isUTF8=false)
{
	//Creator of document
	if($isUTF8)
		$creator=$this->_UTF8toUTF16($creator);
	$this->creator=$creator;
}

function AliasNbPages($alias='{nb}')
{
	//Define an alias for total number of pages
	$this->AliasNbPages=$alias;
}

function Error($msg)
{
	//Fatal error
  throw new Exception('<b>FPDF error:</b> '.$msg);
}

function Open()
{
	//Begin document
	$this->state=1;
}

function Close()
{
	//Terminate document
	if($this->state==3)
		return;
	if($this->page==0)
		$this->AddPage();
	//Page footer
	$this->InFooter=true;
	$this->Footer();
	$this->InFooter=false;
	//Close page
	$this->_endpage();
	//Close document
	$this->_enddoc();
}

function AddPage($orientation='', $format='')
{
	//Start a new page
	if($this->state==0)
		$this->Open();
	$family=$this->FontFamily;
	$style=$this->FontStyle.($this->underline ? 'U' : '');
	$size=$this->FontSizePt;
	$lw=$this->LineWidth;
	$dc=$this->DrawColor;
	$fc=$this->FillColor;
	$tc=$this->TextColor;
	$cf=$this->ColorFlag;
	if($this->page>0)
	{
		//Page footer
		$this->InFooter=true;
		$this->Footer();
		$this->InFooter=false;
		//Close page
		$this->_endpage();
	}
	//Start new page
	$this->_beginpage($orientation,$format);
	//Set line cap style to square
	$this->_out('2 J');
	//Set line width
	$this->LineWidth=$lw;
	$this->_out(sprintf('%.2F w',$lw*$this->k));
	//Set font
	if($family)
		$this->SetFont($family,$style,$size);
	//Set colors
	$this->DrawColor=$dc;
	if($dc!='0 G')
		$this->_out($dc);
	$this->FillColor=$fc;
	if($fc!='0 g')
		$this->_out($fc);
	$this->TextColor=$tc;
	$this->ColorFlag=$cf;
	//Page header
	$this->InHeader=true;
	$this->Header();
	$this->InHeader=false;
	//Restore line width
	if($this->LineWidth!=$lw)
	{
		$this->LineWidth=$lw;
		$this->_out(sprintf('%.2F w',$lw*$this->k));
	}
	//Restore font
	if($family)
		$this->SetFont($family,$style,$size);
	//Restore colors
	if($this->DrawColor!=$dc)
	{
		$this->DrawColor=$dc;
		$this->_out($dc);
	}
	if($this->FillColor!=$fc)
	{
		$this->FillColor=$fc;
		$this->_out($fc);
	}
	$this->TextColor=$tc;
	$this->ColorFlag=$cf;
}

function Header()
{
	//To be implemented in your own inherited class
}

function Footer()
{
	//To be implemented in your own inherited class
}

function PageNo()
{
	//Get current page number
	return $this->page;
}

function SetDrawColor($r, $g=null, $b=null)
{
	//Set color for all stroking operations
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->DrawColor=sprintf('%.3F G',$r/255);
	else
		$this->DrawColor=sprintf('%.3F %.3F %.3F RG',$r/255,$g/255,$b/255);
	if($this->page>0)
		$this->_out($this->DrawColor);
}

function SetFillColor($r, $g=null, $b=null)
{
	//Set color for all filling operations
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->FillColor=sprintf('%.3F g',$r/255);
	else
		$this->FillColor=sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
	$this->ColorFlag=($this->FillColor!=$this->TextColor);
	if($this->page>0)
		$this->_out($this->FillColor);
}

function SetTextColor($r, $g=null, $b=null)
{
	//Set color for text
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->TextColor=sprintf('%.3F g',$r/255);
	else
		$this->TextColor=sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
	$this->ColorFlag=($this->FillColor!=$this->TextColor);
}

function GetStringWidth($s)
{
	//Get width of a string in the current font
	$s=(string)$s;
	$cw=&$this->CurrentFont['cw'];
	$w=0;
	$l=strlen($s);
	for($i=0;$i<$l;$i++)
		$w+=$cw[$s[$i]];
	return $w*$this->FontSize/1000;
}

function SetLineWidth($width)
{
	//Set line width
	$this->LineWidth=$width;
	if($this->page>0)
		$this->_out(sprintf('%.2F w',$width*$this->k));
}

function Line($x1, $y1, $x2, $y2)
{
	//Draw a line
	$this->_out(sprintf('%.2F %.2F m %.2F %.2F l S',$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k));
}

function Rect($x, $y, $w, $h, $style='')
{
	//Draw a rectangle
	if($style=='F')
		$op='f';
	elseif($style=='FD' || $style=='DF')
		$op='B';
	else
		$op='S';
	$this->_out(sprintf('%.2F %.2F %.2F %.2F re %s',$x*$this->k,($this->h-$y)*$this->k,$w*$this->k,-$h*$this->k,$op));
}

function Circle($x, $y, $r, $style='D')
{
    $this->Ellipse($x,$y,$r,$r,$style);
}

function Ellipse($x, $y, $rx, $ry, $style='D')
{
    if($style=='F')
        $op='f';
    elseif($style=='FD' || $style=='DF')
        $op='B';
    else
        $op='S';
    $lx=4/3*(M_SQRT2-1)*$rx;
    $ly=4/3*(M_SQRT2-1)*$ry;
    $k=$this->k;
    $h=$this->h;
    $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
        ($x+$rx)*$k,($h-$y)*$k,
        ($x+$rx)*$k,($h-($y-$ly))*$k,
        ($x+$lx)*$k,($h-($y-$ry))*$k,
        $x*$k,($h-($y-$ry))*$k));
    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
        ($x-$lx)*$k,($h-($y-$ry))*$k,
        ($x-$rx)*$k,($h-($y-$ly))*$k,
        ($x-$rx)*$k,($h-$y)*$k));
    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
        ($x-$rx)*$k,($h-($y+$ly))*$k,
        ($x-$lx)*$k,($h-($y+$ry))*$k,
        $x*$k,($h-($y+$ry))*$k));
    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
        ($x+$lx)*$k,($h-($y+$ry))*$k,
        ($x+$rx)*$k,($h-($y+$ly))*$k,
        ($x+$rx)*$k,($h-$y)*$k,
        $op));
}

function Polygon($points, $style='D')
{
    //Draw a polygon
    if($style=='F')
        $op = 'f';
    elseif($style=='FD' || $style=='DF')
        $op = 'b';
    else
        $op = 's';

    $h = $this->h;
    $k = $this->k;

    $points_string = '';
    for($i=0; $i<count($points); $i+=2){
        $points_string .= sprintf('%.2F %.2F', $points[$i]*$k, ($h-$points[$i+1])*$k);
        if($i==0)
            $points_string .= ' m ';
        else
            $points_string .= ' l ';
    }
    $this->_out($points_string . $op);
}

function AddFont($family, $style='', $file='')
{
	//Add a TrueType or Type1 font
	$family=strtolower($family);
	if($file=='')
		$file=str_replace(' ','',$family).strtolower($style).'.php';
	if($family=='arial')
		$family='helvetica';
	$style=strtoupper($style);
	if($style=='IB')
		$style='BI';
	$fontkey=$family.$style;
	if(isset($this->fonts[$fontkey]))
		return;
	include($this->_getfontpath().$file);
	if(!isset($name))
		$this->Error('Could not include font definition file');
	$i=count($this->fonts)+1;
	$this->fonts[$fontkey]=array('i'=>$i, 'type'=>$type, 'name'=>$name, 'desc'=>$desc, 'up'=>$up, 'ut'=>$ut, 'cw'=>$cw, 'enc'=>$enc, 'file'=>$file);
	if($diff)
	{
		//Search existing encodings
		$d=0;
		$nb=count($this->diffs);
		for($i=1;$i<=$nb;$i++)
		{
			if($this->diffs[$i]==$diff)
			{
				$d=$i;
				break;
			}
		}
		if($d==0)
		{
			$d=$nb+1;
			$this->diffs[$d]=$diff;
		}
		$this->fonts[$fontkey]['diff']=$d;
	}
	if($file)
	{
		if($type=='TrueType')
			$this->FontFiles[$file]=array('length1'=>$originalsize);
		else
			$this->FontFiles[$file]=array('length1'=>$size1, 'length2'=>$size2);
	}
}

function SetFont($family, $style='', $size=0)
{
	//Select a font; size given in points
	global $fpdf_charwidths;

	$family=strtolower($family);
	if($family=='')
		$family=$this->FontFamily;
	if($family=='arial')
		$family='helvetica';
	elseif($family=='symbol' || $family=='zapfdingbats')
		$style='';
	$style=strtoupper($style);
	if(strpos($style,'U')!==false)
	{
		$this->underline=true;
		$style=str_replace('U','',$style);
	}
	else
		$this->underline=false;
	if($style=='IB')
		$style='BI';
	if($size==0)
		$size=$this->FontSizePt;
	//Test if font is already selected
	if($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size)
		return;
	//Test if used for the first time
	$fontkey=$family.$style;
	if(!isset($this->fonts[$fontkey]))
	{
		//Check if one of the standard fonts
		if(isset($this->CoreFonts[$fontkey]))
		{
			if(!isset($fpdf_charwidths[$fontkey]))
			{
				//Load metric file
				$file=$family;
				if($family=='times' || $family=='helvetica')
					$file.=strtolower($style);
				include($this->_getfontpath().$file.'.php');
				if(!isset($fpdf_charwidths[$fontkey]))
					$this->Error('Could not include font metric file');
			}
			$i=count($this->fonts)+1;
			$name=$this->CoreFonts[$fontkey];
			$cw=$fpdf_charwidths[$fontkey];
			$this->fonts[$fontkey]=array('i'=>$i, 'type'=>'core', 'name'=>$name, 'up'=>-100, 'ut'=>50, 'cw'=>$cw);
		}
		else
			$this->Error('Undefined font: '.$family.' '.$style);
	}
	//Select it
	$this->FontFamily=$family;
	$this->FontStyle=$style;
	$this->FontSizePt=$size;
	$this->FontSize=$size/$this->k;
	$this->CurrentFont=&$this->fonts[$fontkey];
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}

function SetFontSize($size)
{
	//Set font size in points
	if($this->FontSizePt==$size)
		return;
	$this->FontSizePt=$size;
	$this->FontSize=$size/$this->k;
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}

function AddLink()
{
	//Create a new internal link
	$n=count($this->links)+1;
	$this->links[$n]=array(0, 0);
	return $n;
}

function SetLink($link, $y=0, $page=-1)
{
	//Set destination of internal link
	if($y==-1)
		$y=$this->y;
	if($page==-1)
		$page=$this->page;
	$this->links[$link]=array($page, $y);
}

function Link($x, $y, $w, $h, $link)
{
	//Put a link on the page
	$this->PageLinks[$this->page][]=array($x*$this->k, $this->hPt-$y*$this->k, $w*$this->k, $h*$this->k, $link);
}

function Text($x, $y, $txt)
{
	//Output a string
	$s=sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
	if($this->underline && $txt!='')
		$s.=' '.$this->_dounderline($x,$y,$txt);
	if($this->ColorFlag)
		$s='q '.$this->TextColor.' '.$s.' Q';
	$this->_out($s);
}

function AcceptPageBreak()
{
	//Accept automatic page break or not
	return $this->AutoPageBreak;
}

function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
{
	//Output a cell
	$k=$this->k;
	if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
	{
		//Automatic page break
		$x=$this->x;
		$ws=$this->ws;
		if($ws>0)
		{
			$this->ws=0;
			$this->_out('0 Tw');
		}
		$this->AddPage($this->CurOrientation,$this->CurPageFormat);
		$this->x=$x;
		if($ws>0)
		{
			$this->ws=$ws;
			$this->_out(sprintf('%.3F Tw',$ws*$k));
		}
	}
	if($w==0)
		$w=$this->w-$this->rMargin-$this->x;
	$s='';
	if($fill || $border==1)
	{
		if($fill)
			$op=($border==1) ? 'B' : 'f';
		else
			$op='S';
		$s=sprintf('%.2F %.2F %.2F %.2F re %s ',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);
	}
	if(is_string($border))
	{
		$x=$this->x;
		$y=$this->y;
		if(strpos($border,'L')!==false)
			$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
		if(strpos($border,'T')!==false)
			$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
		if(strpos($border,'R')!==false)
			$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
		if(strpos($border,'B')!==false)
			$s.=sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
	}
	if($txt!=='')
	{
		if($align=='R')
			$dx=$w-$this->cMargin-$this->GetStringWidth($txt);
		elseif($align=='C')
			$dx=($w-$this->GetStringWidth($txt))/2;
		else
			$dx=$this->cMargin;
		if($this->ColorFlag)
			$s.='q '.$this->TextColor.' ';
		$txt2=str_replace(')','\\)',str_replace('(','\\(',str_replace('\\','\\\\',$txt)));
		$s.=sprintf('BT %.2F %.2F Td (%s) Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$txt2);
		if($this->underline)
			$s.=' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
		if($this->ColorFlag)
			$s.=' Q';
		if($link)
			$this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$this->GetStringWidth($txt),$this->FontSize,$link);
	}
	if($s)
		$this->_out($s);
	$this->lasth=$h;
	if($ln>0)
	{
		//Go to next line
		$this->y+=$h;
		if($ln==1)
			$this->x=$this->lMargin;
	}
	else
		$this->x+=$w;
}

function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
{
	//Output text with automatic or explicit line breaks
	$cw=&$this->CurrentFont['cw'];
	if($w==0)
		$w=$this->w-$this->rMargin-$this->x;
	$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
	$s=str_replace("\r",'',$txt);
	$nb=strlen($s);
	if($nb>0 && $s[$nb-1]=="\n")
		$nb--;
	$b=0;
	if($border)
	{
		if($border==1)
		{
			$border='LTRB';
			$b='LRT';
			$b2='LR';
		}
		else
		{
			$b2='';
			if(strpos($border,'L')!==false)
				$b2.='L';
			if(strpos($border,'R')!==false)
				$b2.='R';
			$b=(strpos($border,'T')!==false) ? $b2.'T' : $b2;
		}
	}
	$sep=-1;
	$i=0;
	$j=0;
	$l=0;
	$ns=0;
	$nl=1;
	while($i<$nb)
	{
		//Get next character
		$c=$s[$i];
		if($c=="\n")
		{
			//Explicit line break
			if($this->ws>0)
			{
				$this->ws=0;
				$this->_out('0 Tw');
			}
			$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			$i++;
			$sep=-1;
			$j=$i;
			$l=0;
			$ns=0;
			$nl++;
			if($border && $nl==2)
				$b=$b2;
			continue;
		}
		if($c==' ')
		{
			$sep=$i;
			$ls=$l;
			$ns++;
		}
		$l+=$cw[$c];
		if($l>$wmax)
		{
			//Automatic line break
			if($sep==-1)
			{
				if($i==$j)
					$i++;
				if($this->ws>0)
				{
					$this->ws=0;
					$this->_out('0 Tw');
				}
				$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			}
			else
			{
				if($align=='J')
				{
					$this->ws=($ns>1) ? ($wmax-$ls)/1000*$this->FontSize/($ns-1) : 0;
					$this->_out(sprintf('%.3F Tw',$this->ws*$this->k));
				}
				$this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
				$i=$sep+1;
			}
			$sep=-1;
			$j=$i;
			$l=0;
			$ns=0;
			$nl++;
			if($border && $nl==2)
				$b=$b2;
		}
		else
			$i++;
	}
	//Last chunk
	if($this->ws>0)
	{
		$this->ws=0;
		$this->_out('0 Tw');
	}
	if($border && strpos($border,'B')!==false)
		$b.='B';
	$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
	$this->x=$this->lMargin;
}

function Write($h, $txt, $link='')
{
	//Output text in flowing mode
	$cw=&$this->CurrentFont['cw'];
	$w=$this->w-$this->rMargin-$this->x;
	$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
	$s=str_replace("\r",'',$txt);
	$nb=strlen($s);
	$sep=-1;
	$i=0;
	$j=0;
	$l=0;
	$nl=1;
	while($i<$nb)
	{
		//Get next character
		$c=$s[$i];
		if($c=="\n")
		{
			//Explicit line break
			$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
			$i++;
			$sep=-1;
			$j=$i;
			$l=0;
			if($nl==1)
			{
				$this->x=$this->lMargin;
				$w=$this->w-$this->rMargin-$this->x;
				$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
			}
			$nl++;
			continue;
		}
		if($c==' ')
			$sep=$i;
		$l+=$cw[$c];
		if($l>$wmax)
		{
			//Automatic line break
			if($sep==-1)
			{
				if($this->x>$this->lMargin)
				{
					//Move to next line
					$this->x=$this->lMargin;
					$this->y+=$h;
					$w=$this->w-$this->rMargin-$this->x;
					$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
					$i++;
					$nl++;
					continue;
				}
				if($i==$j)
					$i++;
				$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
			}
			else
			{
				$this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',0,$link);
				$i=$sep+1;
			}
			$sep=-1;
			$j=$i;
			$l=0;
			if($nl==1)
			{
				$this->x=$this->lMargin;
				$w=$this->w-$this->rMargin-$this->x;
				$wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
			}
			$nl++;
		}
		else
			$i++;
	}
	//Last chunk
	if($i!=$j)
		$this->Cell($l/1000*$this->FontSize,$h,substr($s,$j),0,0,'',0,$link);
}

function Ln($h=null)
{
	//Line feed; default value is last cell height
	$this->x=$this->lMargin;
	if($h===null)
		$this->y+=$this->lasth;
	else
		$this->y+=$h;
}

function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
{
	//Put an image on the page
	if(!isset($this->images[$file]))
	{
		//First use of this image, get info
		if($type=='')
		{
			$pos=strrpos($file,'.');
			if(!$pos)
				$this->Error('Image file has no extension and no type was specified: '.$file);
			$type=substr($file,$pos+1);
		}
		$type=strtolower($type);
		if($type=='jpeg')
			$type='jpg';
		$mtd='_parse'.$type;
		if(!method_exists($this,$mtd))
			$this->Error('Unsupported image type: '.$type);
		$info=$this->$mtd($file);
		$info['i']=count($this->images)+1;
		$this->images[$file]=$info;
	}
	else
		$info=$this->images[$file];
	//Automatic width and height calculation if needed
	if($w==0 && $h==0)
	{
		//Put image at 72 dpi
		$w=$info['w']/$this->k;
		$h=$info['h']/$this->k;
	}
	elseif($w==0)
		$w=$h*$info['w']/$info['h'];
	elseif($h==0)
		$h=$w*$info['h']/$info['w'];
	//Flowing mode
	if($y===null)
	{
		if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
		{
			//Automatic page break
			$x2=$this->x;
			$this->AddPage($this->CurOrientation,$this->CurPageFormat);
			$this->x=$x2;
		}
		$y=$this->y;
		$this->y+=$h;
	}
	if($x===null)
		$x=$this->x;
	$this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',$w*$this->k,$h*$this->k,$x*$this->k,($this->h-($y+$h))*$this->k,$info['i']));
	if($link)
		$this->Link($x,$y,$w,$h,$link);
}

function GetX()
{
	//Get x position
	return $this->x;
}

function SetX($x)
{
	//Set x position
	if($x>=0)
		$this->x=$x;
	else
		$this->x=$this->w+$x;
}

function GetY()
{
	//Get y position
	return $this->y;
}

function SetY($y)
{
	//Set y position and reset x
	$this->x=$this->lMargin;
	if($y>=0)
		$this->y=$y;
	else
		$this->y=$this->h+$y;
}

function SetXY($x, $y)
{
	//Set x and y positions
	$this->SetY($y);
	$this->SetX($x);
}

function Output($name='', $dest='')
{
	//Output PDF to some destination
	if($this->state<3)
		$this->Close();
	$dest=strtoupper($dest);
	if($dest=='')
	{
		if($name=='')
		{
			$name='doc.pdf';
			$dest='I';
		}
		else
			$dest='F';
	}
	switch($dest)
	{
		case 'I':
			//Send to standard output
			if(ob_get_length())
				$this->Error('Some data has already been output, can\'t send PDF file');
			if(php_sapi_name()!='cli')
			{
				//We send to a browser
				header('Content-Type: application/pdf');
				if(headers_sent())
					$this->Error('Some data has already been output, can\'t send PDF file');
				header('Content-Length: '.strlen($this->buffer));
				header('Content-Disposition: inline; filename="'.$name.'"');
				header('Cache-Control: private, max-age=0, must-revalidate');
				header('Pragma: public');
				ini_set('zlib.output_compression','0');
			}
			echo $this->buffer;
			break;
		case 'D':
			//Download file
			if(ob_get_length())
				$this->Error('Some data has already been output, can\'t send PDF file');
			header('Content-Type: application/x-download');
			if(headers_sent())
				$this->Error('Some data has already been output, can\'t send PDF file');
			header('Content-Length: '.strlen($this->buffer));
			header('Content-Disposition: attachment; filename="'.$name.'"');
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			ini_set('zlib.output_compression','0');
			echo $this->buffer;
			break;
		case 'F':
			//Save to local file
			$f=fopen($name,'wb');
			if(!$f)
				$this->Error('Unable to create output file: '.$name);
			fwrite($f,$this->buffer,strlen($this->buffer));
			fclose($f);
			break;
		case 'S':
			//Return as a string
			return $this->buffer;
		default:
			$this->Error('Incorrect output destination: '.$dest);
	}
	return '';
}

/*******************************************************************************
*                                                                              *
*                              Protected methods                               *
*                                                                              *
*******************************************************************************/
function _dochecks()
{
	//Check availability of %F
	if(sprintf('%.1F',1.0)!='1.0')
		$this->Error('This version of PHP is not supported');
	//Check mbstring overloading
	if(ini_get('mbstring.func_overload') & 2)
		$this->Error('mbstring overloading must be disabled');
	//Disable runtime magic quotes
	if(get_magic_quotes_runtime())
		@set_magic_quotes_runtime(0);
}

function _getpageformat($format)
{
	$format=strtolower($format);
	if(!isset($this->PageFormats[$format]))
		$this->Error('Unknown page format: '.$format);
	$a=$this->PageFormats[$format];
	return array($a[0]/$this->k, $a[1]/$this->k);
}

function _getfontpath()
{
	if(!defined('FPDF_FONTPATH') && is_dir(dirname(__FILE__).'/font'))
		define('FPDF_FONTPATH',dirname(__FILE__).'/font/');
	return defined('FPDF_FONTPATH') ? FPDF_FONTPATH : '';
}

function _beginpage($orientation, $format)
{
	$this->page++;
	$this->pages[$this->page]='';
	$this->state=2;
	$this->x=$this->lMargin;
	$this->y=$this->tMargin;
	$this->FontFamily='';
	//Check page size
	if($orientation=='')
		$orientation=$this->DefOrientation;
	else
		$orientation=strtoupper($orientation[0]);
	if($format=='')
		$format=$this->DefPageFormat;
	else
	{
		if(is_string($format))
			$format=$this->_getpageformat($format);
	}
	if($orientation!=$this->CurOrientation || $format[0]!=$this->CurPageFormat[0] || $format[1]!=$this->CurPageFormat[1])
	{
		//New size
		if($orientation=='P')
		{
			$this->w=$format[0];
			$this->h=$format[1];
		}
		else
		{
			$this->w=$format[1];
			$this->h=$format[0];
		}
		$this->wPt=$this->w*$this->k;
		$this->hPt=$this->h*$this->k;
		$this->PageBreakTrigger=$this->h-$this->bMargin;
		$this->CurOrientation=$orientation;
		$this->CurPageFormat=$format;
	}
	if($orientation!=$this->DefOrientation || $format[0]!=$this->DefPageFormat[0] || $format[1]!=$this->DefPageFormat[1])
		$this->PageSizes[$this->page]=array($this->wPt, $this->hPt);
}

function _endpage()
{
	$this->state=1;
}

function _escape($s)
{
	//Escape special characters in strings
	$s=str_replace('\\','\\\\',$s);
	$s=str_replace('(','\\(',$s);
	$s=str_replace(')','\\)',$s);
	$s=str_replace("\r",'\\r',$s);
	return $s;
}

function _textstring($s)
{
	//Format a text string
	return '('.$this->_escape($s).')';
}

function _UTF8toUTF16($s)
{
	//Convert UTF-8 to UTF-16BE with BOM
	$res="\xFE\xFF";
	$nb=strlen($s);
	$i=0;
	while($i<$nb)
	{
		$c1=ord($s[$i++]);
		if($c1>=224)
		{
			//3-byte character
			$c2=ord($s[$i++]);
			$c3=ord($s[$i++]);
			$res.=chr((($c1 & 0x0F)<<4) + (($c2 & 0x3C)>>2));
			$res.=chr((($c2 & 0x03)<<6) + ($c3 & 0x3F));
		}
		elseif($c1>=192)
		{
			//2-byte character
			$c2=ord($s[$i++]);
			$res.=chr(($c1 & 0x1C)>>2);
			$res.=chr((($c1 & 0x03)<<6) + ($c2 & 0x3F));
		}
		else
		{
			//Single-byte character
			$res.="\0".chr($c1);
		}
	}
	return $res;
}

function _dounderline($x, $y, $txt)
{
	//Underline text
	$up=$this->CurrentFont['up'];
	$ut=$this->CurrentFont['ut'];
	$w=$this->GetStringWidth($txt)+$this->ws*substr_count($txt,' ');
	return sprintf('%.2F %.2F %.2F %.2F re f',$x*$this->k,($this->h-($y-$up/1000*$this->FontSize))*$this->k,$w*$this->k,-$ut/1000*$this->FontSizePt);
}

function _parsejpg($file)
{
	//Extract info from a JPEG file
	$a=GetImageSize($file);
	if(!$a)
		$this->Error('Missing or incorrect image file: '.$file);
	if($a[2]!=2)
		$this->Error('Not a JPEG file: '.$file);
	if(!isset($a['channels']) || $a['channels']==3)
		$colspace='DeviceRGB';
	elseif($a['channels']==4)
		$colspace='DeviceCMYK';
	else
		$colspace='DeviceGray';
	$bpc=isset($a['bits']) ? $a['bits'] : 8;
	//Read whole file
	$f=fopen($file,'rb');
	$data='';
	while(!feof($f))
		$data.=fread($f,8192);
	fclose($f);
	return array('w'=>$a[0], 'h'=>$a[1], 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'DCTDecode', 'data'=>$data);
}

function _parsepng($file)
{
	//Extract info from a PNG file
	$f=fopen($file,'rb');
	if(!$f)
		$this->Error('Can\'t open image file: '.$file);
	//Check signature
	if($this->_readstream($f,8)!=chr(137).'PNG'.chr(13).chr(10).chr(26).chr(10))
		$this->Error('Not a PNG file: '.$file);
	//Read header chunk
	$this->_readstream($f,4);
	if($this->_readstream($f,4)!='IHDR')
		$this->Error('Incorrect PNG file: '.$file);
	$w=$this->_readint($f);
	$h=$this->_readint($f);
	$bpc=ord($this->_readstream($f,1));
	if($bpc>8)
		$this->Error('16-bit depth not supported: '.$file);
	$ct=ord($this->_readstream($f,1));
	if($ct==0)
		$colspace='DeviceGray';
	elseif($ct==2)
		$colspace='DeviceRGB';
	elseif($ct==3)
		$colspace='Indexed';
	else
		$this->Error('Alpha channel not supported: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Unknown compression method: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Unknown filter method: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Interlacing not supported: '.$file);
	$this->_readstream($f,4);
	$parms='/DecodeParms <</Predictor 15 /Colors '.($ct==2 ? 3 : 1).' /BitsPerComponent '.$bpc.' /Columns '.$w.'>>';
	//Scan chunks looking for palette, transparency and image data
	$pal='';
	$trns='';
	$data='';
	do
	{
		$n=$this->_readint($f);
		$type=$this->_readstream($f,4);
		if($type=='PLTE')
		{
			//Read palette
			$pal=$this->_readstream($f,$n);
			$this->_readstream($f,4);
		}
		elseif($type=='tRNS')
		{
			//Read transparency info
			$t=$this->_readstream($f,$n);
			if($ct==0)
				$trns=array(ord(substr($t,1,1)));
			elseif($ct==2)
				$trns=array(ord(substr($t,1,1)), ord(substr($t,3,1)), ord(substr($t,5,1)));
			else
			{
				$pos=strpos($t,chr(0));
				if($pos!==false)
					$trns=array($pos);
			}
			$this->_readstream($f,4);
		}
		elseif($type=='IDAT')
		{
			//Read image data block
			$data.=$this->_readstream($f,$n);
			$this->_readstream($f,4);
		}
		elseif($type=='IEND')
			break;
		else
			$this->_readstream($f,$n+4);
	}
	while($n);
	if($colspace=='Indexed' && empty($pal))
		$this->Error('Missing palette in '.$file);
	fclose($f);
	return array('w'=>$w, 'h'=>$h, 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'FlateDecode', 'parms'=>$parms, 'pal'=>$pal, 'trns'=>$trns, 'data'=>$data);
}

function _readstream($f, $n)
{
	//Read n bytes from stream
	$res='';
	while($n>0 && !feof($f))
	{
		$s=fread($f,$n);
		if($s===false)
			$this->Error('Error while reading stream');
		$n-=strlen($s);
		$res.=$s;
	}
	if($n>0)
		$this->Error('Unexpected end of stream');
	return $res;
}

function _readint($f)
{
	//Read a 4-byte integer from stream
	$a=unpack('Ni',$this->_readstream($f,4));
	return $a['i'];
}

function _parsegif($file)
{
	//Extract info from a GIF file (via PNG conversion)
	if(!function_exists('imagepng'))
		$this->Error('GD extension is required for GIF support');
	if(!function_exists('imagecreatefromgif'))
		$this->Error('GD has no GIF read support');
	$im=imagecreatefromgif($file);
	if(!$im)
		$this->Error('Missing or incorrect image file: '.$file);
	imageinterlace($im,0);
	$tmp=tempnam('.','gif');
	if(!$tmp)
		$this->Error('Unable to create a temporary file');
	if(!imagepng($im,$tmp))
		$this->Error('Error while saving to temporary file');
	imagedestroy($im);
	$info=$this->_parsepng($tmp);
	unlink($tmp);
	return $info;
}

function _newobj()
{
	//Begin a new object
	$this->n++;
	$this->offsets[$this->n]=strlen($this->buffer);
	$this->_out($this->n.' 0 obj');
}

function _putstream($s)
{
	$this->_out('stream');
	$this->_out($s);
	$this->_out('endstream');
}

function _out($s)
{
	//Add a line to the document
	if($this->state==2)
		$this->pages[$this->page].=$s."\n";
	else
		$this->buffer.=$s."\n";
}

function _putpages()
{
	$nb=$this->page;
	if(!empty($this->AliasNbPages))
	{
		//Replace number of pages
		for($n=1;$n<=$nb;$n++)
			$this->pages[$n]=str_replace($this->AliasNbPages,$nb,$this->pages[$n]);
	}
	if($this->DefOrientation=='P')
	{
		$wPt=$this->DefPageFormat[0]*$this->k;
		$hPt=$this->DefPageFormat[1]*$this->k;
	}
	else
	{
		$wPt=$this->DefPageFormat[1]*$this->k;
		$hPt=$this->DefPageFormat[0]*$this->k;
	}
	$filter=($this->compress) ? '/Filter /FlateDecode ' : '';
	for($n=1;$n<=$nb;$n++)
	{
		//Page
		$this->_newobj();
		$this->_out('<</Type /Page');
		$this->_out('/Parent 1 0 R');
		if(isset($this->PageSizes[$n]))
			$this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]',$this->PageSizes[$n][0],$this->PageSizes[$n][1]));
		$this->_out('/Resources 2 0 R');
		if(isset($this->PageLinks[$n]))
		{
			//Links
			$annots='/Annots [';
			foreach($this->PageLinks[$n] as $pl)
			{
				$rect=sprintf('%.2F %.2F %.2F %.2F',$pl[0],$pl[1],$pl[0]+$pl[2],$pl[1]-$pl[3]);
				$annots.='<</Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] ';
				if(is_string($pl[4]))
					$annots.='/A <</S /URI /URI '.$this->_textstring($pl[4]).'>>>>';
				else
				{
					$l=$this->links[$pl[4]];
					$h=isset($this->PageSizes[$l[0]]) ? $this->PageSizes[$l[0]][1] : $hPt;
					$annots.=sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>',1+2*$l[0],$h-$l[1]*$this->k);
				}
			}
			$this->_out($annots.']');
		}
		$this->_out('/Contents '.($this->n+1).' 0 R>>');
		$this->_out('endobj');
		//Page content
		$p=($this->compress) ? gzcompress($this->pages[$n]) : $this->pages[$n];
		$this->_newobj();
		$this->_out('<<'.$filter.'/Length '.strlen($p).'>>');
		$this->_putstream($p);
		$this->_out('endobj');
	}
	//Pages root
	$this->offsets[1]=strlen($this->buffer);
	$this->_out('1 0 obj');
	$this->_out('<</Type /Pages');
	$kids='/Kids [';
	for($i=0;$i<$nb;$i++)
		$kids.=(3+2*$i).' 0 R ';
	$this->_out($kids.']');
	$this->_out('/Count '.$nb);
	$this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]',$wPt,$hPt));
	$this->_out('>>');
	$this->_out('endobj');
}

function _putextgstates()
{
    for ($i = 1; $i <= count($this->extgstates); $i++)
    {
        $this->_newobj();
        $this->extgstates[$i]['n'] = $this->n;
        $this->_out('<</Type /ExtGState');
        foreach ($this->extgstates[$i]['parms'] as $k=>$v)
            $this->_out('/'.$k.' '.$v);
        $this->_out('>>');
        $this->_out('endobj');
    }
}

function _putfonts()
{
	$nf=$this->n;
	foreach($this->diffs as $diff)
	{
		//Encodings
		$this->_newobj();
		$this->_out('<</Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['.$diff.']>>');
		$this->_out('endobj');
	}
	foreach($this->FontFiles as $file=>$info)
	{
		//Font file embedding
		$this->_newobj();
		$this->FontFiles[$file]['n']=$this->n;
		$font='';
		$f=fopen($this->_getfontpath().$file,'rb',1);
		if(!$f)
			$this->Error('Font file not found');
		while(!feof($f))
			$font.=fread($f,8192);
		fclose($f);
		$compressed=(substr($file,-2)=='.z');
		if(!$compressed && isset($info['length2']))
		{
			$header=(ord($font[0])==128);
			if($header)
			{
				//Strip first binary header
				$font=substr($font,6);
			}
			if($header && ord($font[$info['length1']])==128)
			{
				//Strip second binary header
				$font=substr($font,0,$info['length1']).substr($font,$info['length1']+6);
			}
		}
		$this->_out('<</Length '.strlen($font));
		if($compressed)
			$this->_out('/Filter /FlateDecode');
		$this->_out('/Length1 '.$info['length1']);
		if(isset($info['length2']))
			$this->_out('/Length2 '.$info['length2'].' /Length3 0');
		$this->_out('>>');
		$this->_putstream($font);
		$this->_out('endobj');
	}
	foreach($this->fonts as $k=>$font)
	{
		//Font objects
		$this->fonts[$k]['n']=$this->n+1;
		$type=$font['type'];
		$name=$font['name'];
		if($type=='core')
		{
			//Standard font
			$this->_newobj();
			$this->_out('<</Type /Font');
			$this->_out('/BaseFont /'.$name);
			$this->_out('/Subtype /Type1');
			if($name!='Symbol' && $name!='ZapfDingbats')
				$this->_out('/Encoding /WinAnsiEncoding');
			$this->_out('>>');
			$this->_out('endobj');
		}
		elseif($type=='Type1' || $type=='TrueType')
		{
			//Additional Type1 or TrueType font
			$this->_newobj();
			$this->_out('<</Type /Font');
			$this->_out('/BaseFont /'.$name);
			$this->_out('/Subtype /'.$type);
			$this->_out('/FirstChar 32 /LastChar 255');
			$this->_out('/Widths '.($this->n+1).' 0 R');
			$this->_out('/FontDescriptor '.($this->n+2).' 0 R');
			if($font['enc'])
			{
				if(isset($font['diff']))
					$this->_out('/Encoding '.($nf+$font['diff']).' 0 R');
				else
					$this->_out('/Encoding /WinAnsiEncoding');
			}
			$this->_out('>>');
			$this->_out('endobj');
			//Widths
			$this->_newobj();
			$cw=&$font['cw'];
			$s='[';
			for($i=32;$i<=255;$i++)
				$s.=$cw[chr($i)].' ';
			$this->_out($s.']');
			$this->_out('endobj');
			//Descriptor
			$this->_newobj();
			$s='<</Type /FontDescriptor /FontName /'.$name;
			foreach($font['desc'] as $k=>$v)
				$s.=' /'.$k.' '.$v;
			$file=$font['file'];
			if($file)
				$s.=' /FontFile'.($type=='Type1' ? '' : '2').' '.$this->FontFiles[$file]['n'].' 0 R';
			$this->_out($s.'>>');
			$this->_out('endobj');
		}
		else
		{
			//Allow for additional types
			$mtd='_put'.strtolower($type);
			if(!method_exists($this,$mtd))
				$this->Error('Unsupported font type: '.$type);
			$this->$mtd($font);
		}
	}
}

function _putimages()
{
	$filter=($this->compress) ? '/Filter /FlateDecode ' : '';
	reset($this->images);
	while(list($file,$info)=each($this->images))
	{
		$this->_newobj();
		$this->images[$file]['n']=$this->n;
		$this->_out('<</Type /XObject');
		$this->_out('/Subtype /Image');
		$this->_out('/Width '.$info['w']);
		$this->_out('/Height '.$info['h']);
		if($info['cs']=='Indexed')
			$this->_out('/ColorSpace [/Indexed /DeviceRGB '.(strlen($info['pal'])/3-1).' '.($this->n+1).' 0 R]');
		else
		{
			$this->_out('/ColorSpace /'.$info['cs']);
			if($info['cs']=='DeviceCMYK')
				$this->_out('/Decode [1 0 1 0 1 0 1 0]');
		}
		$this->_out('/BitsPerComponent '.$info['bpc']);
		if(isset($info['f']))
			$this->_out('/Filter /'.$info['f']);
		if(isset($info['parms']))
			$this->_out($info['parms']);
		if(isset($info['trns']) && is_array($info['trns']))
		{
			$trns='';
			for($i=0;$i<count($info['trns']);$i++)
				$trns.=$info['trns'][$i].' '.$info['trns'][$i].' ';
			$this->_out('/Mask ['.$trns.']');
		}
		$this->_out('/Length '.strlen($info['data']).'>>');
		$this->_putstream($info['data']);
		unset($this->images[$file]['data']);
		$this->_out('endobj');
		//Palette
		if($info['cs']=='Indexed')
		{
			$this->_newobj();
			$pal=($this->compress) ? gzcompress($info['pal']) : $info['pal'];
			$this->_out('<<'.$filter.'/Length '.strlen($pal).'>>');
			$this->_putstream($pal);
			$this->_out('endobj');
		}
	}
}

function _putxobjectdict()
{
	foreach($this->images as $image)
		$this->_out('/I'.$image['i'].' '.$image['n'].' 0 R');
}

function _putresourcedict()
{
	$this->_out('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
	$this->_out('/Font <<');
	foreach($this->fonts as $font)
		$this->_out('/F'.$font['i'].' '.$font['n'].' 0 R');
	$this->_out('>>');
	$this->_out('/XObject <<');
	$this->_putxobjectdict();
	$this->_out('>>');
        $this->_out('/ExtGState <<');
        foreach($this->extgstates as $k=>$extgstate)
            $this->_out('/GS'.$k.' '.$extgstate['n'].' 0 R');
        $this->_out('>>');
}

function _putresources()
{
        $this->_putextgstates();
	$this->_putfonts();
	$this->_putimages();
	//Resource dictionary
	$this->offsets[2]=strlen($this->buffer);
	$this->_out('2 0 obj');
	$this->_out('<<');
	$this->_putresourcedict();
	$this->_out('>>');
	$this->_out('endobj');
}

function _putinfo()
{
	$this->_out('/Producer '.$this->_textstring('FPDF '.FPDF_VERSION));
	if(!empty($this->title))
		$this->_out('/Title '.$this->_textstring($this->title));
	if(!empty($this->subject))
		$this->_out('/Subject '.$this->_textstring($this->subject));
	if(!empty($this->author))
		$this->_out('/Author '.$this->_textstring($this->author));
	if(!empty($this->keywords))
		$this->_out('/Keywords '.$this->_textstring($this->keywords));
	if(!empty($this->creator))
		$this->_out('/Creator '.$this->_textstring($this->creator));
	$this->_out('/CreationDate '.$this->_textstring('D:'.@date('YmdHis')));
}

function _putcatalog()
{
	$this->_out('/Type /Catalog');
	$this->_out('/Pages 1 0 R');
	if($this->ZoomMode=='fullpage')
		$this->_out('/OpenAction [3 0 R /Fit]');
	elseif($this->ZoomMode=='fullwidth')
		$this->_out('/OpenAction [3 0 R /FitH null]');
	elseif($this->ZoomMode=='real')
		$this->_out('/OpenAction [3 0 R /XYZ null null 1]');
	elseif(!is_string($this->ZoomMode))
		$this->_out('/OpenAction [3 0 R /XYZ null null '.($this->ZoomMode/100).']');
	if($this->LayoutMode=='single')
		$this->_out('/PageLayout /SinglePage');
	elseif($this->LayoutMode=='continuous')
		$this->_out('/PageLayout /OneColumn');
	elseif($this->LayoutMode=='two')
		$this->_out('/PageLayout /TwoColumnLeft');
}

function _putheader()
{
	$this->_out('%PDF-'.$this->PDFVersion);
}

function _puttrailer()
{
	$this->_out('/Size '.($this->n+1));
	$this->_out('/Root '.$this->n.' 0 R');
	$this->_out('/Info '.($this->n-1).' 0 R');
}

function _enddoc()
{
        if(!empty($this->extgstates) && $this->PDFVersion<'1.4')
            $this->PDFVersion='1.4';
	$this->_putheader();
	$this->_putpages();
	$this->_putresources();
	//Info
	$this->_newobj();
	$this->_out('<<');
	$this->_putinfo();
	$this->_out('>>');
	$this->_out('endobj');
	//Catalog
	$this->_newobj();
	$this->_out('<<');
	$this->_putcatalog();
	$this->_out('>>');
	$this->_out('endobj');
	//Cross-ref
	$o=strlen($this->buffer);
	$this->_out('xref');
	$this->_out('0 '.($this->n+1));
	$this->_out('0000000000 65535 f ');
	for($i=1;$i<=$this->n;$i++)
		$this->_out(sprintf('%010d 00000 n ',$this->offsets[$i]));
	//Trailer
	$this->_out('trailer');
	$this->_out('<<');
	$this->_puttrailer();
	$this->_out('>>');
	$this->_out('startxref');
	$this->_out($o);
	$this->_out('%%EOF');
	$this->state=3;
}

// alpha: real value from 0 (transparent) to 1 (opaque)
// bm:    blend mode, one of the following:
//          Normal, Multiply, Screen, Overlay, Darken, Lighten, ColorDodge, ColorBurn,
//          HardLight, SoftLight, Difference, Exclusion, Hue, Saturation, Color, Luminosity
function SetAlpha($alpha, $bm='Normal')
{
    // set alpha for stroking (CA) and non-stroking (ca) operations
    $gs = $this->AddExtGState(array('ca'=>$alpha, 'CA'=>$alpha, 'BM'=>'/'.$bm));
    $this->SetExtGState($gs);
}

function AddExtGState($parms)
{
    $n = count($this->extgstates)+1;
    $this->extgstates[$n]['parms'] = $parms;
    return $n;
}

function SetExtGState($gs)
{
    $this->_out(sprintf('/GS%d gs', $gs));
}

//End of class
}

//Handle special IE contype request
if(isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT']=='contype')
{
	header('Content-Type: application/pdf');
	exit;
}

$fpdf_charwidths['helvetica']=array(
	chr(0)=>278,chr(1)=>278,chr(2)=>278,chr(3)=>278,chr(4)=>278,chr(5)=>278,chr(6)=>278,chr(7)=>278,chr(8)=>278,chr(9)=>278,chr(10)=>278,chr(11)=>278,chr(12)=>278,chr(13)=>278,chr(14)=>278,chr(15)=>278,chr(16)=>278,chr(17)=>278,chr(18)=>278,chr(19)=>278,chr(20)=>278,chr(21)=>278,
	chr(22)=>278,chr(23)=>278,chr(24)=>278,chr(25)=>278,chr(26)=>278,chr(27)=>278,chr(28)=>278,chr(29)=>278,chr(30)=>278,chr(31)=>278,' '=>278,'!'=>278,'"'=>355,'#'=>556,'$'=>556,'%'=>889,'&'=>667,'\''=>191,'('=>333,')'=>333,'*'=>389,'+'=>584,
	','=>278,'-'=>333,'.'=>278,'/'=>278,'0'=>556,'1'=>556,'2'=>556,'3'=>556,'4'=>556,'5'=>556,'6'=>556,'7'=>556,'8'=>556,'9'=>556,':'=>278,';'=>278,'<'=>584,'='=>584,'>'=>584,'?'=>556,'@'=>1015,'A'=>667,
	'B'=>667,'C'=>722,'D'=>722,'E'=>667,'F'=>611,'G'=>778,'H'=>722,'I'=>278,'J'=>500,'K'=>667,'L'=>556,'M'=>833,'N'=>722,'O'=>778,'P'=>667,'Q'=>778,'R'=>722,'S'=>667,'T'=>611,'U'=>722,'V'=>667,'W'=>944,
	'X'=>667,'Y'=>667,'Z'=>611,'['=>278,'\\'=>278,']'=>278,'^'=>469,'_'=>556,'`'=>333,'a'=>556,'b'=>556,'c'=>500,'d'=>556,'e'=>556,'f'=>278,'g'=>556,'h'=>556,'i'=>222,'j'=>222,'k'=>500,'l'=>222,'m'=>833,
	'n'=>556,'o'=>556,'p'=>556,'q'=>556,'r'=>333,'s'=>500,'t'=>278,'u'=>556,'v'=>500,'w'=>722,'x'=>500,'y'=>500,'z'=>500,'{'=>334,'|'=>260,'}'=>334,'~'=>584,chr(127)=>350,chr(128)=>556,chr(129)=>350,chr(130)=>222,chr(131)=>556,
	chr(132)=>333,chr(133)=>1000,chr(134)=>556,chr(135)=>556,chr(136)=>333,chr(137)=>1000,chr(138)=>667,chr(139)=>333,chr(140)=>1000,chr(141)=>350,chr(142)=>611,chr(143)=>350,chr(144)=>350,chr(145)=>222,chr(146)=>222,chr(147)=>333,chr(148)=>333,chr(149)=>350,chr(150)=>556,chr(151)=>1000,chr(152)=>333,chr(153)=>1000,
	chr(154)=>500,chr(155)=>333,chr(156)=>944,chr(157)=>350,chr(158)=>500,chr(159)=>667,chr(160)=>278,chr(161)=>333,chr(162)=>556,chr(163)=>556,chr(164)=>556,chr(165)=>556,chr(166)=>260,chr(167)=>556,chr(168)=>333,chr(169)=>737,chr(170)=>370,chr(171)=>556,chr(172)=>584,chr(173)=>333,chr(174)=>737,chr(175)=>333,
	chr(176)=>400,chr(177)=>584,chr(178)=>333,chr(179)=>333,chr(180)=>333,chr(181)=>556,chr(182)=>537,chr(183)=>278,chr(184)=>333,chr(185)=>333,chr(186)=>365,chr(187)=>556,chr(188)=>834,chr(189)=>834,chr(190)=>834,chr(191)=>611,chr(192)=>667,chr(193)=>667,chr(194)=>667,chr(195)=>667,chr(196)=>667,chr(197)=>667,
	chr(198)=>1000,chr(199)=>722,chr(200)=>667,chr(201)=>667,chr(202)=>667,chr(203)=>667,chr(204)=>278,chr(205)=>278,chr(206)=>278,chr(207)=>278,chr(208)=>722,chr(209)=>722,chr(210)=>778,chr(211)=>778,chr(212)=>778,chr(213)=>778,chr(214)=>778,chr(215)=>584,chr(216)=>778,chr(217)=>722,chr(218)=>722,chr(219)=>722,
	chr(220)=>722,chr(221)=>667,chr(222)=>667,chr(223)=>611,chr(224)=>556,chr(225)=>556,chr(226)=>556,chr(227)=>556,chr(228)=>556,chr(229)=>556,chr(230)=>889,chr(231)=>500,chr(232)=>556,chr(233)=>556,chr(234)=>556,chr(235)=>556,chr(236)=>278,chr(237)=>278,chr(238)=>278,chr(239)=>278,chr(240)=>556,chr(241)=>556,
	chr(242)=>556,chr(243)=>556,chr(244)=>556,chr(245)=>556,chr(246)=>556,chr(247)=>584,chr(248)=>611,chr(249)=>556,chr(250)=>556,chr(251)=>556,chr(252)=>556,chr(253)=>500,chr(254)=>556,chr(255)=>500);
?>
<?php
// $Id: file_access.php 128 2010-12-01 16:12:04Z s242720-studenti $

  /*
   * Check for the existence of necessary server files. If they don't
   * exist, try to create them. If the create attempt fails, return
   * false. Some files are created here, while some others
   * (permissions, passwords) are created when accessed the first
   * time, to be more robust to administrator's deletions (passwords
   * may be deleted to be reset, permissions to be generated again in
   * the default form)
   */
function check_files()
{
    global $lock_file, $wb_dir;
    // The lock file is used as a signal that server files have
    // been built (this function has been already successfully
    // executed)
    if (file_exists($lock_file))
        return true;
    else
        // create files (suppress exceptions but consider the return state
        return touch($lock_file) && mkdir($wb_dir, 0700);
}

  /*
   * Action can be one of: 'c' (create), 'a' (access), 'd' (delete),
   * or unspecified (if the action is not specified, we just care for
   * the existence of this user pattern with any permission)
   */
function check_permissions($user, $whiteboard='', $action='')
{
    global $permission_file;
    // If the permission file doesn't exists, create the default
    // one. If it can't be created (directory not writable, for
    // example) deny every permission to everyone
    if (!file_exists($permission_file)) {
        try {
            file_put_contents($permission_file, ".* .* acd\n");
        } catch(Exception $e){
            return false;
        }
    }
    $h = fopen($permission_file, 'r');
    while (($rule = fgetcsv($h, 50, ' ')) !== false) {
        if (!ereg($rule[0], $user))
            continue;
        // If the action is not specified, we just care for the existence
        // of this user pattern
        if ($action == '')
            return true;
        if (!ereg($rule[1], $whiteboard))
            continue;
        if (strstr($rule[2], $action))
            return true;
    }
    return false;
}

function file_create($filename, $data)
{
    // Global lock
    global $lock_file;
    $global_lock = fopen($lock_file, 'r');
    flock($global_lock, LOCK_EX);

    // Operate on the local lock
    $local_lock_name = $filename.'-lock';
    if (file_exists($local_lock_name)) {
        // If the local lock exists, also the file exists, so there's
        // nothing to create
        $return = false;
    } else {
        touch($local_lock_name);

        // Local lock
        $local_lock = fopen($local_lock_name, 'r');
        flock($local_lock, LOCK_EX);

        // Operate on file
        touch($filename);
        $file = fopen($filename, 'rb+');
        fwrite($file, base64_encode(json_encode($data)));
        fclose($file);

        // Local unlock
        flock($local_lock, LOCK_UN);
        fclose($local_lock);
        $return = true;
    }

    // Global unlock
    flock($global_lock, LOCK_UN);
    fclose($global_lock);
    return $return;
}

  /*
   * Delete a protected file and return its contents in one atomical
   * operation
   */
function file_delete($filename)
{
    // Global lock
    global $lock_file;
    $global_lock = fopen($lock_file, 'r');
    flock($global_lock, LOCK_EX);

    // Operate on the local lock
    $local_lock_name = $filename.'-lock';
    if (!file_exists($local_lock_name)) {
        $return = false;
    } else {

        // Local lock
        $local_lock = fopen($local_lock_name, 'r');
        flock($local_lock, LOCK_EX);

        // Operate on file (read and delete)
        $return = json_decode(base64_decode(file_get_contents($filename)), true);
        unlink($filename);
        if (file_exists($filename.'-debug'))
            unlink($filename.'-debug');

        // Local unlock
        flock($local_lock, LOCK_UN);
        fclose($local_lock);

        // Operate on the local lock
        unlink($local_lock_name);
    }
    
    // Global unlock
    flock($global_lock, LOCK_UN);
    fclose($global_lock);

    return $return;
}

  /*
   * Create the whiteboard database file and the corresponding directory
   * with an atomical operation (with respect to the whiteboard local
   * lock)
   */
function whiteboard_create($wb, $data)
{
    global $wb_dir, $local_img_dir;
    $filename = $wb_dir.$wb;

    // Global lock
    global $lock_file;
    $global_lock = fopen($lock_file, 'r');
    flock($global_lock, LOCK_EX);

    // Operate on the local lock
    $local_lock_name = $filename.'-lock';
    if (file_exists($local_lock_name)) {
        // If the local lock exists, also the file exists, so there's
        // nothing to create
        $return = false;
    } else {
        touch($local_lock_name);

        // Local lock
        $local_lock = fopen($local_lock_name, 'r');
        flock($local_lock, LOCK_EX);

        // Operate on file
        touch($filename);
        $file = fopen($filename, 'rb+');
        fwrite($file, base64_encode(json_encode($data)));
        fclose($file);
	mkdir($local_img_dir.$wb);

        // Local unlock
        flock($local_lock, LOCK_UN);
        fclose($local_lock);
        $return = true;
    }

    // Global unlock
    flock($global_lock, LOCK_UN);
    fclose($global_lock);
    return $return;
}

  /*
   * Delete a whiteboard and all its imported images
   */
function whiteboard_delete($wb)
{
    global $wb_dir, $local_img_dir;
    $filename = $wb_dir.$wb;

    // Global lock
    global $lock_file;
    $global_lock = fopen($lock_file, 'r');
    flock($global_lock, LOCK_EX);

    // Operate on the local lock
    $local_lock_name = $filename.'-lock';
    if (!file_exists($local_lock_name)) {
        $return = false;
    } else {

        // Local lock
        $local_lock = fopen($local_lock_name, 'r');
        flock($local_lock, LOCK_EX);

        // Operate on file - delete image directory
        $image_dir = $local_img_dir.$wb.'/';
        $h = opendir($image_dir);
        if ($h) {
            while ( ($image = readdir($h)) !== false ) {
                // To exclude . and ..
                if (is_file($image_dir.$image))
                    unlink($image_dir.$image);
            }
        }
        closedir($h);
        rmdir($image_dir);
        // Operate on file - delete database file
        unlink($filename);
        if (file_exists($filename.'-debug'))
            unlink($filename.'-debug');

        // Local unlock
        flock($local_lock, LOCK_UN);
        fclose($local_lock);

        // Operate on the local lock
        unlink($local_lock_name);
        $return = true;
    }
    
    // Global unlock
    flock($global_lock, LOCK_UN);
    fclose($global_lock);

    return $return;
}

  /*
   * Mode can be 'r' or 'rw'. 'r' is loaded and released immediately,
   * 'rw' implies an exclusive lock to be released with file_put, so
   * with 'rw' mode the functions returns an array to close the file,
   * that it's like a reminder for file_get users
   */
function file_get($filename, $mode)
{
    // Global lock
    global $debug, $lock_file;
    $global_lock = fopen($lock_file, 'r');
    flock($global_lock, LOCK_EX);

    // Operate on the local lock
    $local_lock_name = $filename.'-lock';
    if (!file_exists($local_lock_name)) {
        // If the local lock doesn't exists, also the file doesn't
        // exists, so its content can't be get. Build dummy return
        // values and return
        $local_lock = '';
        $filename   = '';
        $var = false;

        // Global unlock
        flock($global_lock, LOCK_UN);
        fclose($global_lock);
    } else {
        // Local lock (unlock into file_put for 'rw' mode)
        $lockmode = array('r'=>LOCK_SH, 'rw'=>LOCK_EX);
        $local_lock = fopen($local_lock_name, 'r');
        flock($local_lock, $lockmode[$mode]);

        // Global unlock (now that the local lock has been taken by
        // file_get, the lock file and the get file can't be deleted
        // by delete_file until we release the local lock.
        flock($global_lock, LOCK_UN);
        fclose($global_lock);

        // Operate on file
        $var = json_decode(base64_decode(file_get_contents($filename)), true);
        if ($debug) {
            // This is just for debug: write the read variable into a human
            // readable file
            file_put_contents($filename.'-debug',
			"This data can be obsolete (mode=" .
			$mode . ") \n" . var_export($var, true));
	}
    }
    if ($mode=='r')
        return $var;
    else
        return array(array($local_lock, $filename), $var);
}

  /*
   * Close a locked file. Also update its content only if the content is
   * specified into the 'var' parameter
   */
function file_put($file_data, $var='')
{
    // Operate on file
    // local_lock is an handler, filename is a string
    list($local_lock, $filename) = $file_data;
    global $lock_file;
    if($var!=''){
        $file = fopen($filename, 'rb+');
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, base64_encode(json_encode($var)));
        fclose($file);
    }

    // Local unlock
    flock($local_lock, LOCK_UN);
    fclose($local_lock);
}

?>
