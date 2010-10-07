<?php

/*
 This is a default configuration file. You will need to create
 a config.php file with overrides to this file.
*/
$username='admin';
$password='xxxxxxxxxx';

/* If you turn on debug make sure you set $standby_lb to the 
   hostname of your stand-by load balancer */
$debug = 0;
$standby_lb = "";

#$loadbalancers = array("fw1", "fw2");

$hosts_map = array (
    "localhost" => "127.0.0.1");

$color_array = array("#BEFF7C","#FFFDAB","#AAAAAA","#FFEBAB","#FAE5A5","#F9B2F8","#BFFFB5","#B4F8EF" );

/* Should I use memcache to cache results such as active F5 LB or Pool Names */
$cache_results = 0;
$memcache_server = "localhost";
$memcache_port = "11211";

?>
