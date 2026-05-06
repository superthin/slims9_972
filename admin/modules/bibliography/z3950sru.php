<?php
/**
 * Copyright (C) 2012 Arie Nugraha (dicarve@yahoo.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

/* Z3950 Web Services section */

// key to authenticate
define('INDEX_AUTH', '1');
// key to get full database access
define('DB_ACCESS', 'fa');

if (!isset ($errors)) {
    $errors = false;
}

// start the session
require '../../../sysconfig.inc.php';
// IP based access limitation
require LIB.'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
require SB.'admin/default/session.inc.php';
require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';
require MDLBS.'system/biblio_indexer.inc.php';

// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">'.__('You are not authorized to view this section').'</div>');
}
# CHECK ACCESS
if ($_SESSION['uid'] != 1) {
    if (!utility::haveAccess('bibliography.z3950-sru')) {
        die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
    }
}

// get servers
$server_q = $dbs->query('SELECT name, uri, server_id FROM mst_servers WHERE server_type = 3 ORDER BY name ASC');
while ($server = $server_q->fetch_assoc()) {
  $sysconf['z3950_SRU_source'][] = array('id' => $server['server_id'], 'uri' => $server['uri'], 'name' => $server['name']);
}

if (isset($_GET['z3950_SRU_source'])) {
    $inList = (bool)count($matchServer = array_values(array_filter($sysconf['z3950_SRU_source'], fn($sru) => trim(urldecode($_GET['z3950_SRU_source'])) == $sru['uri'])));
    $zserver = $inList ? trim(urldecode($_GET['z3950_SRU_source'])) : '';
    $matchServer = array_pop($matchServer);
} else {
    $zserver = 'http://z3950.loc.gov:7090/voyager?';
}

/* RECORD OPERATION */
if (isset($_POST['zrecord']) && isset($_SESSION['z3950result'])) {

  require MDLBS.'bibliography/biblio_utils.inc.php';

  $gmd_cache = array();
  $publ_cache = array();
  $place_cache = array();
  $lang_cache = array();
  $author_cache = array();
  $subject_cache = array();
  $input_date = date('Y-m-d H:i:s');
  // create dbop object
  $sql_op = new simbio_dbop($dbs);
  $r = 0;

  foreach ($_POST['zrecord'] as $id) {
      // get record detail
      $record = $_SESSION['z3950result'][$id];
      // insert record to database
      if ($record) {
          // create dbop object
          $sql_op = new simbio_dbop($dbs);
          // escape all string value
          foreach ($record as $field => $content) { if (is_string($content)) { $biblio[$field] = $dbs->escape_string(trim($content)); } }
          // gmd
          $biblio['gmd_id'] = utility::getID($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', $record['gmd']??'', $gmd_cache);
          unset($biblio['gmd']);
          // publisher
          $biblio['publisher_id'] = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $record['publisher']??'', $publ_cache);
          unset($biblio['publisher']);
          // publish place
          $biblio['publish_place_id'] = utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $record['publish_place']??'', $place_cache);
          unset($biblio['publish_place']);
          // language
          $biblio['language_id'] = utility::getID($dbs, 'mst_language', 'language_id', 'language_name', getArrayData($record, 'language.name.code'), $lang_cache);
          unset($biblio['language']);
          // authors
          $authors = array();
          if (isset($record['authors'])) {
              $authors = $record['authors'];
              unset($biblio['authors']);
          }
          // subject
          $subjects = array();
          if (isset($record['subjects'])) {
              $subjects = $record['subjects'];
              unset($biblio['subjects']);
          }

          $biblio['source'] = array_search('z3950 SRU server', $sysconf['p2pserver_type']) . '.' . ($_SESSION['z3950sru_id']??0);
          $biblio['input_date'] = $biblio['create_date']??date('Y-m-d H:i:s');
          // $biblio['last_update'] = $biblio['modified_date'];
          $biblio['last_update'] = date('Y-m-d H:i:s');

          // remove unneeded elements
          unset($biblio['manuscript']);
          unset($biblio['collection']);
          unset($biblio['resource_type']);
          unset($biblio['genre_authority']);
          unset($biblio['genre']);
          unset($biblio['issuance']);
          unset($biblio['location']);
          unset($biblio['id']);
          unset($biblio['create_date']);
          unset($biblio['modified_date']);
          unset($biblio['origin']);

          // fot debugging purpose
          // var_dump($biblio);
          // die();

          // insert biblio data
          $sql_op->insert('biblio', $biblio);
          echo '<p>'.$sql_op->error.'</p><p>&nbsp;</p>';
          $biblio_id = $sql_op->insert_id;
          if ($biblio_id < 1) {
              continue;
          }
          // insert authors
          if ($authors) {
              $author_id = 0;
              foreach ($authors as $author) {
                  $author_id = getAuthorID($author['name'], strtolower(substr($author['author_type'], 0, 1)), $author_cache);
                  @$dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblio_id, $author_id, ".$author['level'].")");
              }
          }
          // insert subject/topical terms
          if ($subjects) {
              foreach ($subjects as $subject) {
                  if ($subject['term_type'] == 'Temporal') {
                      $subject_type = 'tm';
                  } else if ($subject['term_type'] == 'Genre') {
                      $subject_type = 'gr';
                  } else if ($subject['term_type'] == 'Occupation') {
                      $subject_type = 'oc';
                  } else {
                      $subject_type = strtolower(substr($subject['term_type'], 0, 1));
                  }
                  $subject_id = getSubjectID($subject['term'], $subject_type, $subject_cache);
                  @$dbs->query("INSERT IGNORE INTO biblio_topic (biblio_id, topic_id, level) VALUES ($biblio_id, $subject_id, 1)");
              }
          }
          if ($biblio_id) {
              // create biblio_indexer class instance
              $indexer = new biblio_indexer($dbs);
              // update index
              $indexer->makeIndex($biblio_id);
              // write to logs
              writeLog('staff', $_SESSION['uid'], 'bibliography',sprintf(__('%s insert bibliographic data from Z3950 service (server : %s) with title (%s) and biblio_id (%s)'),$_SESSION['realname'],$zserver,$biblio['title'],$biblio_id), 'Z3950 SRU', 'Add');  
              $r++;
          }
      }
  }

  unset($_SESSION['z3950result']);
  exit();
}
/* RECORD OPERATION END */

/* SEARCH OPERATION */
if (isset($_GET['keywords']) AND $can_read) {
  
  if (empty($zserver)) die('<div class="errorBox">'. __('Current z3950 SRU address is not register in database!') .'</div>');

  require LIB.'modsxmlslims.inc.php';
  require LIB.'marcxmlslims.inc.php';
  $_SESSION['z3950result'] = array();
  if ($_GET['index'] != 0) {
    $index = trim($_GET['index']).'=';
    $keywords = urlencode($index.'"'.trim($_GET['keywords'].'"'));
  } else {
    $keywords = urlencode('"'.trim($_GET['keywords']).'"');
  }

  $query = '';
  if ($keywords) {

    if (isset($matchServer) && isset($matchServer['id'])) {
      $_SESSION['z3950sru_id'] = $matchServer['id'];
    }

    // Try to fetch with MARCXML first, then fallback to MODS
    $sru_server = $zserver.'?version=1.1&operation=searchRetrieve&query='.$keywords.'&startRecord=1&maximumRecords=20&recordSchema=marcxml';
    
    // Use DOMDocument for better namespace handling
    libxml_use_internal_errors(true);
    $sru_dom = new DOMDocument();
    $sru_dom->load($sru_server);
    
    // Check if we got a valid response with MARCXML
    $use_marcxml = false;
    if ($sru_dom) {
        $xpath = new DOMXPath($sru_dom);
        $xpath->registerNamespace('zs', 'http://www.loc.gov/zing/srw/');
        $xpath->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');
        
        $records = $xpath->query('//zs:records//marc:record');
        if ($records && $records->length > 0) {
            $use_marcxml = true;
        }
    }
    
    // If MARCXML didn't work, try MODS
    if (!$use_marcxml) {
        $sru_server = $zserver.'?version=1.1&operation=searchRetrieve&query='.$keywords.'&startRecord=1&maximumRecords=20&recordSchema=mods';
        $sru_dom = new DOMDocument();
        $sru_dom->load($sru_server);
    }
    
    // below is for debugging purpose
    // echo '<pre>'; var_dump($sru_xml); echo '</pre>'; exit();
    // Debug: Check XML structure
    // if ($sru_xml) {
    //     echo '<pre>'; 
    //     echo "XML Structure:\n";
    //     print_r($sru_xml);
    //     echo "\n\nSRW Children:\n";
    //     $debug_zs = $sru_xml->children('http://www.loc.gov/zing/srw/');
    //     print_r($debug_zs);
    //     echo '</pre>'; 
    //     exit();
    // }
    // Setup XPath for processing
    $xpath = new DOMXPath($sru_dom);
    $xpath->registerNamespace('zs', 'http://www.loc.gov/zing/srw/');
    $xpath->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');
    $xpath->registerNamespace('mods', 'http://www.loc.gov/mods/v3');
    
    // Get number of hits
    $hits_node = $xpath->query('//zs:numberOfRecords')->item(0);
    $hits = $hits_node ? (int)$hits_node->textContent : 0;

    if ($hits > 0) {
      echo '<div class="infoBox">' . str_replace('{hits}', $hits,__('Found {hits} records from Z3950 SRU Server.')) . '</div>';
      $table = new simbio_table();
      $table->table_attr = 'align="center" class="s-table table" cellpadding="5" cellspacing="0"';
      echo  '<div class="p-3">
              <input value="'.__('Check All').'" class="check-all button btn btn-default" type="button"> 
              <input value="'.__('Uncheck All').'" class="uncheck-all button btn btn-default" type="button">
              <input type="submit" name="saveResult" class="s-btn btn btn-success save" value="' . __('Save Z3950 Records to Database') . '" /></div>';
      // table header
      $table->setHeader(array(__('Select'),__('Title'),__('ISBN/ISSN'),__('GMD'),__('Collation'),__('Publisher'),__('Publishing Year')));
      $table->table_header_attr = 'class="dataListHeader alterCell font-weight-bold"';
      $table->setCellAttr(0, 0, '');

      $row = 1;
      $records_displayed = 0;
      
      // Debug: Log parsing issues
      $parse_errors = array();
      
      // Process records using XPath
      $records = $xpath->query('//zs:records//zs:record');
      
      foreach ($records as $rec) {
        // Detect format and parse accordingly
        $parsed_record = null;
        
        // Try MARCXML first
        $marc_records = $xpath->query('.//marc:record', $rec);
        if ($marc_records && $marc_records->length > 0) {
            // MARCXML format
            $marc_record = $marc_records->item(0);
            // Convert to SimpleXMLElement for our parser
            $marc_xml_str = $sru_dom->saveXML($marc_record);
            $marc_sxe = simplexml_load_string($marc_xml_str);
            if ($marc_sxe) {
                $parsed_record = marcXMLslims($marc_sxe);
            }
        } else {
            // Try MODS format
            $mods_records = $xpath->query('.//mods:mods', $rec);
            if ($mods_records && $mods_records->length > 0) {
                $mods_record = $mods_records->item(0);
                $mods_xml_str = $sru_dom->saveXML($mods_record);
                $mods_sxe = simplexml_load_string($mods_xml_str);
                if ($mods_sxe) {
                    $parsed_record = modsXMLslims($mods_sxe);
                }
            }
        }
        
        // Skip if we couldn't parse the record
        if (!$parsed_record || empty($parsed_record['title'])) {
            // Log error for debugging
            $parse_errors[] = "Row $row: Failed to parse record - " . ($parsed_record ? "Missing title" : "Parse returned null");
            continue;
        }
        
        $records_displayed++;
        
        // save it to session vars for retrieving later
        $_SESSION['z3950result'][$row] = $parsed_record;

        // authors
        $authors = array(); foreach ($parsed_record['authors']??[] as $auth) { $authors[] = $auth['name']; }

        $row_class = ($row%2 == 0)?'alterCell':'alterCell2';

        $cb = '<input type="checkbox" name="zrecord['.$row.']" value="'.$row.'">';

        $title_content = '<div class="media">
                      <div class="media-body">
                        <div class="title">'.stripslashes($parsed_record['title']).'</div><div class="authors">'.implode(' - ', $authors).'</div>
                      </div>
                    </div>';

        $table->appendTableRow(array($cb,
          $title_content,
          ($parsed_record['isbn_issn']??'-'),
          ($parsed_record['gmd']??'-'),
          ($parsed_record['collation']??'-'),
          ($parsed_record['publisher']??'-'),
          ($parsed_record['publish_year']??'-'),
        ));
        // set cell attribute
        $row_class = ($row%2 == 0)?'alterCell':'alterCell2';
        $table->setCellAttr($row, 0, 'class="'.$row_class.'" valign="top" style="width: 5px;"');
        $table->setCellAttr($row, 1, 'class="'.$row_class.'" valign="top" style="width: auto;"');
        $table->setCellAttr($row, 2, 'class="'.$row_class.'" valign="top" style="width: auto;"');
        $table->setCellAttr($row, 2, 'class="'.$row_class.'" valign="top" style="width: auto;"');
        $table->setCellAttr($row, 2, 'class="'.$row_class.'" valign="top" style="width: auto;"');
        $table->setCellAttr($row, 2, 'class="'.$row_class.'" valign="top" style="width: auto;"');      
        $table->setCellAttr($row, 2, 'class="'.$row_class.'" valign="top" style="width: auto;"');      
        $row++;
      }

      // If no records were displayed, show a message with debug info
      if ($records_displayed == 0) {
          echo '<div class="errorBox">' . __('Records found but unable to parse. Please check the server response format.') . '</div>';
          // Show debug info
          if (!empty($parse_errors)) {
              echo '<div class="infoBox"><strong>Debug Info:</strong><ul>';
              foreach ($parse_errors as $err) {
                  echo '<li>'.$err.'</li>';
              }
              echo '</ul></div>';
          }
      } else {
          echo $table->printTable();
      }
    } else {
      echo '<div class="errorBox">'.__('No records found from Z3950 SRU Server.').'</div>';
    }
}
/* SEARCH OPERATION END */

// get HTML template for search form
$main_content = $oai_pmh_tpl->fetch();
// print out the content
?>
}
