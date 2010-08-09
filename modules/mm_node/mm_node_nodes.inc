<?php

// $Id: mm_node_nodes.inc,v 1.1.2.5 2010/03/11 21:15:59 msonnabaum Exp $

/**
 * @file
 * This file contains all of the specific functions that
 * are needed to interact with media mover content connected
 * to nodes. These functions are called from hook_nodeapi()
 */


/**
 * Adds media mover files on node_load
 *
 * Fetches files from files db for a specific node
 * invokes media_mover fetch hook to get additional data that media mover
 * modules may have saved in seperate tables regarding a node. Caches results
 * so that invoke is not so painful
 *
 * @ingroup media_mover_files
 * @param $nid
 *   node id
 * @param $status
 *   MM file status for specific files, FALSE for all files
 * @return array files
 *   media mover file array
 */
function mm_node_files_attach($nid, $status = MMA_FILE_STATUS_FINISHED) {
  // if a status is being passed set an id
  if ($status) {
    $status_id = "_$status";
    $file_status = "AND status = \"$status\" ";
  }

  // Create cache name
  $cache_name = 'media_mover_files_node_'. $nid . $status_id;
  // Get data from the cache
  $data = cache_get($cache_name, 'cache_media_mover');
  // We can haz cache?
  if (! $data) {
    $data = array();
    $items = db_query('SELECT mmfid, cid FROM {media_mover_files} WHERE nid = %d ' . $file_status . ' ORDER BY cid', $nid);
    while ($item = db_fetch_object($items)) {
      $data[$item->cid][$item->mmfid] = media_mover_api_file_get($item->mmfid);
    }
    cache_set($cache_name, $data, 'cache_media_mover', CACHE_TEMPORARY);
  }
  return $data->data;
}


/**
 * Handle Media Mover files when a node
 *
 * If a drupal file is removed from a node we can
 * detelete the media mover files associated
 * with that node. This is a per configuration option.
 *
 * @TODO this needs to be updated
 *
 * @param object $node
 *   drupal node object
 */
function mm_node_update($node) {
  // we only do this if there are media mover files that we need to delete
  // because they are linked to files on a node
  // we do not handle creation as other modules are responsible for that
  if ($node->media_mover) {
    // build a list of all the media mover files associated with this node
    foreach ($node->media_mover as $cid => $mm_files) {
      if ($mm_files) {
        foreach ($mm_files as $mm_file) {
          // load the configuration for this file
          $config = media_mover_api_get_configuration($mm_file['cid']);
          // check and see if this media mover file comes from a drupal file AND the
          // drupal file will be removed  AND the configuration removes files
          if ($node->files[$mm_file['fid']]->remove && $config->settings->mma_node_item_delete ) {
            $file->delete();
          }
        }
      }
    }
    // now reset the nodes media mover cache
    cache_clear_all('media_mover_files_node_'. $node->nid, 'cache_media_mover');
  }
}


/**
 * Deletes files associated with a specified node
 *
 * @param object node
 *   drupal node object
 */
function mm_node_files_delete($node) {
  if ($files = $node->media_mover) {
    foreach ($files as $cid => $mm_files) {
      // now we need to get each configuration to check
      // if we should delete the file
      $configuration = media_mover_api_get_configuration($cid);
      if ($configuration->settings->mma_node_item_delete) {
        foreach ($mm_files as $file) {
          $file->delete();
        }
      }
    }
  }
}


/**
 * Copy media mover data from an old node to a translated node
 * @TODO this needs to be updated for the new API
 * @param $node
 *   Object, Drupal node object
 */
function mm_node_translation_insert($node) {
  if ($node->translation_source) {
    $translation_source = $node->translation_source;
    if ($media_mover = $translation_source->media_mover) {
      foreach ($media_mover as $cid) {
        foreach ($cid as $data) {
          $file = new media_mover_file();
          $file->nid = $node->nid;
          foreach ($data as $key => $value) {
            $this->{$key} = $value;
          }
          // make sure that we do not have a mmfid
          unset($file->mmfid);
          $file->save();
          watchdog('MM Node', 'Added Media Mover files to a translated node', array(), WATCHDOG_INFO);
        }
      }
    }
  }
}


/* ************************************************* */
/* RSS Functions                                     */
/* ************************************************* */


/**
 * This governs the rss feeds for a given node
 * @param object $node drupal node object
 * @return array for enclosure
 */
function mm_node_rss($node) {
  // @TODO make settings per configuration to expose RSS
  if ($node->media_mover) {
    foreach ($node->media_mover as $cid => $files) {
      // load the configuration
      $configuration = media_mover_api_get_configuration($cid);
      if ($configuration->settings->mma_node_config_rss) {
        // take the first file that we find on the array
        $file = array_shift($files);
        // structure the RSS
        $rss = array(
          array(
            'key' => 'enclosure',
            'attributes' => array(
              'url' => $GLOBALS['base_url'] .'/'. $file['filepath'],
              'length' => format_size(filesize($file['filepath'])),
              'type' => file_get_mimetype($file['filepath']),
            )
          )
        );
        return $rss;
      }
    }
  }
}
