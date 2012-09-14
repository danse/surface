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
