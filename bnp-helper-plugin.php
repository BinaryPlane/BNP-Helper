<?php
/*
Plugin Name: Helper Plugin
Description: This plugin is used for the site's maintenance. Must not be removed.
Version: 1.1
Author: Moazzam
GitHub Plugin URI: BinaryPlane/BNP-Helper
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add updater class
class GitHub_Plugin_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $username;
    private $repository;
    private $authorize_token;
    private $github_response;

    public function __construct($file) {
        $this->file = $file;
        add_action('admin_init', array($this, 'set_plugin_properties'));

        // Set defaults
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
    }

    public function set_plugin_properties() {
        $this->plugin = get_plugin_data($this->file);
        $this->username = 'BinaryPlane';    // Changed to your organization name
        $this->repository = 'BNP-Helper';   // Changed to your repository name
        $this->authorize_token = null;      // Can be added later if needed
    }

    // Rest of the updater class remains the same as before
    private function get_repository_info() {
        if (is_null($this->github_response)) {
            $request_uri = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->username, $this->repository);
            
            $args = array();
            if ($this->authorize_token) {
                $args['headers']['Authorization'] = "bearer {$this->authorize_token}";
            }

            $response = json_decode(wp_remote_retrieve_body(wp_remote_get($request_uri, $args)), true);

            if (is_array($response)) {
                $this->github_response = $response;
            }
        }
    }

    public function initialize() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    public function modify_transient($transient) {
        if (property_exists($transient, 'checked')) {
            if ($checked = $transient->checked) {
                $this->get_repository_info();
                $out_of_date = version_compare($this->github_response['tag_name'], $checked[$this->basename], 'gt');

                if ($out_of_date) {
                    $new_files = $this->github_response['zipball_url'];
                    $slug = current(explode('/', $this->basename));

                    $plugin = array(
                        'url' => $this->plugin["PluginURI"],
                        'slug' => $slug,
                        'package' => $new_files,
                        'new_version' => $this->github_response['tag_name']
                    );

                    $transient->response[$this->basename] = (object) $plugin;
                }
            }
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return false;
        }

        if (!empty($args->slug)) {
            if ($args->slug == current(explode('/' , $this->basename))) {
                $this->get_repository_info();

                $plugin = array(
                    'name'              => $this->plugin["Name"],
                    'slug'              => $this->basename,
                    'version'           => $this->github_response['tag_name'],
                    'author'            => $this->plugin["AuthorName"],
                    'author_profile'    => $this->plugin["AuthorURI"],
                    'last_updated'      => $this->github_response['published_at'],
                    'homepage'          => $this->plugin["PluginURI"],
                    'short_description' => $this->plugin["Description"],
                    'sections'          => array(
                        'Description'   => $this->plugin["Description"],
                        'Updates'       => $this->github_response['body'],
                    ),
                    'download_link'     => $this->github_response['zipball_url']
                );

                return (object) $plugin;
            }
        }
        return $result;
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        if ($this->active) {
            activate_plugin($this->basename);
        }

        return $result;
    }
}

// Your existing plugin functionality
add_action('admin_enqueue_scripts', function($hook) {
    // Only load on updates page
    if ($hook !== 'update-core.php') {
        return;
    }

    // Add copy icon styles
    wp_enqueue_style('dashicons');

    // Add our custom script
    wp_add_inline_script('jquery', "
        jQuery(document).ready(function($) {
            // Add copy buttons to tables
            $('#update-plugins-table thead tr td:last-child, #update-themes-table thead tr td:last-child').append(
                '<span title=\"Copy Titles\" class=\"dashicons dashicons-clipboard copy-names\" style=\"margin-left:12px;cursor:pointer;color:#2271b1;\"></span>'
            );

            // Copy functionality
            $('.copy-names').on('click', function() {
                let names = [];
                const isPlugins = $(this).closest('table').attr('id') === 'update-plugins-table';
                
                // Get names from the correct table
                $(this).closest('table').find('tbody tr').each(function() {
                    const name = $(this).find(isPlugins ? '.plugin-title strong' : '.theme-title strong').text().trim();
                    if (name) names.push(name);
                });

                // Copy to clipboard
                const tempInput = $('<input>');
                $('body').append(tempInput);
                tempInput.val(names.join('\\n')).select();
                document.execCommand('copy');
                tempInput.remove();

                // Visual feedback
                $(this).css('color', '#00a32a');
                setTimeout(() => {
                    $(this).css('color', '#2271b1');
                }, 1000);
            });
        });
    ");
});

// Initialize the updater
if (is_admin()) {
    $updater = new GitHub_Plugin_Updater(__FILE__);
    $updater->initialize();
}