<?php

/*
Plugin Name: Gravity Forms Zapier Add-on
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with Zapier allowing form submissions to be automatically sent to your configured Zaps.
Version: 1.8
Author: rocketgenius
Author URI: http://www.rocketgenius.com
Text Domain: gravityformszapier
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2009-2015 rocketgenius

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
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
    private static $version = "1.8";
    private static $min_gravityforms_version = "1.7.6";

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

			// paypal standard plugin integration hooks
			add_action( 'gform_paypal_action_fields', array( 'GFZapier', 'add_paypal_settings' ), 10, 2 );
			add_filter( 'gform_paypal_save_config', array( 'GFZapier', 'save_paypal_settings' ) );

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

			//handling paypal fulfillment
			add_action("gform_paypal_fulfillment", array("GFZapier", "paypal_fulfillment"), 10, 4);
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
            <a id="add-new-zapier" class="add-new-h2" href="<?php echo esc_url( $add_new_url); ?>"><?php _e("Add New", "gravityformszapier") ?></a>
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

	            GFCommon::add_message( sprintf( __('Zap saved successfully. %sBack to list.%s', 'gravityformszapier'), '<a href="' . esc_url( remove_query_arg('zid') ) . '">', '</a>') );

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

	public static function send_form_updates( $form, $is_new ) {
		self::send_form_data_to_zapier( '', $form );
	}

	public static function send_form_data_to_zapier( $entry = null, $form ) {
		//if there is an entry, then this is a form submission, get data out of entry to POST to Zapier
		//otherwise this is a dummy setup to give Zapier the field data, get the form fields and POST to Zapier with empty data
		if ( empty( $form ) && empty( $entry ) ) {
			self::log_debug( 'No form or entry was provided to send data to Zapier.' );

			return false;
		}

		//get zaps for form
		$form_id = $form['id'];
		$zaps    = GFZapierData::get_feed_by_form( $form_id, true );
		if ( empty( $zaps ) ) {
			self::log_debug( "There are no zaps configured for form id {$form_id}" );

			return false;
		}

		//see if there is a paypal feed and zapier is set to be delayed until payment is received
		if ( class_exists( 'GFPayPal' ) ) {
			$paypal_feeds = self::get_paypal_feeds( $form['id'] );
			//loop through paypal feeds to get active one for this form submission, needed to see if add-on processing should be delayed
			foreach ( $paypal_feeds as $paypal_feed ) {
				if ( $paypal_feed['is_active'] && self::is_feed_condition_met( $paypal_feed, $form, $entry ) ) {
					$active_paypal_feed = $paypal_feed;
					break;
				}
			}

			$is_fulfilled = rgar( $entry, 'is_fulfilled' );


			if ( ! empty( $active_paypal_feed ) && self::is_delayed( $active_paypal_feed ) && self::has_paypal_payment( $active_paypal_feed, $form, $entry ) && ! $is_fulfilled ) {
				self::log_debug( 'Zapier Feed processing is delayed pending payment, not processing feed for entry #' . $entry['id'] );

				return false;
			}
		}

		//do not send spam entries to zapier
		if ( ! empty( $entry ) && $entry['status'] == 'spam' ) {
			self::log_debug( 'The entry is marked as spam, NOT sending to Zapier.' );

			return false;
		}

		$body = self::get_body( $entry, $form );

		$headers = array();
		if ( GFCommon::is_empty_array( $body ) ) {
			$headers['X-Hook-Test'] = 'true';
		}

		$json_body = json_encode( $body );
		if ( empty( $body ) ) {
			self::log_debug( 'There is no field data to send to Zapier.' );

			return false;
		}

		$is_entry = ! empty( $entry );
		$is_entry ? self::log_debug( 'Gathering entry data to send submission.' ) : self::log_debug( 'Gathering field data to send dummy submission.' );

		$retval = true;
		foreach ( $zaps as $zap ) {
			//checking to see if a condition was specified, and if so, met, otherwise don't send to zapier
			//only check this when there is an entry, simple form updates should go to zapier regardless of conditions existing
			if ( ! $is_entry || ( $is_entry && self::conditions_met( $form, $zap, $entry ) ) ) {
				if ( $is_entry ) {
					self::log_debug( 'No condition specified or a condition was specified and met, sending to Zapier' );
				}

				$form_data = array( 'sslverify' => false, 'ssl' => true, 'body' => $json_body, 'headers' => $headers );
				self::log_debug( 'Posting to url: ' . $zap['url'] . ' data: ' . print_r( $body, true ) );
				$response = wp_remote_post( $zap['url'], $form_data );
				if ( is_wp_error( $response ) ) {
					self::log_error( 'The following error occurred: ' . print_r( $response, true ) );
					$retval = false;
				} else {
					self::log_debug( 'Successful response from Zap: ' . print_r( $response, true ) );
				}
			} else {
				self::log_debug( 'A condition was specified and not met, not sending to Zapier' );
				$retval = false;
			}
		}

		return $retval;
	}

	public static function get_body( $entry, $form ) {
		$body = array();
		foreach ( $form['fields'] as $field ) {
			$input_type = GFFormsModel::get_input_type( $field );
			if ( $input_type == 'honeypot' ) {
				//skip the honeypot field
				continue;
			}

			$field_value = GFFormsModel::get_lead_field_value( $entry, $field );
			if ( ! empty( $entry ) ) {
				$field_value = apply_filters( 'gform_zapier_field_value', $field_value, $form['id'], $field['id'], $entry );
			}

			$field_label = GFFormsModel::get_label( $field );

			$inputs = $field instanceof GF_Field ? $field->get_entry_inputs() : rgar( $field, 'inputs' );

			if ( is_array( $inputs ) && ( is_array( $field_value ) || empty( $entry ) ) ) {
				//handling multi-input fields

				$non_blank_items = array();

				//field has inputs, complex field like name, address and checkboxes. Get individual inputs
				foreach ( $inputs as $input ) {
					$input_label = GFFormsModel::get_label( $field, $input['id'] );

					$field_id             = (string) $input['id'];
					$input_value          = rgar( $field_value, $field_id );
					$body[ $input_label ] = $input_value;

					if ( ! rgblank( $input_value ) ) {
						$non_blank_items[] = $input_value;
					}
				}


				//Also adding an item for the "whole" field, which will be a concatenation of the individual inputs
				switch ( $input_type ) {
					case 'checkbox' :
						//checkboxes will create a comma separated list of values
						$body[ $field_label ] = implode( ', ', $non_blank_items );
						break;

					case 'name' :
					case 'address' :
						//name and address will separate inputs by a single blank space
						$body[ $field_label ] = implode( ' ', $non_blank_items );
						break;
				}
			} else {
				$body[ $field_label ] = rgblank( $field_value ) ? '' : $field_value;
			}
		}
		$entry_meta = GFFormsModel::get_entry_meta( ( $form['id'] ) );
		foreach ( $entry_meta as $meta_key => $meta_config ) {
			$body[ $meta_config['label'] ] = empty( $entry ) ? null : rgar( $entry, $meta_key );
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
        <br/></br>
        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_zapier_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_zapier_uninstall")){ ?>
                <h3><?php _e("Uninstall Zapier Add-On", "gravityformszapier") ?></h3>
                <div class="delete-alert alert_red">
                    <h3><i class="fa fa-exclamation-triangle gf_invalid"></i> Warning</h3>
                    
                    <div class="gf_delete_notice" "=""><strong><?php _e("This operation deletes ALL Zapier feeds.", "gravityformszapier") ?></strong><?php _e("If you continue, you will not be able to recover any Zapier data.", "gravityformszapier") ?>
                    </div>    

                    <input type="submit" name="uninstall" value="Uninstall Zapier Add-on" class="button" onclick="return confirm('<?php _e("Warning! ALL settings will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformszapier") ?>');">
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

	public static function conditions_met( $form, $zap, $entry ) {
		self::log_debug( __METHOD__ . '(): Evaluating conditional logic.' );

		$zap = $zap['meta'];

		if ( ! $zap['zapier_conditional_enabled'] ) {
			self::log_debug( __METHOD__ . '(): Conditional logic not enabled for this feed.' );

			return true;
		}

		$logic = array(
			'logicType' => 'all',
			'rules'     => array(
				array(
					'fieldId'  => rgar( $zap, 'zapier_conditional_field_id' ),
					'operator' => rgar( $zap, 'zapier_conditional_operator' ),
					'value'    => rgar( $zap, 'zapier_conditional_value' ),
				),
			)
		);

        $logic          = apply_filters( 'gform_zapier_feed_conditional_logic', $logic, $form, $zap );
		$is_value_match = GFCommon::evaluate_conditional_logic( $logic, $form, $entry );
        self::log_debug( __METHOD__ . '(): Result: ' . var_export( $is_value_match, 1 ) );

		return $is_value_match;
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

	//These are functions copied from the add-on framework and modified as needed to support the PayPal delay
	public static function add_paypal_settings( $feed, $form ) {
		//this function was copied from the feed framework since this add-on has not yet been migrated
		$form_id        = rgar( $form, 'id' );
		$feed_meta      = $feed['meta'];

		$addon_name  = 'gravityformszapier';
		$addon_feeds = array();
		$feeds = GFZapierData::get_feeds( $form_id );
		if ( count( $feeds ) > 0 ){
			$settings_style = '';
		}
		else{
			$settings_style = 'display:none;';
		}

		foreach ( $feeds as $feed ) {
			$addon_feeds[] = $feed['form_id'];
		}

		?>

		<li style="<?php echo $settings_style ?>" id="delay_<?php echo $addon_name; ?>_container">
			<input type="checkbox" name="paypal_delay_<?php echo $addon_name; ?>" id="paypal_delay_<?php echo $addon_name; ?>" value="1" <?php echo rgar( $feed_meta, "delay_$addon_name" ) ? "checked='checked'" : '' ?> />
			<label class="inline" for="paypal_delay_<?php echo $addon_name; ?>">
				<?php
				_e( 'Send feed to Zapier only when payment is received.', 'gravityformszapier' );
				?>
			</label>
		</li>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {

				jQuery(document).bind('paypalFormSelected', function (event, form) {

					var addonFormIds = <?php echo json_encode( $addon_feeds ); ?>;
					var isApplicableFeed = false;

					if (jQuery.inArray(String(form.id), addonFormIds) != -1)
						isApplicableFeed = true;

					if (isApplicableFeed) {
						jQuery("#delay_<?php echo $addon_name; ?>_container").show();
					} else {
						jQuery("#delay_<?php echo $addon_name; ?>_container").hide();
					}

				});
			});
		</script>

	<?php
	}

	public static function save_paypal_settings( $feed ) {
		$feed['meta'][ 'delay_gravityformszapier' ] = rgpost( 'paypal_delay_gravityformszapier' );

		return $feed;
	}

	public static function get_paypal_feeds( $form_id = null ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gf_addon_feed';
		$has_table  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $has_table ) {
			return array();
		}

		$form_filter = is_numeric( $form_id ) ? $wpdb->prepare( 'AND form_id=%d', absint( $form_id ) ) : '';

		$sql = $wpdb->prepare( "SELECT * FROM {$table_name}
                               WHERE addon_slug=%s {$form_filter}", 'gravityformspaypal' );

		$results = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( $results as &$result ) {
			$result['meta'] = json_decode( $result['meta'], true );
		}

		return $results;
	}

	public static function is_feed_condition_met( $feed, $form, $entry ) {

		$feed_meta            = $feed['meta'];
		$is_condition_enabled = rgar( $feed_meta, 'feed_condition_conditional_logic' ) == true;
		$logic                = rgars( $feed_meta, 'feed_condition_conditional_logic_object/conditionalLogic' );

		if ( ! $is_condition_enabled || empty( $logic ) ) {
			return true;
		}

		return GFCommon::evaluate_conditional_logic( $logic, $form, $entry );
	}

	public static function is_delayed( $paypal_feed ){
		//look for delay in paypal feed specific to zapier add-on
		$delay = rgar( $paypal_feed['meta'], 'delay_gravityformszapier' );
		return $delay;
	}

	public static function has_paypal_payment( $feed, $form, $entry ){

		$products = GFCommon::get_product_fields( $form, $entry );

		$payment_field   = $feed['meta']['transactionType'] == 'product' ? $feed['meta']['paymentAmount'] : $feed['meta']['recurringAmount'];
		$setup_fee_field = rgar( $feed['meta'], 'setupFee_enabled' ) ? $feed['meta']['setupFee_product'] : false;
		$trial_field     = rgar( $feed['meta'], 'trial_enabled' ) ? rgars( $feed, 'meta/trial_product' ) : false;

		$amount       = 0;
		$line_items   = array();
		$discounts    = array();
		$fee_amount   = 0;
		$trial_amount = 0;
		foreach ( $products['products'] as $field_id => $product ) {

			$quantity      = $product['quantity'] ? $product['quantity'] : 1;
			$product_price = GFCommon::to_number( $product['price'] );

			$options = array();
			if ( is_array( rgar( $product, 'options' ) ) ) {
				foreach ( $product['options'] as $option ) {
					$options[] = $option['option_name'];
					$product_price += $option['price'];
				}
			}

			$is_trial_or_setup_fee = false;

			if ( ! empty( $trial_field ) && $trial_field == $field_id ) {

				$trial_amount = $product_price * $quantity;
				$is_trial_or_setup_fee = true;

			} else if ( ! empty( $setup_fee_field ) && $setup_fee_field == $field_id ) {

				$fee_amount = $product_price * $quantity;
				$is_trial_or_setup_fee = true;
			}

			//Do not add to line items if the payment field selected in the feed is not the current field.
			if ( is_numeric( $payment_field ) && $payment_field != $field_id ) {
				continue;
			}

			//Do not add to line items if the payment field is set to "Form Total" and the current field was used for trial or setup fee.
			if ( $is_trial_or_setup_fee && ! is_numeric( $payment_field ) ){
				continue;
			}

			$amount += $product_price * $quantity;

		}


		if ( ! empty( $products['shipping']['name'] ) && ! is_numeric( $payment_field ) ) {
			$line_items[] = array( 'id' => '', 'name' => $products['shipping']['name'], 'description' => '', 'quantity' => 1, 'unit_price' => GFCommon::to_number( $products['shipping']['price'] ), 'is_shipping' => 1 );
			$amount += $products['shipping']['price'];
		}

		return $amount > 0;
	}

	public static function paypal_fulfillment( $entry, $feed, $transaction_id, $amount ) {
		//get zaps for form
		$form_id = $entry['form_id'];
		$zaps    = GFZapierData::get_feed_by_form( $form_id, true );
		if ( ! empty( $zaps ) ) {
			self::log_debug("Running PayPal Fulfillment for transaction {$transaction_id}");
			$is_fulfilled = rgar( $entry, 'is_fulfilled' );
			if ( $is_fulfilled ){
				self::log_debug( 'Payment has been completed, sending to Zapier' );
				$form = RGFormsModel::get_form_meta( $entry['form_id'] );
				self::send_form_data_to_zapier( $entry, $form );
			}
			else{
				self::log_debug('Payment not fulfilled, not running paypal fulfillment.');
			}
		}
	}
	//end of functions to use for PayPal delay

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

	function get_columns() {
		return $this->_column_headers[0];
	}

    function prepare_items() {
    	//query db
 		$zaps = GFZapierData::get_feed_by_form($this->_form_id);

        $this->items = $zaps;
    }

    function display() {
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

            <tbody id="the-list"<?php if ( $this->_args['singular'] ) echo " class='list:{$this->_args['singular']}'"; ?>>

                <?php $this->display_rows_or_placeholder(); ?>

            </tbody>
        </table>

        <?php
    }

    function no_items(){
        $add_new_url = add_query_arg(array("zid" => 0));
	    $add_new_url = esc_url( $add_new_url );
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
            'edit' => '<a title="' . __('Edit this item', 'gravityformszapier') . '" href="' . esc_url( $edit_url ) . '">' . __('Edit', 'gravityformszapier') . '</a>',
            'delete' => '<a title="' . __('Delete this item', 'gravityformszapier') . '" class="submitdelete" onclick="javascript: if(confirm(\'' . __("WARNING: You are about to delete this Zapier feed.", "gravityformszapier") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityforms") . '\')){ DeleteZap(\'' . esc_js( $item["id"] ) . '\'); }" style="cursor:pointer;">' . __('Delete', 'gravityformszapier') . '</a>'
            ));
        ?>

        <strong><?php echo esc_html( rgar($item, 'name') ); ?></strong>
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