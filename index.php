<?php
  // $Id: index.php 106 2010-11-05 17:11:34Z s242720-studenti $

error_reporting(E_ALL);
// Configuration global variables
require('server/configuration.php');
// All the server side logic
require('server/server.php');

// main is defined into server/main.php
main();

?>