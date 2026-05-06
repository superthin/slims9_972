<?php
/**
 *
 * MARCXML to SLIMS/SENAYAN converter for Z39.50 SRU
 *
 * Copyright (C) 2011,2012 Arie Nugraha (dicarve@gmail.com)
 * Modified for SLIMS Z39.50 SRU support
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

/**
 * MARCXML Record parser for SLIMS
 * @param   object  $marcxmlrecords: XML record object from simpleXML
 * @return  array
 **/
function marcXMLslims($marcxmlrecords)
{
    $data = array();
    $data['authors'] = array();
    $data['subjects'] = array();
    
    // Initialize default values
    $data['title'] = '';
    $data['gmd'] = '';
    $data['publish_place'] = '';
    $data['publisher'] = '';
    $data['publish_year'] = '';
    $data['edition'] = '';
    $data['collation'] = '';
    $data['series_title'] = '';
    $data['notes'] = '';
    $data['isbn_issn'] = '';
    $data['classification'] = '';
    $data['language'] = array('code' => '', 'name' => '');
    $data['call_number'] = '';
    
    // Get all datafields
    foreach ($marcxmlrecords->datafield as $field) {
        $tag = (string)$field['tag'];
        
        // Build subfield array for this field
        $subfields = array();
        foreach ($field->subfield as $subfield) {
            $code = (string)$subfield['code'];
            $value = (string)$subfield;
            if (!isset($subfields[$code])) {
                $subfields[$code] = $value;
            } else {
                // Handle repeatable subfields
                if (is_array($subfields[$code])) {
                    $subfields[$code][] = $value;
                } else {
                    $subfields[$code] = array($subfields[$code], $value);
                }
            }
        }
        
        // 020 - ISBN
        if ($tag == '020') {
            if (isset($subfields['a'])) {
                $data['isbn_issn'] = is_array($subfields['a']) ? implode(', ', $subfields['a']) : $subfields['a'];
            }
        }
        
        // 022 - ISSN
        if ($tag == '022') {
            if (isset($subfields['a'])) {
                $issn = is_array($subfields['a']) ? implode(', ', $subfields['a']) : $subfields['a'];
                if (empty($data['isbn_issn'])) {
                    $data['isbn_issn'] = $issn;
                } else {
                    $data['isbn_issn'] .= ', ' . $issn;
                }
            }
        }
        
        // 041 - Language
        if ($tag == '041') {
            if (isset($subfields['a'])) {
                $lang_code = is_array($subfields['a']) ? $subfields['a'][0] : $subfields['a'];
                $data['language']['code'] = $lang_code;
            }
        }
        
        // 082 - DDC Classification
        if ($tag == '082') {
            if (isset($subfields['a'])) {
                $data['classification'] = is_array($subfields['a']) ? $subfields['a'][0] : $subfields['a'];
            }
        }
        
        // 084 - Other Classification
        if ($tag == '084') {
            if (isset($subfields['a'])) {
                if (empty($data['classification'])) {
                    $data['classification'] = is_array($subfields['a']) ? $subfields['a'][0] : $subfields['a'];
                }
            }
        }
        
        // 100 - Main Author (Personal Name)
        if ($tag == '100') {
            if (isset($subfields['a'])) {
                $author_name = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
                // Add dates if present
                if (isset($subfields['d'])) {
                    $author_name .= ' ' . (is_array($subfields['d']) ? implode(' ', $subfields['d']) : $subfields['d']);
                }
                $data['authors'][] = array(
                    'name' => trim($author_name),
                    'authority_list' => '',
                    'level' => 1,
                    'author_type' => 'personal'
                );
            }
        }
        
        // 110 - Main Author (Corporate Name)
        if ($tag == '110') {
            if (isset($subfields['a'])) {
                $author_name = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
                if (isset($subfields['b'])) {
                    $author_name .= ' ' . (is_array($subfields['b']) ? implode(' ', $subfields['b']) : $subfields['b']);
                }
                $data['authors'][] = array(
                    'name' => trim($author_name),
                    'authority_list' => '',
                    'level' => 1,
                    'author_type' => 'corporate'
                );
            }
        }
        
        // 111 - Main Author (Conference/Meeting)
        if ($tag == '111') {
            if (isset($subfields['a'])) {
                $author_name = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
                if (isset($subfields['c'])) {
                    $author_name .= ' ' . (is_array($subfields['c']) ? implode(' ', $subfields['c']) : $subfields['c']);
                }
                $data['authors'][] = array(
                    'name' => trim($author_name),
                    'authority_list' => '',
                    'level' => 1,
                    'author_type' => 'conference'
                );
            }
        }
        
        // 245 - Title Statement
        if ($tag == '245') {
            $title_parts = array();
            if (isset($subfields['a'])) {
                $title_main = is_array($subfields['a']) ? $subfields['a'][0] : $subfields['a'];
                $title_parts[] = $title_main;
            }
            if (isset($subfields['b'])) {
                $title_sub = is_array($subfields['b']) ? implode(': ', $subfields['b']) : $subfields['b'];
                $title_parts[] = $title_sub;
            }
            if (isset($subfields['c'])) {
                $title_resp = is_array($subfields['c']) ? implode('; ', $subfields['c']) : $subfields['c'];
                $title_parts[] = $title_resp;
            }
            $data['title'] = implode(': ', $title_parts);
            
            // GMD from $h
            if (isset($subfields['h'])) {
                $gmd = is_array($subfields['h']) ? $subfields['h'][0] : $subfields['h'];
                $data['gmd'] = str_replace(array('[', ']'), '', trim($gmd));
            }
        }
        
        // 250 - Edition
        if ($tag == '250') {
            if (isset($subfields['a'])) {
                $data['edition'] = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['b'])) {
                $data['edition'] .= ' ' . (is_array($subfields['b']) ? implode(' ', $subfields['b']) : $subfields['b']);
            }
        }
        
        // 260/264 - Publication Information
        if ($tag == '260' || $tag == '264') {
            if (isset($subfields['a'])) {
                $data['publish_place'] = is_array($subfields['a']) ? $subfields['a'][0] : $subfields['a'];
            }
            if (isset($subfields['b'])) {
                $data['publisher'] = is_array($subfields['b']) ? $subfields['b'][0] : $subfields['b'];
            }
            if (isset($subfields['c'])) {
                $year = is_array($subfields['c']) ? $subfields['c'][0] : $subfields['c'];
                // Extract just the year
                preg_match('/(\d{4})/', $year, $matches);
                if (isset($matches[1])) {
                    $data['publish_year'] = $matches[1];
                } else {
                    $data['publish_year'] = $year;
                }
            }
        }
        
        // 300 - Physical Description
        if ($tag == '300') {
            $physical_parts = array();
            if (isset($subfields['a'])) {
                $physical_parts[] = is_array($subfields['a']) ? implode('; ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['b'])) {
                $physical_parts[] = is_array($subfields['b']) ? implode('; ', $subfields['b']) : $subfields['b'];
            }
            if (isset($subfields['c'])) {
                $physical_parts[] = is_array($subfields['c']) ? implode('; ', $subfields['c']) : $subfields['c'];
            }
            $data['collation'] = implode(' : ', $physical_parts);
        }
        
        // 440/490 - Series Statement
        if ($tag == '440' || $tag == '490') {
            if (isset($subfields['a'])) {
                $series = is_array($subfields['a']) ? $subfields['a'][0] : $subfields['a'];
                if (isset($subfields['v'])) {
                    $series .= ' ; ' . (is_array($subfields['v']) ? implode('; ', $subfields['v']) : $subfields['v']);
                }
                $data['series_title'] = $series;
            }
        }
        
        // 5XX - Notes
        if (preg_match('/^5\d+$/', $tag)) {
            $note_parts = array();
            foreach ($subfields as $code => $value) {
                if (is_array($value)) {
                    $note_parts[] = implode(' ', $value);
                } else {
                    $note_parts[] = $value;
                }
            }
            if (!empty($note_parts)) {
                if (empty($data['notes'])) {
                    $data['notes'] = implode(' ', $note_parts);
                } else {
                    $data['notes'] .= '; ' . implode(' ', $note_parts);
                }
            }
        }
        
        // 600 - Subject (Personal Name)
        if ($tag == '600') {
            $subject_parts = array();
            if (isset($subfields['a'])) {
                $subject_parts[] = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['d'])) {
                $subject_parts[] = is_array($subfields['d']) ? implode(' ', $subfields['d']) : $subfields['d'];
            }
            if (isset($subfields['q'])) {
                $subject_parts[] = is_array($subfields['q']) ? implode(' ', $subfields['q']) : $subfields['q'];
            }
            if (!empty($subject_parts)) {
                $data['subjects'][] = array(
                    'term' => trim(implode(' ', $subject_parts)),
                    'term_type' => 'Name',
                    'authority' => ''
                );
            }
        }
        
        // 610 - Subject (Corporate Name)
        if ($tag == '610') {
            $subject_parts = array();
            if (isset($subfields['a'])) {
                $subject_parts[] = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['b'])) {
                $subject_parts[] = is_array($subfields['b']) ? implode(' ', $subfields['b']) : $subfields['b'];
            }
            if (!empty($subject_parts)) {
                $data['subjects'][] = array(
                    'term' => trim(implode(' ', $subject_parts)),
                    'term_type' => 'Name',
                    'authority' => ''
                );
            }
        }
        
        // 611 - Subject (Conference/Meeting)
        if ($tag == '611') {
            $subject_parts = array();
            if (isset($subfields['a'])) {
                $subject_parts[] = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['c'])) {
                $subject_parts[] = is_array($subfields['c']) ? implode(' ', $subfields['c']) : $subfields['c'];
            }
            if (!empty($subject_parts)) {
                $data['subjects'][] = array(
                    'term' => trim(implode(' ', $subject_parts)),
                    'term_type' => 'Temporal',
                    'authority' => ''
                );
            }
        }
        
        // 650 - Subject (Topical Term)
        if ($tag == '650') {
            $subject_parts = array();
            if (isset($subfields['a'])) {
                $subject_parts[] = is_array($subfields['a']) ? implode(' -- ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['x'])) {
                $subject_parts[] = is_array($subfields['x']) ? implode(' -- ', $subfields['x']) : $subfields['x'];
            }
            if (isset($subfields['y'])) {
                $subject_parts[] = is_array($subfields['y']) ? implode(' -- ', $subfields['y']) : $subfields['y'];
            }
            if (isset($subfields['z'])) {
                $subject_parts[] = is_array($subfields['z']) ? implode(' -- ', $subfields['z']) : $subfields['z'];
            }
            if (!empty($subject_parts)) {
                $data['subjects'][] = array(
                    'term' => trim(implode(' -- ', $subject_parts)),
                    'term_type' => 'Topical',
                    'authority' => ''
                );
            }
        }
        
        // 651 - Subject (Geographic Name)
        if ($tag == '651') {
            $subject_parts = array();
            if (isset($subfields['a'])) {
                $subject_parts[] = is_array($subfields['a']) ? implode(' -- ', $subfields['a']) : $subfields['a'];
            }
            if (isset($subfields['x'])) {
                $subject_parts[] = is_array($subfields['x']) ? implode(' -- ', $subfields['x']) : $subfields['x'];
            }
            if (isset($subfields['y'])) {
                $subject_parts[] = is_array($subfields['y']) ? implode(' -- ', $subfields['y']) : $subfields['y'];
            }
            if (isset($subfields['z'])) {
                $subject_parts[] = is_array($subfields['z']) ? implode(' -- ', $subfields['z']) : $subfields['z'];
            }
            if (!empty($subject_parts)) {
                $data['subjects'][] = array(
                    'term' => trim(implode(' -- ', $subject_parts)),
                    'term_type' => 'Geographic',
                    'authority' => ''
                );
            }
        }
        
        // 700 - Added Entry (Personal Name)
        if ($tag == '700') {
            if (isset($subfields['a'])) {
                $author_name = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
                if (isset($subfields['d'])) {
                    $author_name .= ' ' . (is_array($subfields['d']) ? implode(' ', $subfields['d']) : $subfields['d']);
                }
                $data['authors'][] = array(
                    'name' => trim($author_name),
                    'authority_list' => '',
                    'level' => 2,
                    'author_type' => 'personal'
                );
            }
        }
        
        // 710 - Added Entry (Corporate Name)
        if ($tag == '710') {
            if (isset($subfields['a'])) {
                $author_name = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
                if (isset($subfields['b'])) {
                    $author_name .= ' ' . (is_array($subfields['b']) ? implode(' ', $subfields['b']) : $subfields['b']);
                }
                $data['authors'][] = array(
                    'name' => trim($author_name),
                    'authority_list' => '',
                    'level' => 2,
                    'author_type' => 'corporate'
                );
            }
        }
        
        // 711 - Added Entry (Conference/Meeting)
        if ($tag == '711') {
            if (isset($subfields['a'])) {
                $author_name = is_array($subfields['a']) ? implode(' ', $subfields['a']) : $subfields['a'];
                if (isset($subfields['c'])) {
                    $author_name .= ' ' . (is_array($subfields['c']) ? implode(' ', $subfields['c']) : $subfields['c']);
                }
                $data['authors'][] = array(
                    'name' => trim($author_name),
                    'authority_list' => '',
                    'level' => 2,
                    'author_type' => 'conference'
                );
            }
        }
        
        // 852 - Location/Call Number
        if ($tag == '852') {
            if (isset($subfields['h'])) {
                $data['call_number'] = is_array($subfields['h']) ? $subfields['h'][0] : $subfields['h'];
            }
        }
    }
    
    // Set language name from code if available
    if (!empty($data['language']['code'])) {
        $data['language']['name'] = $data['language']['code'];
    }
    
    return $data;
}
