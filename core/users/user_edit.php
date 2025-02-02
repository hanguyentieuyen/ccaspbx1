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
	Portions created by the Initial Developer are Copyright (C) 2008-2019
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>
*/

//includes
	include "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get user uuid
	if ((is_uuid($_REQUEST["id"]) && permission_exists('user_edit')) || (is_uuid($_REQUEST["id"]) && $_REQUEST["id"] == $_SESSION['user_uuid']))  {
		$user_uuid = $_REQUEST["id"];
		$action = 'edit';
	}
	elseif (permission_exists('user_add') && !isset($_REQUEST["id"])) {
		$user_uuid = uuid();
		$action = 'add';
	}
	else {
		// load users own account
		header("Location: user_edit.php?id=".$_SESSION['user_uuid']);
		exit;
	}

//get total user count from the database, check limit, if defined
	if (permission_exists('user_add') && $action == 'add' && $_SESSION['limit']['users']['numeric'] != '') {
		$sql = "select count(*) ";
		$sql .= "from v_users ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$num_rows = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);

		if ($num_rows >= $_SESSION['limit']['users']['numeric']) {
			message::add($text['message-maximum_users'].' '.$_SESSION['limit']['users']['numeric'], 'negative');
			header('Location: users.php');
			exit;
		}
	}

//required to be a superadmin to update an account that is a member of the superadmin group
	if (permission_exists('user_edit') && $action == 'edit') {
		$superadmins = superadmin_list($db);
		if (if_superadmin($superadmins, $user_uuid)) {
			if (!if_group("superadmin")) {
				echo "access denied";
				exit;
			}
		}
	}

//delete the group from the user
	if ($_GET["a"] == "delete" && is_uuid($_GET["group_uuid"]) && is_uuid($user_uuid) && permission_exists("user_delete")) {
		//set the variables
			$group_uuid = $_GET["group_uuid"];
		//delete the group from the users
			$array['user_groups'][0]['group_uuid'] = $group_uuid;
			$array['user_groups'][0]['user_uuid'] = $user_uuid;

			$p = new permissions;
			$p->add('user_group_delete', 'temp');

			$database = new database;
			$database->app_name = 'users';
			$database->app_uuid = '112124b3-95c2-5352-7e9d-d14c0b88f207';
			$database->delete($array);
			unset($array);

			$p->delete('user_group_delete', 'temp');

		//redirect the user
			message::add($text['message-update']);
			header("Location: user_edit.php?id=".$user_uuid);
			exit;
	}

//retrieve password requirements
	$required['length'] = $_SESSION['users']['password_length']['numeric'];
	$required['number'] = ($_SESSION['users']['password_number']['boolean'] == 'true') ? true : false;
	$required['lowercase'] = ($_SESSION['users']['password_lowercase']['boolean'] == 'true') ? true : false;
	$required['uppercase'] = ($_SESSION['users']['password_uppercase']['boolean'] == 'true') ? true : false;
	$required['special'] = ($_SESSION['users']['password_special']['boolean'] == 'true') ? true : false;

//prepare the data
	if (count($_POST) > 0) {

		//get the HTTP values and set as variables
			if (permission_exists('user_edit') && $action == 'edit') {
				$user_uuid = $_REQUEST["id"];
				$username_old = $_POST["username_old"];
			}
			$domain_uuid = $_POST["domain_uuid"];
			$username = $_POST["username"];
			$password = $_POST["password"];
			$password_confirm = $_POST["password_confirm"];
			$user_email = $_POST["user_email"];
			$user_status = $_POST["user_status"];
			$user_language = $_POST["user_language"];
			$user_time_zone = $_POST["user_time_zone"];
			if (permission_exists('user_edit') && $action == 'edit') {
				$contact_uuid = $_POST["contact_uuid"];
			}
			else if (permission_exists('user_add') && $action == 'add') {
				$contact_organization = $_POST["contact_organization"];
				$contact_name_given = $_POST["contact_name_given"];
				$contact_name_family = $_POST["contact_name_family"];
			}
			$group_uuid_name = $_POST["group_uuid_name"];
			$user_enabled = $_POST["user_enabled"];
			$api_key = $_POST["api_key"];
			if (permission_exists('message_view')) {
				$message_key = $_POST["message_key"];
			}

		//check required values
			if ($username == '') {
				message::add($text['message-required'].$text['label-username'], 'negative', 7500);
			}
			if (permission_exists('user_edit') && $action == 'edit') {
				if ($username != $username_old && $username != '') {
					$sql = "select count(*) from v_users where username = :username ";
					if ($_SESSION["user"]["unique"]["text"] != "global") {
						$sql .= "and domain_uuid = :domain_uuid ";
						$parameters['domain_uuid'] = $domain_uuid;
					}
					$parameters['username'] = $username;
					$database = new database;
					$num_rows = $database->select($sql, $parameters, 'column');
					if ($num_rows > 0) {
						message::add($text['message-username_exists'], 'negative', 7500);
					}
					unset($sql);
				}
			}
			if ($password != '' && $password != $password_confirm) {
				message::add($text['message-password_mismatch'], 'negative', 7500);
			}
			if (permission_exists('user_add') && $action == 'add') {
				if ($password == '') {
					message::add($text['message-password_blank'], 'negative', 7500);
				}
				if ($user_email == '') {
					message::add($text['message-required'].$text['label-email'], 'negative', 7500);
				}
				if ($group_uuid_name == '') {
					message::add($text['message-required'].$text['label-group'], 'negative', 7500);
				}
			}

			if (strlen($password) > 0) {
				if (is_numeric($required['length']) && $required['length'] != 0) {
					if (strlen($password) < $required['length']) {
						message::add($text['message-required'].$text['label-characters'], 'negative', 7500);
					}
				}
				if ($required['number']) {
					if (!preg_match('/(?=.*[\d])/', $password)) {
						message::add($text['message-required'].$text['label-numbers'], 'negative', 7500);
					}
				}
				if ($required['lowercase']) {
					if (!preg_match('/(?=.*[a-z])/', $password)) {
						message::add($text['message-required'].$text['label-lowercase_letters'], 'negative', 7500);
					}
				}
				if ($required['uppercase']) {
					if (!preg_match('/(?=.*[A-Z])/', $password)) {
						message::add($text['message-required'].$text['label-uppercase_letters'], 'negative', 7500);
					}
				}
				if ($required['special']) {
					if (!preg_match('/(?=.*[\W])/', $password)) {
						message::add($text['message-required'].$text['label-special_characters'], 'negative', 7500);
					}
				}
			}

		//return if error
			if (message::count() != 0) {
				$_SESSION['tmp'][$_SERVER['PHP_SELF']]['user'] = $_POST;
				header("Location: user_edit.php".(permission_exists('user_edit') && $action != 'add' ? "?id=".$user_uuid : null));
				exit;
			}

		//save the data
			$i = $n = $x = $c = 0; //set initial array indexes

		//check to see if user language is set
			$sql = "select user_setting_uuid, user_setting_value from v_user_settings ";
			$sql .= "where user_setting_category = 'domain' ";
			$sql .= "and user_setting_subcategory = 'language' ";
			$sql .= "and user_uuid = :user_uuid ";
			$parameters['user_uuid'] = $user_uuid;
			$database = new database;
			$row = $database->select($sql, $parameters, 'row');
			if (!is_uuid($row['user_setting_uuid']) && $user_language != '') {
				//add user setting to array for insert
					$array['user_settings'][$i]['user_setting_uuid'] = uuid();
					$array['user_settings'][$i]['user_uuid'] = $user_uuid;
					$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
					$array['user_settings'][$i]['user_setting_category'] = 'domain';
					$array['user_settings'][$i]['user_setting_subcategory'] = 'language';
					$array['user_settings'][$i]['user_setting_name'] = 'code';
					$array['user_settings'][$i]['user_setting_value'] = $user_language;
					$array['user_settings'][$i]['user_setting_enabled'] = 'true';
					$i++;
			}
			else {
				if ($row['user_setting_value'] == '' || $user_language == '') {
					$array_delete['user_settings'][0]['user_setting_category'] = 'domain';
					$array_delete['user_settings'][0]['user_setting_subcategory'] = 'language';
					$array_delete['user_settings'][0]['user_uuid'] = $user_uuid;

					$p = new permissions;
					$p->add('user_setting_delete', 'temp');

					$database = new database;
					$database->app_name = 'users';
					$database->app_uuid = '112124b3-95c2-5352-7e9d-d14c0b88f207';
					$database->delete($array_delete);
					unset($array_delete);

					$p->delete('user_setting_delete', 'temp');
				}
				else {
					//add user setting to array for update
					$array['user_settings'][$i]['user_setting_uuid'] = $row['user_setting_uuid'];
					$array['user_settings'][$i]['user_uuid'] = $user_uuid;
					$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
					$array['user_settings'][$i]['user_setting_category'] = 'domain';
					$array['user_settings'][$i]['user_setting_subcategory'] = 'language';
					$array['user_settings'][$i]['user_setting_name'] = 'code';
					$array['user_settings'][$i]['user_setting_value'] = $user_language;
					$array['user_settings'][$i]['user_setting_enabled'] = 'true';
					$i++;
				}
			}
			unset($sql, $parameters, $row);

		//check to see if user time zone is set
			$sql = "select user_setting_uuid, user_setting_value from v_user_settings ";
			$sql .= "where user_setting_category = 'domain' ";
			$sql .= "and user_setting_subcategory = 'time_zone' ";
			$sql .= "and user_uuid = :user_uuid ";
			$parameters['user_uuid'] = $user_uuid;
			$database = new database;
			$row = $database->select($sql, $parameters, 'row');
			if ($row['user_setting_uuid'] == '' && $user_time_zone != '') {
				//add user setting to array for insert
				$array['user_settings'][$i]['user_setting_uuid'] = uuid();
				$array['user_settings'][$i]['user_uuid'] = $user_uuid;
				$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
				$array['user_settings'][$i]['user_setting_category'] = 'domain';
				$array['user_settings'][$i]['user_setting_subcategory'] = 'time_zone';
				$array['user_settings'][$i]['user_setting_name'] = 'name';
				$array['user_settings'][$i]['user_setting_value'] = $user_time_zone;
				$array['user_settings'][$i]['user_setting_enabled'] = 'true';
				$i++;
			}
			else {
				if ($row['user_setting_value'] == '' || $user_time_zone == '') {
					$array_delete['user_settings'][0]['user_setting_category'] = 'domain';
					$array_delete['user_settings'][0]['user_setting_subcategory'] = 'time_zone';
					$array_delete['user_settings'][0]['user_uuid'] = $user_uuid;

					$p = new permissions;
					$p->add('user_setting_delete', 'temp');

					$database = new database;
					$database->app_name = 'users';
					$database->app_uuid = '112124b3-95c2-5352-7e9d-d14c0b88f207';
					$database->delete($array_delete);
					unset($array_delete);

					$p->delete('user_setting_delete', 'temp');
				}
				else {
					//add user setting to array for update
					$array['user_settings'][$i]['user_setting_uuid'] = $row['user_setting_uuid'];
					$array['user_settings'][$i]['user_uuid'] = $user_uuid;
					$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
					$array['user_settings'][$i]['user_setting_category'] = 'domain';
					$array['user_settings'][$i]['user_setting_subcategory'] = 'time_zone';
					$array['user_settings'][$i]['user_setting_name'] = 'name';
					$array['user_settings'][$i]['user_setting_value'] = $user_time_zone;
					$array['user_settings'][$i]['user_setting_enabled'] = 'true';
					$i++;
				}
			}
			unset($sql, $parameters, $row);

		//check to see if message key is set
			if (permission_exists('message_view')) {
				$sql = "select user_setting_uuid, user_setting_value from v_user_settings ";
				$sql .= "where user_setting_category = 'message' ";
				$sql .= "and user_setting_subcategory = 'key' ";
				$sql .= "and user_uuid = :user_uuid ";
				$parameters['user_uuid'] = $user_uuid;
				$database = new database;
				$row = $database->select($sql, $parameters, 'row');
				if ($row['user_setting_uuid'] == '' && $message_key != '') {
					//add user setting to array for insert
					$array['user_settings'][$i]['user_setting_uuid'] = uuid();
					$array['user_settings'][$i]['user_uuid'] = $user_uuid;
					$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
					$array['user_settings'][$i]['user_setting_category'] = 'message';
					$array['user_settings'][$i]['user_setting_subcategory'] = 'key';
					$array['user_settings'][$i]['user_setting_name'] = 'text';
					$array['user_settings'][$i]['user_setting_value'] = $message_key;
					$array['user_settings'][$i]['user_setting_enabled'] = 'true';
					$i++;
				}
				else {
					if ($row['user_setting_value'] == '' || $message_key == '') {
						$array_delete['user_settings'][0]['user_setting_category'] = 'message';
						$array_delete['user_settings'][0]['user_setting_subcategory'] = 'key';
						$array_delete['user_settings'][0]['user_uuid'] = $user_uuid;

						$p = new permissions;
						$p->add('user_setting_delete', 'temp');

						$database = new database;
						$database->app_name = 'users';
						$database->app_uuid = '112124b3-95c2-5352-7e9d-d14c0b88f207';
						$database->delete($array_delete);
						unset($array_delete);

						$p->delete('user_setting_delete', 'temp');
					}
					else {
						//add user setting to array for update
						$array['user_settings'][$i]['user_setting_uuid'] = $row['user_setting_uuid'];
						$array['user_settings'][$i]['user_uuid'] = $user_uuid;
						$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
						$array['user_settings'][$i]['user_setting_category'] = 'message';
						$array['user_settings'][$i]['user_setting_subcategory'] = 'key';
						$array['user_settings'][$i]['user_setting_name'] = 'text';
						$array['user_settings'][$i]['user_setting_value'] = $message_key;
						$array['user_settings'][$i]['user_setting_enabled'] = 'true';
						$i++;
					}
				}
			}

		//assign the user to the group
			if ((permission_exists('user_add') || permission_exists('user_edit')) && $_REQUEST["group_uuid_name"] != '') {
				$group_data = explode('|', $group_uuid_name);
				$group_uuid = $group_data[0];
				$group_name = $group_data[1];

				//compare the group level to only add groups at the same level or lower than the user
				$sql = "select * from v_groups ";
				$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
				$sql .= "and group_uuid = :group_uuid ";
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['group_uuid'] = $group_uuid;
				$database = new database;
				$row = $database->select($sql, $parameters, 'row');
				if ($row['group_level'] <= $_SESSION['user']['group_level']) {
					$array['user_groups'][$n]['user_group_uuid'] = uuid();
					$array['user_groups'][$n]['domain_uuid'] = $domain_uuid;
					$array['user_groups'][$n]['group_name'] = $group_name;
					$array['user_groups'][$n]['group_uuid'] = $group_uuid;
					$array['user_groups'][$n]['user_uuid'] = $user_uuid;
					$n++;
				}
				unset($parameters);
			}

		//update domain, if changed
			if ((permission_exists('user_add') || permission_exists('user_edit')) && permission_exists('user_domain')) {
				//adjust group user records
					$sql = "select user_group_uuid from v_user_groups ";
					$sql .= "where user_uuid = :user_uuid ";
					$parameters['user_uuid'] = $user_uuid;
					$database = new database;
					$result = $database->select($sql, $parameters, 'all');
					if (is_array($result)) {
						foreach ($result as $row) {
							//add group user to array for update
							$array['user_groups'][$n]['user_group_uuid'] = $row['user_group_uuid'];
							$array['user_groups'][$n]['domain_uuid'] = $domain_uuid;
							$n++;
						}
					}
					unset($sql, $parameters);
				//adjust user setting records
					$sql = "select user_setting_uuid from v_user_settings ";
					$sql .= "where user_uuid = :user_uuid ";
					$parameters['user_uuid'] = $user_uuid;
					$database = new database;
					$result = $database->select($sql, $parameters);
					if (is_array($result)) {
						foreach ($result as $row) {
							//add user setting to array for update
							$array['user_settings'][$i]['user_setting_uuid'] = $row['user_setting_uuid'];
							$array['user_settings'][$i]['domain_uuid'] = $domain_uuid;
							$i++;
						}
					}
					unset($sql, $parameters);
				//unassign any foreign domain groups
					$sql = "delete from v_user_groups ";
					$sql .= "where domain_uuid = :domain_uuid ";
					$sql .= "and user_uuid = :user_uuid ";
					$sql .= "and group_uuid not in (";
					$sql .= "	select group_uuid from v_groups where domain_uuid = :domain_uuid or domain_uuid is null ";
					$sql .= ") ";
					$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
					$parameters['user_uuid'] = $user_uuid;
					$database = new database;
					$database->execute($sql, $parameters);
					unset($sql, $parameters);
			}

		//add contact to array for insert
			if ($action == 'add' && permission_exists('user_add') && permission_exists('contact_add')) {
				$contact_uuid = uuid();
				$array['contacts'][$c]['domain_uuid'] = $domain_uuid;
				$array['contacts'][$c]['contact_uuid'] = $contact_uuid;
				$array['contacts'][$c]['contact_type'] = 'user';
				$array['contacts'][$c]['contact_organization'] = $contact_organization;
				$array['contacts'][$c]['contact_name_given'] = $contact_name_given;
				$array['contacts'][$c]['contact_name_family'] = $contact_name_family;
				$array['contacts'][$c]['contact_nickname'] = $username;
				$c++;
				if (permission_exists('contact_email_add')) {
					$contact_email_uuid = uuid();
					$array['contact_emails'][$c]['contact_email_uuid'] = $contact_email_uuid;
					$array['contact_emails'][$c]['domain_uuid'] = $domain_uuid;
					$array['contact_emails'][$c]['contact_uuid'] = $contact_uuid;
					$array['contact_emails'][$c]['email_address'] = $user_email;
					$array['contact_emails'][$c]['email_primary'] = '1';
					$c++;
				}
			}

		//add user setting to array for update
			$array['users'][$x]['user_uuid'] = $user_uuid;
			$array['users'][$x]['domain_uuid'] = $domain_uuid;
			if ($username != '' && $username != $username_old) {
				$array['users'][$x]['username'] = $username;
			}
			if ($password != '' && $password == $password_confirm) {
				$salt = uuid();
				$array['users'][$x]['password'] = md5($salt.$password);
				$array['users'][$x]['salt'] = $salt;
			}
			$array['users'][$x]['user_email'] = $user_email;
			$array['users'][$x]['user_status'] = $user_status;
			if (permission_exists('user_add') || permission_exists('user_edit')) {
				$array['users'][$x]['api_key'] = ($api_key != '') ? $api_key : null;
				$array['users'][$x]['user_enabled'] = $user_enabled;
				$array['users'][$x]['contact_uuid'] = ($contact_uuid != '') ? $contact_uuid : null;
				if ($action == 'add') {
					$array['users'][$x]['add_user'] = $_SESSION["user"]["username"];
					$array['users'][$x]['add_date'] = date("Y-m-d H:i:s.uO");
				}
			}
			$x++;

		//add the user_edit permission
			$p = new permissions;
			$p->add("user_setting_add", "temp");
			$p->add("user_setting_edit", "temp");
			$p->add("user_edit", "temp");
			$p->add('user_group_add', 'temp');
	
		//save the data
			$database = new database;
			$database->app_name = 'users';
			$database->app_uuid = '112124b3-95c2-5352-7e9d-d14c0b88f207';
			$database->save($array);
			//$message = $database->message;

		//remove the temporary permission
			$p->delete("user_setting_add", "temp");
			$p->delete("user_setting_edit", "temp");
			$p->delete("user_edit", "temp");
			$p->delete('user_group_add', 'temp');

		//if call center installed
			if ($action == 'edit' && permission_exists('user_edit') && file_exists($_SERVER["PROJECT_ROOT"]."/app/call_centers/app_config.php")) {
				//get the call center agent uuid
					$sql = "select call_center_agent_uuid from v_call_center_agents ";
					$sql .= "where domain_uuid = :domain_uuid ";
					$sql .= "and user_uuid = :user_uuid ";
					$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
					$parameters['user_uuid'] = $user_uuid;
					$database = new database;
					$call_center_agent_uuid = $database->select($sql, $parameters, 'column');
					unset($sql, $parameters);

				//update the user_status
					if (isset($call_center_agent_uuid) && is_uuid($call_center_agent_uuid)) {
						$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
						$switch_cmd .= "callcenter_config agent set status ".$call_center_agent_uuid." '".$user_status."'";
						$switch_result = event_socket_request($fp, 'api '.$switch_cmd);
					}

				//update the user state
					if (isset($call_center_agent_uuid) && is_uuid($call_center_agent_uuid)) {
						$cmd = "api callcenter_config agent set state ".$call_center_agent_uuid." Waiting";
						$response = event_socket_request($fp, $cmd);
					}
			}

		//response message
			if ($action == 'edit') {
				message::add($text['message-update'],'positive');
			}
			else {
				message::add($text['message-add'],'positive');
			}
			header("Location: user_edit.php?id=".$user_uuid);
			exit;
	}

//populate the form with values from session variable
	if (is_array($_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']) && sizeof($_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']) != 0) {
		$domain_uuid = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["domain_uuid"];
		$username = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["username"];
		$password = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["password"];
		$password_confirm = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["password_confirm"];
		$api_key = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["api_key"];
		$user_enabled = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["user_enabled"];
		$contact_uuid = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["contact_uuid"];
		$user_status = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']["user_status"];
		$password_confirm = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['password_confirm'];
		$user_settings['domain']['language']['code'] = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['user_language'];
		$user_settings['domain']['time_zone']['name'] = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['user_time_zone'];
		$user_email = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['user_email'];
		$contact_name_given = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['contact_name_given'];
		$contact_name_family = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['contact_name_family'];
		$contact_organization = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['contact_organization'];
		$user_settings["message"]["key"]["text"] = $_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']['message_key'];

		$unsaved = true;
		unset($_SESSION['tmp'][$_SERVER['PHP_SELF']]['user']);
	}
	else {
		//populate the form with values from db
		if ($action == 'edit') {
			$sql = "select * from v_users where user_uuid = :user_uuid ";
			if (!permission_exists('user_all')) {
				$sql .= "and domain_uuid = :domain_uuid ";
				$parameters['domain_uuid'] = $domain_uuid;
			}
			$parameters['user_uuid'] = $user_uuid;
			$database = new database;
			$row = $database->select($sql, $parameters, 'row');
			if (is_array($row) && sizeof($row) > 0) {
				$domain_uuid = $row["domain_uuid"];
				$user_uuid = $row["user_uuid"];
				$username = $row["username"];
				$user_email = $row["user_email"];
				$api_key = $row["api_key"];
				$user_enabled = $row["user_enabled"];
				$contact_uuid = $row["contact_uuid"];
				$user_status = $row["user_status"];
			}
			else {
				message::add($text['message-invalid_user'], 'negative', 7500);
				header("Location: user_edit.php?id=".$_SESSION['user_uuid']);
				exit;
			}
			unset($sql, $parameters, $row);

			//get user settings
			$sql = "select * from v_user_settings ";
			$sql .= "where user_uuid = :user_uuid ";
			$sql .= "and user_setting_enabled = 'true' ";
			$parameters['user_uuid'] = $user_uuid;
			$database = new database;
			$result = $database->select($sql, $parameters, 'all');
			if (is_array($result)) {
				foreach($result as $row) {
					$name = $row['user_setting_name'];
					$category = $row['user_setting_category'];
					$subcategory = $row['user_setting_subcategory'];
					if (strlen($subcategory) == 0) {
						//$$category[$name] = $row['domain_setting_value'];
						$user_settings[$category][$name] = $row['user_setting_value'];
					}
					else {
						$user_settings[$category][$subcategory][$name] = $row['user_setting_value'];
					}
				}
			}
			unset($sql, $parameters, $result, $row);
		}
	}

//include the header
	require_once "resources/header.php";
	$document['title'] = $text['title-user_edit'];

//show the content
	echo "<script>\n";
	echo "	function compare_passwords() {\n";
	echo "		if (document.getElementById('password') === document.activeElement || document.getElementById('password_confirm') === document.activeElement) {\n";
	echo "			if ($('#password').val() != '' || $('#password_confirm').val() != '') {\n";
	echo "				if ($('#password').val() != $('#password_confirm').val()) {\n";
	echo "					$('#password').removeClass('formfld_highlight_good');\n";
	echo "					$('#password_confirm').removeClass('formfld_highlight_good');\n";
	echo "					$('#password').addClass('formfld_highlight_bad');\n";
	echo "					$('#password_confirm').addClass('formfld_highlight_bad');\n";
	echo "				}\n";
	echo "				else {\n";
	echo "					$('#password').removeClass('formfld_highlight_bad');\n";
	echo "					$('#password_confirm').removeClass('formfld_highlight_bad');\n";
	echo "					$('#password').addClass('formfld_highlight_good');\n";
	echo "					$('#password_confirm').addClass('formfld_highlight_good');\n";
	echo "				}\n";
	echo "			}\n";
	echo "		}\n";
	echo "		else {\n";
	echo "			$('#password').removeClass('formfld_highlight_bad');\n";
	echo "			$('#password_confirm').removeClass('formfld_highlight_bad');\n";
	echo "			$('#password').removeClass('formfld_highlight_good');\n";
	echo "			$('#password_confirm').removeClass('formfld_highlight_good');\n";
	echo "		}\n";
	echo "	}\n";

	echo "	function show_strength_meter() {\n";
	echo "		$('#pwstrength_progress').slideDown();\n";
	echo "	}\n";
	echo "</script>\n";

	echo "<form name='frm' id='frm' method='post'>\n";
	echo "<input type='hidden' name='action' id='action' value=''>\n";

	echo "<div style='float:right; white-space: nowrap;'>\n";
	if ($unsaved) {
		echo "<span style='color: #b00;'>".$text['message-unsaved_changes']." <i class='fas fa-exclamation-triangle' style='margin-right: 15px;'></i></span>";
	}
	if (permission_exists('user_add') || permission_exists('user_edit')) {
		echo "	<input type='button' class='btn' style='margin-right: 10px;' onclick=\"window.location='users.php'\" value='".$text['button-back']."'>";
	}
	if (permission_exists('ticket_add') || permission_exists('ticket_edit')) {
		echo "	<input type='button' class='btn' style='margin-right: 3px;' onclick=\"window.location='/app/tickets/tickets.php?user_uuid=".escape($user_uuid)."'\" value='".$text['button-tickets']."'>";
	}
	echo "	<input type='submit' class='btn' value='".$text['button-save']."'>";
	echo "</div>\n";
	echo "<b>".$text['header-user_edit']."</b><br />\n";
	echo $text['description-user_edit']."<br /><br />\n";

	echo "<table cellpadding='0' cellspacing='0' border='0' width='100%'>";

	echo "	<tr>";
	echo "		<td width='30%' class='vncellreq' valign='top'>".$text['label-username']."</td>";
	echo "		<td width='70%' class='vtable'>";
	if (permission_exists("user_edit")) {
		echo "		<input type='text' class='formfld' name='username' id='username' autocomplete='new-password' value='".escape($username)."' required='required'>\n";
		echo "		<input type='text' style='display: none;' disabled='disabled'>\n"; //help defeat browser auto-fill
	}
	else {
		echo "		".escape($username)."\n";
		echo "		<input type='hidden' name='username' id='username' autocomplete='new-password' value='".escape($username)."'>\n";
	}
	echo "		</td>";
	echo "	</tr>";

	echo "	<tr>";
	echo "		<td class='vncell".(($action == 'add') ? 'req' : null)."' valign='top'>".$text['label-password']."</td>";
	echo "		<td class='vtable'>";
	echo "			<input type='password' style='display: none;' disabled='disabled'>"; //help defeat browser auto-fill
	echo "			<input type='password' autocomplete='new-password' class='formfld' name='password' id='password' value=\"".escape($password)."\" ".($action == 'add' ? "required='required'" : null)." onkeypress='show_strength_meter();' onfocus='compare_passwords();' onkeyup='compare_passwords();' onblur='compare_passwords();'>";
	echo "			<div id='pwstrength_progress' class='pwstrength_progress'></div><br />\n";
	if ((is_numeric($required['length']) && $required['length'] != 0) || $required['number'] || $required['lowercase'] || $required['uppercase'] || $required['special']) {
		echo $text['label-required'].': ';
		if (is_numeric($required['length']) && $required['length'] != 0) {
			echo $required['length']." ".$text['label-characters'];
			if ($required['number'] || $required['lowercase'] || $required['uppercase'] || $required['special']) {
				echo " (";
			}
		}
		if ($required['number']) {
			$required_temp[] = $text['label-number'];
		}
		if ($required['lowercase']) {
			$required_temp[] = $text['label-lowercase'];
		}
		if ($required['uppercase']) {
			$required_temp[] = $text['label-uppercase'];
		}
		if ($required['special']) {
			$required_temp[] = $text['label-special'];
		}
		if (is_array($required_temp) && sizeof($required_temp) != 0) {
			echo implode(', ',$required_temp);
			if (is_numeric($required['length']) && $required['length'] != 0) {
				echo ")";
			}
		}
		unset($required_temp);
	}
	echo "		</td>";
	echo "	</tr>";
	echo "	<tr>";
	echo "		<td class='vncell".(($action == 'add') ? 'req' : null)."' valign='top'>".$text['label-confirm_password']."</td>";
	echo "		<td class='vtable'>";
	echo "			<input type='password' autocomplete='new-password' class='formfld' name='password_confirm' id='password_confirm' value=\"".escape($password_confirm)."\" ".($action == 'add' ? "required='required'" : null)." onfocus='compare_passwords();' onkeyup='compare_passwords();' onblur='compare_passwords();'><br />\n";
	echo "			".$text['message-green_border_passwords_match']."\n";
	echo "		</td>";
	echo "	</tr>";

	echo "	<tr>";
	echo "		<td class='vncellreq'>".$text['label-email']."</td>";
	echo "		<td class='vtable'><input type='text' class='formfld' name='user_email' value='".escape($user_email)."' required='required'></td>";
	echo "	</tr>";

	echo "	<tr>\n";
	echo "	<td width='20%' class=\"vncell\" valign='top'>\n";
	echo "		".$text['label-user_language']."\n";
	echo "	</td>\n";
	echo "	<td class=\"vtable\" align='left'>\n";
	echo "		<select id='user_language' name='user_language' class='formfld' style=''>\n";
	echo "		<option value=''></option>\n";
	//get all language codes from database
	$sql = "select * from v_languages order by language asc ";
	$database = new database;
	$languages = $database->select($sql, null, 'all');
	if (is_array($languages) && sizeof($languages) != 0) {
		foreach ($languages as $row) {
			$language_codes[$row["code"]] = $row["language"];
		}
	}
	unset($sql, $languages, $row);
	if (is_array($_SESSION['app']['languages']) && sizeof($_SESSION['app']['languages']) != 0) {
		foreach ($_SESSION['app']['languages'] as $code) {
			$selected = ($code == $user_settings['domain']['language']['code']) ? "selected='selected'" : null;
			echo "	<option value='".escape($code)."' ".escape($selected).">".escape($language_codes[$code])." [".escape($code)."]</option>\n";
		}
	}
	echo "		</select>\n";
	echo "		<br />\n";
	echo "		".$text['description-user_language']."<br />\n";
	echo "	</td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "	<td width='20%' class=\"vncell\" valign='top'>\n";
	echo "		".$text['label-time_zone']."\n";
	echo "	</td>\n";
	echo "	<td class=\"vtable\" align='left'>\n";
	echo "		<select id='user_time_zone' name='user_time_zone' class='formfld' style=''>\n";
	echo "		<option value=''></option>\n";
	//$list = DateTimeZone::listAbbreviations();
	$time_zone_identifiers = DateTimeZone::listIdentifiers();
	$previous_category = '';
	$x = 0;
	foreach ($time_zone_identifiers as $key => $row) {
		$time_zone = explode("/", $row);
		$category = $time_zone[0];
		if ($category != $previous_category) {
			if ($x > 0) {
				echo "		</optgroup>\n";
			}
			echo "		<optgroup label='".$category."'>\n";
		}
		if ($row == $user_settings['domain']['time_zone']['name']) {
			echo "			<option value='".escape($row)."' selected='selected'>".escape($row)."</option>\n";
		}
		else {
			echo "			<option value='".escape($row)."'>".escape($row)."</option>\n";
		}
		$previous_category = $category;
		$x++;
	}
	echo "		</select>\n";
	echo "		<br />\n";
	echo "		".$text['description-time_zone']."<br />\n";
	echo "	</td>\n";
	echo "	</tr>\n";

	if ($_SESSION['user_status_display'] != "false") {
		echo "	<tr>\n";
		echo "	<td width='20%' class=\"vncell\" valign='top'>\n";
		echo "		".$text['label-status']."\n";
		echo "	</td>\n";
		echo "	<td class=\"vtable\">\n";
		$cmd = "'".PROJECT_PATH."/app/calls_active/v_calls_exec.php?cmd=callcenter_config+agent+set+status+".escape($username)."@".$_SESSION['domains'][$domain_uuid]['domain_name']."+'+this.value";
		echo "		<select id='user_status' name='user_status' class='formfld' style='' onchange=\"send_cmd($cmd);\">\n";
		echo "			<option value=''></option>\n";
		echo "			<option value='Available' ".(($user_status == "Available") ? "selected='selected'" : null).">".$text['option-available']."</option>\n";
		echo "			<option value='Available (On Demand)' ".(($user_status == "Available (On Demand)") ? "selected='selected'" : null).">".$text['option-available_on_demand']."</option>\n";
		echo "			<option value='Logged Out' ".(($user_status == "Logged Out") ? "selected='selected'" : null).">".$text['option-logged_out']."</option>\n";
		echo "			<option value='On Break' ".(($user_status == "On Break") ? "selected='selected'" : null).">".$text['option-on_break']."</option>\n";
		echo "			<option value='Do Not Disturb' ".(($user_status == "Do Not Disturb") ? "selected='selected'" : null).">".$text['option-do_not_disturb']."</option>\n";
		echo "		</select>\n";
		echo "		<br />\n";
		echo "		".$text['description-status']."<br />\n";
		echo "	</td>\n";
		echo "	</tr>\n";
	}

	if ($action == 'edit' && permission_exists("user_edit")) {
		echo "	<tr>";
		echo "		<td class='vncell' valign='top'>".$text['label-contact']."</td>";
		echo "		<td class='vtable'>\n";
		$sql = "select ";
		$sql .= "c.contact_uuid, ";
		$sql .= "c.contact_organization, ";
		$sql .= "c.contact_name_given, ";
		$sql .= "c.contact_name_family, ";
		$sql .= "c.contact_nickname ";
		$sql .= "from ";
		$sql .= "v_contacts as c ";
		$sql .= "where ";
		$sql .= "c.domain_uuid = :domain_uuid ";
		$sql .= "and not exists ( ";
		$sql .= "	select ";
		$sql .= "	contact_uuid ";
		$sql .= "	from ";
		$sql .= "	v_users as u ";
		$sql .= "	where ";
		$sql .= "	u.domain_uuid = :domain_uuid ";
		if (is_uuid($contact_uuid)) { //don't exclude currently assigned contact
			$sql .= "and u.contact_uuid <> :contact_uuid ";
			$parameters['contact_uuid'] = $contact_uuid;
		}
		$sql .= "	and u.contact_uuid = c.contact_uuid ";
		$sql .= ") ";
		$sql .= "order by ";
		$sql .= "lower(c.contact_organization) asc, ";
		$sql .= "lower(c.contact_name_family) asc, ";
		$sql .= "lower(c.contact_name_given) asc, ";
		$sql .= "lower(c.contact_nickname) asc ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['contact_uuid'] = $contact_uuid;
		$database = new database;
		$contacts = $database->select($sql, $parameters, 'all');
		unset($parameters);
		echo "<select name=\"contact_uuid\" id=\"contact_uuid\" class=\"formfld\">\n";
		echo "<option value=\"\"></option>\n";
		foreach($contacts as $row) {
			$contact_name = array();
			if ($row['contact_organization'] != '') { $contact_name[] = $row['contact_organization']; }
			if ($row['contact_name_family'] != '') { $contact_name[] = $row['contact_name_family']; }
			if ($row['contact_name_given'] != '') { $contact_name[] = $row['contact_name_given']; }
			if ($row['contact_name_family'] == '' && $row['contact_name_family'] == '' && $row['contact_nickname'] != '') { $contact_name[] = $row['contact_nickname']; }
			echo "<option value='".escape($row['contact_uuid'])."' ".(($row['contact_uuid'] == $contact_uuid) ? "selected='selected'" : null).">".escape(implode(', ', $contact_name))."</option>\n";
		}
		unset($sql, $row_count);
		echo "</select>\n";
		echo "<br />\n";
		echo $text['description-contact']."\n";
		if (strlen($contact_uuid) > 0) {
			echo "			<a href=\"".PROJECT_PATH."/app/contacts/contact_edit.php?id=".escape($contact_uuid)."\">".$text['description-contact_view']."</a>\n";
		}
		echo "		</td>";
		echo "	</tr>";
	}
	else if ($action == 'add' && permission_exists("user_add")) {
		echo "	<tr>";
		echo "		<td class='vncell'>".$text['label-first_name']."</td>";
		echo "		<td class='vtable'><input type='text' class='formfld' name='contact_name_given' value='".escape($contact_name_given)."'></td>";
		echo "	</tr>";
		echo "	<tr>";
		echo "		<td class='vncell'>".$text['label-last_name']."</td>";
		echo "		<td class='vtable'><input type='text' class='formfld' name='contact_name_family' value='".escape($contact_name_family)."'></td>";
		echo "	</tr>";
		echo "	<tr>";
		echo "		<td class='vncell'>".$text['label-organization']."</td>";
		echo "		<td class='vtable'><input type='text' class='formfld' name='contact_organization' value='".escape($contact_organization)."'></td>";
		echo "	</tr>";
	}

	if (permission_exists("user_groups")) {
		echo "	<tr>";
		echo "		<td class='vncellreq' valign='top'>".$text['label-groups']."</td>";
		echo "		<td class='vtable'>";

		$sql = "select ";
		$sql .= "	ug.*, g.domain_uuid as group_domain_uuid ";
		$sql .= "from ";
		$sql .= "	v_user_groups as ug, ";
		$sql .= "	v_groups as g ";
		$sql .= "where ";
		$sql .= "	ug.group_uuid = g.group_uuid ";
		$sql .= "	and (";
		$sql .= "		g.domain_uuid = :domain_uuid ";
		$sql .= "		or g.domain_uuid is null ";
		$sql .= "	) ";
		$sql .= "	and ug.domain_uuid = :domain_uuid ";
		$sql .= "	and ug.user_uuid = :user_uuid ";
		$sql .= "order by ";
		$sql .= "	g.domain_uuid desc, ";
		$sql .= "	g.group_name asc ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['user_uuid'] = $user_uuid;
		$database = new database;
		$user_groups = $database->select($sql, $parameters, 'all');
		if (is_array($user_groups)) {
			echo "<table cellpadding='0' cellspacing='0' border='0'>\n";
			foreach($user_groups as $field) {
				if (strlen($field['group_name']) > 0) {
					echo "<tr>\n";
					echo "	<td class='vtable' style='white-space: nowrap; padding-right: 30px;' nowrap='nowrap'>";
					echo escape($field['group_name']).(($field['group_domain_uuid'] != '') ? "@".$_SESSION['domains'][$field['group_domain_uuid']]['domain_name'] : null);
					echo "	</td>\n";
					if (permission_exists('group_member_delete') || if_group("superadmin")) {
						echo "	<td class='list_control_icons' style='width: 25px;'>\n";
						echo "		<a href='user_edit.php?id=".escape($user_uuid)."&domain_uuid=".escape($domain_uuid)."&group_uuid=".escape($field['group_uuid'])."&a=delete' alt='".$text['button-delete']."' onclick=\"return confirm('".$text['confirm-delete']."')\">".$v_link_label_delete."</a>\n";
						echo "	</td>\n";
					}
					echo "</tr>\n";
					if (is_uuid($field['group_uuid'])) {
						$assigned_groups[] = $field['group_uuid'];
					}
				}
			}
			echo "</table>\n";
		}
		unset($sql, $parameters, $user_groups, $field);

		$sql = "select * from v_groups ";
		$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
		if (sizeof($assigned_groups) > 0) {
			$sql .= "and group_uuid not in ('".implode("','",$assigned_groups)."') ";
		}
		$sql .= "order by domain_uuid desc, group_name asc ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$groups = $database->select($sql, $parameters, 'all');
		if (is_array($groups)) {
			if (isset($assigned_groups)) { echo "<br />\n"; }
			echo "<select name='group_uuid_name' class='formfld' style='width: auto; margin-right: 3px;' ".($action == 'add' ? "required='required'" : null).">\n";
			echo "	<option value=''></option>\n";
			foreach($groups as $field) {
				if ($field['group_level'] <= $_SESSION['user']['group_level']) {
					if (!isset($assigned_groups) || (isset($assigned_groups) && !in_array($field["group_uuid"], $assigned_groups))) {
						if ($group_uuid_name == $field['group_uuid']."|".$field['group_name']) { $selected = "selected='selected'"; } else { $selected = ''; }
						echo "	<option value='".$field['group_uuid']."|".$field['group_name']."' $selected>".$field['group_name'].(($field['domain_uuid'] != '') ? "@".$_SESSION['domains'][$field['domain_uuid']]['domain_name'] : null)."</option>\n";
					}
				}
			}
			echo "</select>";
			if ($action == 'edit') {
				echo "<input type='submit' class='btn' value=\"".$text['button-add']."\" >\n";
			}
		}
		unset($sql, $parameters, $groups, $field);

		echo "		</td>";
		echo "	</tr>";
	}

	if (permission_exists('user_domain')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-domain']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "    <select class='formfld' name='domain_uuid'>\n";
		foreach ($_SESSION['domains'] as $row) {
			echo "	<option value='".escape($row['domain_uuid'])."' ".(($row['domain_uuid'] == $domain_uuid) ? "selected='selected'" : null).">".escape($row['domain_name'])."</option>\n";
		}
		echo "    </select>\n";
		echo "<br />\n";
		echo $text['description-domain_name']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}
	else {
		echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid)."'>";
	}

	if (permission_exists('api_key')) {
		echo "	<tr>";
		echo "		<td class='vncell' valign='top'>".$text['label-api_key']."</td>";
		echo "		<td class='vtable'>\n";
		echo "			<input type=\"text\" class='formfld' name=\"api_key\" id='api_key' value=\"".escape($api_key)."\" >";
		echo "			<input type='button' class='btn' value='".$text['button-generate']."' onclick=\"getElementById('api_key').value='".uuid()."';\">";
		if (strlen($text['description-api_key']) > 0) {
			echo "			<br />".$text['description-api_key']."<br />\n";
		}
		echo "		</td>";
		echo "	</tr>";
	}

	if (permission_exists('message_view')) {
		echo "	<tr>";
		echo "		<td class='vncell' valign='top'>".$text['label-message_key']."</td>";
		echo "		<td class='vtable'>\n";
		echo "			<input type='text' class='formfld' name='message_key' id='message_key' value=\"".escape($user_settings["message"]["key"]["text"])."\" >";
		echo "			<input type='button' class='btn' value='".$text['button-generate']."' onclick=\"getElementById('message_key').value='".uuid()."';\">";
		if (strlen($text['description-message_key']) > 0) {
			echo "			<br />".$text['description-message_key']."<br />\n";
		}
		echo "		</td>";
		echo "	</tr>";
	}

	echo "<tr ".($user_uuid == $_SESSION['user_uuid'] ? "style='display: none;'" : null).">\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='user_enabled'>\n";
	echo "		<option value='true'>".$text['option-true']."</option>\n";
	echo "		<option value='false' ".(($user_enabled != "true") ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "	<tr>";
	echo "		<td colspan='2' align='right' style='white-space: nowrap;'>";
	if ($action == 'edit') {
		echo "		<input type='hidden' name='id' value=\"".escape($user_uuid)."\">";
		if (permission_exists("user_edit")) {
			echo "			<input type='hidden' name='username_old' value=\"".escape($username)."\">";
		}
	}
	echo "			<br>";
	if ($unsaved) {
		echo "		<span style='color: #b00;'>".$text['message-unsaved_changes']." <i class='fas fa-exclamation-triangle' style='margin-right: 15px;'></i></span>";
	}
	echo "			<input type='submit' class='btn' value='".$text['button-save']."'>";
	echo "		</td>";
	echo "	</tr>";
	echo "</table>";
	echo "<br><br>";
	echo "</form>";

	if (permission_exists("user_edit") && permission_exists('user_setting_view') && $action == 'edit') {
		require $_SERVER["DOCUMENT_ROOT"] . PROJECT_PATH . "/core/user_settings/user_settings.php";
	}

//include the footer
	require_once "resources/footer.php";

?>
