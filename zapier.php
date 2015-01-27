<?php

/*
Plugin Name: Gravity Forms Zapier Add-on
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with Zapier allowing form submissions to be automatically sent to your configured Zaps.
Version: 1.4.2
Author: rocketgenius
Author URI: http://www.rocketgenius.com

------------------------------------------------------------------------
Copyright 2009 rocketgenius

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFZapier', 'init'));

class GFZapier {

	private static $slug = "gravityformszapier";
	private static $path = "gravityformszapier/zapier.php";
    private static $url = "http://www.gravityforms.com";
    private static $version = "1.4.2";
    private static $min_gravityforms_version = "1.7.5";

	public static function init(){

		//supports logging
		add_filter("gform_logging_supported", array("GFZapier", "set_logging_supported"));

		if (basename($_SERVER['PHP_SELF']) == "plugins.php") {
            //loading translations
            load_plugin_textdomain('gravityformszapier', FALSE, '/gravityformszapier/languages');
            add_action('after_plugin_row_' . self::$path, array('GFZapier', 'plugin_row'));

            //force new remote request for version info on the plugin page
            self::flush_version_info();
        }

        if(!self::is_gravityforms_supported()){
           return;
		}

		//loading data lib
        require_once(self::get_base_path() . "/data.php");

		if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformszapier', FALSE, '/gravityformszapier/languages' );

            add_filter("transient_update_plugins", array('GFZapier', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFZapier', 'check_update'));

            add_action('install_plugins_pre_plugin-information', array('GFZapier', 'display_changelog'));

            //add item to form settings menu in expand list
			add_action('gform_form_settings_menu', array("GFZapier", 'add_form_settings_menu'));

        	//add action so that when form is updated, data fields are sent to Zapier
        	add_action("gform_after_save_form", array("GFZapier", 'send_form_updates'), 10, 2);

        	if (RGForms::get("page") == "gf_settings") {
				//add Zapier link to settings tabs on GF Main Settings page
	            if(self::has_access("gravityforms_zapier")){
	                RGForms::add_settings_page("Zapier", array("GFZapier", "settings_page"), self::get_base_url() . "/images/zapier_wordpress_icon_32.png");
	            }
        	}

        	if (RGForms::get("subview") == "gravityformszapier"){
        		//add page Zapier link will go to
        		add_action("gform_form_settings_page_gravityformszapier", array("GFZapier", 'zapier_page'));

                //loading upgrade lib
                if(!class_exists("GFZapierUpgrade")){
                    require_once("plugin-upgrade.php");
				}

				//loading Gravity Forms tooltips
            	require_once(GFCommon::get_base_path() . "/tooltips.php");
				add_filter('gform_tooltips', array('GFZapier', 'tooltips'));

        	}

        	//runs the setup when version changes
            self::setup();

        }
        else{
            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFZapier', 'premium_update_push'));
            add_filter( 'mwp_premium_perform_update', array('GFZapier', 'premium_update'));

            add_action("gform_after_submission", array("GFZapier", "send_form_data_to_zapier"), 10, 2);
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities')) {
            add_filter('members_get_capabilities', array("GFZapier", "members_get_capabilities"));
		}
    }

    public static function add_form_settings_menu($tabs) {
        $tabs[] = array("name" => self::$slug, "label" => __("Zapier", "gravityforms"), "query" => array("zid"=>null));
        return $tabs;
    }

    public static function zapier_page(){
    	//see if there is a form id in the querystring
    	$form_id = RGForms::get("id");

        $zapier_id = rgempty("gform_zap_id") ? rgget("zid") : rgpost("gform_zap_id");

        if(!rgblank($zapier_id)) {
            self::zapier_edit_page($form_id, $zapier_id);
		}
        else {
            self::zapier_list_page($form_id);
		}

		GFFormSettings::page_footer();

	}

	private static function zapier_list_page($form_id){
    	if (rgpost("action") == "delete" && check_admin_referer('gform_zapier_list_action', 'gform_zapier_list_action')){
    		$zid = $_POST["action_argument"];
			if (!empty($zid)){
				GFZapierData::delete_feed($zid);
               	GFCommon::add_message( __('Zap deleted.', 'gravityformszapier') );
			}
    	}

	    GFFormSettings::page_header(__('Zapier', 'gravityformszapier'));

		?>
		<script type="text/javascript">
			function DeleteZap(zid){
				//set hidden fields
    			jQuery('#action').val('delete');
    			jQuery('#action_argument').val(zid);
    			jQuery('#zapier_list_form')[0].submit();
			}
		</script>
		<style type="text/css">
            a.limit-text { display: block; height: 18px; line-height: 18px; overflow: hidden; padding-right: 5px;
                color: #555; text-overflow: ellipsis; white-space: nowrap; }
                a.limit-text:hover { color: #555; }

            th.column-name { width: 30%; }
            th.column-type { width: 20%; }
        </style>

		<?php
        $add_new_url = add_query_arg(array("zid" => 0));
        ?>
        <h3><span>
            <?php _e("Zapier Feeds", "gravityforms") ?>
            <a id="add-new-zapier" class="add-new-h2" href="<?php echo $add_new_url?>"><?php _e("Add New", "gravityformszapier") ?></a>
        </span></h3>

        <?php
        $zapier_table = new GFZapierTable($form_id);
        $zapier_table->prepare_items();
        ?>

        <form id="zapier_list_form" method="post">

            <?php $zapier_table->display(); ?>

			<input type="hidden" id="action" name="action" value="">
            <input id="action_argument" name="action_argument" type="hidden" />

            <?php wp_nonce_field('gform_zapier_list_action', 'gform_zapier_list_action') ?>

        </form>
        <?php

	}

	private static function zapier_edit_page($form_id, $zap_id){

		$zap = empty($zap_id) ? array() : GFZapierData::get_feed($zap_id);

		$is_new_zap = empty($zap_id) || empty($zap);

		$is_valid = true;
        $is_update = false;

   		$form = RGFormsModel::get_form_meta($form_id);

        if(rgpost("save")){

            check_admin_referer('gforms_save_zap', 'gforms_save_zap');

            if (rgar($zap, "url") != rgpost("gform_zapier_url")){
            	$is_update = true;
			}

            $zap["name"] = rgpost("gform_zapier_name");
            $zap["url"] = rgpost("gform_zapier_url");
            $zap["is_active"] = rgpost("gform_zapier_active");
            //conditional
            $zap["meta"]["zapier_conditional_enabled"] = rgpost("gf_zapier_conditional_enabled");
            $zap["meta"]["zapier_conditional_field_id"] = rgpost("gf_zapier_conditional_field_id");
            $zap["meta"]["zapier_conditional_operator"] = rgpost("gf_zapier_conditional_operator");
            $zap["meta"]["zapier_conditional_value"] = rgpost("gf_zapier_conditional_value");

            if (empty($zap["url"]) || empty($zap["name"])){
				$is_valid = false;
            }

            if ($is_valid){
	            $zap = apply_filters( 'gform_zap_before_save', apply_filters( "gform_zap_before_save_{$form['id']}", $zap, $form ), $form );

	            $zap_id = GFZapierData::update_feed($zap_id, $form_id, $zap["is_active"], $zap["name"], $zap["url"], $zap["meta"]);

	            GFCommon::add_message( sprintf( __('Zap saved successfully. %sBack to list.%s', 'gravityformszapier'), '<a href="' . remove_query_arg('zid') . '">', '</a>') );

	            if ($is_new_zap || $is_update) {
            		//send field info to zap when new or url has changed
            		$sent = self::send_form_data_to_zapier("", $form);
	            }
			}
			else{
				GFCommon::add_error_message(__('Zap could not be updated. Please enter all required information below.', 'gravityformszapier'));
			}

		}

    	GFFormSettings::page_header(__('Zapier', 'gravityformszapier'));

		?>
		<style type="text/css">
            a.limit-text { display: block; height: 18px; line-height: 18px; overflow: hidden; padding-right: 5px;
                color: #555; text-overflow: ellipsis; white-space: nowrap; }
                a.limit-text:hover { color: #555; }

            th.column-name { width: 30%; }
            th.column-type { width: 20%; }
        </style>
        <div style="<?php echo $is_new_zap ? "display:block" : "display:none" ?>">
        	<?php _e(sprintf("To create a new zap, you must have the Webhook URL. The Webhook URL may be found when you go to your %sZapier dashboard%s and create a new zap, or when you edit an existing zap. Once you have saved your new feed the form fields will be available for mapping on the Zapier site.","<a href='https://zapier.com/app/dashboard' target='_blank'>","</a>")); ?>
        </div>
        <form method="post" id="gform_zapier_form">
         	<?php wp_nonce_field('gforms_save_zap', 'gforms_save_zap') ?>
         	<input type="hidden" id="gform_zap_id" name="gform_zap_id" value="<?php echo $zap_id ?>" />
        	<table class="form-table">
				<tr valign="top">
		            <th scope="row">
		                <label for="gform_zapier_name">
		                    <?php _e("Zap Name", "gravityformszapier"); ?><span class="gfield_required">*</span>
		                    <?php gform_tooltip("zapier_name") ?>
		                </label>
		            </th>
		            <td>
		                <input type="text" class="fieldwidth-2" name="gform_zapier_name" id="gform_zapier_name" value="<?php echo esc_attr(rgar($zap, "name")) ?>"/>
		            </td>
		        </tr>
    			<tr valign="top">
		            <th scope="row">
		                <label for="gform_zapier_url">
		                    <?php _e("Webhook URL", "gravityformszapier"); ?><span class="gfield_required">*</span>
		                    <?php gform_tooltip("zapier_url") ?>
		                </label>
		            </th>
		            <td>
		                <input type="text" class="fieldwidth-2" name="gform_zapier_url" id="gform_zapier_url" value="<?php echo esc_attr(rgar($zap, "url")) ?>"/>
		            </td>
		        </tr>
		        <tr valign="top">
		            <th scope="row">
		                <label for="gform_zapier_active">
		                    <?php _e("Active", "gravityformszapier"); ?>
		                    <?php gform_tooltip("zapier_active") ?>
		                </label>
		            </th>
		            <td>
		                <input type="radio" id="form_active_yes" name="gform_zapier_active" <?php echo $is_new_zap || rgar($zap, 'is_active') == "1" ? "checked='checked'" : ""; ?> value="1" />
		                <label for="form_active_yes" class="inline"><?php _e("Yes", "gravityformszapier") ?></label>
		                <input type="radio" id="form_active_no" name="gform_zapier_active" <?php echo rgar($zap, 'is_active') == "0" ? "checked='checked'" : ""; ?> value="0"/>
		                <label for="form_active_no" class="inline"><?php _e("No", "gravityformszapier") ?></label>
		            </td>
		        </tr>
		        <tr valign="top">
		            <th scope="row">
		                <label for="gform_zapier_conditional_logic">
		                    <?php _e("Conditional Logic", "gravityforms") ?>
		                    <?php gform_tooltip("zapier_conditional") ?>
		                </label>
		            </th>
		            <td>
		                <input type="checkbox" id="gf_zapier_conditional_enabled" name="gf_zapier_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_zapier_conditional_container').fadeIn('fast');} else{ jQuery('#gf_zapier_conditional_container').fadeOut('fast'); }" <?php echo rgars($zap, 'meta/zapier_conditional_enabled') ? "checked='checked'" : ""?>/>
                        <label for="gf_zapier_conditional_enable"><?php _e("Enable", "gravityformszapier"); ?></label>
		                <br/>
                        <div style="height:20px;">
		                    <div id="gf_zapier_conditional_container" <?php echo !rgars($zap, 'meta/zapier_conditional_enabled') ? "style='display:none'" : ""?>>
                                <div id="gf_zapier_conditional_fields" style="display:none;">
                                    <?php _e("Send to Zapier if ", "gravityformszapier") ?>

                                    <select id="gf_zapier_conditional_field_id" name="gf_zapier_conditional_field_id" class="optin_select" onchange='jQuery("#gf_zapier_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                    <select id="gf_zapier_conditional_operator" name="gf_zapier_conditional_operator">
                                        <option value="is" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformszapier") ?></option>
                                        <option value="isnot" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformszapier") ?></option>
                                        <option value=">" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformszapier") ?></option>
                                        <option value="<" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformszapier") ?></option>
                                        <option value="contains" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformszapier") ?></option>
                                        <option value="starts_with" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformszapier") ?></option>
                                        <option value="ends_with" <?php echo rgar($zap['meta'], 'zapier_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformszapier") ?></option>
                                    </select>
                                    <div id="gf_zapier_conditional_value_container" name="gf_zapier_conditional_value_container" style="display:inline;"></div>
                                </div>
                                <div id="gf_zapier_conditional_message" style="display:none">
                                    <?php _e("To create a condition, your form must have a field supported by conditional logic.", "gravityformzapier") ?>
                                </div>
                            </div>
                        </div>
		            </td>
		        </tr> <!-- / conditional logic -->
		    </table>

		    <p class="submit">
                <?php
                    $button_label = $is_new_zap ? __("Save Zapier Feed", "gravityformszapier") : __("Update Zapier Feed", "gravityformszapier");
                    $zapier_button = '<input class="button-primary" type="submit" value="' . $button_label . '" name="save"/>';
                    echo apply_filters("gform_save_zapier_button", $zapier_button);
                ?>
            </p>
        </form>
        <script type="text/javascript">
        	// Conditional Functions

            // initilize form object
            form = <?php echo GFCommon::json_encode($form)?> ;

            // initializing registration condition drop downs
            jQuery(document).ready(function(){
                var selectedField = "<?php echo str_replace('"', '\"', $zap["meta"]["zapier_conditional_field_id"])?>";
                var selectedValue = "<?php echo str_replace('"', '\"', $zap["meta"]["zapier_conditional_value"])?>";
                SetCondition(selectedField, selectedValue);
            });

            function SetCondition(selectedField, selectedValue){

                // load form fields
                jQuery("#gf_zapier_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_zapier_conditional_field_id").val();
                var checked = jQuery("#gf_zapier_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_zapier_conditional_message").hide();
                    jQuery("#gf_zapier_conditional_fields").show();
                    jQuery("#gf_zapier_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_zapier_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_zapier_conditional_message").show();
                    jQuery("#gf_zapier_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_zapier_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
                    str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_zapier_conditional_value", "name"=> "gf_zapier_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
                }
                else if(field.choices){
                    str += '<select id="gf_zapier_conditional_value" name="gf_zapier_conditional_value" class="optin_select">'

                    for(var i=0; i<field.choices.length; i++){
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if(isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if(!isAnySelected && selectedValue){
                        str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }
                    str += "</select>";
                }
                else
                {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
                    //create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
                    str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_zapier_conditional_value' name='gf_zapier_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(!text)
                    return "";

                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
                inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                        "post_tags", "post_custom_field", "post_content", "post_excerpt"];

                var index = jQuery.inArray(inputType, supported_fields);

                return index >= 0;
            }
        </script>

        <?php
	}

    public static function send_form_updates($form, $is_new){
		self::send_form_data_to_zapier("", $form);
	}

	public static function send_form_data_to_zapier($entry = null, $form){
		//if there is an entry, then this is a form submission, get data out of entry to POST to Zapier
		//otherwise this is a dummy setup to give Zapier the field data, get the form fields and POST to Zapier with empty data
		if (empty($form) && empty($entry)){
			self::log_debug("No form or entry was provided to send data to Zapier.");
			return false;
		}
		
		//do not send spam entries to zapier
		if (!empty($entry) && $entry["status"] == "spam"){
			self::log_debug("The entry is marked as spam, NOT sending to Zapier.");
			return false;
		}

        $body = self::get_body($entry, $form);

        $headers = array();
        if(GFCommon::is_empty_array($body)){
            $headers["X-Hook-Test"] = 'true';
        }

        $json_body = json_encode($body);
        if (empty($body)){
			self::log_debug("There is no field data to send to Zapier.");
			return false;
		}

        //get zaps for form
        $form_id = $form["id"];
        $zaps =  GFZapierData::get_feed_by_form($form_id, true);
        if (empty($zaps)){
            self::log_debug("There are no zaps configured for form id {$form_id}");
            return false;
        }

        $is_entry = !empty($entry);
        $is_entry ? self::log_debug("Gathering entry data to send submission.") : self::log_debug("Gathering field data to send dummy submission.");

        $retval = true;
		foreach ($zaps as $zap) {
			//checking to see if a condition was specified, and if so, met, otherwise don't send to zapier
			//only check this when there is an entry, simple form updates should go to zapier regardless of conditions existing
			if (!$is_entry || ($is_entry && self::conditions_met($form, $zap))){
				if ($is_entry){
					self::log_debug("No condition specified or a condition was specified and met, sending to Zapier");
				}

                $form_data = array("sslverify" => false, "ssl" => true, "body" => $json_body, "headers" => $headers);
				self::log_debug("Posting to url: " . $zap["url"] . " data: " . print_r($body,true));
		        $response = wp_remote_post($zap["url"], $form_data);
				if (is_wp_error($response)) {
					self::log_error("The following error occurred: " . print_r($response, true));
					$retval = false;
				}
				else {
					self::log_debug("Successful response from Zap: " . print_r($response, true));
				}
			}
			else{
				self::log_debug("A condition was specified and not met, not sending to Zapier");
				$retval = false;
			}
		}
		return $retval;
	}

    public static function get_body($entry, $form)
    {
		$body = array();
        foreach ($form["fields"] as $field) {
        	if ($field["type"] == "honeypot"){
        		//skip the honeypot field
				continue;
        	}

            $field_value = GFFormsModel::get_lead_field_value($entry, $field);
            if(!empty($entry))
                $field_value = apply_filters("gform_zapier_field_value", $field_value, $form["id"], $field["id"], $entry);

            $field_label = GFFormsModel::get_label($field);

            if (is_array($field["inputs"])) {
                //handling multi-input fields

                $non_blank_items = array();

                //field has inputs, complex field like name, address and checkboxes. Get individual inputs
                foreach ($field["inputs"] as $input) {
                    $input_label = GFFormsModel::get_label($field, $input["id"]);

                    $field_id = (string)$input["id"];
                    $input_value = $field_value == null ? "" : $field_value[$field_id];
                    $body[$input_label] = $input_value;

                    if(!rgblank($input_value)) {
                    	$non_blank_items[] = $input_value;
					}
                }


                //Also adding an item for the "whole" field, which will be a concatenation of the individual inputs
                switch(GFFormsModel::get_input_type($field)){
                    case "checkbox" :
                        //checkboxes will create a comma separated list of values
                        $body[$field_label] = implode(", ", $non_blank_items);
                        break;

                    case "name" :
                    case "address" :
                        //name and address will separate inputs by a single blank space
                        $body[$field_label] = implode(" ", $non_blank_items);
                        break;
                }
            }
            else{
            	$body[$field_label] = rgblank($field_value) ? "" : $field_value;
            }
        }
        $entry_meta = GFFormsModel::get_entry_meta(($form["id"]));
        foreach($entry_meta as $meta_key => $meta_config){
            $body[$meta_config["label"]] = empty($entry) ? null : rgar($entry, $meta_key);
        }

        return $body;
    }

	private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    public static function settings_page(){
		if(!class_exists("GFZapierUpgrade")){
            require_once("plugin-upgrade.php");
		}

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_zapier_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Zapier Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformszapier")?></div>
            <?php
            return;
        }
        ?>
        <style>
            .valid_credentials{color:green;}
            .invalid_credentials{color:red;}
        </style>
		<p style="text-align: left;">
        	<?php _e(sprintf("Zapier is a service to which you may submit your form data so that information may be passed along to another online service. If you do not have a Zapier account, you may %ssign up for one here%s.", "<a href='https://zapier.com/app/signup' target='_blank'>" , "</a>"), "gravityformszapier") ?>
        </p>
        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_zapier_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_zapier_uninstall")){ ?>
                <h3><?php _e("Uninstall Zapier Add-On", "gravityformszapier") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Zapier Feeds.", "gravityformszapier") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Zapier Add-On", "gravityformszapier") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Zapier Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformszapier") . '\');"/>';
                    echo apply_filters("gform_zapier_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
	}

    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

      
        //loading upgrade lib
        if(!class_exists("GFZapierUpgrade")){
            require_once("plugin-upgrade.php");
		}
        $update = GFZapierUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);
        
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        //loading upgrade lib
        if(!class_exists("GFZapierUpgrade")){
            require_once("plugin-upgrade.php");
		}
        $update = GFZapierUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }

    public static function flush_version_info(){
        if(!class_exists("GFZapierUpgrade"))
            require_once("plugin-upgrade.php");

        GFZapierUpgrade::set_version_info(false);
    }

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s"), "<a href='http://www.gravityforms.com'>", "</a>");
            GFZapierUpgrade::display_plugin_message($message, true);
        }
        else{
            $version_info = GFZapierUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);

            if(!$version_info["is_valid_key"]){
                $new_version = version_compare(self::$version, $version_info["version"], '<') ? __('There is a new version of Gravity Forms Zapier Add-On available.', 'gravityformszapier') .' <a class="thickbox" title="Gravity Forms Zapier Add-On" href="plugin-install.php?tab=plugin-information&plugin=' . self::$slug . '&TB_iframe=true&width=640&height=808">'. sprintf(__('View version %s Details', 'gravityformszapier'), $version_info["version"]) . '</a>. ' : '';
                $message = $new_version . sprintf(__('%sRegister%s your copy of Gravity Forms to receive access to automatic upgrades and support. Need a license key? %sPurchase one now%s.', 'gravityformszapier'), '<a href="admin.php?page=gf_settings">', '</a>', '<a href="http://www.gravityforms.com">', '</a>') . '</div></td>';
                GFZapierUpgrade::display_plugin_message($message);
            }
        }
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_zapier");
        $wp_roles->add_cap("administrator", "gravityforms_zapier_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_zapier", "gravityforms_zapier_uninstall"));
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFZapier::has_access("gravityforms_zapier_uninstall"))
            die(__("You don't have adequate permission to uninstall the Zapier Add-On.", "gravityformszapier"));

        //droping all tables
        GFZapierData::drop_tables();

        //removing options
        delete_option("gf_zapier_settings");
        delete_option("gf_zapier_version");

        //Deactivating plugin
        $plugin = "gravityformszapier/zapier.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_zapier_version") != self::$version) {
            GFZapierData::update_table();
		}

        update_option("gf_zapier_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $zapier_tooltips = array(
            "zapier_name" => "<h6>" . __("Zap Name", "gravityformszapier") . "</h6>" . __("This is a friendly name so you know what Zap is run when this form is submitted.", "gravityformszapier"),
            "zapier_url" => "<h6>" . __("Webhook URL", "gravityformszapier") . "</h6>" . __("This is the URL provided by Zapier when you created your Zap on their website. This is the location to which your form data will be submitted to Zapier for additional processing.", "gravityformszapier"),
            "zapier_active" => "<h6>" . __("Active", "gravityformsactive") . "</h6>" . __("Check this box if you want your form submissions to be sent to Zapier for processing.", "gravityformszapier"),
            "zapier_conditional" => "<h6>" . __("Conditional Logic", "gravityformszapier") . "</h6>" . __("When Conditional Logic is enabled, submissions for this form will only be sent to Zapier when the condition is met. When disabled, all submissions for this form will be sent to Zapier.", "gravityformszapier")
        );
        return array_merge($tooltips, $zapier_tooltips);
    }

    //Returns the url of the plugin's root folder
    protected static function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected static function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    public static function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "Zapier";
		return $plugins;
	}

	//Displays current version details on Plugin's page
    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        //loading upgrade lib
        if(!class_exists("GFZapierUpgrade"))
            require_once("plugin-upgrade.php");

        GFZapierUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
        if(!class_exists("GFZapierUpgrade"))
            require_once("plugin-upgrade.php");

        return GFZapierUpgrade::check_update(self::$path, self::$slug, self::$url, self::$slug, self::get_key(), self::$version, $update_plugins_option);
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }

    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_zapier_page(){
        $current_page = trim(strtolower(rgget("page")));
        $zapier_pages = array("gf_zapier");

        return in_array($current_page, $zapier_pages);
    }

    public static function conditions_met($form, $zap) {

        $zap = $zap["meta"];

        $operator = isset($zap["zapier_conditional_operator"]) ? $zap["zapier_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $zap["zapier_conditional_field_id"]);

        if(empty($field) || !$zap["zapier_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $zap["zapier_conditional_value"], $operator);
        $go_to_zapier = $is_value_match && $is_visible;

        return $go_to_zapier;
    }

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}
}

require_once(ABSPATH . '/wp-admin/includes/class-wp-list-table.php');

class GFZapierTable extends WP_List_Table {
    private $_form_id;

    function __construct($form_id) {
        $this->_form_id = $form_id;

        $this->items = array();

        $this->_column_headers = array(
            array(
                'name' => __('Zap Name', 'gravityformszapier'),
                'url' => __('Webhook URL', 'gravityformszapier')
                ),
                array(),
                array()
            );

        parent::__construct();
    }

    function prepare_items() {
    	//query db
 		$zaps = GFZapierData::get_feed_by_form($this->_form_id);

        $this->items = $zaps;
    }

    function display() {
        extract( $this->_args );
        ?>

        <table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>" cellspacing="0">
            <thead>
            <tr>
                <?php $this->print_column_headers(); ?>
            </tr>
            </thead>

            <tfoot>
            <tr>
                <?php $this->print_column_headers( false ); ?>
            </tr>
            </tfoot>

            <tbody id="the-list"<?php if ( $singular ) echo " class='list:$singular'"; ?>>

                <?php $this->display_rows_or_placeholder(); ?>

            </tbody>
        </table>

        <?php
    }

    function no_items(){
        $add_new_url = add_query_arg(array("zid" => 0));
        printf(__("You currently don't have any Zapier Feeds, let's go %screate one%s", "gravityformszapier"), "<a href='{$add_new_url}'>", "</a>");
    }

    function single_row( $item ) {
        static $row_class = '';
        $row_class = ( $row_class == '' ? ' class="alternate"' : '' );

        echo '<tr id="zapier-' . $item['id'] . '" ' . $row_class . '>';
        echo $this->single_row_columns( $item );
        echo '</tr>';
    }

    function column_default($item, $column) {
        echo rgar($item, $column);
    }

    function column_name($item) {
        $edit_url = add_query_arg(array("zid" => $item["id"]));
        $actions = apply_filters('gform_zapier_actions', array(
            'edit' => '<a title="' . __('Edit this item', 'gravityformszapier') . '" href="' . $edit_url . '">' . __('Edit', 'gravityformszapier') . '</a>',
            'delete' => '<a title="' . __('Delete this item', 'gravityformszapier') . '" class="submitdelete" onclick="javascript: if(confirm(\'' . __("WARNING: You are about to delete this Zapier feed.", "gravityformszapier") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityforms") . '\')){ DeleteZap(\'' . $item["id"] . '\'); }" style="cursor:pointer;">' . __('Delete', 'gravityformszapier') . '</a>'
            ));
        ?>

        <strong><?php echo rgar($item, 'name'); ?></strong>
        <div class="row-actions">

            <?php
            if(is_array($actions) && !empty($actions)) {
            	$keys = array_keys($actions);
                $last_key = array_pop($keys);
                foreach($actions as $key => $html) {
                    $divider = $key == $last_key ? '' : " | ";
                    ?>
                    <span class="<?php echo $key; ?>">
                        <?php echo $html . $divider; ?>
                    </span>
                <?php
                }
            }
            ?>

        </div>

        <?php
    }

}