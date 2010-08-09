<?php

// $Id: mm_node_theme.inc,v 1.1.2.3 2010/03/08 03:46:46 arthuregg Exp $

/**
 * @file
 * This contains the theming functions for node display
 */


function theme_mm_node_files_view($files) {

}


/**
 * Theme the Media Mover files list. Shamelessly stollen from upload.module
 *
 * @ingroup themeable
 */
function theme_mm_node_files_edit(&$form) {
  $header = array(t('List'), t('Delete'), t('Reprocess'), t('Filepath'), t('Size'), t('Status'));
  foreach (element_children($form) as $key) {
    $row = array();
    $row[] = drupal_render($form[$key]['list']);
    $row[] = drupal_render($form[$key]['remove']);
    $row[] = drupal_render($form[$key]['reprocess']);
    $row[] = drupal_render($form[$key]['filepath']);
    $row[] = drupal_render($form[$key]['size']);
    $row[] = drupal_render($form[$key]['status']);
    $rows[] = array('data' => $row);
  }
  $output = theme('table', $header, $rows, array('id' => 'mm_node-files'));
  $output .= drupal_render($form);
  return $output;
}