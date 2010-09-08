<?php

// $Id: mm_ftp.inc,v 1.1.2.2 2010/02/23 05:18:01 arthuregg Exp $;

/**
 * @file
 * Provides FTP wrapper functionality for FTP operations
 */


/**
 * Helper function to get a list of files from a given directory.
 * By default this will
 * @param $ftp_connection
 * @param $directory
 * @return array
 */
function mm_ftp_get_file_list($ftp_connection, $directory, $recursive = TRUE) {
  $files = array();

  // Get a list of files for the specified directory
  $file_list = ftp_rawlist($ftp_connection, $directory, $recusive);

  // make sure we have files being handed back
  if (! count($file_list)) {
    return;
  }

  $files = array();
  foreach ($file_list as $file_item) {
    $file = mm_ftp_parse_single_file($file_item);
    $files[$file['filepath']] = $file;
  }
  return $files;
}

/**
 * Takes an FTP file result and returns an array of data. This
 * is super ugly, but it is largely the fault of FTP for making
 * this such a pain to deal with
 * @param $file_item
 *   string, the FTP file string from rawlist
 * @param $parrent_directory
 *   string, parent directory
 * @param $file_types
 *   array, filter for these filetypes
 * @return array
 */
function mm_ftp_parse_single_file($file_item, $parent_directory) {
  // This regex will pull data out of a file listing as specified by STDOUT
  // <directory/link/file><file mode> <number of links> <owner> <group> <bytes> <time> <filename>
  $regex = "/^(.)([rwx-]*)\s*([0-9]*)\s*([^\s]*)\s*(.*?)\s*([0-9]*?)\s([a-zA-Z]{3}\s[0-9]{2}\s..:..)\s(.*)$/";
  preg_match($regex, $file_item, $matches);

  switch ($matches[1]) {
    case '-':
      $type = 'file';
    break;
    case 'd':
      $type = 'directory';
    break;
    case 'l':
      $type = 'link';
    break;
  }

  $file = array(
    'type' => $type,
    'filepath' => $parent_directory . '/' . $matches[9],
    'owner' => $maches[4],
    'size' => $matches[7],
    'created' => strtotime($matches[8])
  );
  return $file;
}


/**
 * Handles the file retrevial from the FTP server. Will test file
 * size against incoming file data to determine if the file has
 * changed size which would indicate that it is being uploaded. Harvest
 * file should be in this format:
 *
 * array(
 *   'type' => file,
 *   'filepath' => DIR/FILENAME.EXT,
 *   'owner' => OWNER,
 *   'size' => BYTES,
 *   'created' => UNIXTIME
 * );
 *
 * @param $ftp_connection
 * @param $step
 * @param $harvest_file
 * @return array
 */
function mmm_ftp_get_file($ftp_connection, $step, $harvest_file) {
  // Create a Drupal path for the file
  $local_file_path = file_create_filename(basename($harvest_file['filepath']),  media_mover_api_dir_path($step->sid));

  // We need to check the server's file size to see if there has
  // been a change in size. This would indicate that the file is
  // still being uploaded
  $file_list = mm_ftp_get_file_list($ftp_connection, dirname($harvest_file['filepath'], FALSE));
  if (! $file_list[$harvest_file['filepath']]['size'] != $harvest_file['size']) {
    return FALSE;
  }

  // Download the file via FTP
  if (! ftp_get($ftp_connection, $local_file_path, $ftp_file_path, FTP_BINARY)) {
    $message = 'FTP file harvest failed to download file: !file';
    $params = array('!file' => $local_file_path);
    watchdog('mm_ftp', $message, $params, WATCHDOG_ERROR);
    return FALSE;
  }

  // No file should be allowed to executed by the webserver
  chmod($local_file_path, 0600);

  // @TODO we may want to lock this file down more, not sure. This is a potential
  //       security risk because we don't really know what the file is that has been
  //       downloaded. We can use the mimedetect library to help us determine what kind
  //       of file it is and remove it if we think it's dangerous.

  // Get the mime value of the file we downloaded
  $mime = file_get_mimetype($local_file_path);
  $allowed_mimes = strtolower($step->settings['mime_types']);
  if (strpos($allowed_mimes, $mime) ===  FALSE) {
    // Log the error
    watchdog('mm_ftp', 'Harvested an illegal MIME type: !mime for !file', array('!mime' => $mime, '!file' => $local_file_path), WATCHDOG_ERROR);
    // Delete the LOCAL file for failing the mime test
    file_delete($local_file_path);
    return FALSE;
  }

  // Construct the file
  $item['filemime'] = $mime;
  $item['filesize'] = filesize($local_file_path);
  $item['filename'] = basename($local_file_path);
  $item['source_filepath'] =  $local_file_path;
  $item['filepath'] = $local_file_path;
  $item['node']->title = $local_file;
  return $item;
}

/**
 * Puts a file on the remote server
 * @param $ftp_connection
 * @param $local_filepath
 * @param $remote_filepath
 * @return boolean
 */
function mm_ftp_put_file($ftp_connection, $local_filepath, $remote_filepath) {
  if (! file_exists($local_filepath)) {
    watchdog('mm_ftp', 'Putting file !file to remote server failed: file does not exist', array('!file' => $local_filepath), WATCHDOG_ERROR);
    return FALSE;
  }

   // Open the file that is being moved
  $put_file = fopen($local_filepath, 'r');

  // put this file on the ftp server
  if (! ftp_fput($ftp_connection, $remote_filepath, $put_file, FTP_BINARY)) {
    watchdog(MM_FTP_WATCHDOG_TYPE, 'Failed to put file !file on remote server', array('!file' => $local_filepath), WATCHDOG_ERROR);
    return FALSE;
  }

  // Close the local file
  fclose($put_file);

  // what kind of file path are we returning?
  if ($configuration['mm_ftp_url'] == '') {
    return TRUE;
  }
  else {
    return $configuration['mm_ftp_url'] . $file_ftp_path;
  }
}


/**
 * Deletes a file from the FTP server
 * @param $ftp_connection
 * @param $delete_file
 *   array, file to be deleted is $delete_file['filepath']
 * @return unknown_type
 */
function mm_ftp_delete_file($ftp_connection, $delete_file) {
  if (! ftp_delete($ftp_connection, $delete_file['filepath'])) {
    $message = 'Error deleting file: !file from FTP server';
    $params = array('!file' => $delete_file['filepath']);
    watchdog('mm_ftp', $message, $params, WATCHDOG_ERROR);
    return FALSE;
  }
  return TRUE;
}