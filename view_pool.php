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

?>
<html>
<head>
<title>View F5 Pool Status</title>
<style>
table th {
  background-color: #66ffff;
}

table td {
  text-align: center;
}

table tr.hostindex_even {
  background-color: #dddddd;
}

table tr.hostindex_odd {
  background-color: white;
}
table td.offline {
  background-color: orange;
}
table td.enabled {
  background-color: lightgreen;
}
table td.disabled {
  background-color: yellow;
}
table td.forced_offline {
  background-color: red;
}
</style>
</head>
<body>
<form>
    Pool name:  <select name="pool_name" onchange='this.form.submit();'>
    <option value="none">Please choose</option>

<?php

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

      $key = "f5-pool-list";
      $pool_list = $memcache->get($key);
      if ( $pool_list === false ) {
	$client = new SoapClient("https://" . $active_lb . $wsdl_pool_suffix,array('location'=> "https://" . $active_lb . $location_suffix,'login'=>$username,'password'=>$password));
	/* Get a list of all pools */
	$pool_list = $client->get_list();
	$memcache->set($key, $pool_list, 0, 28800);
      }
    
    }
        
} else {
    
    $active_lb = f5_active_server($loadbalancers, $username, $password);
    $client = new SoapClient( "https://" . $active_lb . $wsdl_pool_suffix,array('location'=> "https://" . $active_lb . $location_suffix,'login'=>$username,'password'=>$password));
    /* Get a list of all pools */
    $pool_list = $client->get_list();

}

/* Loop through the instances array */
foreach ($pool_list as $key => $value) {
  if ( isset($_GET['pool_name']) && $_GET['pool_name'] == $value ){
	  echo("<option value=\"" . $value . "\" selected>" . $value . "</option>\n");
  } else {
	  echo("<option value=\"" . $value . "\">" . $value . "</option>\n");
  }

}

print '</select></form> Active Server: ' . $active_lb . "<p>";

if ( isset($_GET['pool_name']) ) {

  if ( ! in_array($_GET['pool_name'], $pool_list)) {
    print "<font color=red>You have supplied an invalid pool name. Exiting...</font>";
    exit(1);
  }

?>

<table width=60% border=1>
<tr>
  <th>Address</th>
  <th>Port</th>
  <th>Current active conn</th>
  <th>Status</th>
</tr>

<?php

  # Rest interface expects pool list
  $pool_list_requested = array($_GET['pool_name']);

  # This is to keep track of what is the current server
  $server_num = 0;
  $current_server = "";
  
  # Get an array with pool states
  $pool_states = &f5_pool_member_states( $active_lb, $pool_list_requested, $hosts_map, $username, $password );

  for ( $i = 0; $i < sizeof($pool_states) ; $i++ ) {
  
      $server_name = $pool_states[$i]['server_name'];
      $port = $pool_states[$i]['port'];
      # Increment server num
      if ( $server_name != $current_server ) {
	$server_num++;
	$current_server = $server_name;
      }

      if ( ($server_num % 2) == 0 ) 
	$rowclass = "hostindex_even";
      else
	$rowclass = "hostindex_odd";

      print "<tr class=$rowclass><td>$server_name</td><td>" . $port ."</td><td>" .
      $pool_states[$i]['curr_connections'] . 
      "</td><td class=" . $pool_states[$i]['status'] . ">" . $pool_states[$i]['status'] . "</td>"; 
      print "</tr>";

    }

}

?>
</table>

</body>
</html>