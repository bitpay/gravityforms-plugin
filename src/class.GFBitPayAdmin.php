<?php

/**
 * @license Copyright 2011-2014 BitPay Inc., MIT License
 * see https://github.com/bitpay/gravityforms-plugin/blob/master/LICENSE
 */

/**
 * Class for admin screens
 */
class GFBitPayAdmin
{
    public $settingsURL;
    private $plugin;

    /**
     * @param GFBitPayPlugin $plugin
     */
    public function __construct($plugin)
    {
        $this->plugin = $plugin;

        // handle change in settings pages
        if (true === class_exists('GFCommon')) {
            if (version_compare(GFCommon::$version, '1.6.99999', '<')) {
                // pre-v1.7 settings
                $this->settingsURL = admin_url('admin.php?page=gf_settings&addon=BitPay+Payments');
            } else {
                // post-v1.7 settings
                $this->settingsURL = admin_url('admin.php?page=gf_settings&subview=BitPay+Payments');
            }
        }

        // handle admin init action
        add_action('admin_init', array($this, 'adminInit'));

        // add GravityForms hooks
        add_action("gform_entry_info", array($this, 'gformEntryInfo'), 10, 2);

        // hook for showing admin messages
        add_action('admin_notices', array($this, 'actionAdminNotices'));

        // add action hook for adding plugin action links
        add_action('plugin_action_links_' . GFBITPAY_PLUGIN_NAME, array($this, 'addPluginActionLinks'));

        // hook for adding links to plugin info
        add_filter('plugin_row_meta', array($this, 'addPluginDetailsLinks'), 10, 2);

        // hook for enqueuing admin styles
        add_filter('admin_enqueue_scripts', array($this, 'enqueueScripts'));

        self::create_table();
    }

    /**
     * test whether GravityForms plugin is installed and active
     * @return boolean
     */
    public static function isGfActive()
    {
        return class_exists('RGForms');
    }

    /**
     * handle admin init action
     */
    public function adminInit()
    {
        if (true === isset($_GET['page'])) {
            switch ($_GET['page']) {
                case 'gf_settings':
                    // add our settings page to the Gravity Forms settings menu
                    RGForms::add_settings_page('BitPay Payments', array($this, 'optionsAdmin'));
                    break;
                default:
                    // not used
            }
        }
    }

    /**
     * only output our stylesheet if this is our admin page
     */
    public function enqueueScripts()
    {
        wp_enqueue_style('gfbitpay-admin', $this->plugin->urlBase . 'style-admin.css', false, GFBITPAY_PLUGIN_VERSION);
    }

    /**
     * show admin messages
     */
    public function actionAdminNotices()
    {
        if (self::isGfActive() == false) {
            $this->plugin->showError('Gravity Forms BitPay Payments requires <a href="http://www.gravityforms.com/">Gravity Forms</a> to be installed and activated.');
        }
    }

    /**
     * action hook for adding plugin action links
     */
    public function addPluginActionLinks($links)
    {
        // add settings link, but only if GravityForms plugin is active
        if (self::isGfActive() == true) {
            $settings_link = sprintf('<a href="%s">%s</a>', $this->settingsURL, __('Settings'));
            array_unshift($links, $settings_link);
        }

        return $links;
    }

    /**
    * action hook for adding plugin details links
    */
    public static function addPluginDetailsLinks($links, $file)
    {
        if (true === isset($file) && $file == GFBITPAY_PLUGIN_NAME) {
            $links[] = '<a href="https://support.bitpay.com">' . __('Get help') . '</a>';
            $links[] = '<a href="https://www.bitpay.com">' . __('Bitpay.com') . '</a>';
        }

        return $links;
    }

    /**
     * action hook for building the entry details view
     * @param int $form_id
     * @param array $lead
     */
    public function gformEntryInfo($form_id, $lead)
    {
        if (true === isset($lead) && false === empty($lead)) {
            $payment_gateway = gform_get_meta($lead['id'], 'payment_gateway');

            if ($payment_gateway == 'gfbitpay') {
                $authcode = gform_get_meta($lead['id'], 'authcode');

                if (true === isset($authcode)) {
                    echo 'Auth Code: ', esc_html($authcode), "<br /><br />\n";
                }
            }
        } else {
            error_log('[ERROR] In GFBitPayAdmin::gformEntryInfo(): Missing or invalid $lead parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $lead parameter in the gformEntryInfo() function.');
        }
    }

    /**
     * action hook for processing admin menu item
     */
    public function optionsAdmin()
    {
        $admin = new GFBitPayOptionsAdmin($this->plugin, 'gfbitpay-options', $this->settingsURL);

        if (true === isset($admin) && false === empty($admin)) {
            $admin->process();
        } else {
            error_log('[ERROR] In GFBitPayAdmin::optionsAdmin(): Could not create a new GFBitPayOptionsAdmin object.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Could not create a new GFBitPayOptionsAdmin object.');
        }
    }

    /**
     * creates the database table used by this plugin
     */
    public static function create_table()
    {
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        // Access to Wordpress Database
        global $wpdb;

        // Query for creating Keys Table
        $sql = "CREATE TABLE IF NOT EXISTS `bitpay_transactions` (
                `id` int(11) not null auto_increment,
                `lead_id` varchar(1000) not null,
                `buyer_email` varchar(1000) not null,
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

        try {
            // execute SQL statement
            dbDelta($sql);
        } catch (\Exception $e) {
            error_log('[Error] In GFBitPayAdmin::create_table() function on line ' . $e->getLine() . ', with the error "' . $e->getMessage() . '" .');
            throw $e;
        }
    }
}
