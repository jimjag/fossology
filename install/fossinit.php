#!/usr/bin/php
<?php
/***********************************************************
 Copyright (C) 2008-2015 Hewlett-Packard Development Company, L.P.
 Copyright (C) 2014-2015,2019 Siemens AG

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

/** \brief Print Usage statement.
 *  \return No return, this calls exit.
 **/
function explainUsage()
{
  global $argv;

  $usage = "Usage: " . basename($argv[0]) . " [options]
  Update FOSSology database. This should be used immediately after an install or update. Options are:
  -c  path to fossology configuration files
  -d  {database name} default is 'fossology'
  -f  {file} update the schema with file generated by schema-export.php
  -l  update the license_ref table with fossology supplied licenses
  -r  {prefix} drop database with name starts with prefix
  -v  enable verbose preview (prints sql that would happen, but does not execute it, DB is not updated)
  --force-decision force recalculation of SHA256 for decision tables
  --force-pfile    force recalculation of SHA256 for pfile entries
  --force-encode   force recode of copyright and sister tables
  -h  this help usage";
  print "$usage\n";
  exit(0);
}


/**
 * @file fossinit.php
 * @brief This program applies core-schema.dat to the database (which
 *        must exist) and updates the license_ref table.
 * @return 0 for success, 1 for failure.
 **/

use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Db\Driver\Postgres;

/* Note: php 5 getopt() ignores options not specified in the function call, so add
 * dummy options in order to catch invalid options.
 */
$AllPossibleOpts = "abc:d:ef:ghijklmnopqr:stuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
$longOpts = [
  "force-decision",
  "force-pfile",
  "force-encode"
];

/* defaults */
$Verbose = false;
$DatabaseName = "fossology";
$UpdateLiceneseRef = false;
$sysconfdir = '';
$delDbPattern = 'the option -rfosstest will drop data bases with datname like "fosstest%"';
$forceDecision = false;
$forcePfile = false;

/* command-line options */
$Options = getopt($AllPossibleOpts, $longOpts);
foreach($Options as $optKey => $optVal)
{
  switch($optKey)
  {
    case 'c': /* set SYSCONFIDR */
      $sysconfdir = $optVal;
      break;
    case 'd': /* optional database name */
      $DatabaseName = $optVal;
      break;
    case 'f': /* schema file */
      $SchemaFilePath = $optVal;
      break;
    case 'h': /* help */
      explainUsage();
    case 'l': /* update the license_ref table */
      $UpdateLiceneseRef = true;
      break;
    case 'v': /* verbose */
      $Verbose = true;
      break;
    case 'r':
      $delDbPattern = $optVal ? "$optVal%" : "fosstest%";
      break;
    case "force-decision":
      $forceDecision = true;
      break;
    case "force-pfile":
      $forcePfile = true;
      break;
    case "force-encode":
      putenv('FOSSENCODING=1');
      break;
    default:
      echo "Invalid Option \"$optKey\".\n";
      explainUsage();
  }
}

require_once 'fossinit-common.php';

/* Set SYSCONFDIR and set global (for backward compatibility) */
$SysConf = bootstrap($sysconfdir);
$SysConf["DBCONF"]["dbname"] = $DatabaseName;
$GLOBALS["SysConf"] = array_merge($GLOBALS["SysConf"], $SysConf);
$projectGroup = $SysConf['DIRECTORIES']['PROJECTGROUP'] ?: 'fossy';
$gInfo = posix_getgrnam($projectGroup);
posix_setgid($gInfo['gid']);
$groups = `groups`;
if (!preg_match("/\s$projectGroup\s/",$groups) && (posix_getgid() != $gInfo['gid']))
{
  print "FATAL: You must be in group '$projectGroup'.\n";
  exit(1);
}

require_once("$MODDIR/vendor/autoload.php");
require_once("$MODDIR/lib/php/common-db.php");
require_once("$MODDIR/lib/php/common-container.php");
require_once("$MODDIR/lib/php/common-cache.php");
require_once("$MODDIR/lib/php/common-sysconfig.php");

/* Initialize global system configuration variables $SysConfig[] */
ConfigInit($SYSCONFDIR, $SysConf);

/** delete from copyright where pfile_fk not in (select pfile_pk from pfile) */
/** add foreign constraint on copyright pfile_fk if not exist */
/** comment out for 2.5.0
require_once("$LIBEXECDIR/dbmigrate_2.0-2.5-pre.php");
Migrate_20_25($Verbose);
*/

if (empty($SchemaFilePath)) {
  $SchemaFilePath = "$MODDIR/www/ui/core-schema.dat";
}

if (!file_exists($SchemaFilePath))
{
  print "FAILED: Schema data file ($SchemaFilePath) not found.\n";
  exit(1);
}

require_once("$MODDIR/lib/php/libschema.php");
$pgDriver = new Postgres($PG_CONN);
$libschema->setDriver($pgDriver);
$previousSchema = $libschema->getCurrSchema();
$isUpdating = array_key_exists('TABLE', $previousSchema) && array_key_exists('users', $previousSchema['TABLE']);
/** @var DbManager $dbManager */
if ($dbManager->existsTable('sysconfig'))
{
  $sysconfig = $dbManager->createMap('sysconfig', 'variablename', 'conf_value');
  if(!array_key_exists('Release', $sysconfig))
  {
    $sysconfig['Release'] = 0;
  }
  print "Old release was $sysconfig[Release]\n";
}

$migrateColumns = array('clearing_decision'=>array('reportinfo','clearing_pk','type_fk','comment'),
        'license_ref_bulk'=>array('rf_fk','removing'));
if($isUpdating && !empty($sysconfig) && $sysconfig['Release'] == '2.6.3.1')
{
  $dbManager->queryOnce('begin;
    CREATE TABLE uploadtree_b AS (SELECT * FROM uploadtree_a);
    DROP TABLE uploadtree_a;
    CREATE TABLE uploadtree_a () INHERITS (uploadtree);
    ALTER TABLE uploadtree_a ADD CONSTRAINT uploadtree_a_pkey PRIMARY KEY (uploadtree_pk);
    INSERT INTO uploadtree_a SELECT * FROM uploadtree_b;
    DROP TABLE uploadtree_b;
    COMMIT;',__FILE__.'.rebuild.uploadtree_a');
}

if($dbManager->existsTable("author"))
{
  require_once("$LIBEXECDIR/resequence_author_table.php"); // If table exists, clean up for Schema
}

// Migration script to clear tables for new constraints
require_once("$LIBEXECDIR/dbmigrate_3.3-3.4.php");
Migrate_33_34($dbManager, $Verbose);

$FailMsg = $libschema->applySchema($SchemaFilePath, $Verbose, $DatabaseName, $migrateColumns);
if ($FailMsg)
{
  print "ApplySchema failed: $FailMsg\n";
  exit(1);
}
$Filename = "$MODDIR/www/ui/init.ui";
$flagRemoved = !file_exists($Filename);
if (!$flagRemoved)
{
  if ($Verbose)
  {
    print "Removing flag '$Filename'\n";
  }
  if (is_writable("$MODDIR/www/ui/"))
  {
    $flagRemoved = unlink($Filename);
  }
}
if (!$flagRemoved)
{
  print "Failed to remove $Filename\n";
  print "Remove this file to complete the initialization.\n";
}
else
{
  print "Database schema update completed successfully.\n";
}

/* initialize the license_ref table */
if ($UpdateLiceneseRef)
{
  $row = $dbManager->getSingleRow("SELECT count(*) FROM license_ref",array(),'license_ref.count');
  if ($row['count'] >  0) {
    print "Update reference licenses\n";
    initLicenseRefTable(false);
  }
  else if ($row['count'] ==  0) {
    insertInToLicenseRefTableUsingJson('license_ref');

    $row_max = $dbManager->getSingleRow("SELECT max(rf_pk) from license_ref",array(),'license_ref.max.rf_pk');
    $current_license_ref_rf_pk_seq = $row_max['max'];
    $dbManager->getSingleRow("SELECT setval('license_ref_rf_pk_seq', $current_license_ref_rf_pk_seq)",array(),
            'set next license_ref_rf_pk_seq value');

    print "fresh install, import licenseRef.json \n";
  }
}

if (array_key_exists('r', $Options))
{
  $dbManager->prepare(__METHOD__.".getDelDbNames",'SELECT datname FROM pg_database WHERE datistemplate = false and datname like $1');
  $resDelDbNames = $dbManager->execute(__METHOD__.".getDelDbNames",array($delDbPattern));
  $delDbNames=pg_fetch_all($resDelDbNames);
  pg_free_result($resDelDbNames);
  foreach ($delDbNames as $deleteDatabaseName)
  {
    $dbManager->queryOnce("DROP DATABASE $deleteDatabaseName[datname]");
  }
  if ($Verbose)
  {
    echo "dropped " . count($delDbNames) . " databases ";
  }
}

/* migration */
$currSchema = $libschema->getCurrSchema();
$sysconfig = $dbManager->createMap('sysconfig','variablename','conf_value');
global $LIBEXECDIR;
if($isUpdating && empty($sysconfig['Release'])) {
  require_once("$LIBEXECDIR/dbmigrate_2.0-2.1.php");  // this is needed for all new installs from 2.0 on
  Migrate_20_21($Verbose);
  require_once("$LIBEXECDIR/dbmigrate_2.1-2.2.php");
  print "Migrate data from 2.1 to 2.2 in $LIBEXECDIR\n";
  Migrate_21_22($Verbose);
  if($dbManager->existsTable('license_file_audit') && array_key_exists('clearing_pk', $currSchema['TABLE']['clearing_decision']))
  {
    require_once("$LIBEXECDIR/dbmigrate_2.5-2.6.php");
    migrate_25_26($Verbose);
  }
  if(!array_key_exists('clearing_pk', $currSchema['TABLE']['clearing_decision']) && $isUpdating)
  {
    $timeoutSec = 20;
    echo "Missing column clearing_decision.clearing_pk, you should update to version 2.6.2 before migration\n";
    echo "Enter 'i' within $timeoutSec seconds to ignore this warning and run the risk of losing clearing decisions: ";
    $handle = fopen ("php://stdin","r");
    stream_set_blocking($handle,0);
    for($s=0;$s<$timeoutSec;$s++)
    {
      sleep(1);
      $line = fread($handle,1);
      if ($line) {
        break;
      }
    }
    if(trim($line) != 'i')
    {
     echo "ABORTING!\n";
     exit(26);
    }
  }
  $sysconfig['Release'] = '2.6';
}
if (! $isUpdating) {
  require_once ("$LIBEXECDIR/dbmigrate_2.1-2.2.php");
  print "Creating default user\n";
  Migrate_21_22($Verbose);
} else {
  require_once ("$LIBEXECDIR/dbmigrate_3.5-3.6.php");
  migrate_35_36($dbManager, $forceDecision);
  updatePfileSha256($dbManager, $forcePfile);
}

if(!$isUpdating || $sysconfig['Release'] == '2.6')
{
  if(!$dbManager->existsTable('license_candidate'))
  {
    $dbManager->queryOnce("CREATE TABLE license_candidate (group_fk integer) INHERITS (license_ref)");
  }
  if ($isUpdating && array_key_exists('clearing_pk', $currSchema['TABLE']['clearing_decision']))
  {
    require_once("$LIBEXECDIR/dbmigrate_clearing-event.php");
    $libschema->dropColumnsFromTable(array('reportinfo','clearing_pk','type_fk','comment'), 'clearing_decision');
  }
  $sysconfig['Release'] = '2.6.3';
}

if($sysconfig['Release'] == '2.6.3')
{
  require_once("$LIBEXECDIR/dbmigrate_real-parent.php");
}

$expiredDbReleases = array('2.6.3', '2.6.3.1', '2.6.3.2');
if($isUpdating && (empty($sysconfig['Release']) || in_array($sysconfig['Release'], $expiredDbReleases)))
{
  require_once("$LIBEXECDIR/fo_mapping_license.php");
  print "Rename license (using $LIBEXECDIR) for SPDX validity\n";
  renameLicensesForSpdxValidation($Verbose);
}

$expiredDbReleases[] = '2.6.3.3';
$expiredDbReleases[] = '3.0.0';
if($isUpdating && (empty($sysconfig['Release']) || in_array($sysconfig['Release'], $expiredDbReleases)))
{
  require_once("$LIBEXECDIR/dbmigrate_bulk_license.php");
}

if(in_array($sysconfig['Release'], $expiredDbReleases))
{
  $sysconfig['Release'] = '3.0.1';
}

// Update '3dfx' licence shortname to 'Glide'. Since shortname is used as an
// identifier, this is not done as part of the licenseref updates.
if($isUpdating && (empty($sysconfig['Release']) || $sysconfig['Release'] == '3.0.1'))
{
  $dbManager->begin();
  $row = $dbManager->getSingleRow("
    SELECT rf1.rf_pk AS id_3dfx,
           rf2.rf_pk AS id_glide
    FROM license_ref rf1
      INNER JOIN license_ref rf2 USING (rf_fullname)
    WHERE rf1.rf_shortname='3DFX'
      AND rf2.rf_shortname='Glide'
    LIMIT 1", array(), 'old.3dfx.rf_pk');
  if (!empty($row))
  {
    $id_3dfx = intval($row['id_3dfx']);
    $id_glide = intval($row['id_glide']);
    $dbManager->queryOnce("DELETE FROM license_ref WHERE rf_pk=$id_glide");
    $dbManager->queryOnce("UPDATE license_ref SET rf_shortname='Glide' WHERE rf_pk=$id_3dfx");
  }
  $dbManager->commit();

  $sysconfig['Release'] = "3.0.2";
}

if($isUpdating && (empty($sysconfig['Release']) || $sysconfig['Release'] == '3.0.2'))
{
  require_once("$LIBEXECDIR/dbmigrate_multiple_copyright_decisions.php");

  $sysconfig['Release'] = "3.1.0";
}

// fix release-version datamodel-version missmatch
if($isUpdating && (empty($sysconfig['Release']) || $sysconfig['Release'] == "3.1.0")) {
  $sysconfig['Release'] = "3.3.0";
}

$dbManager->begin();
$dbManager->getSingleRow("DELETE FROM sysconfig WHERE variablename=$1",array('Release'),'drop.sysconfig.release');
$dbManager->insertTableRow('sysconfig',
        array('variablename'=>'Release','conf_value'=>$sysconfig['Release'],'ui_label'=>'Release','vartype'=>2,'group_name'=>'Release','description'=>''));
$dbManager->commit();
/* email/url/author data migration to other table */
require_once("$LIBEXECDIR/dbmigrate_copyright-author.php");

// Migration script to move candidate licenses in obligations
require_once("$LIBEXECDIR/dbmigrate_3.6-3.7.php");
Migrate_36_37($dbManager, $Verbose);

/* instance uuid */
require_once("$LIBEXECDIR/instance_uuid.php");

// Migration script for 3.7 => 3.8
require_once("$LIBEXECDIR/dbmigrate_3.7-3.8.php");
Migrate_37_38($dbManager, $MODDIR);

/* sanity check */
require_once ("$LIBEXECDIR/sanity_check.php");
$checker = new SanityChecker($dbManager,$Verbose);
$errors = $checker->check();

if($errors>0)
{
  echo "ERROR: $errors sanity check".($errors>1?'s':'')." failed\n";
}
exit($errors);

/**
 * \brief insert into license_ref table using json file.
 *
 * \param $tableName
 **/
function insertInToLicenseRefTableUsingJson($tableName)
{
  global $LIBEXECDIR;
  global $dbManager;

  if (!is_dir($LIBEXECDIR)) {
    print "FATAL: Directory '$LIBEXECDIR' does not exist.\n";
    return (1);
  }

  $dir = opendir($LIBEXECDIR);
  if (!$dir) {
    print "FATAL: Unable to access '$LIBEXECDIR'.\n";
    return (1);
  }
  $dbManager->begin();
  if ($tableName === 'license_ref_2') {
    $dbManager->queryOnce("DROP TABLE IF EXISTS license_ref_2",
      __METHOD__.'.dropAncientBackUp');
    $dbManager->queryOnce("CREATE TABLE license_ref_2 (LIKE license_ref INCLUDING DEFAULTS)",
      __METHOD__.'.backUpData');
  }
  /** import licenseRef.json */
  $keysToBeChanged = array(
    'rf_OSIapproved' => '"rf_OSIapproved"',
    'rf_FSFfree'=> '"rf_FSFfree"',
    'rf_GPLv2compatible' => '"rf_GPLv2compatible"',
    'rf_GPLv3compatible'=> '"rf_GPLv3compatible"',
    'rf_Fedora' => '"rf_Fedora"'
    );

  $jsonData = json_decode(file_get_contents("$LIBEXECDIR/licenseRef.json"), true);
  $statementName = __METHOD__.'.insertInTo'.$tableName;
  foreach($jsonData as $licenseArray) {
    $arrayKeys = array_keys($licenseArray);
    $arrayValues = array_values($licenseArray);
    $keys = strtr(implode(",", $arrayKeys), $keysToBeChanged);
    $valuePlaceHolders = "$" . join(",$",range(1, count($arrayKeys)));
    $md5PlaceHolder = "$". (count($arrayKeys) + 1);
    $arrayValues[] = $licenseArray['rf_text'];
    $SQL = "INSERT INTO $tableName ( $keys,rf_md5 ) " .
      "VALUES ($valuePlaceHolders,md5($md5PlaceHolder));";
    $dbManager->prepare($statementName, $SQL);
    $dbManager->execute($statementName, $arrayValues);
  }
  $dbManager->commit();
  return (0);
}

/**
 * \brief Load the license_ref table with licenses.
 *
 * \param $Verbose display database load progress information.  If $Verbose is false,
 * this function only prints errors.
 *
 * \return 0 on success, 1 on failure
 **/
function initLicenseRefTable($Verbose)
{
  global $dbManager;

  $dbManager->begin();
  insertInToLicenseRefTableUsingJson('license_ref_2');
  $dbManager->prepare(__METHOD__.".newLic", "SELECT * FROM license_ref_2");
  $result_new = $dbManager->execute(__METHOD__.".newLic");

  $dbManager->prepare(__METHOD__.'.licenseRefByShortname',
    'SELECT *,md5(rf_text) AS hash FROM license_ref WHERE rf_shortname=$1');
  /** traverse all records in user's license_ref table, update or insert */
  while ($row = pg_fetch_assoc($result_new))
  {
    $rf_shortname = $row['rf_shortname'];
    $result_check = $dbManager->execute(__METHOD__.'.licenseRefByShortname', array($rf_shortname));
    $count = pg_num_rows($result_check);

    $rf_text = $row['rf_text'];
    $rf_md5 = $row['rf_md5'];
    $rf_url = $row['rf_url'];
    $rf_fullname = $row['rf_fullname'];
    $rf_notes = $row['rf_notes'];
    $rf_active = $row['rf_active'];
    $marydone = $row['marydone'];
    $rf_text_updatable = $row['rf_text_updatable'];
    $rf_detector_type = $row['rf_detector_type'];
    $rf_flag = $row['rf_flag'];

    if ($count) // update when it is existing
    {
      $row_check = pg_fetch_assoc($result_check);
      pg_free_result($result_check);
      $params = array();
      $rf_text_check = $row_check['rf_text'];
      $rf_md5_check = $row_check['rf_md5'];
      $hash_check = $row_check['hash'];
      $rf_url_check = $row_check['rf_url'];
      $rf_fullname_check = $row_check['rf_fullname'];
      $rf_notes_check = $row_check['rf_notes'];
      $rf_active_check = $row_check['rf_active'];
      $marydone_check = $row_check['marydone'];
      $rf_text_updatable_check = $row_check['rf_text_updatable'];
      $rf_detector_type_check = $row_check['rf_detector_type'];
      $rf_flag_check = $row_check['rf_flag'];

      $candidateLicense = isACandidateLicense($dbManager, $rf_shortname);
      if ($candidateLicense) {
        mergeCandidateLicense($dbManager, $candidateLicense);
      }

      $statement = __METHOD__ . ".updateLicenseRef";
      $sql = "UPDATE license_ref set ";
      if (($rf_flag_check == 2 && $rf_flag == 1) ||
        ($hash_check != $rf_md5_check)) {
        $params[] = $rf_text_check;
        $position = "$" . count($params);
        $sql .= "rf_text=$position,rf_md5=md5($position),";
        $statement .= ".text";
      } else {
        if ($rf_text_check != $rf_text && !empty($rf_text) &&
          !(stristr($rf_text, 'License by Nomos'))) {
          $params[] = $rf_text;
          $position = "$" . count($params);
          $sql .= "rf_text=$position,rf_md5=md5($position),rf_flag=1,";
          $statement .= ".insertT";
        }
      }
      if ($rf_url_check != $rf_url && !empty($rf_url)) {
        $params[] = $rf_url;
        $position = "$" . count($params);
        $sql .= "rf_url=$position,";
        $statement .= ".url";
      }
      if ($rf_fullname_check != $rf_fullname && !empty($rf_fullname)) {
        $params[] = $rf_fullname;
        $position = "$" . count($params);
        $sql .= "rf_fullname=$position,";
        $statement .= ".name";
      }
      if ($rf_notes_check != $rf_notes && !empty($rf_notes)) {
        $params[] = $rf_notes;
        $position = "$" . count($params);
        $sql .= "rf_notes=$position,";
        $statement .= ".notes";
      }
      if ($rf_active_check != $rf_active && !empty($rf_active)) {
        $params[] = $rf_active;
        $position = "$" . count($params);
        $sql .= "rf_active=$position,";
        $statement .= ".active";
      }
      if ($marydone_check != $marydone && !empty($marydone)) {
        $params[] = $marydone;
        $position = "$" . count($params);
        $sql .= "marydone=$position,";
        $statement .= ".marydone";
      }
      if ($rf_text_updatable_check != $rf_text_updatable && !empty($rf_text_updatable)) {
        $params[] = $rf_text_updatable;
        $position = "$" . count($params);
        $sql .= "rf_text_updatable=$position,";
        $statement .= ".tUpdate";
      }
      if ($rf_detector_type_check != $rf_detector_type && !empty($rf_detector_type)) {
        $params[] = $rf_detector_type;
        $position = "$" . count($params);
        $sql .= "rf_detector_type=$position,";
        $statement .= ".dType";
      }
      $sql = substr_replace($sql, "", -1);

      if ($sql != "UPDATE license_ref set") { // check if have something to update
        $params[] = $rf_shortname;
        $position = "$" . count($params);
        $sql .= " WHERE rf_shortname=$position;";
        $dbManager->getSingleRow($sql, $params, $statement);
      }
    } else {  // insert when it is new
      pg_free_result($result_check);
      $params = array();
      $params['rf_shortname'] = $rf_shortname;
      $params['rf_text'] = $rf_text;
      $params['rf_url'] = $rf_url;
      $params['rf_fullname'] = $rf_fullname;
      $params['rf_notes'] = $rf_notes;
      $params['rf_active'] = $rf_active;
      $params['rf_text_updatable'] = $rf_text_updatable;
      $params['rf_detector_type'] = $rf_detector_type;
      $params['marydone'] = $marydone;
      insertNewLicense($dbManager, $params);
    }
  }
  pg_free_result($result_new);

  $dbManager->queryOnce("DROP TABLE license_ref_2");
  $dbManager->commit();

  return (0);
} // initLicenseRefTable()

/**
 * Check if the given shortname is a candidate license.
 *
 * @param DbManager $dbManager DbManager used
 * @param string $rf_shortname Shortname of the license to check
 * @returns False if the license is not candidate else DB row
 */
function isACandidateLicense($dbManager, $rf_shortname)
{
  $sql = "SELECT * FROM ONLY license_candidate WHERE rf_shortname = $1;";
  $candidateRow = $dbManager->getSingleRow($sql, array($rf_shortname));
  if (! empty($candidateRow) > 0) {
    return $candidateRow;
  } else {
    return false;
  }
}

/**
 * Merge the candidate license to the main license_ref table.
 *
 * @param DbManager $dbManager    DbManager used
 * @param array $candidateLicense Shortname of the license to check
 * @return integer License ID
 */
function mergeCandidateLicense($dbManager, $candidateLicense)
{
  $dbManager->begin();
  $deleteSql = "DELETE FROM license_candidate WHERE rf_pk = $1;";
  $deleteStatement = __METHOD__ . ".deleteCandidte";
  $dbManager->prepare($deleteStatement, $deleteSql);
  $dbManager->execute($deleteStatement, array($candidateLicense['rf_pk']));
  $licenseId = insertNewLicense($dbManager, $candidateLicense, true);
  $dbManager->commit();
  return $licenseId;
}

/**
 * Insert new license to license_ref
 *
 * @param DbManager $dbManager  DbManager to be used
 * @param array $license        License row to be added
 * @param boolean $wasCandidate Was the new license already a candidate?
 *        (required for rf_pk)
 * @return integer New license ID
 */
function insertNewLicense($dbManager, $license, $wasCandidate = false)
{
  $insertStatement = __METHOD__ . ".insertNewLicense";
  $sql = "INSERT INTO license_ref (";
  if ($wasCandidate) {
    $sql .= "rf_pk, ";
    $insertStatement .= ".wasCandidate";
  }
  $sql .= "rf_shortname, rf_text, rf_url, rf_fullname, rf_notes, rf_active, " .
    "rf_text_updatable, rf_detector_type, marydone, rf_md5, rf_add_date" .
    ") VALUES (";
  $params = array();
  if ($wasCandidate) {
    $params[] = $license['rf_pk'];
  }
  $params[] = $license['rf_shortname'];
  $params[] = $license['rf_text'];
  $params[] = $license['rf_url'];
  $params[] = $license['rf_fullname'];
  $params[] = $license['rf_notes'];
  $params[] = $license['rf_active'];
  $params[] = $license['rf_text_updatable'];
  $params[] = $license['rf_detector_type'];
  $params[] = $license['marydone'];

  for ($i = 1; $i <= count($params); $i++) {
    $sql .= "$" . $i . ",";
  }

  $params[] = $license['rf_text'];
  $textPos = "$" . count($params);

  $sql .= "md5($textPos),now())";
  return $dbManager->insertPreparedAndReturn($insertStatement, $sql, $params,
    "rf_pk");
}
