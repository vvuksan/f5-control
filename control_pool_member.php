<?php

/* -------------------------------------------------------------------------
License: Apache 2
Author: Vladimir Vuksan
------------------------------------------------------------------------ */

/*

 Script: Controls pool number. You can invoke it on the command line e.g.

 php control_pool_member.php --pool=WEB-Prod --server=192.168.230.222 --port=80 --action=enable

or through a get method e.g.

 wget http://bla/f5/control_pool_member.php?pool=WEB-Prod&server=192.168.230.222&port=80&action=enable

 There is an optional argument force which you can use with disable ie. --force=1.
 Trick is that if you disable a pool member clients that e.g. have a valid
 session (per persistence profile) can still access the server. If you want
 to boot those off you have to force offline the node. Alternatively you
 may want to disable, wait for a while then force offline.

*/

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
if ( ! isset($cmd_line_array['pool']) || ! isset($cmd_line_array['server']) || ! isset($cmd_line_array['port']) ||  ! isset($cmd_line_array['action'])) { 
        print "You need to supply pool, server, port and action (disable or enable). force=1 is optional. Exiting ...\n";
        exit(1);
}

$pool_name = $cmd_line_array["pool"];
$server_name = $cmd_line_array["server"];

/* -------------------------------------------------------------------------
  F5 uses IPs in pool member names. We can supply hostnames instead of IPs.
  If that is the case ie. server_name supplied does not start with a
  number we'll use the hosts_map which is a simple hash that looks like this
  "web1234" => "192.168.234.200" etc.
   -------------------------------------------------------------------------*/
if ( ! preg_match("/^[0-9]/", $server_name ) ) {
  if ( isset ( $hosts_map[$server_name] ) ) 
    $server_name = $hosts_map[$server_name];
  else
    die("Hostname supplied " . $server_name . " is invalid\n");
}

$server_port = $cmd_line_array["port"];
$action = $cmd_line_array["action"];

/* Default to force=0 */
if ( isset( $cmd_line_array['force'] ) && $cmd_line_array['force'] == "1" ) {
  $force = 1;
} else {
  $force = 0;
}

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

control_pool_node( $active_lb, $username, $password, $pool_name, $server_name, $server_port, $action, $force );

?>
