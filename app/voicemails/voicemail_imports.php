<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2018
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes
	include "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('voicemail_import')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//built in str_getcsv requires PHP 5.3 or higher, this function can be used to reproduct the functionality but requirs PHP 5.1.0 or higher
	if(!function_exists('str_getcsv')) {
		function str_getcsv($input, $delimiter = ",", $enclosure = '"', $escape = "\\") {
			$fp = fopen("php://memory", 'r+');
			fputs($fp, $input);
			rewind($fp);
			$data = fgetcsv($fp, null, $delimiter, $enclosure); // $escape only got added in 5.3.0
			fclose($fp);
			return $data;
		}
	}

//set the max php execution time
	ini_set(max_execution_time,7200);

//get the http get values and set them as php variables
	$action = $_POST["action"];
	$from_row = $_POST["from_row"];
	$delimiter = $_POST["data_delimiter"];
	$enclosure = $_POST["data_enclosure"];

//save the data to the csv file
	if (isset($_POST['data'])) {
		$file = $_SESSION['server']['temp']['dir']."/voicemails-".$_SESSION['domain_name'].".csv";
		file_put_contents($file, $_POST['data']);
		$_SESSION['file'] = $file;
	}

//copy the csv file
	//$_POST['submit'] == "Upload" &&
	if ( is_uploaded_file($_FILES['ulfile']['tmp_name']) && permission_exists('voicemail_import')) {
		if ($_POST['type'] == 'csv') {
			move_uploaded_file($_FILES['ulfile']['tmp_name'], $_SESSION['server']['temp']['dir'].'/'.$_FILES['ulfile']['name']);
			$save_msg = "Uploaded file to ".$_SESSION['server']['temp']['dir']."/". htmlentities($_FILES['ulfile']['name']);
			//system('chmod -R 744 '.$_SESSION['server']['temp']['dir'].'*');
			unset($_POST['txtCommand']);
			$file = $_SESSION['server']['temp']['dir'].'/'.$_FILES['ulfile']['name'];
			$_SESSION['file'] = $file;
		}
	}

//get the schema
	if (strlen($delimiter) > 0) {
		//get the first line
			$line = fgets(fopen($_SESSION['file'], 'r'));
			$line_fields = explode($delimiter, $line);

		//get the schema
			$x = 0;
			include ("app/voicemails/app_config.php");
			$i = 0;
			foreach($apps[0]['db'] as $table) {
				//get the table name and parent name
				$table_name = $table["table"]['name'];
				$parent_name = $table["table"]['parent'];

				//remove the v_ table prefix
				if (substr($table_name, 0, 2) == 'v_') {
						$table_name = substr($table_name, 2);
				}
				if (substr($parent_name, 0, 2) == 'v_') {
						$parent_name = substr($parent_name, 2);
				}

				//filter for specific tables and build the schema array
				if ($table_name == "voicemails") {
					$schema[$i]['table'] = $table_name;
					$schema[$i]['parent'] = $parent_name;
					foreach($table['fields'] as $row) {
						if ($row['deprecated'] !== 'true') {
							if (is_array($row['name'])) {
								$field_name = $row['name']['text'];
							}
							else {
								$field_name = $row['name'];
							}
							$schema[$i]['fields'][] = $field_name;
						}
					}
					$i++;	
				}
			}
	}

//match the column names to the field names
	if (strlen($delimiter) > 0 && file_exists($_SESSION['file']) && $action != 'import') {

		//form to match the fields to the column names
			require_once "resources/header.php";

			echo "<form action='voicemail_imports.php' method='POST' enctype='multipart/form-data' name='frmUpload' onSubmit=''>\n";
			echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

			echo "	<tr>\n";
			echo "	<td valign='top' align='left' nowrap='nowrap'>\n";
			echo "		<b>".$text['header-import']."</b><br />\n";
			echo "	</td>\n";
			echo "	<td valign='top' align='right'>\n";
			echo "		<input type='button' class='btn' name='' alt='".$text['button-back']."' onclick=\"window.location='voicemails.php'\" value='".$text['button-back']."'>\n";
			echo "		<input name='submit' type='submit' class='btn' id='import' value=\"".$text['button-import']."\">\n";
			echo "	</td>\n";
			echo "	</tr>\n";
			echo "	<tr>\n";
			echo "	<td colspan='2' align='left'>\n";
			echo "		".$text['description-import']."\n";
			echo "	</td>\n";
			echo "	</tr>\n";

			//echo "<tr>\n";
			//echo "<td align='left' width='30%' nowrap='nowrap'><b>".$text['header-import']."</b></td>\n";
			//echo "<td width='70%' align='right'>\n";
			//echo "	<input type='button' class='btn' name='' alt='".$text['button-back']."' onclick=\"window.location='voicemails.php'\" value='".$text['button-back']."'>\n";
			//echo "</td>\n";
			//echo "</tr>\n";

			//loop through user columns
			$x = 0;
			foreach ($line_fields as $line_field) {
				$line_field = trim(trim($line_field), $enclosure);
				echo "<tr>\n";
				echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
				//echo "    ".$text['label-zzz']."\n";
				echo $line_field;
				echo "</td>\n";
				echo "<td class='vtable' align='left'>\n";
				echo "    			<select class='formfld' style='' name='fields[$x]'>\n";
				echo "    			<option value=''></option>\n";
				foreach($schema as $row) {
					echo "			<optgroup label='".$row['table']."'>\n";
					foreach($row['fields'] as $field) {
						$selected = '';
						if ($field == $line_field) {
							$selected = "selected='selected'";
						}
						if ($field !== 'domain_uuid') {
							echo "    			<option value='".$row['table'].".".$field."' ".$selected.">".$field."</option>\n";
						}
					}
					echo "			</optgroup>\n";
				}
				echo "    			</select>\n";
				//echo "<br />\n";
				//echo $text['description-zzz']."\n";
				echo "			</td>\n";
				echo "		</tr>\n";
				$x++;
			}

			echo "		<tr>\n";
			echo "			<td colspan='2' valign='top' align='right' nowrap='nowrap'>\n";
			echo "				<input name='action' type='hidden' value='import'>\n";
			echo "				<input name='from_row' type='hidden' value='$from_row'>\n";
			echo "				<input name='data_delimiter' type='hidden' value='$delimiter'>\n";
			echo "				<input name='data_enclosure' type='hidden' value='$enclosure'>\n";
			echo "				<input type='submit' class='btn' id='import' value=\"".$text['button-import']."\">\n";
			echo "			</td>\n";
			echo "		</tr>\n";

			echo "	</table>\n";
			echo "</form>\n";
			require_once "resources/footer.php";

		//normalize the column names
			//$line = strtolower($line);
			//$line = str_replace("-", "_", $line);
			//$line = str_replace($delimiter."title".$delimiter, $delimiter."contact_title".$delimiter, $line);
			//$line = str_replace("firstname", "name_given", $line);
			//$line = str_replace("lastname", "name_family", $line);
			//$line = str_replace("company", "organization", $line);
			//$line = str_replace("company", "contact_email", $line);

		//end the script
			exit;
	}

//get the parent table
	function get_parent($schema,$table_name) {
		foreach ($schema as $row) {
			if ($row['table'] == $table_name) {
				return $row['parent'];
			}
		}
	}

//upload the csv
	if (file_exists($_SESSION['file']) && $action == 'import') {

		//form to match the fields to the column names
			//require_once "resources/header.php";

		//user selected fields
			$fields = $_POST['fields'];
			
		//set the domain_uuid
			$domain_uuid = $_SESSION['domain_uuid'];

		//get the contents of the csv file and convert them into an array
			$handle = @fopen($_SESSION['file'], "r");
			if ($handle) {
				//set the starting identifiers
					$row_id = 0;
					$row_number = 1;

				//loop through the array
					while (($line = fgets($handle, 4096)) !== false) {
						if ($from_row <= $row_number) {
							//format the data
								$y = 0;
								foreach ($fields as $key => $value) {
									//get the line
									$result = str_getcsv($line, $delimiter, $enclosure);
									
									//get the table and field name
									$field_array = explode(".",$value);
									$table_name = $field_array[0];
									$field_name = $field_array[1];
									//echo "value: $value<br />\n";
									//echo "table_name: $table_name<br />\n";
									//echo "field_name: $field_name<br />\n";
									
									//get the parent table name
									$parent = get_parent($schema, $table_name);

									//remove formatting from the phone number
									if ($field_name == "phone_number") {
										$result[$key] = preg_replace('{\D}', '', $result[$key]);
									}

									//build the data array
									if (strlen($table_name) > 0) {
										if (strlen($parent) == 0) {
											$array[$table_name][$row_id]['domain_uuid'] = $domain_uuid;
											$array[$table_name][$row_id][$field_name] = $result[$key];
										}
										else {
											$array[$parent][$row_id][$table_name][$y]['domain_uuid'] = $domain_uuid;
											$array[$parent][$row_id][$table_name][$y][$field_name] = $result[$key];
										}
									}
								}

							//process a chunk of the array
								if ($row_id === 1000) {

									//save to the data
										$database = new database;
										$database->app_name = 'voicemails';
										$database->app_uuid = 'b523c2d2-64cd-46f1-9520-ca4b4098e044';
										$database->save($array);
										//$message = $database->message;

									//clear the array
										unset($array);

									//set the row id back to 0
										$row_id = 0;
								}

						} //if ($from_row <= $row_id)
						$row_number++;
						$row_id++;
					} //end while
					fclose($handle);

					//save to the data
					if (is_array($array)) {
						$database = new database;
						$database->app_name = 'voicemails';
						$database->app_uuid = 'b523c2d2-64cd-46f1-9520-ca4b4098e044';
						$database->save($array);
						//$message = $database->message;
					}

				//send the redirect header
					header("Location: voicemails.php");
					return;
			}
	}

//include the header
	require_once "resources/header.php";

//begin the content
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "	<tr>\n";
	echo "	<td valign='top' align='left' width='30%' nowrap='nowrap'>\n";
	echo "		<b>".$text['header-import']."</b><br />\n";
	echo "		".$text['description-import']."\n";
	echo "	</td>\n";
	echo "	<td valign='top' width='70%' align='right'>\n";
	echo "		<input type='button' class='btn' name='' alt='".$text['button-back']."' onclick=\"window.location='voicemails.php?".$_GET["query_string"]."'\" value='".$text['button-back']."'>\n";
	//echo "		<input name='submit' type='submit' class='btn' id='import' value=\"".$text['button-import']."\">\n";
	echo "	</td>\n";
	echo "	</tr>\n";
	echo "</table>";

	echo "<br />\n";

	echo "<form action='' method='POST' enctype='multipart/form-data' name='frmUpload' onSubmit=''>\n";
	echo "	<table border='0' cellpadding='0' cellspacing='0' width='100%'>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "    ".$text['label-import_data']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <textarea name='data' id='data' rows='7' class='formfld' style='width: 100%;' wrap='off'>$data</textarea>\n";
	echo "<br />\n";
	echo $text['description-import_data']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "    ".$text['label-from_row']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "		<select class='formfld' name='from_row'>\n";
	$i=1;
	while($i<=99) {
		$selected = ($i == $from_row) ? "selected" : null;
		echo "			<option value='$i' ".$selected.">$i</option>\n";
		$i++;
	}
	echo "		</select>\n";
	echo "<br />\n";
	echo $text['description-from_row']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "    ".$text['label-import_delimiter']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <select class='formfld' style='width:40px;' name='data_delimiter'>\n";
	echo "    <option value=','>,</option>\n";
	echo "    <option value='|'>|</option>\n";
	echo "    </select>\n";
	echo "<br />\n";
	echo $text['description-import_delimiter']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "    ".$text['label-import_enclosure']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <select class='formfld' style='width:40px;' name='data_enclosure'>\n";
	echo "    <option value='\"'>\"</option>\n";
	echo "    <option value=''></option>\n";
	echo "    </select>\n";
	echo "<br />\n";
	echo $text['description-import_enclosure']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "			".$text['label-import_file_upload']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "			<input name='ulfile' type='file' class='formfld fileinput' id='ulfile'>\n";
	echo "<br />\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "	<tr>\n";
	echo "		<td valign='bottom'>\n";
	echo "			&nbsp;\n";
	echo "		</td>\n";
	echo "		<td valign='bottom' align='right' nowrap>\n";
	echo "			<input name='type' type='hidden' value='csv'>\n";
	echo "			<br />\n";
	echo "			<input name='submit' type='submit' class='btn' id='import' value=\"".$text['button-import']."\">\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "	</table>\n";
	echo "<br><br>";
	echo "</form>";

//include the footer
	require_once "resources/footer.php";

?>