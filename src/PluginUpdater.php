<?php

namespace Wakaloka\Lib\EDD;

use Exception;
use WP_Error;

/**
 * Plugin updater class. Handles the plugin update process. 
 * Integrated with Easy Digital Download's Software Licensing plugin.
 */
class PluginUpdater
{
	/**
	 * The options that will passed to the EDD_SL_Plugin_Updater instance.
	 * 
	 * @var array
	 */
	private $payload = [];

	/**
	 * The plugin ID.
	 * 
	 * @var string
	 */
	private $plugin_id;

	/**
	 * The instance of EDD_SL_Plugin_Updater class.
	 * 
	 * @var EDD_SL_Plugin_Updater
	 */
	public $edd_sl;

	/**
	 * The constructor of the class.
	 * 
	 * @param string $plugin_id The plugin ID.
	 * @param array  $payload   The options that will passed to the EDD_SL_Plugin_Updater instance.
	 */
	public function __construct($plugin_id, $payload)
	{
		$this->payload   = $payload;
		$this->plugin_id = $plugin_id;

		add_action('init', [$this, 'plugin_update']);
	}

	/**
	 * Run the plugin update process.
	 * 
	 * @throws Exception 
	 */
	public function plugin_update()
	{
		if ($this->is_activated()) {
			$doing_cron = defined('DOING_CRON') && DOING_CRON;
			if (!(current_user_can('manage_options') && $doing_cron)) {
				if (!$this->edd_sl) {
					$this->edd_sl = new EDD_SL_Plugin_Updater($this->payload['store_url'], $this->payload['plugin_file'], $this->payload);
				}
			}
		}
	}

	/**
	 * Check if the plugin have licensed and activated.
	 * 
	 * @return mixed 
	 * @throws Exception 
	 */
	public function is_activated()
	{
		$license = get_transient("{$this->plugin_id}_license_seed");

		if (!$license && empty($this->payload['license'])) {
			if (array_key_exists('is_require_license', $this->payload) && $this->payload['is_require_license']) {
				throw new Exception('Enter your license key to get update');
			}

			return false;
		}

		if ($license) {
			if ($license->license !== 'valid') {
				throw new Exception($this->error_message($license->license));
			}

			return $license;
		}

		$response = $this->api_request('check_license');

		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			throw new Exception(is_wp_error($response) ? $response->get_error_message() : 'An Updater error occurred, please try again.');
		}

		$license_data = json_decode(wp_remote_retrieve_body($response));

		if ($license_data->success === false) {
			if (property_exists($license_data, 'error')) {
				throw new Exception($this->error_message($license_data->error));
			}

			return false;
		}

		set_transient("{$this->plugin_id}_license_seed", $license_data, 60 * 60 * 24);

		return $license_data;
	}

	private function api_request($action)
	{
		return wp_remote_post($this->payload['store_url'], [
			'timeout'   => 15,
			'sslverify' => false,
			'body'      => [
				'edd_action'  => $action,
				'license'     => $this->payload['license'] ?? '',
				'item_id'     => $this->payload['item_id'] ?? false,
				'version'     => $this->payload['version'] ?? false,
				'slug'        => basename($this->payload['plugin_file'], '.php'),
				'author'      => $this->payload['author'],
				'url'         => site_url(),
				'beta'        => $this->payload['beta'] ?? false,
				'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
			]
		]);
	}

	/**
	 * Deregister the current site from the license server.
	 * 
	 * @return array|WP_Error 
	 */
	public function deactivate()
	{
		delete_transient("{$this->plugin_id}_license_seed");

		return $this->api_request('deactivate_license');
	}

	/**
	 * Register the current site to the license server.
	 * 
	 * @param null|string $license 
	 * @return array|WP_Error 
	 */
	public function activate(?string $license)
	{
		if ($license) {
			$this->payload['license'] = $license;
		}

		delete_transient("{$this->plugin_id}_license_seed");

		return $this->api_request('activate_license');
	}

	public function error_message($msgcode)
	{
		switch ($msgcode) {
			case 'expired':
				return 'Your license key expired';
			case 'disabled':
			case 'revoked':
				return 'Your license key has been disabled.';
			case 'inactive':
			case 'site_inactive':
				return 'Your license is not active for this URL.';
			case 'missing_url':
				return 'License doesn\'t exist or URL not provided.';
			case 'key_mismatch':
			case 'missing':
			case 'invalid':
			case 'invalid_item_id':
			case 'item_name_mismatch':
				return 'Invalid license key';
			case 'no_activations_left':
				return 'Your license key has reached its activation limit.';
			default:
				return 'An error occurred on update, please try again.';
		}
	}
}
