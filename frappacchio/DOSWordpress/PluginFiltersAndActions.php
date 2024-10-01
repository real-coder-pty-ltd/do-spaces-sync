<?php

namespace frappacchio\DOSWordpress;

use frappacchio\DOSpaces\Filesystem;
use frappacchio\DOSpaces\Space;
use Spatie\ImageOptimizer\OptimizerChainFactory;

/**
 * Class PluginFiltersAndActions
 * @package frappacchio\DOSWordpress
 * @property Filesystem $fileSystem
 */
class PluginFiltersAndActions
{
    /**
     * @var Space;
     */
    private $fileSystem;

    /**
     * PluginFiltersAndActions constructor.
     */
    public function __construct()
    {
        if (PluginSettings::get('dos_key') && PluginSettings::get('dos_secret') && PluginSettings::get('dos_endpoint')) {
            $this->addActions();
            $this->addFilters();
            $this->fileSystem = $this->getFileSystem();
        }
    }

    /**
     * Binds Wordpress actions for add attachment and delete attachment
     */
    public function addActions()
    {
        add_action('add_attachment', [$this, 'action_add_attachment'], 20, 1);
        add_action('delete_attachment', [$this, 'action_delete_attachment'], 20, 1);
        add_action('admin_enqueue_scripts', [$this, 'registerAssets']);
        add_action('wp_ajax_dos_test_connection', [$this, 'testConnection']);
    }

    /**
     * Add filter in order to save all image formats in metadata
     */
    public function addFilters()
    {
        add_filter('wp_update_attachment_metadata', [$this, 'filter_wp_update_attachment_metadata'], 20, 1);
    }

    /**
     * Returns a space instance (ex. Digitalocean space instance), as file system instance
     * to use it for others actions
     * @return Filesystem
     */
    private function getFileSystem()
    {
        return new Filesystem(
            PluginSettings::get('dos_key'),
            PluginSettings::get('dos_secret'),
            PluginSettings::get('dos_container'),
            PluginSettings::get('dos_endpoint'),
            PluginSettings::get('dos_storage_path'),
            PluginSettings::get('dos_filter')
        );
    }

    /**
     * Register static assets
     * @param string $hook
     */
    public function registerAssets($hook)
    {
        if ($hook === 'settings_page_dos-settings-page') {
            wp_enqueue_script('dos-script-js', DOS_PLUGIN_URL . DIRECTORY_SEPARATOR . 'assets/scripts/core.js', array('jquery'));
            wp_localize_script('dos-script-js', 'DOSUtils', array(
                'url' => admin_url('admin-ajax.php'),
                'response' => [
                    'true' => __('Connection established successfully', 'dos'),
                    'false' => __('There was a problem during connection test', 'dos'),
                ],
            ));
        }
    }

    /**
     * Check if the environment connection is available
     * @return bool
     */
    public function testConnection()
    {
        return wp_send_json(['success' => $this->fileSystem->testConnection()]);
    }

    /**
     * Check for image formats in metadata and save all of them
     * @param $metadata
     * @return array
     */
    public function filter_wp_update_attachment_metadata($metadata)
    {
        if (!empty($metadata['sizes'])) {
            foreach ($this->getPaths($metadata) as $filePath) {
                $this->fileUpload($filePath);
            }
        }

        return $metadata;
    }

    /**
     * Checks for other images formats in the metadata array and returns them as array
     * with a list of file path to local folder
     * @param array $metadata
     * @return array
     */
    private function getPaths($metadata)
    {
        $paths = [];
        $upload_dir = wp_upload_dir();
        if (isset($metadata['file'])) {
            $path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $metadata['file'];
            array_push($paths, $path);
            $file_info = pathinfo($path);
            $basepath = isset($file_info['extension']) ? str_replace($file_info['filename'] . "." . $file_info['extension'], "", $path) : $path;
        }
        if (isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (isset($size['file'])) {
                    $path = $basepath . $size['file'];
                    array_push($paths, $path);
                }
            }
        }
        return $paths;
    }

    /**
     * Upload a file to the space and delete it from local folder if this is setted from
     * the settings page
     * @param string $filePath
     */
    private function fileUpload($filePath)
    {
        if (file_exists($filePath)) {
            $this->optimizeImage($filePath);
            if ($this->fileSystem->upload($filePath, $this->cleanFilePath($filePath))) {
                if (PluginSettings::get('dos_storage_file_only')) {
                    unlink($filePath);
                }
            }
        }

    }

    /**
     * Optimize images throug Image optimizer
     * The image will be replaced with the optmized one
     * @see https://github.com/spatie/image-optimizer
     * @param string $filePath
     */
    private function optimizeImage($filePath)
    {
        if (PluginSettings::get('dos_optimize_images') && preg_match('/.*\.(jpg|jpeg|gif|svg|png)/', $filePath)) {
            $optimizerChain = OptimizerChainFactory::create();
            $optimizerChain->optimize($filePath);
        }
    }

    /**
     * Returns the correct file path removing the local path
     * or adding a prefix folder if it's defined
     * @param $filePath
     * @return string
     */
    private function cleanFilePath($filePath)
    {
        $upload_dir = wp_upload_dir();
        $basePath = str_replace($upload_dir['basedir'], '', $filePath);
        if (PluginSettings::get('dos_storage_path')) {
            $basePath = PluginSettings::get('dos_storage_path') . DIRECTORY_SEPARATOR . $basePath;
        }
        return $basePath;
    }

    /**
     * Save the file by it's Wordpress ID identifier
     * @param int $postID
     * @return bool
     */
    public function action_add_attachment($postID)
    {
        if (wp_attachment_is_image($postID) === false) {
            $this->fileUpload(get_attached_file($postID));
        }
        return true;
    }

    /**
     * Delete the file and all its related formats by it's Wordpress ID identifier
     * @param int $postID
     * @return bool
     */
    public function action_delete_attachment($postID)
    {
        if (PluginSettings::get('dos_storage_file_delete')) {
            if (wp_attachment_is_image($postID) == false) {
                $filePath = get_attached_file($postID);
                return $this->fileSystem->delete($this->cleanFilePath($filePath));
            } else {
                $metadata = wp_get_attachment_metadata($postID);
                foreach ($this->getPaths($metadata) as $filepath) {
                    if (!$this->fileSystem->delete($this->cleanFilePath($filepath))) {
                        return false;
                    }
                }
                return true;
            }
        }

    }
}
