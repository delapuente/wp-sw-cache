<?php

load_plugin_textdomain('wpswcache', false, dirname(plugin_basename(__FILE__)) . '/lang');

class SW_Cache_Admin {
  private static $instance;

  public function __construct() {
    add_action('admin_menu', array($this, 'on_admin_menu'));
    add_action('admin_notices', array($this, 'on_admin_notices'));
    add_action('after_switch_theme', array($this, 'on_switch_theme'));
  }

  public static function init() {
    if (!self::$instance) {
      self::$instance = new self();
    }
  }

  public function on_admin_notices() {
    // TODO:  Add notice that if the plugin is activated but no files are selected, nothing is happening

    if(get_option('wp_sw_cache_enabled') && !count(get_option('wp_sw_cache_files'))) {
      echo '<div class="update-nag"><p>',  __('Service Worker is enabled but no files have been selected for caching.  To take full advantage of this plugin, please select files to cache.'), '</p></div>';
    }

    if(get_option('wp_sw_cache_enabled') && ($_SERVER['REQUEST_SCHEME'] != 'https' && strrpos(strtolower($_SERVER['HTTP_HOST']), 'localhost', -strlen($_SERVER['HTTP_HOST']) === false))) {
      echo '<div class="update-nag"><p>The ServiceWorker API requires a secure origin (HTTPS or localhost).  Your Service Worker may not work.</p></div>';
    }
  }

  public function on_admin_menu() {
    add_options_page(__('WP SW Cache', 'wpswcache'), __('WP SW Cache', 'wpswcache'), 'manage_options', 'wp-sw-cache-options', array($this, 'options'));
  }

  public function on_switch_theme() {
    if(get_option('wp_sw_cache_enabled')) {
      update_option('wp_sw_cache_enabled', false);
      update_option('wp_sw_cache_files', array());
      add_action('admin_notices', array($this, 'show_switch_theme_message'));
    }
  }

  function show_switch_theme_message() {
    echo '<div class="update-nag"><p>',  __('You\'ve changed themes; please update your WP ServiceWorker Cache options.'), '</p></div>';
  }

  // http://php.net/manual/en/function.scandir.php#109140
  public function scan_theme_dir($directory, $recursive = true, $listDirs = false, $listFiles = true, $exclude = '') {
    $arrayItems = array();
    $skipByExclude = false;
    $handle = opendir($directory);
    if ($handle) {
        while (false !== ($file = readdir($handle))) {
        preg_match("/(^(([\.]){1,2})$|(\.(svn|git|md))|(Thumbs\.db|\.DS_STORE))$/iu", $file, $skip);
        if($exclude){
            preg_match($exclude, $file, $skipByExclude);
        }
        if (!$skip && !$skipByExclude) {
            if (is_dir($directory. DIRECTORY_SEPARATOR . $file)) {
                if($recursive) {
                    $arrayItems = array_merge($arrayItems, $this->scan_theme_dir($directory. DIRECTORY_SEPARATOR . $file, $recursive, $listDirs, $listFiles, $exclude));
                }
                if($listDirs){
                    $file = $directory . DIRECTORY_SEPARATOR . $file;
                    $arrayItems[] = $file;
                }
            } else {
                if($listFiles){
                    $file = $directory . DIRECTORY_SEPARATOR . $file;
                    $arrayItems[] = $file;
                }
            }
        }
    }
    closedir($handle);
    }
    return $arrayItems;
  }

  function options() {

    $submitted = false;

    // Form submission
    if(isset($_POST['form_submitted'])) {

      $submitted = true;

      // Update "enabled" status
      update_option('wp_sw_cache_enabled', isset($_POST['wp_sw_cache_enabled']));

      // Update "prefix" value
      if(isset($_POST['wp_sw_cache_name'])) {
        update_option('wp_sw_cache_name', $_POST['wp_sw_cache_name']);
      }
      else {
        update_option('wp_sw_cache_name', SW_Cache_DB::$cache_prefix.'-'.time());
      }

      // Update files to cache
      if(isset($_POST['wp_sw_cache_files'])) {
        update_option('wp_sw_cache_files', $_POST['wp_sw_cache_files']);
      }
      else {
        update_option('wp_sw_cache_files', array());
      }
    }

    $selected_files = get_option('wp_sw_cache_files');
    if(!$selected_files) {
      $selected_files = array();
    }

?>

<div class="wrap">

  <?php if($submitted) { ?>
    <div class="updated">
      <p><?php _e('Your settings have been saved.'); ?></p>
    </div>
  <?php } ?>

  <h1><?php _e('WordPress Service Worker Cache', 'wpswcache'); ?></h1>

  <p><?php _e('WordPress Service Worker Cache is a ultility that harnesses the power of the <a href="https://serviceworke.rs" target="_blank">ServiceWorker API</a> to cache frequently used assets for the purposes of performance and offline viewing.'); ?></p>

  <form method="post" action="">
    <input type="hidden" name="form_submitted" value="1">

    <h2><?php _e('ServiceWorker Cache Settings', 'wpswcache'); ?></h2>
    <table class="form-table">
    <tr>
      <th scope="row"><label for="wp_sw_cache_enabled"><?php _e('Enable Service Worker', 'wpswcache'); ?></label></th>
      <td>
        <input type="checkbox" name="wp_sw_cache_enabled" id="wp_sw_cache_enabled" value="1" <?php if(get_option('wp_sw_cache_enabled')) echo 'checked'; ?> autofocus />
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wp_sw_cache_name"><?php _e('Cache Name', 'wpswcache'); ?></label></th>
      <td>
        <input type="text" name="wp_sw_cache_name" id="wp_sw_cache_name" value="<?php echo esc_attr__(get_option('wp_sw_cache_name')); ?>" class="regular-text ltr" disabled />
        <?php _e('(Will update upon save for cache-busting purposes.)'); ?>
      </td>
    </tr>
    </table>

    <h2><?php _e('Theme Files to Cache', 'wpswcache'); ?> (<code><?php echo get_template(); ?></code>)</h2>
    <p>
      <?php _e('Select theme assets (typically JavaScript, CSS, fonts, and image files) that are used on a majority of pages.', 'wpswcache'); ?>
      <button type="button" class="button button-primary wp-sw-cache-suggest-file-button" data-suggested-text="<?php echo esc_attr__('Files Suggested: '); ?>"><?php _e('Suggest More Files'); ?></button>
    </p>
    <div class="wp-sw-cache-file-list">

      <?php
        $template_abs_path = get_template_directory();
        $theme_files = $this->scan_theme_dir($template_abs_path);
        $categories = array(
          array('title' => __('CSS Files', 'wpswcache'), 'extensions' => array('css'), 'files' => array()),
          array('title' => __('JavaScript Files', 'wpswcache'), 'extensions' => array('js'), 'files' => array()),
          array('title' => __('Font Files', 'wpswcache'), 'extensions' => array('woff', 'woff2', 'ttf'), 'files' => array()),
          array('title' => __('Image Files', 'wpswcache'), 'extensions' => array('svg', 'jpg', 'jpeg', 'gif', 'png', 'webp'), 'files' => array()),
          array('title' => __('Other Files', 'wpswcache'), 'extensions' => array('*'), 'files' => array()) // Needs to be last
        );

        // Sort the files and place them in their baskets
        foreach($theme_files as $file) {
          $file_relative = str_replace(get_theme_root().'/'.get_template().'/', '', $file);
          $path_info = pathinfo($file_relative);
          $file_category_found = false;

          foreach($categories as $index=>$category) {
            if(in_array(strtolower($path_info['extension']), $category['extensions']) || ($file_category_found === false && $category['extensions'][0] === '*')) {
              $categories[$index]['files'][] = $file_relative;
              $file_category_found = true;
            }
          }
        }

        foreach($categories as $category) { ?>
          <h3><?php echo $category['title']; ?> (<?php echo implode(', ', $category['extensions']); ?>)</h3>
          <?php if(count($category['files'])) { ?>
          <table id="files-list">
            <?php foreach($category['files'] as $file) { ?>
            <tr>
              <td style="width: 30px;">
                <input type="checkbox" name="wp_sw_cache_files[]" id="wp_sw_cache_files['<?php echo $file; ?>']" value="<?php echo $file; ?>" <?php if(in_array($file, $selected_files)) { echo 'checked'; } ?> />
              </td>
              <td>
                <label for="wp_sw_cache_files['<?php echo $file; ?>']"><?php echo $file; ?></label>
              </td>
            </tr>
            <?php } ?>
          </table>
          <?php } else { ?><p><?php _e('No matching files found.', 'wpswcache'); ?></p><?php } ?>

        <?php } ?>
    </div>

    <?php submit_button(__('Save Changes'), 'primary'); ?>
  </form>

  <h2>Clear Caches</h2>
  <p><?php _e('Click the button below to clear any caches created by this plugin.'); ?></p>
  <button type="button" class="button button-primary wp-sw-cache-clear-caches-button" data-cleared-text="<?php echo esc_attr__('Caches cleared: '); ?>"><?php _e('Clear Caches'); ?></button>

</div>

<script type="text/javascript">
  jQuery('.wp-sw-cache-suggest-file-button').on('click', function() {
    // TODO:  More advanced logic

    var $this = jQuery(this);
    var suggestedCounter = 0;

    // Suggest main level CSS and JS files
    jQuery([
      '#files-list input[type="checkbox"][value$=".css"]:not([checked]):not([value*="/"])',
      '#files-list input[type="checkbox"][value$=".js"]:not([checked]):not([value*="/"])'
    ].join(',')).each(function() {
      this.checked = true;
      jQuery(this).closest('tr').addClass('wp-sw-cache-suggested');
      suggestedCounter++;
    });

    $this.text($this.data('suggested-text') + ' ' + suggestedCounter);
    this.disabled = true;
  });


  jQuery('.wp-sw-cache-clear-caches-button').on('click', function() {
    var clearedCounter = 0;
    var $button = jQuery(this);

    // Clean up old cache in the background
    caches.keys().then(function(cacheNames) {
      return Promise.all(
        cacheNames.map(function(cacheName) {
          clearedCounter++;
          return caches.delete(cacheName);
        })
      );
    }).then(function() {
      $button.text($button.data('cleared-text') + ' ' + clearedCounter);
      $button[0].disabled = true;
    });
  });
</script>

<style>
  .wp-sw-cache-suggested {
    background: lightgreen;
  }

  .wp-sw-cache-suggest-file-button {
    float: right;
  }

  .wp-sw-cache-file-list {
    max-height: 300px;
    background: #fefefe;
    border: 1px solid #ccc;
    padding: 10px;
    overflow-y: auto;
  }
</style>

<?php
  }
}
?>
