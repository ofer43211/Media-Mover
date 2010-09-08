<?php

// $Id: class_media_mover_configuration.php,v 1.1.2.48 2010/09/03 16:36:12 arthuregg Exp $

/**
 * @file
 * Base class for media mover configurations
 */

class media_mover_configuration {

  /**
   * Construct a new configuration
   */
  function __construct($cid = NULL) {
    // Make sure status isset
    $this->status = NULL;
    $this->step = 0;
    $this->steps = array();
    $this->passthrough = NULL;
    // Before a configuration is loaded it has no information loaded from the db
    $this->new = TRUE;
    if ($cid) {
      $this->load($cid);
    }
  }


  /**
   * Load the requested configuration
   *
   * @param $cid
   *   int, configuration id
   */
  function load($cid) {
    // Get the configuration data
    $data = db_fetch_object(db_query("SELECT * FROM {media_mover_configurations} WHERE cid = '%s'", $cid));
    // Get the configuration settings
    $data->settings = unserialize($data->settings);
    foreach ($data as $key => $value) {
      $this->{$key} = $value;
    }
    // Get the specific step data for this configuration
    $this->steps_load();
    // Configuration has been loaded
    $this->new = FALSE;
    drupal_alter('media_mover_configuration_load', $this);
  }


  /**
   * This saves configuration data. It will create any data that is new
   * or overwrite existing data in the db.
   */
  function save() {
    drupal_alter('media_mover_api_configuration_save', $this);
    // Allow configurations to pass through the system without
    // being saved to the db
    if ($this->passthrough) {
      return;
    }
    // Save record
    drupal_write_record('media_mover_configurations', $this, $this->new ? NULL : 'cid');
    // Save each of the steps
    foreach ($this->steps as $step_order => $step) {
      // Step has to know which configuration it belongs to
      $step->cid = $this->cid;
      // Step has to have order
      $step->step_order = $step_order;
      // Save the completed step
      $step->save();
    }
  }


  /**
   * Get the files associated with this configuration
   *
   * @TODO this has to support limiting the number of files
   *       selected by the current configuration
   * @param $status
   *   String, step order to select files for
   * @param $step_order
   *   Integer, what step to select for
   * @param $load
   *   Boolean, should the full file be returned or only the mmfid?
   */
  function get_files($status = FALSE, $step_order = FALSE, $load = TRUE) {
    $conditions[] = "cid = '" . $this->cid . "'";
    if ($status) {
      $conditions[] = "status = '$status'";
    }
    if ($step_order) {
      $conditions[] = "step_order = '$step_order'";
    }
    $conditions = 'WHERE '. implode(' AND ', $conditions);

    // Find all matching files
    $results = db_query("SELECT mmfid FROM {media_mover_files} " . $conditions);
    // Put files into an array
    $files = array();
    while ($result = db_fetch_array($results)) {
      if ($load) {
        $file = new media_mover_file($result['mmfid'], FALSE);
        $files[] = $file;
      }
      else {
        $files[] = $result['mmfid'];
      }
    }
    return $files;
  }

  /**
   * Return a file count for this configuration
   * @param $status
   *   string, media mover file status
   * @return int
   */
  function file_count($status = NULL) {
    $conditions = '';
    if ($status) {
      $conditions = ' AND status = "'. $status .'"';
    }
    $count = db_result(db_query("SELECT COUNT(mmfid) FROM {media_mover_files} WHERE cid = '%s' $conditions", $this->cid));
    return $count;
  }

  /**
   * Run a complete configuration. Run on single or multiple files
   *
   * @param $file
   *   Object, is a media mover file
   *
   */
  function run($file = NULL) {
    // No file is being passed in, run the full configuration
    foreach ($this->steps as $step) {
      // If we are harvesting, do not use any files
      if ($step->harvest) {
        $step->run();
      }
      // Was a file passed in?
      else if ($file) {
        $step->run(array($files));
      }
      // Look for new files to operate on
      else {
        $files = $this->get_files(MMA_FILE_STATUS_READY, ($step->step_order - 1));
        $this->log('Notice', t('Configuration step: %description is acting on %count files',
          array('%description' => $step->description, '%count' => count($files) )), WATCHDOG_INFO);
        // Run the step on each of the files
        foreach ($files as $file) {
          $step->run($file);
        }
      }
    }
  }


  /**
   * Run a complete configuration on a existing file
   *
   * Takes a media mover file (from db or code) and runs
   * all the steps on it
   *
   * @param $file
   *   Object, Media Mover file object
   * @param $step_order
   *   Int, specify a step to start from
   */
  function run_file(&$file, $step_order = 0) {
    $steps = array_slice($this->steps, $step_order);
    // Run each step
    foreach ($steps as $step) {
      $step->run($file);
    }
  }


  /**
   * Run the harvest operation
   */
  function harvest($params = NULL) {
    $function = $this->steps[0]->callback;
    if ($params) {
      return $function($this->steps[0], $params);
    }
    else {
      return $function($this->steps[0]);
    }
  }

  /**
   * Simple logging function
   *
   * @param unknown_type $step
   * @param unknown_type $message
   * @return unknown_type
   */
  function log($type, $message) {
    $this->messages[] = array($type, $message);
  }


  /**
   * Delete all files associated with this configuration
   *
   * @param $files
   *   Array, array of media mover files
   *
   * @param unknown_type $mmfid
   */
  function delete_files($files = FALSE) {
    // There is an edge case
    // where a file that is currently in use could be processed after
    // the process that owns it finishes. This could allow the file
    // to be opperated on again before the deletion queue finishes.
    // The concequences of this are hopefully small

    // Fetch the files if none are passed in
    if (! $files) {
      $files = $this->get_files();
    }

    // Make sure drupal_queue is included
    drupal_queue_include();
    $delete_queue = DrupalQueue::get('media_mover_api_queue_file_delete');
    foreach ($files as $file) {
      $file->lock();
      $delete_queue->createItem($file);
    }
  }

  /**
   * Checks to see if this configuration has already harvested this filepath
   *
   * @param $filepath
   *   String, a file path
   * @return boolean
   */
  function file_harvested($filepath) {
    if (db_result(db_query('SELECT mmfid FROM {media_mover_files} WHERE source_filepath = "%s" AND cid = "%s"', $filepath, $this->cid))) {
      return TRUE;
    }
    return FALSE;
  }


  /**
   * Retrieves all the steps for this configuration
   */
  private function steps_load() {
    // Find all the steps associated with this configuration
    $results = db_query("SELECT * FROM {media_mover_step_map} WHERE cid = '%s' ORDER BY step_order", $this->cid);
    while ($result = db_fetch_array($results)) {
      // Load the step in question
      $step = media_mover_api_step_get($result['sid']);
      // If this configuration is not being saved to the db, honor this on the step as well
      if ($this->passthrough) {
        $step->passthrough = $this->passthrough;
      }
      // Add the step order data to each step
      foreach ($result as $key => $value) {
        $step->{$key} = $value;
      }
      // Append this step to the configuration
      $this->steps[$step->step_order] = $step;
    }
  }


  /**
   * Total number of steps in this configuration
   *
   * @return unknown
   */
  function step_count() {
    return count($this->steps);
  }


  /**
   * Returns the last numerical step in a configuration
   *
   * @return integer
   */
  function last_step() {
    return count($this->steps) - 1;
  }


  /**
   * Sets all the step statuses to ready
   * @return unknown_type
   */
  function steps_reset() {
    if ($this->steps) {
      foreach ($this->steps as $step) {
        $this->steps[$step->step_order]->status = MMA_STEP_STATUS_READY;
      }
      $this->save();
    }
  }


  /**
   * Completely deletes a configuration
   * @param $files
   *   boolean, should this configurations files be deleted?
   * @return boolean FALSE if delete conditions fail.
   */
  function delete($files = FALSE) {
    // Prepare to remove all steps
    foreach ($this->steps as $step) {
      if (! $step->delete()) {
        return FALSE;
      }
    }

    // Are there files to be deleted?
    if ($files) {
      $this->delete_files($files);
    }

    // Remove all of the configurations steps
    // @TODO can not delete these steps until all files are deleted.
    foreach ($this->steps as $step) {
      $step->delete();
    }

    db_query("DELETE FROM {media_mover_configurations} WHERE cid = '%s'", $this->cid);
    db_query("DELETE FROM {media_mover_step_map} WHERE cid = '%s'", $this->cid);
  }

}
