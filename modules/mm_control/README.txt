; $Id: README.txt,v 1.1.2.1 2010/04/12 01:46:41 arthuregg Exp $

ABOUT
----------------------------------------------------------
This module enables some basic control over Media Mover configurations while 
they are running. It supports restricting cron runs, restricting when CPU 
usage is high, and helps distribute load in a multi-machine environment



MULTI-MACHINE
----------------------------------------------------------
To run Media Mover in a multi-machine environment, it is best
to use the specific batch processing system that MM Run Control
offers. While you can just use Drupal's standard cron runs, 
MM Run Control has a specific batch processing system that 
makes it easier for multiple machine to work in tandem.

Media Mover is easy to run in a multi-machine environment. You 
can add any number of additional machines which have the same
Drupal installation (hint, use version control!) and connect
to a shared database. This allows all the machines that are 
connected to get access to the same data.

Setup:
1) create a master Drupal configuration that is cloned to each
of your additional machines
2) all machines should connect to the same DB server
3) all machines need access to the same files repository (mount 
remote file system for example)
4) each machine doing file processing needs to have a cron job
which is point at admin/build/media_mover/batch This url should
have a unique server id appended to it, eg: 
admin/build/media_mover/batch/8core which identifies the machine
in the machine list. This can also be specified in settings.php

  $conf = array( 
    'mm_run_control_sid' => '8core',
  );
 
The advantage to doing it the cron URL is that you can use an 
environment variable (eg: HOST) which makes it very easy to deploy
this kind of solution in a cloud.

If neither of these is present, MM Run Control will try to use 
the IP of current server, however this is not a reliable ID.  


