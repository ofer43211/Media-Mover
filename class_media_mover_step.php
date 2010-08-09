<?php

// $Id: class_media_mover_step.php,v 1.1.2.22 2010/07/18 21:20:31 msonnabaum Exp $

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
    if ($actions = media_mover_api_actions_get(null, $step->module, $step->action_id)) {
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
  }


  /**
   * Saves a step to the database. $this->passthrough prevents save
   * to the database. If a configuration does not exist in the steps
   * database table, a new one is created.
   *
   */
  function save() {
    if ($this->passthrough) {
      return;
    }
    db_query("DELETE FROM {media_mover_steps} WHERE sid = '%s'", $this->sid);
    db_query("INSERT INTO {media_mover_steps} (sid, name, module, action_id, settings) VALUES ('%s', '%s', '%s', '%s', '%s')",
      $this->sid, $this->name, $this->module, $this->action_id, serialize($this->settings)
    );

    // If this step has $this->step_order set, we need to update the media_mover_step_map table
    // with the new data
    if ($this->step_order) {
      // Delete to allow a reinsert if the row already exists
      db_query("DELETE FROM {media_mover_step_map} WHERE sid = '%s' AND cid = '%s' AND step_order = %d",
        $this->sid, $this->cid, $this->step_order);
      db_query("INSERT INTO {media_mover_step_map} (sid, cid, name, step_order, start_time, stop_time) VALUES ('%s', '%s', '%s', %d, %d, %d)",
        $this->sid, $this->cid, $this->name, $this->step_order, $this->start_time, $this->stop_time);
    }
  }


  /**
   * Starts the run of a specified step. Will lock the status
   * and set the start/stop times
   *
   * @return boolean
   *   TRUE if the configuration started, FALSE if not
   */
  function start() {
    // If this is a passthrough, ignore the lock
    if ($this->passthrough) {
      return TRUE;
    }

    // Lock tables to make sure nobody else gets in our way
    db_lock_table('media_mover_step_map');
    // Make sure this step is not running
    if (! db_result(db_query("SELECT status FROM {media_mover_step_map} WHERE cid = '%s' AND sid = '%s' AND step_order = %d", $this->cid, $this->sid, $step->order)) == MMA_STEP_STATUS_READY) {
      // Update the status on our step
      db_query("UPDATE {media_mover_step_map} SET status = '%s', start_time = '%s', stop_time = '%s' WHERE cid = '%s' AND sid = '%s' AND step_order = %d",
        MMA_STEP_STATUS_RUNNING, time(), $this->start_time, $this->cid, $this->sid, $this->step_order);
      $this->status = MMA_STEP_STATUS_RUNNING;
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
  function stop() {
    // Lock tables to make sure nobody else gets in our way
    db_lock_table('media_mover_step_map');
    // Stop the step from running
    if (db_result(db_query("SELECT status FROM {media_mover_step_map} WHERE cid = '%s' AND sid = '%s'", $this->cid, $this->sid )) == MMA_STEP_STATUS_RUNNING) {
      // Update the status on our step
      db_query("UPDATE {media_mover_step_map} SET status = '%s', start_time = '%s', stop_time = '%s' WHERE cid = '%s' AND sid = '%s' AND step_order = %d",
        MMA_STEP_STATUS_READY, time(), $this->start_time, $this->cid, $this->sid, $this->step_order);
      $this->status = MMA_STEP_STATUS_READY;
      // unlock the tables
      db_unlock_tables();
      return TRUE;
    }
    db_unlock_tables();
    return FALSE;
  }


  /**
   * Executes the callback function on a specific step
   * @param $file
   * @return string
   */
  function callback_run($file = NULL, $nid = null) {
    $function = $this->callback;
    if ($file) {
      return $function($this, $file);
    }
    else {
      return $function($this);
    }
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
    if (! media_mover_api_run_control($this)) {
      return FALSE;
    }

    $this->start();
    $output = "Running step ". $this->name . "\n";

    // Is this the harvest operation?
    if ($this->harvest && ! $file) {
      // Can this action harvest from nodes?
      if ($this->harvest_from_node && $nid) {
        $files = $this->callback_run($nid);
      }
      else {
        $files = $this->callback_run();
      }

      // Did we get any files back?
      if ($files) {
        foreach ($files as $harvested_file) {
          // create the new
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
          if (! $file->data) {
            $file->data = array();
          }
          $file->data['steps'][$this->step_order] = $this;
          $file->data['files'][$this->sid] = $file->filepath;
          $file->save();
        }
      }
      return $files ? $files : array();
    }
    // Non harvest operations
    else {
      // If the file isn't locked, lock it
      if ($file->lock() || $file->status == MMA_FILE_STATUS_LOCKED) {

        // Run the steps callback function
        $filepath = $this->callback_run($file);
        if ($file->update_filepath($this, $filepath)) {
          // call the event triger
          media_mover_api_event_trigger('config', 'run', $this, $file);
        }
        $file->save(true);
        $file->unlock();
      }
    }
    $this->stop;
  }

  /**
   * Adds a step to this configuration in the step map
   */
  function map($cid, $step_order) {
    // Remove this step from the map if it already exists
    db_query("DELETE FROM {media_mover_step_map} WHERE cid = '%s' AND sid = '%s' AND step_order = %d",
      $this->cid, $this->sid, $step_order
    );
    // Create the step mapping
    db_query("INSERT INTO {media_mover_step_map} (cid, sid, step_order, name, status, start_time, stop_time) VALUES ('%s', '%s', %d, '%s', '%s', %d, %d)",
      $cid, $this->sid, $step_order, $this->name, $this->status, $this->start_time, $this->stop_time
    );
  }

  /**
   * When a step is removed, it can only be removed if it is not currently
   * running. If it is running, return FALSE
   *
   * @param $instance
   *   boolean, should only this instance be addressed, or all instances
   * @return boolean
   */
  function remove_prepare($instance) {
    db_lock_table('media_mover_step_map');

    if ($instance) {
      // Check the status of this instance of this step
      if (db_result(db_query("SELECT status FROM {media_mover_step_map} WHERE cid = '%s' AND sid = '%s' AND step_order = %d", $this->cid, $this->sid, $this->step_order)) != MMA_STEP_STATUS_RUNNING) {
        // Update the status on our step
        db_query("UPDATE {media_mover_step_map} SET status = '%s' WHERE cid = '%s' AND sid = '%s' AND step_order = %d", MMA_STEP_STATUS_REMOVE);
        $this->status = $status;
        // unlock the tables
        db_unlock_tables();
        return TRUE;
      }
    }
    else {
      // Check the status of all instances of this step
      if (! db_result(db_query("SELECT COUNT(sid) FROM {media_mover_step_map} WHERE cid = '%s' AND sid = '%s' AND status = '%s'", $this->cid, $this->sid, MMA_STEP_STATUS_RUNNING))) {
        // Update the status on our step
        db_query("UPDATE {media_mover_step_map} SET status = '%s' WHERE cid = '%s' AND sid = '%s'", MMA_STEP_STATUS_REMOVE);
        $this->status = $status;
        // unlock the tables
        db_unlock_tables();
        return TRUE;
      }
    }
    return FALSE;
  }


  /**
   * Removes this step from the configuration that it is associated with
   */
  function remove($instance = TRUE) {
    if ($this->remove_prepare($instance)) {
      // Delete this single instance
      if ($instance) {
        db_query("DELETE FROM {media_mover_step_map} WHERE sid = '%s' AND cid = '%s' AND step_order = %d", $this->sid, $this->cid, $this->step_order);
      }
      // Delete all instances
      else {
        db_query("DELETE FROM {media_mover_step_map} WHERE sid = '%s'", $this->sid);
      }
      return TRUE;
    }
    return FALSE;
  }


  /**
   * Delete this step completely
   */
  function delete() {
    // Remove this from all configurations
    $this->remove(false);
    // Delete this from the step table
    db_query("DELETE FROM {media_mover_steps} WHERE sid = '%s'", $this->sid);
  }


}
