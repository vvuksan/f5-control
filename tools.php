<?php

/* -------------------------------------------------------------------------
License: Apache 2
Author: Vladimir Vuksan
------------------------------------------------------------------------ */
$debug = 0;
/* Basic WSDL settings */
$GLOBALS['wsdl_pool_suffix'] = "/iControl/iControlPortal.cgi?WSDL=LocalLB.Pool";
$GLOBALS['wsdl_poolmember_suffix'] = "/iControl/iControlPortal.cgi?WSDL=LocalLB.PoolMember";
$GLOBALS['wsdl_service_failover_suffix'] = "/iControl/iControlPortal.cgi?WSDL=System.Failover";
$GLOBALS['location_suffix'] = "/iControl/iControlPortal.cgi?";

/* -------------------------------------------------------------------------
  Copied from
  http://www.php.net/features.commandline
  If the argument is of the form –NAME=VALUE it will be represented in the array as an element
  with the key NAME and the value VALUE. If the argument is a flag of the form -NAME
 it will be represented as a boolean with the name NAME with a value of true in the associative array.

  Example:
 
 <?php print_r(arguments($argv));
  php5 myscript.php --user=nobody --password=secret -p
 
 Array
 (
     [user] => nobody
     [password] => secret
     [p] => true
 ) 
 ------------------------------------------------------------------------- */
function commandline_arguments($argv) {
    $_ARG = array();
    foreach ($argv as $arg) {
        if (ereg('--[a-zA-Z0-9]*=.*',$arg)) {
            $str = split("=",$arg); $arg = '';
            $key = ereg_replace("--",'',$str[0]);
            for ( $i = 1; $i < count($str); $i++ ) {
                $arg .= $str[$i];
            }
                        $_ARG[$key] = $arg;
        } elseif(ereg('-[a-zA-Z0-9]',$arg)) {
            $arg = ereg_replace("-",'',$arg);
            $_ARG[$arg] = 'true';
        }
   
    }
return $_ARG;
}



/* -------------------------------------------------------------------------
 Convert node IPs from ip_address to hostname for easier control
 ------------------------------------------------------------------------- */
function f5_convert_ip_to_hostname ( $ip_address, $hosts_map ) {

  foreach ( $hosts_map as $host => $ip ) {
    if ( $ip == $ip_address )
      return $host;
  }
  
  /* If IP address hasn't been found return false */
  return false;
 
}



/* -------------------------------------------------------------------------
 Find F5 active server
 ------------------------------------------------------------------------- */
function f5_active_server ( $lbs, $username, $password ) {
  
  global $location_suffix, $wsdl_service_failover_suffix;
  
  foreach ( $lbs as $index => $lb ) {
  
    $location = "https://$lb" . $GLOBALS['location_suffix'];

    $wsdl_service_failover = "https://$lb" . $GLOBALS['wsdl_service_failover_suffix'];

    $client = new SoapClient($wsdl_service_failover, array('location' => $location,'login'=> $username,'password'=>$password));

    $result = $client->get_failover_state();

    if ( $result == "FAILOVER_STATE_ACTIVE" )
      return $lb;
  }

  return false;

}

/* -------------------------------------------------------------------------
 Find F5 active server
 ------------------------------------------------------------------------- */
function f5_pool_member_states ( $lb, $pool_list, $hosts_map, $username, $password ) {

  $wsdl_poolmember = "https://$lb/iControl/iControlPortal.cgi?WSDL=LocalLB.PoolMember";
  $location = "https://$lb/iControl/iControlPortal.cgi?";

  $client = new SoapClient($wsdl_poolmember,array('location'=>$location,'login'=>$username,'password'=>$password));

  $memberlist = $client->get_object_status($pool_list);

  foreach ($pool_list as $index => $pool)  {

    # Loop through each pool member to find out the number of active
    # connections. You could possibly get rid of this if you don't
    # care for this info.
    foreach ($memberlist[$index] as $member_index => $member_value)
    {

      $ip = $member_value->member->address;
      $port = $member_value->member->port;
      $availability_status = $member_value->object_status->availability_status;
      $enabled_status = $member_value->object_status->enabled_status;
      
      switch ( $availability_status ) {
	case "AVAILABILITY_STATUS_GREEN":
	  if ( $enabled_status == "ENABLED_STATUS_ENABLED" )
	    $status = "enabled";
	  else
	    $status = "disabled";
	  break;
	case "AVAILABILITY_STATUS_RED":
	  if ( $enabled_status == "ENABLED_STATUS_ENABLED" )
	    $status = "offline";
	  else
	    $status = "forced_offline";
	  break;
      }

      $request->address = $ip;
      $request->port = $port;
	
      /* Don't ask why we have to wrap it in two arrays but we do */
      $full_request = array(array($request));
	    
      $result = $client->get_statistics( $pool_list , $full_request);

      $curr_connections = $result[0]->statistics[0]->statistics[4]->value->low;
      
      unset($full_request);

      /* convert IP address as shown in F5 into a host name */
      $address = f5_convert_ip_to_hostname($ip, $hosts_map);

      if ( $address === false )
	$address = $ip;

      $pool_states[] = array ( "server_name" => $address,
	"port" => $port,
	"status" => $status,
	"curr_connections" => $curr_connections
      );
      
    }
  }

  return $pool_states;
  
} //


/* -------------------------------------------------------------------------
  Control Node
  ------------------------------------------------------------------------- */

function control_pool_node( $lb, $username, $password, $pool_name, $server_name, $server_port, $action, $force = 1 ) {

  /* initialize client */
  $wsdl_poolmember = "https://$lb/iControl/iControlPortal.cgi?WSDL=LocalLB.PoolMember";
  $location = "https://$lb/iControl/iControlPortal.cgi?";  
  
  $client = new SoapClient($wsdl_poolmember,array('location'=>$location,'login'=>$username,'password'=>$password, "trace" => 0));

  /* -------------------------------------------------------------------------
   Per this thread
   http://devcentral.f5.com/Forums/tabid/1082223/asg/51/showtab/groupforums/aff/1/aft/84952/afv/topic/Default.aspx
   Enabled (All traffic allowed):
   set_monitor_state : STATE_ENABLED
   set_session_enabled_state : STATE_ENABLED

   Disabled (Only persistent or active connections allowed):
   set_monitor_state : STATE_ENABLED
   set_session_enabled_state : STATE_DISABLED

   Forced Offline (Only active connections allowed):
   set_monitor_state : STATE_DISABLED
   set_session_enabled_state : STATE_DISABLED
   ------------------------------------------------------------------------- */

  try {

    $pool_list = array($pool_name);
    
    print "LB: $lb Pool: $pool_name Action: $action Server: $server_name Port: $server_port\n";
      
    $request->member->address = $server_name;
    $request->member->port = $server_port;
      
    if ( $action == "enable" ) {
	$request->session_state = "STATE_ENABLED";
	$request->monitor_state = "STATE_ENABLED";  
    } 
    
    if ( $action == "disable" ) {
	$request->session_state = "STATE_DISABLED";
	if ( $force == 1 ) {
	  $request->monitor_state = "STATE_DISABLED";  
	} else {
	  $request->monitor_state = "STATE_ENABLED";  
	}

    }
    
    /* Don't ask why we have to wrap it in two arrays but we do */
    $full_request = array(array($request));

    $result = $client->set_session_enabled_state( $pool_list , $full_request);
    $result = $client->set_monitor_state( $pool_list , $full_request);

//     if ( $debug == 1 ) {
//       print "<pre>\n";
//       print "Request :\n".($client->__getLastRequest()) ."\n";
//       print "Response:\n".($client->__getLastResponse())."\n";
//       print "</pre>";     
//     }

  }

  catch (SoapFault $fault) {
      trigger_error("SOAP Fault: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})", E_USER_ERROR);
  }

}

?>
