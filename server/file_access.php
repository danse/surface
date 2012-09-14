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
