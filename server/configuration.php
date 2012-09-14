<?php

/****************************************************************
 * Please refer to the administrator guide for help
 * on the meaning of configuration variables
 ****************************************************************/

// Activate the global server debug. This writes a readable copy of
// database and password files
$debug = false;
// Activate the server_log function to write server errors on the
// $log_file
$activate_log = true;

/**************** Paths ***************
 * directory names are always ended with '/'
 */

// Relative and absolute data roots
$rel_root = 'data/';
$abs_root = $_SERVER['DOCUMENT_ROOT'].'/'.$rel_root;

// private files or directories
$private_path    = $abs_root.'private/';
$wb_dir          = $private_path.'whiteboards/';
$permission_file = $private_path.'permissions';
$passw_file      = $private_path.'passwords';
$log_file        = $private_path.'log';
$lock_file       = $private_path.'lock';

// public directory for imported images (change roots to unbind the
// public folders from the private ones)
$rel_img_root    = $rel_root;
$abs_img_root    = $abs_root;
$image_dir       = 'imported/';
$local_img_dir    = $abs_img_root.$image_dir;
$public_img_dir   = $rel_img_root.$image_dir;

/**************** Timeouts ****************
 * time values are expressed in milliseconds
 */

// This is the time waited on the client side code for each ajax
// request. After this time has elapsed, a new request will be sent.
$client_ajax_timeout = 5000;
// This is the timeout for the 'read' mode on the client side. It is
// larger than others ajax timeouts, and it must be larger than the
// server-side timeout for the 'read' mode
$client_update_timeout = 15000;
// Server-side timeout for the 'read' mode
$server_update_timeout = 6000;
// Frequency of polling for the 'read' mode (see the administrator
// guide or the function 'read', file updates.php)
$server_update_retry = 30;

/**************** Layout ****************/

// Layout parameters used into page_write.php. Not all values can be
// changed here, many sizes also depend on css settings
$layout = array('width'  => 800,     // default width
                'height' => 600,     // default height
                'side_w' => 250,     // default right column width
                'margin' => 10,      // a reference margin value between boxes, for several uses
                'm_w'    => 90,      // whiteboard menu width
                // The pixels to subtract from the extern frame sizes
                // to get the sizes of its content
                'frame_narrow_width' => 22,
                'frame_narrow_height'=> 102);

?>
