<?php

// $Id: class_media_mover_step.php,v 1.1.2.27 2010/09/03 16:36:13 arthuregg Exp $

/**
 * @file
 * The base class for media mover steps
 * @author arthur
 *
 */
class media_mover_step {

  /**
   * Construct a step
   * @return unknown_type
   */
  function __construct($sid = NULL) {
    $this->settings = array();
    $this->status = MMA_STEP_STATUS_READY;
    $this->start_time = 0;
    $this->stop_time = 0;
    // Step is not yet loaded
    $this->new = TRUE;
    // The unique id is null on creation
    $this->sid = $sid;
    if ($sid) {
      $this->load($sid);
    }
  }


  /**
   * Loads a complete step
   * @param $sid
   *   int, step id
   */
  function load($sid, $cid = NULL) {
    $step = db_fetch_object(db_query("SELECT * FROM {media_mover_steps} WHERE sid = '%s'", $sid));
    // Unserialize the configuration data
    $step->settings = unserialize($step->settings);

    // Map data back to this
    foreach ($step as $key => $value) {
      $this->{$key} = $value;
    }

    // Get this steps module/id data
    if ($actions = media_mover_api_action_get($step->action_id)) {
      foreach ($actions as $key => $value) {
        $this->{$key} = $value;
      }
    }
    // Get configuration data
    if (isset($cid)) {
      $configuration = db_fetch_object(db_query("SELECT * FROM {media_mover_step_map} WHERE cid = '%s' AND sid = '%s'", $cid, $sid));
      foreach ($configuration as $key => $value) {
        $this->{$key} = $value;
      }
    }

    // Step has been loaded
    $this->new = FALSE;
    drupal_alter('media_mover_step_load', $this);
  }


  /**
   * Saves a step to the database. $this->passthrough prevents save
   * to the database. If a configuration does not exist in the steps
   * database table, a new one is created.
   *
   */
  function save() {
    drupal_alter('media_mover_step_save', $this);
    // Should we save this step?
    if ($this->passthrough) {
      return;
    }

     dpm($this);
    // Save the step data
    drupal_write_record('media_mover_steps', $this, $this->new ? NULL : 'sid');

    // Update the step map table, harvest step is 0
    if ($this->cid && ($this->step_order === 0 || $this->step_order)) {
      drupal_write_record('media_mover_step_map', $this, $this->new ? NULL : 'step_map_id');
    }
  }


  /**
   * Delete one or all instances of a step
   *
   * @param $all
   *   Boolean
   *
   */
  function delete($all = FALSE) {
    if ($all) {
      return $this->delete_all();
    }
    return $this->delete_instance();
  }


  /**
   * Runs the step on an array of files. If the step order is 1, this
   * is a harvest function.
   *
   * @param $file
   *   Object, media mover file. If not passed in, assumes a harvest function
   */
  function run(&$file = NULL) {
    // Check to see if the system will allow this step to run right now
    $errors = array();
    drupal_alter('media_mover_process_control', $file, $this, $errors);
    if (count($errors)) {
      watchdog('media_mover_api', t('Run control: !errors', array('!errors' => implode('<br>', $errors))), WATCHDOG_NOTICE);
      return FALSE;
    }

    // Attach the step data to the file
    $file->steps[$this->step_order] = $this;

    // Ready the step
    $this->start();
    // Harvest if this step is a harvest operation.
    if ($this->harvest) {
      $this->file_harvest();
    }

    // Non harvest operations
    else {
      // If the file is not locked, lock it
      if ($file->lock() || $file->status == MMA_FILE_STATUS_LOCKED) {
        // Run the steps callback function
        $this->file_process($file);
        // Now unlock the file
        $file->unlock();
      }
    }
    // Stop the step
    $this->stop();
  }


  /**
   * Harvest files
   */
  private function file_harvest() {
    // Get the function to run
    $function = $this->callback;
    if ($files = $function($this)) {
      foreach ($files as $harvested_file) {
        // Create the media mover file
        $file = new media_mover_file();
        // Add any additional data to $file object that is passed in
        foreach ($harvested_file as $key => $value) {
          $file->{$key} = $value;
        }
        // Set the configuration id. Needed when a file is harvested
        $file->cid = $this->cid;
        // Set the current step of the file to this step
        $file->step_order = $this->step_order;
        // Add this step data to the file
        $file->steps[$this->step_order] = $this;
        // Map the current filepath to the filepath in as this is the source material
        $file->source_filepath = $file->filepath;
        $file->data['files'][$this->step_order] = $file->filepath;
        $file->steps[0] = $this;
        $file->save(TRUE);
        drupal_alter('media_mover_file_harvest_post', $file);
      }
    }
    return $files ? $files : array();
  }


  /**
   * Executes the callback function on a specific step.
   *
   * @param $file
   *   Object, media mover file object. When absent, this is a harvest function
   */
  private function file_process($file = NULL) {
    // Allow altering of the file pre process
    drupal_alter('media_mover_file_process_pre', $file, $this);
    // Get the function to run
    $function = $this->callback;
    // Process the file
    if ($filepath = $function($this, $file)) {
      // Update the filepath
      $file->update_filepath($filepath);
      $file->steps[$this->step_order] = $this;
      // Allow altering of the file post process
      drupal_alter('media_mover_file_process_post', $file, $this);
      // Save all new data and advance the file's step order
      $file->save(TRUE);
    }
  }


  /**
   * Remove a step that is not currently running
   *
   * @return success
   */
  private function delete_instance() {
    // Make this thread safe
    db_lock_table('media_mover_step_map');
    // Make sure that this step is not running
    if (! db_result(db_query("SELECT status FROM {media_mover_step_map} WHERE step_map_id = '%s' AND status = %d'", $this->step_map_id, MMA_STEP_STATUS_RUNNING))) {
      // Delete the specified step
      $this->delete_from_step_map();
      db_unlock_tables();
      return TRUE;
    }
    watchdog('media_mover_api', t('Failed to delete step: !sid from configuration !cid because the step was currently running',
      array(
        '!sid' => $this->sid,
        '!cid' => $this->cid
    )), WATCHDOG_ERROR);
    db_unlock_tables();
    return FALSE;
  }

  /**
   * Remove all instances of a step that are not running
   */
  private function delete_all() {
    // Make this thread safe
    db_lock_table('media_mover_step_map');
    $errors = FALSE;
    // Get all the instances of this step
    $results = db_query("SELECT step_map_id FROM {media_mover_step_map} WHERE sid = '%s'", $this->sid);
    while ($result = db_fetch_object($results)) {
      // Attempt to delete this step
      if (! $this->delete_from_step_map($result->step_map_id)) {
        watchdog('media_mover_api', t('Failed to delete step: !sid from configuration !cid because the step was currently running',
          array(
            '!sid' => $this->sid,
            '!cid' => $this->cid
        )), WATCHDOG_ERROR);
        //
        $errors = TRUE;
      }
      if (! $errors) {
        $this->delete_from_step_table;
        return TRUE;
      }
      // Errors were found
      return FALSE;
    }
  }


  /**
   * Utility function to delete entries from the step map table
   *
   * @param $step_map_id
   *   Int, step map id
   */
  private function delete_from_step_map($step_map_id = FALSE) {
    if (! $step_map_id) {
      if (! $step_map_id = $this->step_map_id) {
        watchdog('media_mover_api', t('Could not delete step because no step map id was specified'));
      }
    }
    db_query("DELETE FROM {media_mover_step_map} WHERE step_map_id = %d", $step_map_id);
    // Check to see if this was the only use of this step
    if (! db_result(db_query("SELECT count(sid) FROM {media_mover_step_map} WHERE sid = '%s'", $this->sid))) {
      db_query("DELETE FROM {media_mover_steps} WHERE sid = '%s'", $this->sid);
    }
  }

  /**
   * Utility function to delete a single step from the steps table
   */
  private function delete_from_step_table() {
    db_query("DELETE FROM {media_mover_steps} WHERE sid = '%s'", $this->sid);
  }


 /**
   * Starts the run of a specified step. Will lock the status
   * and set the start/stop times
   *
   * @return boolean
   *   TRUE if the configuration started, FALSE if not
   */
  private function start() {
    // If this is a passthrough, ignore the lock
    if ($this->passthrough) {
      return TRUE;
    }

    // Lock tables to make sure nobody else gets in our way
    db_lock_table('media_mover_step_map');
    // Make sure this step is not running
    if (! db_result(db_query("SELECT status FROM {media_mover_step_map} WHERE step_map_id = %d", $this->step_map_id)) == MMA_STEP_STATUS_READY) {
      // Update the status on our step
      $this->status = MMA_STEP_STATUS_RUNNING;
      $this->start_time = time();
      // Save the status
      drupal_write_record('media_mover_step_map', $this, 'step_map_id');
      // unlock the tables
      db_unlock_tables();
      return TRUE;
    }

    // unlock the tables
    db_unlock_tables();
    return FALSE;
  }


  /**
   * Stops a running step.
   *
   * @return boolean
   *   TRUE if the step was stopped, FALSE if not
   */
  private function stop() {
    // If this is a passthrough, ignore the lock
    if ($this->passthrough) {
      return TRUE;
    }
    // Lock tables to make sure nobody else gets in our way
    db_lock_table('media_mover_step_map');
    // Stop the step from running
    if (db_result(db_query("SELECT status FROM {media_mover_step_map} WHERE step_map_id = %d", $this->step_map_id )) == MMA_STEP_STATUS_RUNNING) {
      // Update the status on our step
      $this->status = MMA_STEP_STATUS_READY;
      $this->stop_time = time();
      drupal_write_record('media_mover_step_map', $this, 'step_map_id');
      // unlock the tables
      db_unlock_tables();
      return TRUE;
    }
    db_unlock_tables();
    return FALSE;
  }


}
