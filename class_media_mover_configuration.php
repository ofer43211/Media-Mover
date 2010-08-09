<?php

// $Id: class_media_mover_configuration.php,v 1.1.2.42 2010/07/25 14:25:59 arthuregg Exp $

/**
 * @file
 * Base class for media mover configurations
 */

class media_mover_configuration {

  /**
   * When we create a new MM configuration, it is by default on step one.
   * @return unknown_type
   */
  function __construct($cid = NULL) {
    $this->status = NULL;
    $this->step = 0;
    $this->steps = array();
    $this->passthrough = NULL;

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
    // get the configuration data
    $configuration = db_fetch_object(db_query("SELECT * FROM {media_mover_configurations} WHERE cid = '%s'", $cid));
    $this->cid = $cid;
    $this->name = $configuration->name;
    $this->description = $configuration->description;
    // Get the configuration settings
    $this->settings = unserialize($configuration->settings);
    // Get the specific step data for this configuration
    $this->steps_get();
  }


  /**
   * This saves configuration data. It will create any data that is new
   * or overwrite existing data in the db.
   */
  function save() {
    // We can only move through this function once. Because of FAPI
    // we wil go through this twice
    // @TODO check to see if this is really true
    static $control;

    if (! $control) {
      // Allow configurations to pass through the system without
      // being saved to the db
      if ($this->passthrough) {
        return;
      }

      // If this configuration already exists, we delete the entry just in case
      // @TODO convert this to drupal_write_record
      db_query("DELETE FROM {media_mover_configurations} WHERE cid = '%s'");
      db_query("INSERT INTO {media_mover_configurations} (cid, name, description, settings, status) VALUES ('%s', '%s', '%s', '%s', '%s')",
        $this->cid, $this->name, $this->description, serialize($this->settings), $this->status
      );

      // Save each of the steps
      foreach ($this->steps as $step_order => $step) {
        $step->save();
        // Update the step map
        $step->map($this->cid, $step_order);
      }

      // Update the st
      $control = TRUE;
    }
  }


  /**
   * Get the files associated with this configuration
   *
   * @TODO this has to support limiting the number of files
   *       selected by the current configuration
   * @param $status
   *   string, optional file status
   * @param $step_order
   *   Integer, what step to select for
   * @param $load
   *   Boolean, should the full file be returned or only the mmfid?
   */
  function get_files($status = FALSE, $step_order = FALSE, $load = TRUE) {
    $options = array();
    $options[] = "cid = '" . $this->cid . "'";
    // Select by the status if requested
    if ($status) {
      $options[] = "status = \"$status\"";
    }
    // Select only from the specified step
    if ($step_order) {
      $options[] = "step_order = $step_order";
    }
    $options = ' WHERE '. implode(' AND ', $options);

    // Find all matching files
    $results = db_query("SELECT mmfid FROM {media_mover_files} ". $options);
    // Put files into an array
    $files = array();
    while ($result = db_fetch_array($results)) {
      if ($load) {
        $file = media_mover_api_file_get($result['mmfid'], TRUE);
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
   * Run a complete configuration.
   *
   */
  function run($parameters = array()) {
    // No file is being passed in, run the full configuration
    foreach ($this->steps as $step) {
      // Harvest the files
      if ($step->harvest) {
        $step->run();
      }
      // Run post harvest steps
      else {
        $files = $this->get_files(MMA_FILE_STATUS_READY, $step->step_order - 1);
        $this->log('Notice', t('Configuration step: %description is acting on %count files',
          array('%description' => $step->description, '%count' => count($files) )));
        // Run the step on each of the files
        foreach ($files as $mmfile) {
          $files = $step->run($mmfile);
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
   */
  function run_file(&$file) {
    // Run each step
    foreach ($this->steps as $step) {
      // Assign the parameters
      $step->parameters = $parameters;
      // We have the file; do not harvest
      if ($step->step_order != 1) {
        $step->run($file);
      }
    }
  }


  /**
   * Run a complete configuration on a node
   *
   * only run the configuration on this file
   * @param $nid
   *   Int, Drupal node id
   */
  function run_nid($nid) {
    if (! isset($this->steps[0]->harvest_from_node)) {
      watchdog('media maover api', 'Attempted to run a configuration which does not support harvesting from nodes.', array(), WATCHDOG_INFO, l($this->name, 'admin/build/media_mover/config/' . $this->cid));
      return FALSE;
    }

    // Assign the nid
    $this->steps[0]->parameters['nid'] = $nid;
    // Harvest from this node
    $files = $this->steps[0]->run();
    foreach ($this->steps as $step) {
      // We have the file; do not harvest
      if ($step->step_order != 1) {
        foreach ($files as $file) {
          $step->run($file);
        }
      }
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
   * @TODO this is not complete. Queuing function is needed
   * @param $mmfid
   *   int, media mover file id
   *
   * @param unknown_type $mmfid
   */
  function delete_files() {
    // We don't use $this->get_files() because we just need to get
    // a list of mmfids to queue for deletion. There is an edge case
    // where a file that is currently in use could be processed after
    // the process that owns it finishes. This could allow the file
    // to be opperated on again before the deletion queue finishes.
    // The concequences of this are hopefully small

    // Fetch the files
    $files = $this->get_files();

  }


  /**
   * Retrieves all the steps for this configuration
   */
  function steps_get() {
    // Find all the steps associated with this configuration
    $results = db_query("SELECT * FROM {media_mover_step_map} WHERE cid = '%s' ORDER BY step_order", $this->cid);
    while ($result = db_fetch_array($results)) {
      // Load the step in question
      $step = media_mover_api_step_get($result['sid']);
      // If this configuration is not being saved to the db,
      // honor this on the step as well
      if ($this->passthrough) {
        $step->passthrough = $this->passthrough;
      }
      // Add the configuration defaults to each step
      $step->settings['defaults'] = $this->settings;
      // Add the step order to each step
      // Map all the additional configuration data onto the step
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
   * Removes a single step from a configuration and
   * reorders the remaining steps
   * @param $step_order
   *   int, step that should be removed
   * @return unknown_type
   */
  function step_remove($step_order) {
    $steps = array_slice($this->steps, $step_order);
    foreach ($steps as $step) {
      $step->step_order = $step_order;
      $step->save();
      $step_order++;
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
      if (! $step->remove_prepare()) {
        return FALSE;
      }
    }
    // Now remove the steps
   foreach ($this->steps as $step) {
     $this->remove();
   }

     // Should we delete this configurations files?
    if ($files) {
      // @TODO Delete files here
      //
    }

    // Remove all of the configurations steps
    foreach ($this->steps as $step) {
      $step->remove();
    }
   db_query("DELETE FROM {media_mover_configurations} WHERE cid = '%s'", $this->cid);
  }

}
