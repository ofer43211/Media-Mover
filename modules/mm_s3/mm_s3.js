// $Id: mm_s3.js,v 1.1.2.1 2010/04/22 12:48:22 arthuregg Exp $

/**
 * @file 
 * JS for mm_s3
 */


Drupal.behaviors.mmS3 = function (context) {
	$('.mm_s3_perm_select').bind('change', function () {
	  var perms = $(this).parent('div').siblings('.mm_s3_perm_roles');
	  if ($(this).val() == 'private') {
	    perms.show('slow').addClass('open');        
	  }
      else {
        if (perms.hasClass('open')) {
          perms.hide('slow').removeClass('open');
        }
      }
	});
}