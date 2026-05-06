# Z39.50 SRU MARCXML Processing Fix

## Problem Description
When searching Z39.50 SRU servers (like http://z3950.nlv.gov.vn:9999/biblios) with "Bill Gates" as author, the system reported "Found 2 records from Z3950 SRU Server" but displayed an empty table with only headers.

## Root Cause
The issue was caused by **XML namespace handling** in MARCXML records. When SRU servers return MARCXML format, the `<record>` element inside `<recordData>` typically has a MARC21 namespace:

```xml
<recordData>
    <record xmlns="http://www.loc.gov/MARC21/slim">
        <leader>...</leader>
        <datafield tag="245">...</datafield>
    </record>
</recordData>
```

The original code tried to access `$rec->recordData->record` directly without handling the namespace, which resulted in:
- SimpleXMLElement returning `null` when trying to access namespaced elements
- The `marcXMLslims()` function receiving an empty/null object
- All parsed fields being empty, especially the `title` field
- Records being skipped or displayed with empty data

## Solution Implemented

### 1. Enhanced Namespace Detection (`/workspace/admin/modules/bibliography/z3950sru.php`)

Added proper namespace handling in the record parsing loop:

```php
if (isset($rec->recordData->record)) {
    $marc_record = $rec->recordData->record;
    // Get MARC21 namespace if present
    $namespaces = $marc_record->getNamespaces(true);
    if (!empty($namespaces[''])) {
        // Access children with the MARC21 namespace
        $marc_record_with_ns = $marc_record->children($namespaces['']);
        $parsed_record = marcXMLslims($marc_record_with_ns);
    } else {
        $parsed_record = marcXMLslims($marc_record);
    }
}
```

### 2. Improved Format Detection

Enhanced the initial format detection to check for namespaced records:

```php
// Also check for record in children (namespace handling)
if (isset($rec_check->recordData)) {
    $record_children = $rec_check->recordData->children();
    foreach ($record_children as $child_name => $child) {
        if ($child_name == 'record') {
            $use_marcxml = true;
            break 2;
        }
    }
}
```

### 3. Better Error Handling and Debugging

- Added `$records_displayed` counter to track successfully parsed records
- Added `$parse_errors` array to log parsing failures
- Display helpful error messages when records are found but can't be parsed
- Included commented-out debug code that can be enabled to inspect XML structure

### 4. Validation Check

Added validation to skip records that couldn't be properly parsed:

```php
// Skip if we couldn't parse the record
if (!$parsed_record || empty($parsed_record['title'])) {
    // Log error for debugging
    $parse_errors[] = "Row $row: Failed to parse record - " . 
                      ($parsed_record ? "Missing title" : "Parse returned null");
    continue;
}
```

## Files Modified

1. **`/workspace/admin/modules/bibliography/z3950sru.php`**
   - Lines 220-242: Enhanced MARCXML detection with namespace checking
   - Lines 250-262: Added debug code (commented out)
   - Lines 279-334: Improved record parsing with namespace handling
   - Lines 372-385: Added error reporting for failed parses

2. **`/workspace/lib/marcxmlslims.inc.php`** (already created)
   - MARCXML parser function for SLIMS

## Testing Instructions

### 1. Test with Vietnam National Library Server

```
Server URL: http://z3950.nlv.gov.vn:9999/biblios
Search term: Bill Gates
Index: Authors
```

Expected result: Table should display bibliographic records with title, authors, ISBN, etc.

### 2. Enable Debug Mode (if issues persist)

Uncomment lines 253-262 in `z3950sru.php`:

```php
if ($sru_xml) {
    echo '<pre>'; 
    echo "XML Structure:\n";
    print_r($sru_xml);
    echo "\n\nSRW Children:\n";
    $debug_zs = $sru_xml->children('http://www.loc.gov/zing/srw/');
    print_r($debug_zs);
    echo '</pre>'; 
    exit();
}
```

This will show you the exact XML structure returned by the server.

### 3. Check Error Messages

If records still don't display, look for the "Debug Info" section that shows:
- Which row failed to parse
- Whether it was a null parse or missing title

## How It Works

1. **SRU Request**: System requests records in MARCXML format first
2. **Format Detection**: Checks if response contains MARCXML or MODS
3. **Namespace Handling**: 
   - Gets the namespace URI from the `<record>` element
   - Uses `children($namespace_uri)` to access MARC21 elements properly
4. **Parsing**: Passes properly-namespaced XML to `marcXMLslims()` function
5. **Validation**: Ensures parsed record has required fields (especially title)
6. **Display**: Shows successfully parsed records in table
7. **Error Reporting**: Logs and displays info about failed parses

## Backward Compatibility

The fix maintains full backward compatibility:
- Still supports MODS format (fallback)
- Handles both namespaced and non-namespaced MARCXML
- Auto-detects format from XML structure
- Works with existing Z39.50 SRU servers

## Common Issues & Solutions

### Issue: Empty table despite "X records found"
**Solution**: Check the debug info box for parse errors. Most likely cause is namespace handling.

### Issue: "Attempt to read property 'title' on null"
**Solution**: This error should no longer occur with the new validation checks. If it does, enable debug mode to inspect the XML.

### Issue: Some records display, others don't
**Solution**: Different records may have different formats or structures. Check parse errors for specific rows.

## Additional Notes

- The fix handles multiple MARCXML namespace variations
- Supports both direct access (`$rec->recordData->record`) and child iteration
- Gracefully falls back to MODS if MARCXML parsing fails
- Includes comprehensive error logging for troubleshooting

## Support

For further issues:
1. Enable debug mode to see raw XML
2. Check parse error messages
3. Verify server returns valid MARCXML or MODS
4. Ensure PHP SimpleXML extension is enabled
