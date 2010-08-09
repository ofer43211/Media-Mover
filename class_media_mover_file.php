<?php

// $Id: class_media_mover_file.php,v 1.1.2.28 2010/07/25 14:25:59 arthuregg Exp $

/**
 * @file
 * Base class for media mover files
 */


class media_mover_file {
  // Default file status
  var $status = MMA_FILE_STATUS_READY;

  /**
   * When we create a new MM file we can load the full object
   */
  function __construct($mmfid = NULL) {
    $this->status = MMA_FILE_STATUS_READY;
    if ($mmfid) {
      $this->load($mmfid);
    }
  }


  /**
   * Get file data for the requested id.
   *
   * @param $mmfid
   *   int, media mover file id
   *
   */
  function load($mmfid) {
    // get the main file
    $data = db_fetch_object(db_query("SELECT * FROM {media_mover_files} WHERE mmfid = %d", $mmfid));
    // get the file data into a usable form
    $data->data = unserialize($data->data);

    // Check the file status
    if ($data->status == NULL) {
      $data->status = MMA_FILE_STATUS_READY;
    }

    // Add the data back onto the file
    foreach ($data as $key => $value) {
      $this->{$key} = $value;
    }

    // @TODO fix up api, figure out file paths, etc

    // load any additional data associated assigned by a MM module
    // even though this maybe in the DB, we need to load it fresh. It is cached
    // above this function in media_mover_api_get_file($mmfid);
    /*
    foreach ($this->steps as $step_id) {
      if (function_exists($this->steps[$step_id]['action']['add data'])) {
        $data = $this->steps[$step_id]['action']['add data']['add data']($file);
        $this->steps[$step_id]['data'] = array_merge($this->steps[$step_id]['data'], $data);
      }
    }
    */
  }


  /**
   * Updates all data associated with a file. Note that this is
   * not thread safe.
   *
   * @NOTE - $file->status is *not* saved on existing files. Status
   *         can only be saved on $file->unlock() to keep files
   *         thread safe.
   *
   * @param $advance
   *   should the file's current step be advanced?
   * @param $single_step
   *   boolean, generally we only want to save the current step
   *   that the file is on, rather than saving all the steps, set to
   *   FALSE if you want to save the full file object
   */
  function save($advance = FALSE, $single_step = TRUE) {
    // Advance the step for this file if requested
    if ($advance) {
      $this->step_next();
    }

    // If a filesize was not passed in, see if we can get one
    if (! $this->filesize) {
      if (file_exists($this->filepath)) {
        $this->filsize = filesize($this->filepath);
      }
    }

    // If the file does not have a mmfid, it is a new file
    // so we need to build a new record for it. A file could also
    // be programatically pushed through- it will not have a mmfid,
    // but it should have $this->passthrough = TRUE
    if (! $this->passthrough) {
      if (! $this->mmfid) {
        db_query("INSERT INTO {media_mover_files} (nid, fid, cid, step_order, filepath, filesize, status, date, data, filepath_in)
          VALUES (%d, %d, '%s', %d, '%s', %d, '%s', %d, '%s', '%s')",
          $this->nid, $this->fid, $this->cid, $this->step_order, $this->filepath, $this->filesize, MMA_FILE_STATUS_READY, time(), serialize($this->data), $this->filepath);
        // get the mmfid
        $this->mmfid = db_last_insert_id('media_mover_files', 'mmfid');
      }
      else {
        // update the top level file data
        db_query("UPDATE {media_mover_files} SET filepath = '%s', filesize = %d, step_order = %d, data = '%s' WHERE mmfid = %d",
          $this->filepath, $this->filesize, $this->step_order, serialize($this->data), $this->mmfid
        );
      }
    }

    // clear the cache for this file if we have a NID
    if ($this->nid) {
      cache_clear_all('media_mover_files_node_'. $file->nid, 'cache_media_mover', TRUE);
    }
  }


  /**
   * Locks a media mover file to prevent it from being used
   * by multiple processes at the same time
   *
   * @return boolean
   */
  function lock() {
    // Only lock if the file is in the db, note that this
    // prevents locking if we are passing through a file
    // rather than saving it to the db
    if (! $this->mmfid) {
      return TRUE;
    }

    // lock the tables to prevent over run
    db_lock_table('media_mover_files');

    // check the status of the file
    $result = db_result(db_query("SELECT status FROM {media_mover_files} WHERE mmfid = %d", $this->mmfid));

    // we need to check the status again to make sure that
    // no one else has acted on the file while the list of files was
    // being gathered
    if ($result == MMA_FILE_STATUS_READY ) {
      $this->status = MMA_FILE_STATUS_LOCKED;
      // Set status to locked and time stamp when the file was locked
      db_query("UPDATE {media_mover_files} SET status = %d, lock_date = %d WHERE mmfid = %d", $this->status, time(), $this->mmfid);
      db_unlock_tables();
      return TRUE;
    }

    // Failed, unlock tables and assign status
    $this->status = $result;
    db_unlock_tables();
    return FALSE;
  }


  /**
   * Unlock a file for further use
   *
   * This will only unlock a file whos state is currently MMA_FILE_STATUS_LOCKED,
   * otherwise the file status is left as is.
   */
  function unlock() {
    // If the status of the file is still MMA_FILE_STATUS_LOCKED then an action
    // has not modified the status. Set the status to ready
    if ($this->status == MMA_FILE_STATUS_LOCKED) {
      $this->status = MMA_FILE_STATUS_READY;
    }
    $configuration = media_mover_api_configuration_get($this->cid);
    // Is this the last step for this file?
    if ($this->step_order == $configuration->step_count() && $this->status == MMA_FILE_STATUS_READY) {
      $this->status = MMA_FILE_STATUS_FINISHED;
    }
    // Set this file status to ready and reset lock date
    db_query("UPDATE {media_mover_files} SET status = '%s', lock_date = %d WHERE mmfid = %d", $this->status, 0, $this->mmfid);
  }


  /**
   * Updates a file's filepath as the file moves from
   * step to step. Note that this is not thread safe.
   * @param $step
   *   object, media mover step object
   * @param $filepath
   *   string, filepath
   * @return boolean
   */
  function update_filepath($step, $filepath) {
    if ($filepath) {
      // add the new file path to the file object. Some modules may return TRUE instead
      // of a file path. If this is the case, get the current filepath
      $this->filepath = $filepath === TRUE ? $this->filepath : $filepath;
      // Keep a record of what file was used in each step
      $this->data['steps'][$step->sid]['filepath'] = $file_path;
      // Update this file status as we have the new filepath only
      // if this does not have a custom status
      if ($this->status == MMA_FILE_STATUS_RUNNING) {
        $this->status = MMA_FILE_STATUS_READY;
      }
      return TRUE;
    }
    else {
      // failed
      if ($this->status == MMA_FILE_STATUS_RUNNING) {
        $this->status = MMA_FILE_STATUS_ERROR;
      }
      return FALSE;
    }
  }


  /**
   * Return a node from the file if there is one available
   *
   * @return object
   */
  function node_get() {
    if (! $nid = $this->nid) {
      if (! $nid = $this->data['node']->nid ) {
        return FALSE;
      }
    }
    return node_load($nid);
  }


  /**
   * Looks for user data in the file object
   * the file array. Returns FALSE if it can not find any
   * otherwise returns a user
   * @param $file
   *   media mover file array
   * @return object
   *   drupal user object
   */
  function user_get() {
    if ($uid = $file->data['user']->uid) {
      return user_load($file->data['user']->user);
    }
  }


  /**
   * Moves the file one step forward and sets the file status
   * If the file is in the last step, mark completed.
   */
  function step_next() {
    // Load the configuration
    $configuration = media_mover_api_configuration_get($this->cid);
    // if we are not on the final step, advance the file
    if ($this->step_order < $configuration->step_count() ) {
      $this->step_order = $this->step_order + 1;
    }
  }


  /**
   * Helper function to get a filepath from a specified step
   *
   * @param $data
   *   string or array, is either a $sid or an array(MODULE_NAME, ACTION)
   * @return string, filepath
   */
  function retrive_filepath($data) {
    if (is_array($data)) {
      foreach ($file->steps as $step) {
        if ($step->module == $data['module'] && $step->action_id == $data['action_id']) {
          $sid = $step->sid;
        }
      }
    }
    if (is_string($data)) {
      $sid = $data;
    }
    return $this->data['files'][$sid];
  }


  /**
   * Delete a file
   */
  function delete() {

    // load up the configuration for this file
    // $configuration = media_mover_api_configuration_get($this->cid);
    // call the modules that made it

    /* **
     // @TODO this all needs to be fixed!!

    // now check if there are any files in the item left to delete
    // note: NEVER delete harvest file because that may not belong to us.
    $do_not_delete = $file->steps[0]->filepath;
    $display_files = array();
    if ($file->steps) {
      foreach($file->steps as $sid => $step) {
    // @TODO implement a new delete API hook per step
    $configuration->file_delete($file->mmfid);

        if ($delete_item !=  $do_not_delete) {
          // keep a record of what has been deleted
          $display_files[] = str_replace('_', ' ', $delete_item) .': '.$step->filepath_out;
          //print $file[$delete_item] ."\n";
          file_delete($step->filepath_out);
        }
      }
    }

    ** */
    // @TODO NOTE---- the above has to be fixed. It is totally broken

    // remove the file from the database
    db_query("DELETE FROM {media_mover_files} WHERE mmfid = %d", $this->mmfid);
    // delete all the file data
    //db_query("DELETE FROM {media_mover_file_data} WHERE mmfid = %d", $this->mmfid);

   // $replacements = array('%file' => basename($this['complete_file']));
    // watchdog('Media Mover', 'Deleted files: %file', array(implode('<br />', $display_files)));

    // Clear the cache if we have a node
    if ($this->nid) {
      cache_clear_all('media_mover_files_node_'. $this->nid, 'cache_media_mover', TRUE);
    }
  }


  /**
   * Returns the filepath that should be reprocessed
   *
   * @param unknown_type $step
   */
  function reprocess_filepath($step = 0) {
    // the first step should return original file
    if ($step === 0) {
      return $this->filepath_in;
    }
    return $this->steps[$step]->filepath;
  }

}
