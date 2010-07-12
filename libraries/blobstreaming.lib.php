<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * @package     BLOBStreaming
 */

/**
 * checks whether the necessary plugins for BLOBStreaming exist
 *
 * @access  public
 * @uses    PMA_Config::get()
 * @uses    PMA_Config::settings()
 * @uses    PMA_Config::set()
 * @uses    PMA_BS_SetVariables()
 * @uses    PMA_BS_GetVariables()
 * @uses    PMA_cacheSet()
 * @uses    PMA_cacheGet()
 * @return  boolean
*/
function checkBLOBStreamingPlugins()
{
    // load PMA configuration
    $PMA_Config = $GLOBALS['PMA_Config'];

    // return if unable to load PMA configuration
    if (empty($PMA_Config)) {
        return FALSE;
    }

    // At this point we might already know that plugins do not exist
    // because this was recorded in the session (cache).
    if (PMA_cacheGet('skip_blobstreaming', true)) {
        return false;
    }
	
    // If we don't know that we can skip blobstreaming, we continue
    // verifications; anyway, in case we won't skip blobstreaming,
    // we still need to set some variables in non-persistent settings,
    // which is done via $PMA_Config->set().

    /** Retrieve current server configuration;
     *  at this point, $PMA_Config->get('Servers') contains the server parameters
     *  as explicitely defined in config.inc.php, so it cannot be used; it's
     *  better to use $GLOBALS['cfg']['Server'] which contains the explicit
     *  parameters merged with the default ones
     *
     */
    $serverCfg = $GLOBALS['cfg']['Server'];

    // return if unable to retrieve current server configuration
    if (! $serverCfg) {
        return FALSE;
    }

    // if PHP extension in use is 'mysql', specify element 'PersistentConnections'
    if ($serverCfg['extension'] == "mysql") {
        $serverCfg['PersistentConnections'] = $PMA_Config->settings['PersistentConnections'];
    }

    // if connection type is TCP, unload socket variable
    if (strtolower($serverCfg['connect_type']) == "tcp") {
        $serverCfg['socket'] = "";
    }

    $has_blobstreaming = false;
    if (PMA_MYSQL_INT_VERSION >= 50109) {

        // Retrieve MySQL plugins
        $existing_plugins = PMA_DBI_fetch_result('SHOW PLUGINS');

        foreach ($existing_plugins as $one_existing_plugin)	{
            // check if required plugins exist
			if ( strtolower($one_existing_plugin['Library']) == 'libpbms.so'
					&& $one_existing_plugin['Status'] == "ACTIVE") {
				$has_blobstreaming = true;
				break;
			 }
        }
       unset($existing_plugins, $one_existing_plugin);
    }
    
    // set variable indicating BS plugin existence
    $PMA_Config->set('BLOBSTREAMING_PLUGINS_EXIST', $has_blobstreaming);

    if ($has_blobstreaming) {
		$bs_variables = PMA_BS_GetVariables();
		
       // if no BS variables exist, set plugin existence to false and return
        if (count($bs_variables) <= 0) {
            $PMA_Config->set('BLOBSTREAMING_PLUGINS_EXIST', FALSE);
            PMA_cacheSet('skip_blobstreaming', true, true);
            return FALSE;
        } // end if (count($bs_variables) <= 0)

         // get BS server port
        $BS_PORT = $bs_variables['pbms_port'];

        // if no BS server port exists, set plugin existance to false and return
        if (! $BS_PORT) {
            $PMA_Config->set('BLOBSTREAMING_PLUGINS_EXIST', FALSE);
            PMA_cacheSet('skip_blobstreaming', true, true);
            return FALSE;
        } // end if (!$BS_PORT)

        // add selected BS, CURL and fileinfo library variables to PMA configuration
        $PMA_Config->set('BLOBSTREAMING_PORT', $BS_PORT);
        $PMA_Config->set('BLOBSTREAMING_HOST', $serverCfg['host']);
        $PMA_Config->set('BLOBSTREAMING_SERVER', $serverCfg['host'] . ':' . $BS_PORT);
        $PMA_Config->set('PHP_PBMS_EXISTS', FALSE);
        $PMA_Config->set('FILEINFO_EXISTS', FALSE);

		// Check to see if the BLOB Streaming PHP extension is loaded
		if (extension_loaded("PBMS")) {
			$PMA_Config->set('PHP_PBMS_EXISTS', TRUE);
		}
 
		// check if PECL's fileinfo library exist
        $finfo = NULL;

        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME);
        }

        // fileinfo library exists, set necessary variable and close resource
        if (! empty($finfo)) {
            $PMA_Config->set('FILEINFO_EXISTS', TRUE);
            finfo_close($finfo);
        } // end if (!empty($finfo))
		
    } else {
        PMA_cacheSet('skip_blobstreaming', true, true);
        return FALSE;
    } // end if ($has_blobstreaming)

    return TRUE;
}

/**
 * sets BLOBStreaming variables to a list of specified arguments
 * @access  public
 * @uses    PMA_DBI_query()
 * @returns boolean - success of variables setup
*/

function PMA_BS_SetVariables($bs_variables)
{
    // if no variables exist in array, return false
    if (empty($bs_variables) || count($bs_variables) == 0)
        return FALSE;

    // set BS variables to those specified in array
    foreach ($bs_variables as $key=>$val)
        if (!is_null($val) && strlen($val) > 0)
        {
            // set BS variable to specified value
            $query = "SET GLOBAL $key=" . PMA_sqlAddSlashes($val);
            $result = PMA_DBI_query($query);

            // if query fails execution, return false
            if (!$result)
                return FALSE;
        } // end if (!is_null($val) && strlen($val) > 0)

    // return true on success
    return TRUE;
}

/**
 * returns a list of BLOBStreaming variables used by MySQL
 *
 * @access  public
 * @uses    PMA_Config::get()
 * @uses    PMA_DBI_query()
 * @uses    PMA_DBI_fetch_assoc()
 * @return  array - list of BLOBStreaming variables
*/
function PMA_BS_GetVariables()
{
    // load PMA configuration
    $PMA_Config = $GLOBALS['PMA_Config'];

    // return if unable to load PMA configuration
    if (empty($PMA_Config))
        return NULL;

    // run query to retrieve BS variables
    $query = "SHOW VARIABLES LIKE '%pbms%'";
    $result = PMA_DBI_query($query);

    $BS_Variables = array();

    // while there are records to retrieve
    while ($data = @PMA_DBI_fetch_assoc($result))
        $BS_Variables[$data['Variable_name']] = $data['Value'];

    // return BS variables
    return $BS_Variables;
}

//========================
//========================
function PMA_BS_ReportPBMSError($msg)
{
	$tmp_err = pbms_error();
	PMA_showMessage("PBMS error, $msg $tmp_err");
}

//------------
function PMA_do_connect($db_name, $quiet)
{
    $PMA_Config = $GLOBALS['PMA_Config'];

    // return if unable to load PMA configuration
    if (empty($PMA_Config))
        return FALSE;

    // generate bs reference link
    $pbms_host = $PMA_Config->get('BLOBSTREAMING_HOST');
	$pbms_port = $PMA_Config->get('BLOBSTREAMING_PORT');

	if (pbms_connect($pbms_host, $pbms_port, $db_name) == FALSE) {
		if ($quiet == FALSE)
			PMA_BS_ReportPBMSError("PBMS Connectiuon failed: pbms_connect($pbms_host, $pbms_port, $db_name)");	
		return FALSE;
	}
	return TRUE;
}

//------------
function PMA_do_disconnect()
{
	pbms_close();
}

//------------
/**
 * checks whether the BLOB reference looks valid
 *
*/
function PMA_BS_IsPBMSReference($bs_reference, $db_name)
{
	if (PMA_do_connect($db_name, TRUE) == FALSE) {
		return FALSE;
	}
    $ok = pbms_is_blob_reference($bs_reference);
	PMA_do_disconnect();
	return $ok ;
}

//------------
function PMA_BS_CreateReferenceLink($bs_reference, $db_name)
{
	if (PMA_do_connect($db_name, FALSE) == FALSE) {
		return 'Error';
	}
	
	if (pbms_get_info($bs_reference) == FALSE) {
		PMA_BS_ReportPBMSError("PBMS get BLOB info failed: pbms_get_info($bs_reference)");	
		PMA_do_disconnect();
		return 'Error';
	}

	$content_type = pbms_get_metadata_value("Content-type");
	if ($content_type == FALSE) {
		PMA_BS_ReportPBMSError("PMA_BS_CreateReferenceLink($bs_reference, $db_name): get BLOB Content-type failed: ");	
	}
	
	PMA_do_disconnect();
	
	if (!$content_type)
		$content_type = "image/jpeg";

	$bs_url = PMA_BS_getURL($bs_reference);
	if (empty($bs_url)) {
		PMA_BS_ReportPBMSError("No blob streaming server configured!");	
		return 'Error';
	}
	
	$output = "<a href=\"#\" onclick=\"requestMIMETypeChange('" . urlencode($db_name) . "', '" . urlencode($GLOBALS['table']) . "', '" . urlencode($bs_reference) . "', '" . urlencode($content_type) . "')\">$content_type</a>";

	// specify custom HTML for various content types
	switch ($content_type)
	{
		// no content specified
		case NULL:
			$output = "NULL";
			break;
		// image content
		case 'image/jpeg':
		case 'image/png':
			$output .= ' (<a href="' . $bs_url . '" target="new">' . __('View image') . '</a>)';
			break;
		// audio content
		case 'audio/mpeg':
			$output .= ' (<a href="#" onclick="popupBSMedia(\'' . PMA_generate_common_url() . '\',\'' . urlencode($bs_reference) . '\', \'' . urlencode($content_type) . '\',' . ($is_custom_type ? 1 : 0) . ', 640, 120)">' . __('Play audio'). '</a>)';
			break;
		// video content
		case 'application/x-flash-video':
		case 'video/mpeg':
			$output .= ' (<a href="#" onclick="popupBSMedia(\'' . PMA_generate_common_url() . '\',\'' . urlencode($bs_reference) . '\', \'' . urlencode($content_type) . '\',' . ($is_custom_type ? 1 : 0) . ', 640, 480)">' . __('View video') . '</a>)';
			break;
		// unsupported content. specify download
		default:
			$output .= ' (<a href="' . $bs_url . '" target="new">' . __('Download file'). '</a>)';
	}

//PMA_showMessage("PMA_BS_CreateReferenceLink($bs_reference, $db_name): $output");
	return $output;
}

//------------
// In the future there may be server variables to turn on/off PBMS
// BLOB streaming on a per table or database basis. So in anticipation of this
// PMA_BS_IsTablePBMSEnabled() passes in the table and database name even though
// they are not currently needed.
function PMA_BS_IsTablePBMSEnabled($db_name, $tbl_name, $tbl_type)
{
	if ((isset($tbl_type) == FALSE) || (strlen($tbl_type) == 0))
		return FALSE;
	
    // load PMA configuration
    $PMA_Config = $GLOBALS['PMA_Config'];

    // return if unable to load PMA configuration
    if (empty($PMA_Config))
        return FALSE;

	// This information should be cached rather than selecting it each time.
   //$query = "SELECT count(*)  FROM information_schema.TABLES T, pbms.pbms_enabled E where T.table_schema = ". PMA_backquote($db_name) . " and T.table_name = ". PMA_backquote($tbl_name) . " and T.engine = E.name"; 
   $query = "SELECT count(*)  FROM pbms.pbms_enabled E where E.name = '". PMA_sqlAddslashes($tbl_type) . "'" ; 
   $result = PMA_DBI_query($query);

 	$data = PMA_DBI_fetch_row($result);
	if ($data[0] == 1)
		return TRUE;
		
    return FALSE;
}

//------------
function PMA_BS_UpLoadFile($db_name, $tbl_name, $file_type, $file_name)
{

	if (PMA_do_connect($db_name, FALSE) == FALSE) {
		return FALSE;
	}
	
	$fh = fopen($file_name, 'r');
	if (!$fh) {
		PMA_do_disconnect();
		PMA_showMessage("Could not open file: $file_name");	
		return FALSE;
	}
	
	pbms_add_metadata("Content-type", $file_type);
	
	$pbms_blob_url = pbms_read_stream($fh, filesize($file_name), $tbl_name);
	if (!$pbms_blob_url) {
		PMA_BS_ReportPBMSError("pbms_read_stream() Failed");
	}

	//PMA_showMessage(" PMA_BS_UpLoadFile($db_name, $tbl_name, $file_type, $file_name): $pbms_blob_url");
	fclose($fh);
	PMA_do_disconnect();
	return $pbms_blob_url;
}

//------------
function PMA_BS_SetContentType($db_name, $bsTable, $blobReference, $contentType)
{
	// This is a really ugly way to do this but currently there is nothing better.
	// In a future version of PBMS the system tables will be redesigned to make this
	// more eficient.
	$query = "SELECT Repository_id, Repo_blob_offset FROM pbms_reference  WHERE Blob_url='" . PMA_sqlAddslashes($blobReference) . "'";
//error_log(" PMA_BS_SetContentType: $query\n", 3, "/tmp/mylog");
	$result = PMA_DBI_query($query);
//error_log(" $query\n", 3, "/tmp/mylog");

	// if record exists
	if ($data = PMA_DBI_fetch_assoc($result))
	{
		$where = "WHERE Repository_id=" . $data['Repository_id'] . " AND Repo_blob_offset=" . $data['Repo_blob_offset'] ;
		$query = "SELECT name from  pbms_metadata $where";
		$result = PMA_DBI_query($query);
		
		if (PMA_DBI_num_rows($result) == 0)
			$query = "INSERT into pbms_metadata Values( ". $data['Repository_id'] . ", " . $data['Repo_blob_offset']  . ", 'Content_type', '" . PMA_sqlAddslashes($contentType)  . "')";
		else
			$query = "UPDATE pbms_metadata SET name = 'Content_type', Value = '" . PMA_sqlAddslashes($contentType)  . "' $where";

//error_log("$query\n", 3, "/tmp/mylog");
		PMA_DBI_query($query);
		
		
	} else {
//		if ($result == FALSE) {
//			$err = PMA_DBI_getError();
//			error_log("MySQL ERROR: $err\n", 3, "/tmp/mylog");
//		} else
//			error_log("No results: $query\n", 3, "/tmp/mylog");
		return FALSE;
	}
	
	return TRUE;
}

//------------
function PMA_BS_IsHiddenTable($table)
{
	switch ($table) {
		case 'pbms_repository' :
		case 'pbms_reference' :
		case 'pbms_metadata' :
		case 'pbms_metadata_header' :
		case 'pbms_dump' :
			return TRUE;
	}
	
	return FALSE;
}

//------------
function PMA_BS_getURL($reference)
{
	// load PMA configuration
	$PMA_Config = $GLOBALS['PMA_Config'];
	if (empty($PMA_Config))
		return FALSE;

	// retrieve BS server variables from PMA configuration
	$bs_server = $PMA_Config->get('BLOBSTREAMING_SERVER');
	if (empty($bs_server))
		return FALSE;
		
	$bs_url = 'http://' . $bs_server . '/' . rtrim($reference);
//PMA_showMessage(" PMA_BS_getURL($reference): $bs_url");
	return $bs_url;

}

?>
