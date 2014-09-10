<?php
/*
Plugin Name: Shopp Migrate Images
Version: 1.0.5
Description: This plugin gives a very basic MailChimp/Shopp integration. This plugin is part of the <a href="http://www.shopptoolbox.com">Shopp Toolbox</a>
Plugin URI: http://www.shopptoolbox.com
Author: Shopp Toolbox
Author URI: http://www.shopptoolbox.com

	This plugin is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This plugin is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this plugin.  If not, see <http://www.gnu.org/licenses/>.
*/

if(!defined('ABSPATH')) die();

require('lib/welcome.php');

$ShoppMigrateImages = new ShoppMigrateImages();

class ShoppMigrateImages{
	var $name = 'Shopp Migrate Images';
	var $short_name = 'Migrate Images';
	var $product = 'shopp-migrate-images';

	function __construct(){
		add_action('admin_menu', array(&$this, 'add_menu'), 99);
	}

	function add_menu(){
		global $menu;
		$position = 52;
		while (isset($menu[$position])) $position++;

		if(!$this->toolbox_menu_exist()){
			add_menu_page('Shopp Toolbox', 'Shopp Toolbox', 'shopp_menu', 'shopp-toolbox', array('ShoppToolbox_Welcome', 'display_welcome'), plugin_dir_url(__FILE__) . 'img/toolbox.png', $position);
			add_submenu_page('shopp-toolbox', 'Shopp Toolbox', 'Get Started', 'shopp_menu', 'shopp-toolbox', array('ShoppToolbox_Welcome', 'display_welcome'));
		}

		$page = add_submenu_page('shopp-toolbox', $this->name, $this->short_name, 'shopp_menu', $this->product, array(&$this, 'display_settings'));

		add_meta_box($this->product.'_save', 'Migrate', array(&$this, 'display_save_meta'), $page, 'side', 'default');
		add_meta_box($this->product.'_settings', 'Images', array(&$this, 'display_images_meta'), $page, 'normal', 'core');
	}

	function notices(){
		if(!is_plugin_active('shopp/Shopp.php')){
			echo '<div class="error"><p><strong><?php echo $this->name; ?></strong>: It is highly recommended to have the <a href="http://www.shopplugin.net">Shopp Plugin</a> active before using any of the Shopp Toolbox plugins.</p></div>';
		}
	}

	function on_activation(){
		$this->do_upgrade();
	}

	function do_upgrade(){
	}

	function toolbox_menu_exist(){
		global $menu;

		$return = false;
		foreach($menu as $menus => $item){
			if($item[0] == 'Shopp Toolbox'){
				$return = true;
			}
		}
		return $return;
	}

	function get_path(){
		chdir(WP_CONTENT_DIR);
		$FSStorage = shopp_meta(false, 'shopp', 'FSStorage', 'setting');
			if(!empty($FSStorage)){
				foreach($FSStorage as $id => $obj){
					return trailingslashit(realpath($obj->value['path']['image']));
				}
			}

			return false;
	}

	function is_image_fsstorage(){
		$data = shopp_meta(false, 'shopp', 'image_storage', 'setting');
		if(!empty($data)){
			foreach($data as $obj){
				if($obj->value == 'FSStorage'){
					return true;
				}
			}
		}

		return false;
	}

	function get_image_count(){
		global $wpdb;
		return $wpdb->get_var("SELECT COUNT(*) FROM ".$wpdb->prefix."shopp_meta WHERE type = 'image' AND value LIKE '%DBStorage%'");

	}

	function migrate_images(){
		global $wpdb;
		set_time_limit(900);
		ini_set('memory_limit', '256M');

		$image_path = $this->get_path();
		$image_count = $this->get_image_count();

		$data = $wpdb->get_results("SELECT id, context, name, value FROM ".$wpdb->prefix."shopp_meta WHERE type = 'image' AND value LIKE '%DBStorage%'");

		$count = 0;
		foreach($data as $row){
			$obj = unserialize($row->value);

			if($obj->storage == 'DBStorage'){

				if(empty($obj->uri)){
					shopp_rmv_meta($row->id);
					continue;
				}

				$blob = $wpdb->get_var($wpdb->prepare("SELECT data FROM ".$wpdb->prefix."shopp_asset WHERE id = %d", $obj->uri));

				//skip any rows that don't have data in them
				if(empty($blob)){
					shopp_rmv_meta($row->id);
					continue;
				}

				$result = file_put_contents($image_path . $obj->filename, $blob);

				if(!$result){
					echo '<div class="error"><p>We\'re having problems with image id: '.$row->id.'</p></div>';
					return false;
				}

				//Update our object
				//storage engine
				$obj->storage = 'FSStorage';
				$obj->uri = $obj->filename;

				//set the new object
				$wpdb->update($wpdb->prefix.'shopp_meta', array('value' => serialize($obj)), array('id' => $row->id));

				if($count == 500){
					return $row->id;
				}

				$count++;

			}else{
				return false;
			}

		}

		return true;
	}

	function display_save_meta(){
				$options = get_option($this->product);
?>
				<input type="hidden" name="<?php echo $this->product; ?>_save" value="true" />
				<input type="submit" class="button-primary" value="Migrate Images" name="submit" />
<?php
		}

		function display_images_meta(){
			//GRAB PATH
			$image_path = $this->get_path();
			if(!$image_path){
				echo '<div class="error"><p>We could not find the image path. Please set it under Shopp->System->Image Storage</p></div>';
			}

			if(!$this->is_image_fsstorage()){
				echo '<div class="error"><p>It looks like you\'re not using the File System Storage Module. Please set it under Shopp->System->Image Storage</p></div>';
			}

			//is writable?
			if(!is_writable($image_path)){
				echo '<div class="error"><p>Hey, whoa! We can\'t write to the image folder. You need to fix this!</p></div>';
			}

			//GRAB COUNT
			$image_count = $this->get_image_count();
?>
		<div>
			<ol>
				<li>
					<p>
						Where we're saving your images: <strong><?php echo esc_attr($image_path); ?></strong>
					</p>
				</li>
				<li>
					<p>
						We found <strong><?php echo absint($image_count); ?></strong> images that we will migrate
					</p>
				</li>
				<li>
					<p>
						If this sounds right to you, click the migrate button to the right.
					</p>
				</li>
			</ol>
		</div>
<?php
		}

	function display_settings(){
		$options = get_option($product);

		if(isset($_REQUEST[$this->product.'_save']) && wp_verify_nonce($_REQUEST[$this->product.'_nonce'], 'nonce_save_settings')){
			$results = $this->migrate_images();

			if($results){
				echo '<div class="updated"><p>500 images migrated successfully! The last ID was: '.$results.'</p></div>';
			}else{
				echo '<div class="error"><p>We ran into an issue migrating the images... probably the MySQL server went away or the script timed out. Try running the script again to pick up where we left off.</p></div>';
			}
		}
?>
				<div id="<?php echo esc_attr($this->product); ?>" class="wrap">
						<h2><?php echo esc_attr($this->name); ?></h2>
						<div class="description">
								<p>This plugin allows you to migrate images that are stored in the database to actual files on the file system in batches of 500. <strong>This is not a destructive process! If something goes wrong, </strong></p>
						</div>
						<form action="" method="post">
										<div id="poststuff" class="metabox-holder has-right-sidebar">
												<div id="side-info-column" class="inner-sidebar">
														<?php do_meta_boxes('shopp-toolbox_page_'.$this->product, 'side', null); ?>
												</div>

												<div id="post-body" class="has-sidebar">
												<div id="post-body-content" class="has-sidebar-content">
														<div id="titlediv">
																<div id="titlewrap">
																</div>
																<div class="inside">
																		<?php do_meta_boxes('shopp-toolbox_page_'.$this->product, 'normal', null); ?>
																</div>
														</div>
												</div>
												</div>

										</div>
								<?php wp_nonce_field('nonce_save_settings', $this->product.'_nonce'); ?>
					</form>
				</div>
<?php
	}

	/**
	* Converts natural language text to boolean values
	*
	* Used primarily for handling boolean text provided in shopp() tag options.
	*
	* @author Jonathan Davis
	* @since 1.0
	*
	* @param string $value The natural language value
	* @return boolean The boolean value of the provided text
	**/
	function value_is_true ($value) {
		switch (strtolower($value)) {
			case "yes": case "true": case "1": case "on": return true;
			default: return false;
		}
	}
}
