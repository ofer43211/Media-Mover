// $Id: media_mover_ui.js,v 1.1.2.5 2010/02/08 01:54:55 arthuregg Exp $

/**
 * @file
 * UI javascript support
 */


Drupal.behaviors.mediaMover = function (context) {
  
  /**
   * Hide all the machine name input fields when the page loads
   */
  $('.machine_name_wrapper').each(function () { 
    // Do not hide if there is an error on the machine name
    if (! $(this).children('div').children('input').hasClass('error')) {  
      $(this).css('display', 'none');
    }
  });
  
  
  /**
   * Toggle the machine name fields
   */
  $('a.machine_name_link').click(function () {
    if (! $(this).parents('.form-item').next('.machine_name_wrapper').hasClass('open')) {
      $(this).parents('.form-item').next('.machine_name_wrapper').show('slow');
      $(this).parents('.form-item').next('.machine_name_wrapper').addClass('open');
    }
    else {
      $(this).parents('.form-item').next('.machine_name_wrapper').removeClass('open');
      $(this).parents('.form-item').next('.machine_name_wrapper').hide('slow');
    }
    return false;
  });
  
  
  /**
   * Handle the step machine names
   */
   $('input.step_machine_name').each(function () {
     // On the page load, check to see if this
     // field has a value already.
     if ($(this).val()) {
       $(this).attr('step_id', '');
     }
     else {
       var step_id = $(this).attr('step_id');
       var name = $('input.step_name[step_id=' + step_id + ']').val()
       name = name.toLowerCase().replace(/[^a-z0-9]/g, '_');
       $(this).val(name);
     }
     
     // @TODO
     // bind a keyup here and remove the step_id 
   });
  
   /**
    * Find all the step names and link changes in these 
    * step names to the machine name generation
    */
  $('input.step_name').bind('keyup', function() {
    var step_id = $(this).attr('step_id');
    var name = $(this).val();
    name = name.toLowerCase().replace(/[^a-z0-9]/g, '_');
    $('input.step_machine_name[step_id=' + step_id + ']').val(name);    
  });    
  
}