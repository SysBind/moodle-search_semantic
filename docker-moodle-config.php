<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'postgres';
$CFG->dbpass    = 'p@ssw0rd';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
);

$CFG->wwwroot   = 'http://localhost';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->routerconfigured = true;

// Ollama runs as another container on the compose network (172.16.0.0/12) on port 11434.
// Moodle's curl security blocks private ranges and non-80/443 ports by default, so allow them here.
$CFG->curlsecurityblockedhosts = "127.0.0.0/8\n192.168.0.0/16\n10.0.0.0/8\n0.0.0.0\nlocalhost\n169.254.169.254\n0000::1";
$CFG->curlsecurityallowedport = "443\n80\n11434";

$CFG->phpunit_dataroot = '/var/www/phpunit_moodledata';
$CFG->phpunit_prefix = 'phpu_';

require_once(__DIR__ . '/lib/setup.php');
