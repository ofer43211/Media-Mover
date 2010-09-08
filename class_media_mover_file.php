<?php

// $Id: class_media_mover_file.php,v 1.1.2.35 2010/09/03 16:36:12 arthuregg Exp $

/**
 * @file
 * Base class for media mover files
 */


class media_mover_file {
  // Default file status
  var $status = MMA_FILE_STATUS_READY;

  /**
   * By default, load the file from cache if possible
   */
  function __construct($mmfid = NULL, $cache = TRUE) {
    // Default status is ready
    $this->status = MMA_FILE_STATUS_READY;
    // Default id
    $this->mmfid = $mmfid;
    $this->data = array();
    // If an id is passed in, attempt to load it
    if ($mmfid) {
      // Load the file
      $this->load($mmfid, $cache);
    }
  }


  /**
   * Get file data for the requested id.
   *
   * @param $mmfid
   *   int, media mover file id
   * @return
   *   Boolean, did the file load or not?
   *
   */
  function load($mmfid, $cache = FALSE) {
    // By default we try to load a cached file
    if (! ($cache && $this->cache_fetch())) {
      // Load the file
      $data = db_fetch_array(db_query("SELECT * FROM {media_mover_files} WHERE mmfid = %d", $this->mmfid));

      // If the file wasn't found, return false
      if (! $data['mmfid']) {
        $this->error[] =t('Failed to load !mmfid', array('!mmfid' => $this->mmfid));
        return FALSE;
      }

      // Load the data onto the file
      $this->load_data($data);

      // Cache this file by default
      if ($cache) {
        $this->cache();
      }
    }
    // Allow the file to be altered
    drupal_alter('media_mover_file_load', $this);
  }


  /**
   * Updates all data associated with a file. Note that this is
   * not thread safe.
   *
   * @NOTE - $this->status is *not* saved on existing files if the file
   *         is locked. Status can only be saved on $this->unlock() to
   *         keep files thread safe.
   *
   * @param $advance
   *   should the file's current step be advanced?
   * @param $single_step
   *   boolean, generally we only want to save the current step
   *   that the file is on, rather than saving all the steps, set to
   *   FALSE if you want to save the full file object
   */
  function save($advance = FALSE, $single_step = TRUE) {
    // Allow the file to be altered before being saved
    drupal_alter('media_mover_file_save', $this);

    // Advance the step for this file if requested
    if ($advance) {
      $this->step_next();
    }

    // If a filesize was not passed in, see if we can get one
    if (! $this->filesize) {
      if (file_exists($this->filepath)) {
        $this->filesize = filesize($this->filepath);
      }
    }

    // A file could be programatically pushed through- it will not have a mmfid,
    // but it should have $this->passthrough = TRUE This allows modules to run
    // Media Mover configurations without using the DB
    if (! $this->passthrough) {

      // Changing a file's status when saving is dangerous. If a file is changed
      // to "ready", another process may grab this file and operate on it wile the
      // current operation is still going. If you need to change a file's status
      // use the $file->set_status() function.
      // There is one exception to this when a file is firsted harvested

      if ($this->status == MMA_FILE_STATUS_LOCKED) {
        unset($this->status);
      }

      if (! $this->mmfid) {
        // We save the file status the first time the file is saved
        $this->status = MMA_FILE_STATUS_READY;
        $this->date = time();
      }



      // Format the step data for saving
      foreach ($this->steps as $step_order => $step) {
        $this->data['steps'][$step_order] = $step->sid;
      }

      // Save to the db.
      drupal_write_record('media_mover_files', $this, $this->mmfid ? 'mmfid' : NULL);

      // If the status was removed, add it back. It will be saved when the file is unlocked
      if (! $this->status) {
        $this->status = MMA_FILE_STATUS_LOCKED;
      }

    }
    // Reset the cache of this file.
    $this->uncache();
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
    if (! $this->mmfid || $this->pass_through) {
      return TRUE;
    }
    // Was the status correctly set?
    if ($this->status_set()) {
      return TRUE;
    }
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
    // has not modified the status. Set the status to ready if we can.
    if (! $this->status_set(MMA_FILE_STATUS_LOCKED, MMA_FILE_STATUS_READY)) {
      return FALSE;
    }
    // Now we need to see if this is the last step in the configuration
    $configuration = media_mover_api_configuration_get($this->cid);
    // Is this the last step for this file?
    if ($this->step_order == $configuration->step_count() && $this->status == MMA_FILE_STATUS_READY) {
      $this->status_set(MMA_FILE_STATUS_READY, MMA_FILE_STATUS_FINISHED);
    }
    // Reset the lock date
    $this->lock_date = 0;
    // Set this file status to ready and reset lock date
    drupal_write_record('media_mover_files', $this, 'mmfid');
  }


  /**
   * Updates a file's filepath
   *
   * As the file moves from step to step. Note that this is not thread safe
   * however it should only be called within
   *
   * @param $step
   *   object, media mover step object
   * @param $filepath
   *   string, filepath
   * @return boolean
   */
  function update_filepath($filepath, $step_order = FALSE) {
    // Update the current filepath
    $this->filepath = $filepath;
    if (! $step_order) {
      $step_order = $this->step_order;
    }
    // Some modules may return the filepath as TRUE, if so, use the last good filepath
    if ($filepath === TRUE) {
      $filepath = $this->data['files'][$step_order];
    }
    // Store a copy of this file path in the steps
    $this->steps[$step_order]->filepath = $filepath;
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
    if ($uid = $this->data['user']->uid) {
      return user_load($this->data['user']->user);
    }
  }


  /**
   * Moves the file one step forward and sets the file status
   * If the file is in the last step, mark completed.
   */
  private function step_next() {
    // Load the configuration
    $configuration = media_mover_api_configuration_get($this->cid);
    // If we are not on the final step, advance the file
    if ($this->step_order < $configuration->last_step() ) {
      $this->step_order = $this->step_order + 1;
    }
    // Are we on the final step?
    else if ($this->step_order == $configuration->last_step()) {
      $this->status = MMA_FILE_STATUS_FINISHED;
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
      foreach ($this->steps as $step) {
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
   * Delete a single file
   */
  function delete() {
    if ($this->steps) {
      // NEVER delete harvest file unless explicitly told because it may not belong to us.
      $do_not_delete = $this->steps[1]->filepath;
      foreach ($this->steps as $id => $step) {
        drupal_alter('media_mover_file_delete', &$this, &$step);
        // If the file is present and it is not the source material, delete
        if ($step->filepath != $do_not_delete) {
          file_delete($step->filepath);
        }
      }
    }

    // Remove the file from the database
    db_query("DELETE FROM {media_mover_files} WHERE mmfid = %d", $this->mmfid);
  }


  /**
   * Returns the filepath that should be reprocessed
   *
   * @param unknown_type $step
   */
  function reprocess_filepath($step = 0) {
    // the first step should return original file
    if ($step === 0) {
      return $this->source_filepath;
    }
    return $this->steps[$step]->filepath;
  }


  /**
   * Set file status
   *
   * This function is intended to be used only for changing a file's status.
   * $file->save will not change the file's status
   *
   * @param $status_check
   *   String, the status state to check if this file is in
   * @param $status_change
   *   String, the status to set the file to
   * @param $time
   *   Int, unix time stamp
   * @return boolean could the file status be set?
   */
   function status_set($status_check = MMA_FILE_STATUS_READY, $status_change = MMA_FILE_STATUS_LOCKED, $time = FALSE) {
    // lock the tables to prevent over run
    db_lock_table('media_mover_files');

    // Get the real status of the file
    $result = db_result(db_query("SELECT status FROM {media_mover_files} WHERE mmfid = %d", $this->mmfid));

    // Check the real file status against what is requested
    if ($result == $status_check ) {
      // We can change the status
      $this->status = $status_change;
      $this->lock_date = $time ? $time : time();
      // Update the status in the DB
      drupal_write_record('media_mover_files', $this, 'mmfid');
      db_unlock_tables();
      return TRUE;
    }

    // Failed, unlock tables and assign status
    $this->status = $result;
    db_unlock_tables();
    return FALSE;
  }


  /**
   * Cache this file
   */
  private function cache() {
    cache_set('media_mover_file_' . $this->mmfid, $this, 'cache_media_mover');
  }


  /**
   * Delete file cache
   */
  private function uncache() {
    cache_clear_all('media_mover_file_' . $this->mmfid, 'cache_media_mover');
  }


  /**
   * Attempts to load a file from the cache
   *
   * @param $mmfid
   *   Int, file id
   */
  private function cache_fetch() {
    $data = cache_get('media_mover_file_' . $this->mmfid, 'cache_media_mover');
    if ($data = $data->data) {
      // We have to map the cached values onto the current object
      foreach ($data as $key => $value) {
        $this->{$key} = $value;
      }
      $this->cached = TRUE;
      return TRUE;
    }
    return FALSE;
  }


  /**
   * Utility function to add data to the file
   *
   * @param $data
   *   Object, data to add to the file
   */
  private function load_data($data) {
    // Make sure we do not have serialized data
    if (! is_array($data['data'])) {
      $data['data'] = unserialize($data['data']);
    }
    // Make sure that we have a valid status
    if ($data['status'] == NULL) {
      $data['status'] = MMA_FILE_STATUS_READY;
    }

    // Move the step data to $file->steps
    foreach ($data['data']['steps'] as $step_order => $sid) {
      $this->steps[$step_order] = media_mover_api_step_get($sid);
    }

    // unset($data['data']['steps']);
    // Add the data back onto the file
    foreach ($data as $key => $value) {
      $this->{$key} = $value;
    }

  }



}
