<?php

// $Id: media_mover_ui.theme.inc,v 1.1.2.2 2010/07/18 21:20:31 msonnabaum Exp $


/**
 * @file
 * Theme functions for the Media Mover UI
 */

/**
 * Display the add configuration action selector
 * @param $element
 * @return unknown_type
 */
function theme_media_mover_ui_configuration_action_select($element) {
  foreach (element_children($element) as $item) {
    if (! element_property($element[$item])) {
      $html[] = '<div style="float: left;">';
      $html[] = drupal_render($element[$item]);
      $html[] = '</div>';
    }
  }
  $html[] = '<div style="clear: left;"></div>';
  return implode("\n", $html);
}
