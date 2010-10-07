<?php

/* -------------------------------------------------------------------------
License: Apache 2
Author: Vladimir Vuksan
------------------------------------------------------------------------ */
$GLOBALS['base'] = dirname(__FILE__);

/* Load common configuration options */
require_once($GLOBALS['base'] . "/config.default.php");
/* If there are any overrides include them now */
if ( ! is_readable($GLOBALS['base'] . '/config.php') ) {
    echo("<H2>WARNING: Configuration file config.php does not exist. Please
         notify your system administrator.</H2>");
} else
    include_once($GLOBALS['base'] . '/config.php');

require_once($GLOBALS['base'] . '/tools.php');

/* -------------------------------------------------------------------------
   This script can be either invoked from a command line or as a simple
   GET script. Let's check which one it is. To be able to easily handle
   it we simply assign $_GET to $cmd_line_array to make things easy for us
   ------------------------------------------------------------------------- */
if ( isset($_GET) && sizeof($_GET) > 0 ) {
  $cmd_line_array = $_GET;
  header('Content-type: text/plain');
} else {
  $cmd_line_array = commandline_arguments($argv);
}

/* -------------------------------------------------------------------------
 Check whether we have all the necessary pieces. If not we need to bail
   ------------------------------------------------------------------------- */
if ( ! isset($cmd_line_array['pool']) ) { 
        print "You need to supply pool name e.g. 
        php list_pool_status.php --pool=Web-PROD\nExiting ...\n";
        exit(1);
}

$pool_name = $cmd_line_array["pool"];

/* -------------------------------------------------------------------------
 If we are in debug mode conduct actions against the standby
 ------------------------------------------------------------------------- */
if ( $debug == 0 ) {

    /* Should we use memcache ? */
    if ( $cache_results == 1 ) {

        /* Use Memcache to reduce number of requests */    
        $memcache = memcache_connect($memcache_server, $memcache_port);
        
        if ( $memcache ) {
    
            $key = "f5-active-lb";
            $active_lb = $memcache->get($key);
            if ( $active_lb === false ) {
              $active_lb = f5_active_server($loadbalancers, $username, $password);
              /* Cache for 2 hours */
              $memcache->set($key, $active_lb, 0, 7200);
            }

        }
        
    } else {
        
        $active_lb = f5_active_server($loadbalancers, $username, $password);

    }

} else {

  $active_lb = $standby_lb;

}

print "Active LB is " . $active_lb . "\n";

try {

  $pool_list = array($pool_name);

  $pool_states = &f5_pool_member_states( $active_lb, $pool_list, $hosts_map, $username, $password );

  for ( $i = 0; $i < sizeof($pool_states) ; $i++ ) {
      $num = $i + 1;
      print $num . ". Address: " . $pool_states[$i]['server_name'] . " Port: " . $pool_states[$i]['port'] . " State: " .
	$pool_states[$i]['status'] . "\n";

  }

} // end of try

catch (Exception $e) {
  echo "Error!
  ";
  echo $e -> getMessage ();
}

?> 
